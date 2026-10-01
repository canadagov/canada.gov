<?php
declare(strict_types=1);

/**
 * POST /api/chat.php  {"lang": "en"|"fr", "messages": [{"role", "content"}, ...]}
 *
 * Answers as a server-sent event stream:
 *   event: sources    data: [{"title", "url", "org"}, ...]
 *   event: delta      data: {"text": "..."}
 *   event: followups  data: ["question", ...]
 *   event: poem       data: {"photo": {"src", "alt", "credit", "width", "height"} or null}
 *   event: error      data: {"message": "..."}
 *   event: done       data: {}
 *
 * Nothing about the conversation is written to disk or logged.
 */

require_once __DIR__ . '/../../src/search.php';
require_once __DIR__ . '/../../src/hopscotch.php';
require_once __DIR__ . '/../../src/leafs.php';

const MAX_MESSAGES = 20;
const MAX_MESSAGE_LENGTH = 4000;
const FALLBACK_SOURCE_URLS = [
    'https://www.canada.ca/en/government/dept.html',
    'https://www.canada.ca/en/contact.html',
];
const REGIONS = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'];

function send_event(string $event, array $data): void
{
    echo "event: $event\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    flush();
}

/** The first JSON value of the given kind ('{' or '[') in a model's reply, or null. */
function json_in(string $reply, string $open): ?array
{
    $start = strpos($reply, $open);
    $end = strrpos($reply, $open === '{' ? '}' : ']');
    $value = $start !== false && $end !== false ? json_decode(substr($reply, $start, $end - $start + 1), true) : null;
    return is_array($value) ? $value : null;
}

function transcript(array $messages): string
{
    $lines = array_map(
        fn(array $m) => ($m['role'] === 'user' ? 'Person: ' : 'Assistant: ') . mb_substr($m['content'], 0, 600),
        array_slice($messages, -5)
    );
    return implode("\n", $lines);
}

/**
 * What to search for: ['lang', 'query', 'region']. The fast model words the
 * search, so that "and for a child?" or "I lost my job" find the right pages.
 * If it cannot, the person's own words are used.
 */
function plan_search(array $messages, string $lang): array
{
    $userTurns = array_column(array_filter($messages, fn(array $m) => $m['role'] === 'user'), 'content');
    $plan = ['lang' => $lang, 'query' => implode(' ', array_slice($userTurns, -2)), 'region' => null];
    try {
        $reply = json_in(hopscotch_complete([
            ['role' => 'system', 'content' => <<<PROMPT
                You prepare searches over Canadian government web pages. Read the conversation and reply with one JSON object and nothing else:
                {"lang": "en" or "fr", the language of the person's last message,
                 "query": "5 to 12 search keywords in that language for what the person needs now: the topic, the official names of the programs, documents or agencies involved, and common synonyms",
                 "region": the two-letter code of the province or territory the person has named or clearly lives in (ON, QC, BC, AB and so on), or null}
                PROMPT],
            ['role' => 'user', 'content' => transcript($messages)],
        ]), '{');
    } catch (HopscotchError) {
        return $plan;
    }
    if (in_array($reply['lang'] ?? '', ['en', 'fr'], true)) {
        $plan['lang'] = $reply['lang'];
    }
    if (is_string($reply['query'] ?? null) && trim($reply['query']) !== '') {
        // The person's own words still count, after the model's.
        $plan['query'] = $reply['query'] . ' ' . end($userTurns);
    }
    if (in_array($reply['region'] ?? '', REGIONS, true)) {
        $plan['region'] = $reply['region'];
    }
    return $plan;
}

/** The official pages to answer from, each as ['title', 'url', 'org', 'text']. */
function retrieve(array $plan): array
{
    $lang = $plan['lang'];
    if (($pages = search_index_fresh($plan['query'], $lang, $plan['region']))) {
        return $pages;
    }
    // No index yet, or nothing in it: the hand-picked catalogue.
    $sources = search_sources($plan['query']) ?: array_values(array_filter(
        load_sources(),
        fn(array $s) => in_array($s['url_en'], FALLBACK_SOURCE_URLS, true)
    ));
    return array_map(fn(array $s) => [
        'title' => $s["title_$lang"],
        'url' => $s["url_$lang"],
        'org' => $s['org'],
        'text' => $s['text'],
    ], with_page_text($sources, $lang));
}

function system_prompt(string $lang, ?string $region, array $sources): string
{
    $language = $lang === 'fr' ? 'French' : 'English';
    $where = $region ? "The person is in $region." : 'The person has not said which province or territory they are in.';
    $documents = '';
    foreach ($sources as $n => $source) {
        $documents .= sprintf(
            "\n[%d] %s (%s)\nURL: %s\n%s\n",
            $n + 1,
            $source['title'],
            $source['org'],
            $source['url'],
            $source['text'] !== '' ? $source['text'] : '(Page text unavailable. Refer the person to this page.)'
        );
    }

    return <<<PROMPT
    You are the assistant on Canada.gov, an independent demonstration site that helps people find and understand government services in Canada. You are not the Government of Canada and you must never claim to be.

    Rules:
    - Answer only questions about government services, programs and obligations in Canada (federal, provincial, territorial or municipal). For anything else, say briefly that you can only help with government services.
    - Base every answer only on the official sources below. If they do not contain the answer, say so plainly and point to the most relevant official page from the list. Never guess amounts, dates, deadlines, fees, phone numbers, processing times or eligibility rules: if a detail is not in the sources, leave it out.
    - Cite as you go: end each step or fact with a Markdown link to the source it came from, using a short name of at most four words for the page or organization as the link text, like [Service Canada](URL), not the page's full title.
    - Link only to URLs that appear after "URL:" in the official sources below, copied exactly. Never invent or alter a URL. Links to any other address are removed before the person sees them.
    - Reply in $language.
    - Use plain language. Lead with the direct answer, then give the steps as a short numbered list when there are steps. No headings. Keep it under 200 words.
    - Say which level of government is responsible when it is not the federal government.
    - $where When the answer depends on the province or territory and you do not know it, give the part that applies everywhere, then ask which province or territory they are in. Do not ask for anything more precise than that.
    - Never ask for personal information such as a Social Insurance Number, date of birth, address or health details, and do not repeat any that the person shares.
    - You do not give legal, medical or financial advice. For an emergency, tell the person to call 911. For a mental health crisis, tell them to call or text 9-8-8.

    Official sources:
    $documents
    PROMPT;
}

/** Up to two questions the person might ask next, or none if the model has nothing usable. */
function suggest_followups(array $messages, string $answer, string $lang, array $sources): array
{
    $language = $lang === 'fr' ? 'French' : 'English';
    $titles = implode("\n", array_map(fn(array $s) => '- ' . $s['title'], $sources));
    try {
        $reply = json_in(hopscotch_complete([
            ['role' => 'system', 'content' => <<<PROMPT
                Suggest two short follow-up questions the person might ask next about government services in Canada, written in $language as the person would ask them. They must follow from the conversation and be about the topics of these official pages:
                $titles
                If the assistant asked the person a question, do not answer it for them. Reply with a JSON array of two strings and nothing else.
                PROMPT],
            ['role' => 'user', 'content' => transcript(array_merge($messages, [['role' => 'assistant', 'content' => $answer]]))],
        ], 120), '[');
    } catch (HopscotchError) {
        return [];
    }
    $questions = array_filter($reply ?? [], fn($q) => is_string($q) && trim($q) !== '' && mb_strlen($q) <= 120);
    return array_slice(array_values($questions), 0, 2);
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
$lang = ($input['lang'] ?? 'en') === 'fr' ? 'fr' : 'en';
$messages = [];
foreach (array_slice((array) ($input['messages'] ?? []), -MAX_MESSAGES) as $message) {
    $role = $message['role'] ?? '';
    $content = trim((string) ($message['content'] ?? ''));
    if (in_array($role, ['user', 'assistant'], true) && $content !== '') {
        $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, MAX_MESSAGE_LENGTH)];
    }
}
if (!$messages || end($messages)['role'] !== 'user') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'The last message must be from the user.']);
    exit;
}

header('Content-Type: text/event-stream');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) {
    ob_end_flush();
}

// The easter egg: a poem, a line at a time, with a photo to end on.
if (is_leafs_cup_question(end($messages)['content'])) {
    send_event('poem', ['photo' => leafs_photo($lang)]);
    foreach (explode("\n", LEAFS_POEM[$lang]) as $line) {
        send_event('delta', ['text' => $line . "\n"]);
        usleep(70_000);
    }
    send_event('done', []);
    exit;
}

$plan = plan_search($messages, $lang);
$sources = retrieve($plan);

send_event('sources', array_map(fn(array $s) => [
    'title' => $s['title'],
    'url' => $s['url'],
    'org' => $s['org'],
], $sources));

$answer = '';
try {
    hopscotch_stream_chat(
        array_merge([['role' => 'system', 'content' => system_prompt($plan['lang'], $plan['region'], $sources)]], $messages),
        function (string $text) use (&$answer): void {
            $answer .= $text;
            send_event('delta', ['text' => $text]);
        }
    );
    // An answer that cites nothing is a refusal or a question back; there is nothing to follow up on.
    $cited = array_filter($sources, fn(array $s) => str_contains($answer, $s['url']));
    if ($cited && ($followups = suggest_followups($messages, $answer, $plan['lang'], $cited))) {
        send_event('followups', $followups);
    }
} catch (HopscotchError $e) {
    send_event('error', ['message' => $e->getMessage()]);
}
send_event('done', []);
