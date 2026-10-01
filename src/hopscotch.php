<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const DEFAULT_BASE_URL = 'https://api.hopscotchlabs.ai/v1';
const DEFAULT_MODEL = 'cohere/command-a-03-2025';
/** A smaller model for the short jobs around an answer: wording the search and suggesting follow-ups. */
const DEFAULT_FAST_MODEL = 'cohere/command-r7b-12-2024';

class HopscotchError extends RuntimeException
{
}

function hopscotch_model(bool $fast = false): string
{
    $setting = $fast ? 'HOPSCOTCH_FAST_MODEL' : 'HOPSCOTCH_MODEL';
    $model = env($setting, $fast ? DEFAULT_FAST_MODEL : DEFAULT_MODEL);
    if (!is_canadian_model($model)) {
        throw new HopscotchError("Model \"$model\" is not from a Canadian lab. Set $setting to a Cohere model.");
    }
    return $model;
}

function hopscotch_request(string $path, ?array $body, ?callable $onChunk = null): array
{
    $key = env('HOPSCOTCH_API_KEY');
    if ($key === '') {
        throw new HopscotchError('HOPSCOTCH_API_KEY is not set.');
    }

    // One handle for the life of the request, so that later calls reuse the connection.
    static $ch = null;
    $ch ??= curl_init();
    curl_reset($ch);
    $buffer = '';
    curl_setopt_array($ch, [
        CURLOPT_URL => rtrim(env('HOPSCOTCH_BASE_URL', DEFAULT_BASE_URL), '/') . $path,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$buffer, $onChunk): int {
            // Error bodies are JSON, not a stream; keep them for the caller.
            if ($onChunk && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200) {
                $onChunk($data);
            } else {
                $buffer .= $data;
            }
            return strlen($data);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $ok = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($ok === false) {
        throw new HopscotchError('Could not reach the model router: ' . curl_error($ch));
    }
    $json = json_decode($buffer, true);
    if ($status !== 200) {
        throw new HopscotchError($json['error']['message'] ?? "The model router answered HTTP $status.");
    }
    return is_array($json) ? $json : [];
}

/**
 * Stream a chat completion. $onText receives each piece of answer text as it
 * arrives.
 */
function hopscotch_stream_chat(array $messages, callable $onText): void
{
    $pending = '';
    hopscotch_request('/chat/completions', [
        'model' => hopscotch_model(),
        'messages' => $messages,
        'stream' => true,
        'temperature' => 0.2,
        'max_tokens' => 900,
    ], function (string $data) use (&$pending, $onText): void {
        $pending .= $data;
        // Server-sent events: one "data: {json}" line per chunk.
        while (($end = strpos($pending, "\n")) !== false) {
            $line = rtrim(substr($pending, 0, $end), "\r");
            $pending = substr($pending, $end + 1);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $chunk = json_decode(trim(substr($line, 5)), true);
            if (isset($chunk['error'])) {
                throw new HopscotchError($chunk['error']['message'] ?? 'The model stopped early.');
            }
            $text = $chunk['choices'][0]['delta']['content'] ?? '';
            if (is_string($text) && $text !== '') {
                $onText($text);
            }
        }
    });
}

/** A short, complete reply from the fast model. */
function hopscotch_complete(array $messages, int $maxTokens = 200): string
{
    $reply = hopscotch_request('/chat/completions', [
        'model' => hopscotch_model(fast: true),
        'messages' => $messages,
        'temperature' => 0,
        'max_tokens' => $maxTokens,
    ]);
    return (string) ($reply['choices'][0]['message']['content'] ?? '');
}

/** Ids of the models this key can call that come from Canadian labs. */
function hopscotch_canadian_models(): array
{
    $ids = array_column(hopscotch_request('/models', null)['data'] ?? [], 'id');
    return array_values(array_filter($ids, 'is_canadian_model'));
}
