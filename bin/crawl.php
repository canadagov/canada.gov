#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Builds the search index (data/index.sqlite) by reading official sites.
 *
 *   php bin/crawl.php                 crawl every site in data/crawl.json, and the catalogue pages
 *   php bin/crawl.php --site=ontario  only some sites (comma-separated; "catalogue" is one)
 *   php bin/crawl.php --limit=20      at most 20 pages per site, for trying it out
 *   php bin/crawl.php --retry         try again the pages that failed last time
 *
 * It starts from each site's "start" pages and follows links found in page
 * content, nearest first, for as long as they match the site's "include"
 * pattern. Each page's counterpart in the other official language is read
 * too. Stop it at any time; running it again carries on where it left off.
 *
 * It asks each host for one page at a time and waits CRAWL_DELAY seconds (or
 * the site's own Crawl-delay, if longer) before asking it for another, obeys
 * robots.txt, and leaves a host alone for the rest of the run after
 * MAX_STRIKES refusals in a row.
 */

require_once __DIR__ . '/../src/search.php';

const CRAWL_DELAY = 1.0;
const MAX_STRIKES = 3;

$options = getopt('', ['site:', 'limit:', 'retry']);
$only = isset($options['site']) ? explode(',', $options['site']) : null;

$sites = [];
foreach (json_decode(file_get_contents(ROOT . '/data/crawl.json'), true) as $site) {
    $sites[$site['site']] = $site;
}
// The hand-picked catalogue pages are indexed on their own, without following their links.
$sites['catalogue'] = ['site' => 'catalogue', 'host' => null, 'region' => 'CA', 'max_pages' => PHP_INT_MAX, 'max_depth' => 0];
if ($only) {
    $sites = array_intersect_key($sites, array_flip($only));
}
if (isset($options['limit'])) {
    foreach ($sites as &$site) {
        $site['max_pages'] = (int) $options['limit'];
    }
    unset($site);
}

$db = index_db();
$db->exec("UPDATE queue SET state = 'todo' WHERE state = 'held'" . (isset($options['retry']) ? " OR state = 'failed'" : ''));

function enqueue(string $url, string $site, string $lang, string $org, int $depth): void
{
    static $add = null;
    $add ??= index_db()->prepare('INSERT OR IGNORE INTO queue (url, site, lang, org, depth) VALUES (:url, :site, :lang, :org, :depth)');
    foreach (compact('url', 'site', 'lang', 'org', 'depth') as $name => $value) {
        $add->bindValue(":$name", $value);
    }
    $add->execute();
}

function set_state(string $url, string $state): void
{
    static $set = null;
    $set ??= index_db()->prepare('UPDATE queue SET state = :state WHERE url = :url');
    $set->bindValue(':state', $state);
    $set->bindValue(':url', $url);
    $set->execute();
}

/** Leave a host alone for the rest of the run. */
function hold_host(string $host): void
{
    $hold = index_db()->prepare("UPDATE queue SET state = 'held' WHERE state = 'todo' AND url LIKE :prefix");
    $hold->bindValue(':prefix', "https://$host/%");
    $hold->execute();
}

/**
 * What robots.txt asks of every crawler on a host: ['rules' => [[allow, regex], ...],
 * 'delay' => seconds], or null when robots.txt could not be read.
 */
function robots(string $host): ?array
{
    static $cache = [];
    if (array_key_exists($host, $cache)) {
        return $cache[$host];
    }
    $url = "https://$host/robots.txt";
    $response = http_get_many([$url], FETCH_TIMEOUT)[$url];
    if ($response['status'] !== 200 && $response['status'] !== 404) {
        return $cache[$host] = null;
    }
    $robots = ['rules' => [], 'delay' => CRAWL_DELAY];
    $applies = false;
    $inAgents = false;
    foreach ($response['status'] === 200 ? preg_split('/\R/', $response['body']) : [] as $line) {
        $line = trim(explode('#', $line, 2)[0]);
        if (!preg_match('/^([a-z-]+)\s*:\s*(.*)$/i', $line, $m)) {
            continue;
        }
        [$field, $value] = [strtolower($m[1]), $m[2]];
        if ($field === 'user-agent') {
            // Consecutive User-agent lines share the rules that follow them.
            $applies = ($inAgents && $applies) || $value === '*';
            $inAgents = true;
            continue;
        }
        $inAgents = false;
        if (!$applies) {
            continue;
        }
        if ($field === 'crawl-delay') {
            $robots['delay'] = max(CRAWL_DELAY, (float) $value);
        } elseif (($field === 'allow' || $field === 'disallow') && $value !== '') {
            $regex = str_replace('\*', '.*', preg_quote($value, '~'));
            $regex = str_ends_with($regex, '\$') ? substr($regex, 0, -2) . '$' : $regex;
            $robots['rules'][] = [$field === 'allow', '~^' . $regex . '~', strlen($value)];
        }
    }
    return $cache[$host] = $robots;
}

/** Whether robots.txt lets a crawler read this URL. The longest matching rule decides. */
function robots_allows(string $url): bool
{
    $robots = robots((string) parse_url($url, PHP_URL_HOST));
    if ($robots === null) {
        return false;
    }
    $path = (parse_url($url, PHP_URL_PATH) ?: '/') . (($query = parse_url($url, PHP_URL_QUERY)) ? "?$query" : '');
    $allowed = true;
    $longest = -1;
    foreach ($robots['rules'] as [$allow, $regex, $length]) {
        if (preg_match($regex, $path) && ($length > $longest || ($length === $longest && $allow))) {
            $allowed = $allow;
            $longest = $length;
        }
    }
    return $allowed;
}

/** Whether a link found on a site's page is one this crawl should read. */
function in_scope(string $url, array $site): bool
{
    $parts = parse_url($url);
    $path = $parts['path'] ?? '/';
    return ($parts['host'] ?? '') === $site['host']
        && empty($parts['query'])
        && strlen($url) < 300
        && preg_match('~' . $site['include'] . '~', $path)
        && !preg_match('~' . $site['exclude'] . '~', $path)
        // Pages only: no PDFs, images or downloads.
        && preg_match('~(^|/)[^/.]*(\.(html?|page|aspx))?$~i', $path);
}

function page_links(string $html, string $base): array
{
    preg_match_all('/<a\b[^>]*\bhref="([^"]+)"/i', main_html($html), $m);
    $links = array_map(fn(string $href) => absolute_url(html_entity_decode($href), $base), $m[1]);
    return array_unique(array_filter($links));
}

// Where each crawl begins.
foreach ($sites as $name => $site) {
    foreach ($site['start'] ?? [] as $url) {
        enqueue($url, $name, $site['lang'], $site['org'][$site['lang']], 0);
    }
}
foreach (load_sources() as $source) {
    $host = parse_url($source['url_en'], PHP_URL_HOST);
    $home = current(array_filter($sites, fn(array $s) => $s['host'] === $host)) ?: ($sites['catalogue'] ?? null);
    if (!$home) {
        continue;
    }
    $follow = $home['site'] !== 'catalogue' && in_scope($source['url_en'], $home);
    enqueue($source['url_en'], $home['site'], 'en', $home['org']['en'] ?? $source['org'], $follow ? 0 : -1);
    if ($source['url_fr'] !== $source['url_en']) {
        enqueue($source['url_fr'], $home['site'], 'fr', $home['org']['fr'] ?? $source['org'], -1);
    }
}

$count = $db->prepare("SELECT COUNT(*) FROM queue WHERE site = :site AND state = 'done' AND depth >= 0");
$next = $db->prepare("SELECT * FROM queue WHERE site = :site AND state = 'todo' AND (depth < 0 OR :open) ORDER BY depth, rowid LIMIT 1");
$done = [];
foreach ($sites as $name => $site) {
    $count->bindValue(':site', $name);
    $done[$name] = $count->execute()->fetchArray(SQLITE3_NUM)[0];
}
$readyAt = [];
$strikes = [];

for (;;) {
    // One page from each site with something left to read, never two from the same host.
    $jobs = [];
    foreach ($sites as $name => $site) {
        $next->bindValue(':site', $name);
        $next->bindValue(':open', (int) ($done[$name] < $site['max_pages']));
        $row = $next->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            continue;
        }
        $host = parse_url($row['url'], PHP_URL_HOST);
        if (isset($jobs[$host])) {
            continue;
        }
        if (robots($host) === null) {
            echo "HELD     $host: robots.txt could not be read\n";
            hold_host($host);
        } elseif (!robots_allows($row['url'])) {
            set_state($row['url'], 'skipped');
            // Not a request, so no need to wait before the next round.
            $jobs[$host] = null;
        } else {
            $jobs[$host] = $row;
        }
    }
    if (!$jobs) {
        break;
    }
    $jobs = array_filter($jobs);
    if (!$jobs) {
        continue;
    }

    $wait = max(array_map(fn(string $host) => $readyAt[$host] ?? 0, array_keys($jobs))) - microtime(true);
    if ($wait > 0) {
        usleep((int) ($wait * 1e6));
    }
    $pages = http_get_many(array_column($jobs, 'url'), FETCH_TIMEOUT);
    foreach ($jobs as $host => $job) {
        $readyAt[$host] = microtime(true) + robots($host)['delay'];
        crawled($job, $pages[$job['url']], $host);
    }
}

/** File one fetched page: index it, and queue its counterpart and its links. */
function crawled(array $job, array $page, string $host): void
{
    global $db, $sites, $done, $strikes;
    ['url' => $url, 'site' => $name, 'depth' => $depth, 'lang' => $lang, 'org' => $org] = $job;
    $site = $sites[$name];

    $status = $page['status'];
    if ($status !== 200 || !str_contains($page['type'], 'html')) {
        set_state($url, 'failed');
        echo "FAILED   $status  $url\n";
        // A refusal or no answer at all, as opposed to a page that is simply gone.
        $refused = $status === 0 || $status === 403 || $status === 429 || $status >= 500;
        $strikes[$host] = $refused ? ($strikes[$host] ?? 0) + 1 : 0;
        if ($strikes[$host] >= MAX_STRIKES) {
            echo "HELD     $host refused " . MAX_STRIKES . " requests in a row; leaving it alone. Run again later to carry on.\n";
            hold_host($host);
        }
        return;
    }
    $strikes[$host] = 0;

    // A redirect: file the page under the address it really lives at.
    if ($page['url'] !== $url) {
        set_state($url, 'moved');
        $known = $db->prepare('SELECT state FROM queue WHERE url = :url');
        $known->bindValue(':url', $page['url']);
        $state = $known->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;
        $ok = $depth < 0 ? is_official_url($page['url']) : in_scope($page['url'], $site);
        if (!$ok || ($state !== null && $state !== 'todo') || !robots_allows($page['url'])) {
            return;
        }
        $url = $page['url'];
        enqueue($url, $name, $lang, $org, $depth);
    }

    $html = $page['body'];
    $hidden = (bool) preg_match('/<meta\b[^>]*name="robots"[^>]*content="[^"]*noindex/i', $html);
    try {
        $indexed = !$hidden && index_page($url, $lang, $org, $site['region'], $html);
    } catch (Throwable $e) {
        // One unreadable page should not end the crawl.
        set_state($url, 'failed');
        echo "FAILED   " . $e->getMessage() . "  $url\n";
        return;
    }
    set_state($url, $indexed ? 'done' : 'thin');
    printf("%-8s %-9s %s %2d  %s\n", $indexed ? 'ok' : 'thin', $name, $lang, $depth, $indexed ? page_title($html) : $url);

    if ($depth < 0) {
        return;
    }
    $done[$name] += (int) $indexed;
    $other = $lang === 'fr' ? 'en' : 'fr';
    $counterpart = alternate_url($html, $other, $url);
    if ($indexed && $counterpart !== '' && $counterpart !== $url && is_official_url($counterpart)) {
        enqueue($counterpart, $name, $other, $site['org'][$other], -1);
    }
    if ($depth < $site['max_depth']) {
        foreach (page_links($html, $url) as $link) {
            if (in_scope($link, $site)) {
                enqueue($link, $name, $lang, $org, $depth + 1);
            }
        }
    }
}

echo "\n";
$summary = $db->query('SELECT org, lang, COUNT(*) FROM pages GROUP BY org, lang ORDER BY 3 DESC');
while ($row = $summary->fetchArray(SQLITE3_NUM)) {
    printf("%5d  %s  %s\n", $row[2], $row[1], $row[0]);
}
echo $db->querySingle('SELECT COUNT(*) FROM pages') . ' pages, ' . $db->querySingle('SELECT COUNT(*) FROM passages') . " passages in data/index.sqlite\n";
