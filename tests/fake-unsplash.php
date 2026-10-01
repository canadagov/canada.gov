<?php
declare(strict_types=1);

/**
 * A stand-in for the Unsplash API, for working on the carousel without a key.
 * Every search returns the same five real, free-to-use Unsplash photos. Only
 * the first photographer has a location, so only that photo should appear.
 *
 *   php -S localhost:8791 tests/fake-unsplash.php
 *   UNSPLASH_BASE_URL=http://localhost:8791 UNSPLASH_ACCESS_KEY=test php -S localhost:8000 -t public
 */

$photos = [
    ['photo-1506104489822-562ca25152fe', '#73a6a6', 'Redd Francisco', 'reddfrancisco'],
    ['photo-1635316233469-17bb8877db22', '#a68c40', 'Ric Matkowski', 'matkowrs', 'Calgary, Alberta, Canada'],
    ['photo-1623039925698-1f96229b1a51', '#d9d9d9', 'Cole Keister', 'coleito'],
    ['photo-1613059488547-0fc691db5231', '#c0d9f3', 'Benoit Debaix', 'benoit1974'],
    ['photo-1493810329807-db131c118da5', '#262626', 'Ken Cheung', 'kencheungphoto'],
];

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Client-ID test') {
    http_response_code(401);
    echo json_encode(['errors' => ['OAuth error: The access token is invalid']]);
    return;
}
if (preg_match('#^/photos/[\w-]+/download$#', $path)) {
    error_log('use reported: ' . $path);
    echo json_encode(['url' => 'https://images.unsplash.com/']);
    return;
}
if ($path !== '/search/photos') {
    http_response_code(404);
    echo json_encode(['errors' => ['Not found']]);
    return;
}

// Start each search at a different photo so neighbouring slides differ.
$offset = strlen($_GET['query'] ?? '') % count($photos);
$results = [];
foreach (array_merge(array_slice($photos, $offset), array_slice($photos, 0, $offset)) as $photo) {
    [$id, $color, $name, $username] = $photo;
    $results[] = [
        'id' => $id,
        'color' => $color,
        'urls' => ['raw' => "https://images.unsplash.com/$id?ixlib=rb-4.1.0"],
        'links' => [
            'html' => "https://unsplash.com/photos/$id",
            'download_location' => "http://{$_SERVER['HTTP_HOST']}/photos/$id/download",
        ],
        'user' => ['name' => $name, 'location' => $photo[4] ?? null, 'links' => ['html' => "https://unsplash.com/@$username"]],
    ];
}
echo json_encode(['total' => count($results), 'total_pages' => 1, 'results' => $results]);
