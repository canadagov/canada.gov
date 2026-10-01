<?php
declare(strict_types=1);

require_once __DIR__ . '/web.php';

const UNSPLASH_BASE_URL = 'https://api.unsplash.com';
const UNSPLASH_IMAGE_HOST = 'images.unsplash.com';
const UNSPLASH_REFERRAL = '?utm_source=canada_gov&utm_medium=referral';
const UNSPLASH_RETRY_SECONDS = 600;

/** One search per carousel slide, coast to coast to coast. */
const UNSPLASH_PLACES = [
    'Moraine Lake Banff',
    'Parliament Hill Ottawa',
    'Old Quebec City',
    'Peggys Cove Nova Scotia',
    'northern lights Canada',
    'Toronto skyline',
    'Saskatchewan prairie',
    'Vancouver Stanley Park',
    'Niagara Falls Canada',
    'Gros Morne Newfoundland',
];

/** Words in a photographer's profile location that place them in Canada. */
const CANADA_PLACE_NAMES = [
    'canada', 'british columbia', 'alberta', 'saskatchewan', 'manitoba', 'ontario', 'quebec', 'québec',
    'new brunswick', 'nova scotia', 'prince edward island', 'newfoundland', 'labrador', 'yukon',
    'northwest territories', 'nunavut',
    'toronto', 'montreal', 'montréal', 'vancouver', 'calgary', 'edmonton', 'ottawa', 'winnipeg',
    'saskatoon', 'whitehorse', 'yellowknife', 'iqaluit',
];

/**
 * One photo per slide, the same for every visitor all day. Once a day the
 * search results are fetched again and each slide moves on to the next photo
 * in its results; nothing else calls the Unsplash API. Only photographers
 * whose profile says they are based in Canada are used. A slide with no photo
 * gets null. With no UNSPLASH_ACCESS_KEY the result is empty and the slides
 * keep their gradients.
 *
 * @return list<?array{src: string, color: string, name: string, profile: string, page: string}>
 */
function unsplash_photos(int $slides): array
{
    $key = env('UNSPLASH_ACCESS_KEY');
    if ($key === '') {
        return [];
    }
    $base = rtrim(env('UNSPLASH_BASE_URL', UNSPLASH_BASE_URL), '/');
    $file = sys_get_temp_dir() . '/canada-gov-unsplash-v2-' . md5($key . $base) . '.json';
    $cache = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    $today = new DateTimeImmutable('now', new DateTimeZone('America/Toronto'));
    $day = (int) $today->format('Y') * 366 + (int) $today->format('z');
    $isToday = ($cache['day'] ?? null) === $day;
    // A day whose fetch partly failed is tried again after a short wait.
    if ($isToday && ($cache['retry_at'] === null || $cache['retry_at'] > time())) {
        return array_slice($cache['photos'], 0, $slides);
    }

    $headers = ['Authorization: Client-ID ' . $key, 'Accept-Version: v1'];
    $pools = $cache['pools'] ?? array_fill(0, count(UNSPLASH_PLACES), []);
    $urls = [];
    foreach (UNSPLASH_PLACES as $place) {
        $urls[] = $base . '/search/photos?' . http_build_query([
            'query' => $place, 'orientation' => 'landscape', 'content_filter' => 'high', 'per_page' => 30,
        ]);
    }
    $responses = http_get_many($urls, 4, $headers);
    $failed = false;
    foreach ($urls as $i => $url) {
        $results = json_decode($responses[$url]['body'], true)['results'] ?? null;
        if ($responses[$url]['status'] !== 200 || !is_array($results)) {
            // Keep the last results for this place.
            $failed = true;
            continue;
        }
        $pools[$i] = array_values(array_filter(array_map(fn (array $result) => unsplash_photo($result, $base), $results)));
    }

    $photos = [];
    foreach ($pools as $pool) {
        $photos[] = $pool ? $pool[$day % count($pool)] : null;
    }

    // Unsplash asks to be told when a photo is put to use. Once per photo per day.
    $reported = $isToday ? $cache['reported'] : [];
    $toReport = [];
    foreach (array_filter($photos) as $photo) {
        if (!in_array($photo['id'], $reported, true)) {
            $toReport[$photo['id']] = $photo['download'];
        }
    }
    if ($toReport) {
        $pings = http_get_many(array_values($toReport), 4, $headers);
        foreach ($toReport as $id => $url) {
            if ($pings[$url]['status'] === 200) {
                $reported[] = $id;
            }
        }
    }

    file_put_contents($file, json_encode([
        'day' => $day,
        'retry_at' => $failed ? time() + UNSPLASH_RETRY_SECONDS : null,
        'pools' => $pools,
        'photos' => $photos,
        'reported' => $reported,
    ]), LOCK_EX);
    return array_slice($photos, 0, $slides);
}

/**
 * Whether the location a photographer wrote on their profile is in Canada.
 * When in doubt the answer is no: an empty location, or a name shared with a
 * place abroad (Vancouver, Washington; Ontario, California), does not count.
 */
function unsplash_in_canada(?string $location): bool
{
    $location = trim((string) $location);
    $lower = mb_strtolower($location);
    if (str_contains($lower, 'canada')) {
        return true;
    }
    if (preg_match('/\b(california|washington|wa|usa|united states)\b/', $lower)) {
        return false;
    }
    foreach (CANADA_PLACE_NAMES as $name) {
        if (preg_match('/(?<!\pL)' . $name . '(?!\pL)/u', $lower)) {
            return true;
        }
    }
    // "Moose Jaw, SK". Codes that also mean somewhere else (NL, NT, CA) are left out.
    return (bool) preg_match('/[,\s](BC|AB|SK|MB|ON|QC|NB|PEI)\.?$/', $location);
}

/**
 * The fields the page needs from one search result, or null if it is not a
 * plain Unsplash photo by a photographer based in Canada.
 */
function unsplash_photo(array $result, string $base): ?array
{
    $id = $result['id'] ?? '';
    $raw = $result['urls']['raw'] ?? '';
    $page = $result['links']['html'] ?? '';
    $download = $result['links']['download_location'] ?? '';
    $profile = $result['user']['links']['html'] ?? '';
    $name = trim($result['user']['name'] ?? '');
    if (!is_string($id) || $id === '' || $name === ''
        || !unsplash_in_canada($result['user']['location'] ?? null)
        || parse_url($raw, PHP_URL_HOST) !== UNSPLASH_IMAGE_HOST
        || !str_starts_with($page, 'https://unsplash.com/photos/')
        || !str_starts_with($profile, 'https://unsplash.com/@')
        || !str_starts_with($download, $base . '/photos/')) {
        return null;
    }
    $color = $result['color'] ?? '';
    return [
        'id' => $id,
        // Twice the 688x458 card, for sharp display on dense screens. The API
        // terms require loading the image from Unsplash's own address.
        'src' => $raw . (str_contains($raw, '?') ? '&' : '?') . 'w=1376&h=916&fit=crop&q=75&auto=format',
        'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#26374a',
        'name' => $name,
        // Kept in the cache to show why the photo was accepted; the page does not use it.
        'based' => trim($result['user']['location']),
        'profile' => $profile . UNSPLASH_REFERRAL,
        'page' => $page . UNSPLASH_REFERRAL,
        'download' => $download,
    ];
}
