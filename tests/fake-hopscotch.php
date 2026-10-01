<?php
declare(strict_types=1);

/**
 * A stand-in for the Hopscotch API, for working on the site without a key
 * or credit. It answers in the same OpenAI-compatible shapes.
 *
 *   php -S localhost:8787 tests/fake-hopscotch.php
 *   HOPSCOTCH_BASE_URL=http://localhost:8787 HOPSCOTCH_API_KEY=test php -S localhost:8000 -t public
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/models') {
    header('Content-Type: application/json');
    echo json_encode(['object' => 'list', 'data' => [
        ['id' => 'cohere/command-a-03-2025', 'object' => 'model', 'owned_by' => 'cohere'],
        ['id' => 'openai/gpt-4o-mini', 'object' => 'model', 'owned_by' => 'openai'],
    ]]);
    return;
}

$request = json_decode(file_get_contents('php://input'), true);
$question = end($request['messages'])['content'] ?? '';

if (str_contains($question, 'fail')) {
    http_response_code(402);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'Your balance is too low to serve this request.', 'type' => 'insufficient_credit']]);
    return;
}

// The short jobs around an answer are not streamed: wording the search, then suggesting follow-ups.
if (empty($request['stream'])) {
    $reply = str_contains($request['messages'][0]['content'], 'follow-up')
        ? ['What documents do I need?', 'How long does it take?']
        : ['lang' => 'en', 'query' => preg_replace('/^.*Person: /s', '', $question), 'region' => null];
    header('Content-Type: application/json');
    echo json_encode(['object' => 'chat.completion', 'model' => $request['model'],
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode($reply)], 'finish_reason' => 'stop']]]);
    return;
}

// Echo back the first source the site retrieved, so the whole path is visible.
preg_match('/\[1\] (.*)\nURL: (\S+)/', $request['messages'][0]['content'], $source);
$answer = "This is a **stand-in answer** from the local test server, not from a model.\n\n"
    . "You asked: *$question*\n\n"
    . "1. Start at [" . ($source[1] ?? 'Canada.ca') . "](" . ($source[2] ?? 'https://www.canada.ca/') . ").\n"
    . "2. This link is not official and must not be clickable: [example](https://example.com/).\n";

header('Content-Type: text/event-stream');
while (ob_get_level() > 0) {
    ob_end_flush();
}
foreach (mb_str_split($answer, 7) as $piece) {
    echo 'data: ' . json_encode(['object' => 'chat.completion.chunk', 'model' => $request['model'],
        'choices' => [['index' => 0, 'delta' => ['content' => $piece], 'finish_reason' => null]]]) . "\n\n";
    flush();
    usleep(30000);
}
echo ':x-hopscotch-usage {"v":1,"reported":true,"prompt_tokens":1,"completion_tokens":1,"total_tokens":2}' . "\n\n";
echo "data: [DONE]\n\n";
