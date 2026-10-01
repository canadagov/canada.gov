<?php
declare(strict_types=1);

/**
 * The catalogue: a hand-picked list of official pages (data/sources.json),
 * searched by keyword. It answers questions when the search index has
 * nothing, and its words are the ones every search is cut into.
 */

require_once __DIR__ . '/web.php';

const SOURCES_FILE = ROOT . '/data/sources.json';
const PAGE_CACHE_TTL = 86400;
const PAGE_TEXT_LIMIT = 5000;

const STOPWORDS = [
    'the', 'and', 'for', 'how', 'can', 'what', 'when', 'where', 'who', 'which', 'you', 'your', 'get',
    'with', 'from', 'that', 'this', 'have', 'need', 'want', 'just', 'got', 'does', 'new', 'best', 'help',
    'les', 'des', 'une', 'pour', 'que', 'qui', 'comment', 'puis', 'mon', 'mes', 'est', 'dans', 'avec',
    'sur', 'aux', 'quand', 'quel', 'quelle', 'dois', 'faire', 'obtenir', 'viens', 'suis',
];
/** Two-letter words worth searching for all the same. */
const SHORT_TERMS = ['ei', 'pr', 'ae', 'rp', 'bn', 'ne', 'sv'];

/** Lowercased, accent-free words of three letters or more, and the SHORT_TERMS. */
function tokenize(string $text): array
{
    $text = mb_strtolower($text);
    $text = strtr($text, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'œ' => 'oe',
        '’' => ' ', "'" => ' ',
    ]);
    $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_filter(
        $words,
        fn(string $w) => (strlen($w) >= 3 || in_array($w, SHORT_TERMS, true))
            && !in_array($w, STOPWORDS, true)
    )));
}

function words_match(string $a, string $b): bool
{
    if ($a === $b) {
        return true;
    }
    // Cheap stemming: "passports" ~ "passport", "renewing" ~ "renew".
    $min = min(strlen($a), strlen($b));
    return $min >= 4 && (str_starts_with($a, $b) || str_starts_with($b, $a));
}

function load_sources(): array
{
    static $sources = null;
    if ($sources === null) {
        $sources = is_file(SOURCES_FILE) ? json_decode(file_get_contents(SOURCES_FILE), true) : [];
    }
    return $sources;
}

/** The official pages most relevant to a question, best first. */
function search_sources(string $query, int $limit = 4): array
{
    $queryWords = tokenize($query);
    $scored = [];
    foreach (load_sources() as $i => $source) {
        $keywords = tokenize($source['keywords']);
        $titleWords = tokenize($source['title_en'] . ' ' . $source['title_fr']);
        $score = 0;
        foreach ($queryWords as $word) {
            foreach ($keywords as $keyword) {
                if (words_match($word, $keyword)) {
                    $score += 3;
                    break;
                }
            }
            foreach ($titleWords as $titleWord) {
                if (words_match($word, $titleWord)) {
                    $score += 2;
                    break;
                }
            }
        }
        if ($score > 0) {
            $scored[] = [$score, -$i, $source];
        }
    }
    rsort($scored);
    return array_map(fn(array $row) => $row[2], array_slice($scored, 0, $limit));
}

/**
 * Attach the live text of each source's page, read from the official site
 * (and cached for a day, since these are public pages rather than user data).
 */
function with_page_text(array $sources, string $lang): array
{
    $cacheDir = sys_get_temp_dir() . '/canada-gov-pages';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0700, true);
    }

    $cacheFile = fn(string $url): string => $cacheDir . '/' . md5($url) . '.txt';
    $toFetch = [];
    foreach ($sources as $i => $source) {
        if (!empty($source["text_$lang"])) {
            $sources[$i]['text'] = $source["text_$lang"];
            continue;
        }
        $url = $source["url_$lang"];
        $file = $cacheFile($url);
        if (is_file($file) && time() - filemtime($file) < PAGE_CACHE_TTL) {
            $sources[$i]['text'] = file_get_contents($file);
        } else {
            $toFetch[$i] = $url;
        }
    }

    if ($toFetch) {
        $responses = http_get_many(array_values($toFetch));
        foreach ($toFetch as $i => $url) {
            $response = $responses[$url];
            $text = $response['status'] === 200
                ? mb_substr(extract_main_text($response['body']), 0, PAGE_TEXT_LIMIT)
                : '';
            $sources[$i]['text'] = $text;
            if ($text !== '') {
                @file_put_contents($cacheFile($url), $text);
            }
        }
    }
    return $sources;
}
