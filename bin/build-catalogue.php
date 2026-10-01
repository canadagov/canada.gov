#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Builds data/sources.json from data/seeds.json.
 *
 * Every seed URL is fetched from the official site. For Canada.ca pages the
 * title and the French counterpart are read from the page itself, so the
 * catalogue only ever contains pages that exist. Seeds that fail are dropped
 * and reported.
 */

require_once __DIR__ . '/../src/catalogue.php';

$seeds = json_decode(file_get_contents(ROOT . '/data/seeds.json'), true);
if (!is_array($seeds)) {
    fwrite(STDERR, "data/seeds.json is not valid JSON\n");
    exit(1);
}

$english = http_get_many(array_column($seeds, 'url_en'), FETCH_TIMEOUT);

// Canada.ca seeds name only the English page; find the French one on it.
foreach ($seeds as $i => $seed) {
    $page = $english[$seed['url_en']];
    if ($page['status'] === 200 && empty($seed['url_fr'])) {
        $seeds[$i]['url_fr'] = alternate_url($page['body'], 'fr', $seed['url_en']);
        $seeds[$i]['title_en'] ??= page_title($page['body']);
    }
}
$french = http_get_many(array_filter(array_column($seeds, 'url_fr')), FETCH_TIMEOUT);

$sources = [];
foreach ($seeds as $seed) {
    $en = $english[$seed['url_en']];
    $fr = $french[$seed['url_fr'] ?? ''] ?? ['status' => 0, 'body' => ''];
    if ($en['status'] !== 200 || empty($seed['title_en'])) {
        echo "DROPPED  {$en['status']}  {$seed['url_en']}\n";
        continue;
    }
    if ($fr['status'] !== 200) {
        echo "NO FRENCH {$fr['status']}  {$seed['url_en']}\n";
        $seed['url_fr'] = $seed['url_en'];
        $seed['title_fr'] ??= $seed['title_en'];
    }
    $seed['title_fr'] ??= page_title($fr['body']) ?: $seed['title_en'];
    $sources[] = $seed;
    echo "ok       {$seed['title_en']}  |  {$seed['title_fr']}\n";
}

file_put_contents(
    SOURCES_FILE,
    json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
echo count($sources) . ' of ' . count($seeds) . " sources written to data/sources.json\n";
