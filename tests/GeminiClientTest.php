<?php
require_once __DIR__ . '/../includes/AiClient.php';

const GEMINI_KEY = 'AIza-test-SECRET-9876';

function gemini_transport(array $responses, array &$requests): callable {
    return function (string $url, array $headers, string $payload) use (&$responses, &$requests) {
        $requests[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($payload, true)];
        return array_shift($responses);
    };
}

function gemini_ok(string $text, string $finish = 'STOP'): array {
    return ['status' => 200, 'headers' => [], 'error' => null, 'body' => json_encode([
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => 'thinking…', 'thought' => true], ['text' => $text]]],
            'finishReason' => $finish,
        ]],
        'usageMetadata' => ['promptTokenCount' => 2500, 'candidatesTokenCount' => 600, 'thoughtsTokenCount' => 900],
        'modelVersion' => 'gemini-3.8-flash',
    ])];
}

/** Free-tier 429 for a per-day quota, shaped like Google's. */
function gemini_daily_quota(): array {
    return ['status' => 429, 'headers' => [], 'error' => null, 'body' => json_encode(['error' => [
        'code' => 429, 'message' => 'You exceeded your current quota', 'status' => 'RESOURCE_EXHAUSTED', 'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [[
                'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests',
                'quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier',
            ]]],
            ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '41s'],
        ],
    ]])];
}

function gemini_client(array $responses, array &$requests, ?array &$sleeps = null, array $fallbacks = []): GeminiClient {
    $sleeps = [];
    return new GeminiClient(GEMINI_KEY, 'gemini-3.8-flash', GeminiClient::DEFAULT_ENDPOINT,
        gemini_transport($responses, $requests), function (int $s) use (&$sleeps) { $sleeps[] = $s; }, $fallbacks);
}

return [
    'request: system instruction, user message, JSON mode with schema, key in a header' => function () {
        $requests = [];
        $res = gemini_client([gemini_ok('{"a":1}')], $requests)->complete('SYS', 'USER', ['type' => 'object']);
        $r = $requests[0];
        assert_same('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent', $r['url']);
        assert_not_contains(GEMINI_KEY, $r['url'], 'key is not in the URL');
        assert_contains('x-goog-api-key: ' . GEMINI_KEY, implode("\n", $r['headers']));
        assert_same('SYS', $r['body']['systemInstruction']['parts'][0]['text']);
        assert_same('USER', $r['body']['contents'][0]['parts'][0]['text']);
        assert_same('application/json', $r['body']['generationConfig']['responseMimeType']);
        assert_same(['type' => 'object'], $r['body']['generationConfig']['responseJsonSchema']);
        assert_true($r['body']['generationConfig']['maxOutputTokens'] >= 2000);
        assert_same('{"a":1}', $res['text'], 'thought parts are skipped');
        assert_same(2500, $res['input_tokens']);
        assert_same(1500, $res['output_tokens'], 'thinking tokens count as output');
        assert_same('gemini-3.8-flash', $res['model']);
    },
    'free-tier 429 waits for the RetryInfo delay, then retries' => function () {
        $requests = [];
        $limited = ['status' => 429, 'headers' => [], 'error' => null, 'body' => json_encode(['error' => [
            'code' => 429, 'message' => 'Quota exceeded', 'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '17s']],
        ]])];
        $client = gemini_client([$limited, gemini_ok('{}')], $requests, $sleeps);
        $client->complete('S', 'U', []);
        assert_same(2, count($requests));
        assert_same([17], $sleeps);
    },
    'at most 3 attempts on server errors; key never in the error' => function () {
        $requests = [];
        $fail = ['status' => 503, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"The model is overloaded"}}'];
        try {
            gemini_client([$fail, $fail, $fail, gemini_ok('{}')], $requests)->complete('S', 'U', []);
            throw new AssertionFailed('expected an exception');
        } catch (AiRequestException $e) {
            assert_same(3, count($requests));
            assert_contains('overloaded', $e->getMessage());
            assert_not_contains(GEMINI_KEY, $e->getMessage());
        }
    },
    'a schema the model rejects falls back to plain JSON mode once' => function () {
        $requests = [];
        $bad = ['status' => 400, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"Invalid JSON payload: responseJsonSchema anyOf not supported"}}'];
        $res = gemini_client([$bad, gemini_ok('{"ok":true}')], $requests)->complete('S', 'U', ['type' => 'object']);
        assert_same(2, count($requests));
        assert_true(!isset($requests[1]['body']['generationConfig']['responseJsonSchema']), 'schema dropped on the retry');
        assert_same('application/json', $requests[1]['body']['generationConfig']['responseMimeType']);
        assert_same('{"ok":true}', $res['text']);
    },
    'other 400s are not retried' => function () {
        $requests = [];
        try {
            gemini_client([['status' => 400, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"API key not valid"}}']], $requests)->complete('S', 'U', []);
            throw new AssertionFailed('expected an exception');
        } catch (AiRequestException $e) {
            assert_same(1, count($requests));
            assert_contains('API key not valid', $e->getMessage());
        }
    },
    'MAX_TOKENS and SAFETY finish reasons are normalised' => function () {
        $requests = [];
        assert_same('max_tokens', gemini_client([gemini_ok('{', 'MAX_TOKENS')], $requests)->complete('S', 'U', [])['stop_reason']);
        assert_same('refusal', gemini_client([gemini_ok('', 'SAFETY')], $requests)->complete('S', 'U', [])['stop_reason']);
    },
    'overloaded model falls back to the next one, which is recorded' => function () {
        $requests = [];
        $busy = ['status' => 503, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"This model is currently experiencing high demand."}}'];
        $ok = gemini_ok('{"a":1}');
        $ok['body'] = str_replace('"modelVersion":"gemini-3.8-flash"', '"modelVersion":"gemini-3.5-flash"', $ok['body']);
        $res = gemini_client([$busy, $busy, $busy, $ok], $requests, $sleeps, ['gemini-3.5-flash', 'gemini-3.5-flash-lite'])->complete('S', 'U', []);
        assert_same(4, count($requests), '3 attempts on the busy model, then the fallback');
        assert_contains('/models/gemini-3.5-flash:generateContent', $requests[3]['url']);
        assert_same('gemini-3.5-flash', $res['model']);
    },
    'a retired model (404) skips straight to the next one' => function () {
        $requests = [];
        $gone = ['status' => 404, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"models/gemini-2.5-flash is no longer available to new users"}}'];
        gemini_client([$gone, gemini_ok('{}')], $requests, $sleeps, ['gemini-3.5-flash-lite'])->complete('S', 'U', []);
        assert_same(2, count($requests));
        assert_same([], $sleeps, 'no waiting on a 404');
    },
    'every model failing reports each one' => function () {
        $requests = [];
        $busy = ['status' => 503, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"high demand"}}'];
        try {
            gemini_client(array_fill(0, 6, $busy), $requests, $sleeps, ['gemini-3.5-flash'])->complete('S', 'U', []);
            throw new AssertionFailed('expected an exception');
        } catch (AiRequestException $e) {
            assert_same(6, count($requests));
            assert_contains('gemini-3.8-flash: failed after 3 attempts', $e->getMessage());
            assert_contains('gemini-3.5-flash: failed after 3 attempts', $e->getMessage());
        }
    },
    'a used-up daily quota (429 PerDay) is not retried; the next model is tried at once' => function () {
        $requests = [];
        $res = gemini_client([gemini_daily_quota(), gemini_ok('{}')], $requests, $sleeps, ['gemini-3.5-flash-lite'])->complete('S', 'U', []);
        assert_same(2, count($requests), 'one call to the used-up model, then the fallback');
        assert_contains('/models/gemini-3.5-flash-lite:generateContent', $requests[1]['url']);
        assert_same([], $sleeps, 'no waiting');
        assert_same('gemini-3.8-flash', $res['model']);
    },
    'every model out of daily quota throws AiQuotaExhausted' => function () {
        $requests = [];
        try {
            gemini_client([gemini_daily_quota(), gemini_daily_quota()], $requests, $sleeps, ['gemini-3.5-flash'])->complete('S', 'U', []);
            throw new AssertionFailed('expected an exception');
        } catch (AiQuotaExhausted $e) {
            assert_same(2, count($requests));
            assert_contains('daily quota used up', $e->getMessage());
        }
    },
    'one model out of quota and another overloaded is a normal failure, not AiQuotaExhausted' => function () {
        $requests = [];
        $busy = ['status' => 503, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"high demand"}}'];
        try {
            gemini_client([gemini_daily_quota(), $busy, $busy, $busy], $requests, $sleeps, ['gemini-3.5-flash'])->complete('S', 'U', []);
            throw new AssertionFailed('expected an exception');
        } catch (AiRequestException $e) {
            assert_true(!$e instanceof AiQuotaExhausted, 'the overloaded model may answer on the next run');
        }
    },
    'a bad key (400) does not try other models' => function () {
        $requests = [];
        try {
            gemini_client([['status' => 400, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"API key not valid"}}']], $requests, $sleeps, ['gemini-3.5-flash'])->complete('S', 'U', []);
            throw new AssertionFailed('expected an exception');
        } catch (AiRequestException $e) {
            assert_same(1, count($requests));
        }
    },
];
