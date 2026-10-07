<?php
require_once __DIR__ . '/../includes/AiClient.php';

const FAKE_KEY = 'sk-ant-test-SECRET-1234';

/** Scripted HTTP responses for AnthropicClient; records each request. */
function fake_transport(array $responses, array &$requests): callable {
    return function (string $url, array $headers, string $payload) use (&$responses, &$requests) {
        $requests[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($payload, true)];
        return array_shift($responses);
    };
}

function api_ok(string $text): array {
    return ['status' => 200, 'headers' => [], 'error' => null, 'body' => json_encode([
        'model' => 'claude-opus-5-5',
        'stop_reason' => 'end_turn',
        'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => 300, 'cache_read_input_tokens' => 2000, 'cache_creation_input_tokens' => 0, 'output_tokens' => 700],
    ])];
}

return [
    'sends system prompt, user message, JSON schema and enough output tokens' => function () {
        $requests = [];
        $client = new AnthropicClient(FAKE_KEY, 'claude-opus-5-5', AnthropicClient::DEFAULT_ENDPOINT, 'medium', true,
            fake_transport([api_ok('{"a":1}')], $requests), function () {});
        $res = $client->complete('SYS', 'USER', ['type' => 'object']);

        $body = $requests[0]['body'];
        assert_same('claude-opus-5-5', $body['model']);
        assert_same('SYS', $body['system'][0]['text']);
        assert_same([['role' => 'user', 'content' => 'USER']], $body['messages']);
        assert_same('json_schema', $body['output_config']['format']['type']);
        assert_true($body['max_tokens'] >= 2000);
        assert_contains('x-api-key: ' . FAKE_KEY, implode("\n", $requests[0]['headers']));
        assert_same('{"a":1}', $res['text'], 'thinking blocks are skipped');
        assert_same(2300, $res['input_tokens'], 'cached input tokens are included');
        assert_same(700, $res['output_tokens']);
    },
    'retries 429 and 5xx with backoff, at most 3 attempts' => function () {
        $requests = [];
        $sleeps = [];
        $client = new AnthropicClient(FAKE_KEY, 'm', AnthropicClient::DEFAULT_ENDPOINT, 'medium', false, fake_transport([
            ['status' => 429, 'headers' => ['retry-after' => '5'], 'error' => null, 'body' => '{"error":{"message":"rate limited"}}'],
            ['status' => 529, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"Overloaded"}}'],
            api_ok('{}'),
        ], $requests), function (int $s) use (&$sleeps) { $sleeps[] = $s; });
        $client->complete('S', 'U', []);
        assert_same(3, count($requests));
        assert_same([5, 8], $sleeps, 'Retry-After is honoured, then backoff');
    },
    'gives up after 3 failed attempts without leaking the key' => function () {
        $requests = [];
        $fail = ['status' => 503, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"unavailable"}}'];
        $client = new AnthropicClient(FAKE_KEY, 'm', AnthropicClient::DEFAULT_ENDPOINT, 'medium', false,
            fake_transport([$fail, $fail, $fail, api_ok('{}')], $requests), function () {});
        try {
            $client->complete('S', 'U', []);
            throw new AssertionFailed('expected an exception');
        } catch (AiRequestException $e) {
            assert_same(3, count($requests));
            assert_contains('503', $e->getMessage());
            assert_not_contains(FAKE_KEY, $e->getMessage());
        }
    },
    'does not retry a 400' => function () {
        $requests = [];
        $client = new AnthropicClient(FAKE_KEY, 'm', AnthropicClient::DEFAULT_ENDPOINT, 'medium', false, fake_transport([
            ['status' => 400, 'headers' => [], 'error' => null, 'body' => '{"error":{"message":"bad model"}}'],
        ], $requests), function () {});
        try {
            $client->complete('S', 'U', []);
            throw new AssertionFailed('expected an exception');
        } catch (AiRequestException $e) {
            assert_same(1, count($requests));
            assert_contains('bad model', $e->getMessage());
        }
    },
    'refusal fallback only on Anthropic\'s own endpoint' => function () {
        $requests = [];
        (new AnthropicClient(FAKE_KEY, 'm', AnthropicClient::DEFAULT_ENDPOINT, 'medium', true, fake_transport([api_ok('{}')], $requests), function () {}))
            ->complete('S', 'U', []);
        assert_same('default', $requests[0]['body']['fallbacks']);
        assert_contains('anthropic-beta: server-side-fallback-2026-07-01', implode("\n", $requests[0]['headers']));

        $requests = [];
        (new AnthropicClient(FAKE_KEY, 'm', 'https://my-proxy.internal/v1/messages', 'medium', true, fake_transport([api_ok('{}')], $requests), function () {}))
            ->complete('S', 'U', []);
        assert_true(!isset($requests[0]['body']['fallbacks']));
    },
];
