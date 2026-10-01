<?php
declare(strict_types=1);

/**
 * Fetching official pages and reading their HTML: the main text, the title,
 * the links, and the same page in the other official language.
 */

require_once __DIR__ . '/config.php';

const FETCH_USER_AGENT = 'Mozilla/5.0 (compatible; canada.gov-demo/1.0)';
/** How long the scripts in bin/ wait for a page. Requests from the site itself wait less. */
const FETCH_TIMEOUT = 20;

/**
 * Remove whole elements, tags and contents. Done by scanning rather than one
 * regular expression, which gives up on very large pages.
 */
function strip_elements(string $html, array $tags): string
{
    foreach ($tags as $tag) {
        $kept = '';
        $pos = 0;
        while (preg_match('/<' . $tag . '\b/i', $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1];
            $kept .= substr($html, $pos, $start - $pos) . ' ';
            $end = stripos($html, "</$tag>", $start);
            $pos = $end === false ? strlen($html) : $end + strlen($tag) + 3;
        }
        $html = $kept . substr($html, $pos);
    }
    return $html;
}

/** What lies between the first <tag ...> and the last </tag>, or null. */
function element_contents(string $html, string $tag): ?string
{
    if (!preg_match('/<' . $tag . '\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $end = strripos($html, "</$tag>");
    return $end === false || $end < $start ? null : substr($html, $start, $end - $start);
}

/** The part of a page that holds its content, without site navigation. */
function main_html(string $html): string
{
    $main = element_contents($html, 'main');
    if ($main !== null) {
        return $main;
    }
    // Pages without <main>: drop the site header and footer instead.
    return strip_elements(element_contents($html, 'body') ?? $html, ['header', 'footer']);
}

/** Readable text of a page's main content. Headings become "## " lines. */
function extract_main_text(string $html): string
{
    $html = strip_elements(main_html($html), ['script', 'style', 'nav', 'form', 'svg', 'noscript']);
    $html = preg_replace('/<h[1-6]\b[^>]*>\s*/i', "\n## ", $html);
    $html = preg_replace('/<\/(p|li|h[1-6]|tr|div|section)>/i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00a0}]+/u', ' ', $text);
    $text = preg_replace('/\s*\n\s*/', "\n", $text);
    return trim($text);
}

function page_title(string $html): string
{
    if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $m) || preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $m)) {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
    return '';
}

/** The same page in the other official language, as the page itself declares it. */
function alternate_url(string $html, string $lang, string $base): string
{
    if (preg_match('/<link\b[^>]*\bhreflang="' . $lang . '[^"]*"[^>]*\bhref="([^"]+)"/i', $html, $m)
        || preg_match('/<a\b[^>]*\blang="' . $lang . '"[^>]*\bhref="([^"]+)"/i', $html, $m)
        || preg_match('/<a\b[^>]*\bhref="([^"]+)"[^>]*\blang="' . $lang . '"/i', $html, $m)) {
        return absolute_url(html_entity_decode($m[1]), $base);
    }
    return '';
}

/** An https URL without its fragment, or '' when the link is not a web page address. */
function absolute_url(string $href, string $base): string
{
    $href = trim(explode('#', $href, 2)[0]);
    if ($href === '' || preg_match('/^(mailto|tel|javascript|data):/i', $href)) {
        return '';
    }
    $origin = 'https://' . parse_url($base, PHP_URL_HOST);
    if (str_starts_with($href, '//')) {
        $href = 'https:' . $href;
    } elseif (str_starts_with($href, '/')) {
        $href = $origin . $href;
    } elseif (!preg_match('/^https?:\/\//i', $href)) {
        $path = parse_url($base, PHP_URL_PATH) ?: '/';
        $href = $origin . substr($path, 0, strrpos($path, '/') + 1) . $href;
    }
    return preg_replace('/^http:/i', 'https:', $href);
}

/**
 * Fetch several URLs at once. Returns [url => ['status' => int, 'url' => string
 * (after redirects), 'type' => string, 'body' => string]].
 */
function http_get_many(array $urls, int $timeout = 5, array $headers = []): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => FETCH_USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$url] = $ch;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $url => $ch) {
        $results[$url] = [
            'status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'url' => explode('#', (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), 2)[0],
            'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'body' => (string) curl_multi_getcontent($ch),
        ];
        curl_multi_remove_handle($multi, $ch);
    }
    curl_multi_close($multi);
    return $results;
}
