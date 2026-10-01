<?php
declare(strict_types=1);

/**
 * The search index: official pages crawled by bin/crawl.php, cut into
 * passages and held in SQLite full-text search (data/index.sqlite).
 */

require_once __DIR__ . '/catalogue.php';

const INDEX_FILE = ROOT . '/data/index.sqlite';
const PASSAGE_LENGTH = 1200;
const PASSAGE_MIN_LENGTH = 400;
const PASSAGES_PER_PAGE = 3;
/** Pages older than this are read again from the official site before they are used in an answer. */
const INDEX_MAX_AGE = 7 * 86400;

function index_db(): SQLite3
{
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(INDEX_FILE);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pages (
                id INTEGER PRIMARY KEY,
                url TEXT NOT NULL UNIQUE,
                lang TEXT NOT NULL,
                org TEXT NOT NULL,
                region TEXT NOT NULL,
                title TEXT NOT NULL,
                fetched_at INTEGER NOT NULL
            );
            CREATE VIRTUAL TABLE IF NOT EXISTS passages USING fts5(
                title, heading, body, page_id UNINDEXED,
                tokenize = 'porter unicode61 remove_diacritics 2'
            );
            CREATE TABLE IF NOT EXISTS queue (
                url TEXT PRIMARY KEY,
                site TEXT NOT NULL,
                lang TEXT NOT NULL,
                org TEXT NOT NULL,
                depth INTEGER NOT NULL,
                state TEXT NOT NULL DEFAULT 'todo'
            );
            CREATE INDEX IF NOT EXISTS queue_next ON queue (site, state, depth);
        SQL);
    }
    return $db;
}

/** Cut page text into passages of about PASSAGE_LENGTH, each under the heading it sits below. */
function split_passages(string $text): array
{
    $passages = [];
    $section = '';
    $heading = '';
    $body = '';
    $close = function () use (&$passages, &$section, &$heading, &$body): void {
        if (trim($body) !== '') {
            $passages[] = ['heading' => $heading, 'body' => trim($body)];
        }
        $heading = $section;
        $body = '';
    };
    foreach (explode("\n", $text) as $line) {
        if (str_starts_with($line, '## ')) {
            $section = substr($line, 3);
            // Short sections, such as the entries of a menu page, stay together.
            if (mb_strlen($body) >= PASSAGE_MIN_LENGTH) {
                $close();
            }
            if ($body === '') {
                $heading = $section;
                continue;
            }
            $line = $section;
        }
        if (mb_strlen($body) + mb_strlen($line) > PASSAGE_LENGTH && $body !== '') {
            $close();
        }
        // A single line can be longer than a passage on pages with little markup.
        foreach (mb_str_split($line, PASSAGE_LENGTH) as $piece) {
            $body .= $piece . "\n";
            if (mb_strlen($piece) === PASSAGE_LENGTH) {
                $close();
            }
        }
    }
    $close();
    return $passages;
}

/**
 * Put a page in the index, replacing what was there for its URL.
 * Returns false when the page has too little text to be worth keeping.
 */
function index_page(string $url, string $lang, string $org, string $region, string $html): bool
{
    $text = extract_main_text($html);
    $title = page_title($html);
    if ($title === '' || mb_strlen($text) < 200) {
        return false;
    }

    $db = index_db();
    $db->exec('BEGIN');
    try {
        $find = $db->prepare('SELECT id FROM pages WHERE url = :url');
        $find->bindValue(':url', $url);
        $id = $find->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;
        if ($id !== null) {
            $db->exec('DELETE FROM passages WHERE page_id = ' . (int) $id);
        }
        $save = $db->prepare(
            'INSERT INTO pages (id, url, lang, org, region, title, fetched_at) VALUES (:id, :url, :lang, :org, :region, :title, :at)
             ON CONFLICT (id) DO UPDATE SET title = excluded.title, fetched_at = excluded.fetched_at'
        );
        $save->bindValue(':id', $id);
        $save->bindValue(':url', $url);
        $save->bindValue(':lang', $lang);
        $save->bindValue(':org', $org);
        $save->bindValue(':region', $region);
        $save->bindValue(':title', $title);
        $save->bindValue(':at', time());
        $save->execute();
        $id ??= $db->lastInsertRowID();

        $add = $db->prepare('INSERT INTO passages (title, heading, body, page_id) VALUES (:title, :heading, :body, :id)');
        foreach (split_passages($text) as $passage) {
            $add->bindValue(':title', $title);
            $add->bindValue(':heading', $passage['heading']);
            $add->bindValue(':body', $passage['body']);
            $add->bindValue(':id', $id);
            $add->execute();
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        // Leave the shared connection usable for the next page.
        $db->exec('ROLLBACK');
        throw $e;
    }
    return true;
}

/**
 * The indexed pages that best match a query, best first, each with its
 * best-matching passages as 'text'. $region is a province or territory code;
 * with one, other provinces' pages are left out.
 */
function search_index(string $query, string $lang, ?string $region = null, int $limit = 5): array
{
    $words = tokenize($query);
    // Without the check, opening the database would create an empty one.
    if (!$words || !is_file(INDEX_FILE)) {
        return [];
    }
    $find = index_db()->prepare(
        'SELECT p.id, p.url, p.title, p.org, p.region, p.lang, p.fetched_at, passages.rowid AS passage, passages.heading,
                passages.body, bm25(passages, 6.0, 3.0, 1.0) AS score
         FROM passages JOIN pages p ON p.id = passages.page_id
         WHERE passages MATCH :match AND p.lang = :lang AND (:region IS NULL OR p.region IN (\'CA\', :region))
         ORDER BY score LIMIT 60'
    );
    $find->bindValue(':match', implode(' OR ', array_map(fn(string $w) => '"' . $w . '"', $words)));
    $find->bindValue(':lang', $lang);
    $find->bindValue(':region', $region);
    $rows = $find->execute();

    $pages = [];
    while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
        $page = &$pages[$row['id']];
        $page ??= $row + ['passages' => []];
        if (count($page['passages']) < PASSAGES_PER_PAGE) {
            $page['passages'][$row['passage']] = ($row['heading'] !== '' ? $row['heading'] . "\n" : '') . $row['body'];
        }
        unset($page);
    }
    $pages = array_slice(array_values($pages), 0, $limit);
    foreach ($pages as &$page) {
        // Passages go to the model in the order they appear on the page.
        ksort($page['passages']);
        $page['text'] = implode("\n…\n", $page['passages']);
    }
    return $pages;
}

/**
 * search_index(), after reading again any matching page that was last
 * fetched more than INDEX_MAX_AGE ago. A page that cannot be read keeps its
 * indexed text.
 */
function search_index_fresh(string $query, string $lang, ?string $region = null): array
{
    $pages = search_index($query, $lang, $region);
    $stale = array_filter($pages, fn(array $p) => time() - $p['fetched_at'] > INDEX_MAX_AGE);
    if (!$stale) {
        return $pages;
    }
    $responses = http_get_many(array_column($stale, 'url'));
    foreach ($stale as $page) {
        $response = $responses[$page['url']];
        if ($response['status'] !== 200) {
            continue;
        }
        try {
            index_page($page['url'], $page['lang'], $page['org'], $page['region'], $response['body']);
        } catch (Throwable) {
            // The index is busy or the page unreadable: answer from the text already held.
        }
    }
    return search_index($query, $lang, $region);
}
