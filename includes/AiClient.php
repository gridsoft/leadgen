<?php
require_once __DIR__ . '/../config.php';

/**
 * Provider-agnostic contract for one "system prompt + user message → JSON
 * text" call, so a different AI provider can drop in via config
 * ('ai_provider') without touching AgencyQualifier. Same idea as
 * SearchProvider for web search.
 */
interface AiClient {
    /**
     * @param array $schema JSON Schema the reply must follow (used for the provider's structured-output mode).
     * @return array{text: string, model: string, input_tokens: int, output_tokens: int, stop_reason: ?string, raw: string}
     * @throws AiRequestException when the request fails after retries
     */
    public function complete(string $system, string $user, array $schema): array;
}

class AiRequestException extends RuntimeException {
    /** Raw response body, if the API returned one (never contains the API key). */
    public ?string $raw = null;
}

/** One model stayed overloaded / rate-limited / unavailable; a client with fallback models tries the next. */
class AiModelUnavailable extends AiRequestException {}

/** A model's free daily quota is used up: no retry can succeed before the daily reset. */
class AiDailyQuotaUsed extends AiModelUnavailable {}

/** Every configured model's daily quota is used up, so nothing will answer until it resets. */
class AiQuotaExhausted extends AiRequestException {}

final class AiClientFactory {
    /** Builds the client named by config 'ai_provider'. Throws if it isn't configured. */
    public static function fromConfig(): AiClient {
        $c = app_config();
        if (!has_ai_api_key()) {
            throw new AiRequestException("No AI API key configured. Add 'ai_api_key' to config.local.php (see config.local.php.example).");
        }
        $provider = strtolower($c['ai_provider'] ?? 'gemini');
        switch ($provider) {
            case 'gemini':
                return new GeminiClient(
                    $c['ai_api_key'],
                    $c['ai_model'] ?? GeminiClient::DEFAULT_MODEL,
                    $c['ai_endpoint'] ?? GeminiClient::DEFAULT_ENDPOINT,
                    null,
                    null,
                    (array) ($c['ai_fallback_models'] ?? GeminiClient::DEFAULT_FALLBACK_MODELS)
                );
            case 'anthropic':
                return new AnthropicClient(
                    $c['ai_api_key'],
                    $c['ai_model'] ?? AnthropicClient::DEFAULT_MODEL,
                    $c['ai_endpoint'] ?? AnthropicClient::DEFAULT_ENDPOINT,
                    $c['ai_effort'] ?? 'medium',
                    (bool) ($c['ai_fallbacks'] ?? true)
                );
            default:
                throw new AiRequestException("Unknown ai_provider '$provider' in config.local.php. Supported: gemini, anthropic.");
        }
    }

    /** Non-secret settings, for display. The key is masked to its last 4 characters. */
    public static function describeConfig(): array {
        $c = app_config();
        $key = (string) ($c['ai_api_key'] ?? '');
        $provider = strtolower($c['ai_provider'] ?? 'gemini');
        $isGemini = $provider === 'gemini';
        return [
            'provider' => $provider,
            'model' => $c['ai_model'] ?? ($isGemini ? GeminiClient::DEFAULT_MODEL : AnthropicClient::DEFAULT_MODEL),
            'endpoint' => $c['ai_endpoint'] ?? ($isGemini ? GeminiClient::DEFAULT_ENDPOINT : AnthropicClient::DEFAULT_ENDPOINT),
            'effort' => $isGemini ? null : ($c['ai_effort'] ?? 'medium'),
            'fallbacks' => $isGemini ? null : (bool) ($c['ai_fallbacks'] ?? true),
            'fallback_models' => $isGemini ? (array) ($c['ai_fallback_models'] ?? GeminiClient::DEFAULT_FALLBACK_MODELS) : null,
            'key_masked' => has_ai_api_key() ? str_repeat('•', 12) . substr($key, -4) : null,
        ];
    }
}

/**
 * Claude Messages API over plain curl (the project calls every external API
 * this way). Uses structured outputs (output_config.format) so the reply is
 * schema-valid JSON, and retries 429 / 5xx with backoff — at most 3 attempts.
 */
class AnthropicClient implements AiClient {
    public const DEFAULT_MODEL = 'claude-opus-5-5';
    public const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    // Non-streaming default; the reply itself is ~1–2K tokens, the rest is room for thinking.
    private const MAX_TOKENS = 16000;
    private const MAX_ATTEMPTS = 3;
    private const TIMEOUT = 300;

    private string $apiKey;
    private string $model;
    private string $endpoint;
    private string $effort;
    private bool $fallbacks;
    /** @var callable(string, string[], string): array{status: int, body: string, headers: array<string,string>, error: ?string} */
    private $transport;
    /** @var callable(int): void */
    private $sleep;

    public function __construct(
        string $apiKey,
        string $model = self::DEFAULT_MODEL,
        string $endpoint = self::DEFAULT_ENDPOINT,
        string $effort = 'medium',
        bool $fallbacks = true,
        ?callable $transport = null,
        ?callable $sleep = null
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->endpoint = $endpoint;
        $this->effort = $effort;
        // Server-side fallback exists only on Anthropic's own API — a proxy endpoint may reject the field.
        $this->fallbacks = $fallbacks && stripos($endpoint, 'api.anthropic.com') !== false;
        $this->transport = $transport ?? [self::class, 'httpPost'];
        $this->sleep = $sleep ?? function (int $seconds) { sleep($seconds); };
    }

    public function complete(string $system, string $user, array $schema): array {
        $body = [
            'model' => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            // The system prompt is identical on every call — cache it.
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => [
                'effort' => $this->effort,
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ];
        $headers = [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::API_VERSION,
            'content-type: application/json',
        ];
        if ($this->fallbacks) {
            // On a safety-classifier decline, the API re-runs the request on its recommended fallback model.
            $body['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: ' . self::FALLBACK_BETA;
        }
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $lastError = 'request not sent';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $res = ($this->transport)($this->endpoint, $headers, $payload);
            $status = $res['status'];

            $retryable = $status === 0 || $status === 429 || $status >= 500;
            if ($retryable) {
                $lastError = $status === 0 ? 'Connection failed: ' . ($res['error'] ?? 'unknown error') : "HTTP $status: " . self::apiErrorMessage($res['body']);
                if ($attempt < self::MAX_ATTEMPTS) {
                    ($this->sleep)(self::backoffSeconds($attempt, $res['headers']['retry-after'] ?? null));
                }
                continue;
            }
            if ($status !== 200) {
                $e = new AiRequestException("AI API error (HTTP $status): " . self::apiErrorMessage($res['body']));
                $e->raw = $res['body'];
                throw $e;
            }

            $data = json_decode($res['body'], true);
            if (!is_array($data)) {
                $e = new AiRequestException('AI API returned a response that is not JSON');
                $e->raw = $res['body'];
                throw $e;
            }

            // With adaptive thinking, thinking blocks come first; the answer is the last text block.
            $text = '';
            foreach ($data['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text' && trim($block['text'] ?? '') !== '') {
                    $text = $block['text'];
                }
            }
            $usage = $data['usage'] ?? [];
            return [
                'text' => $text,
                'model' => (string) ($data['model'] ?? $this->model),
                // Cached system-prompt tokens are still input tokens, just cheaper — count them all.
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'stop_reason' => $data['stop_reason'] ?? null,
                'raw' => $res['body'],
            ];
        }

        throw new AiRequestException('AI API failed after ' . self::MAX_ATTEMPTS . " attempts. Last error: $lastError");
    }

    /** Honours Retry-After (capped at 60 s), otherwise 2 s, then 8 s. */
    private static function backoffSeconds(int $attempt, ?string $retryAfter): int {
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return max(1, min(60, (int) ceil((float) $retryAfter)));
        }
        return $attempt === 1 ? 2 : 8;
    }

    private static function apiErrorMessage(string $body): string {
        $data = json_decode($body, true);
        $msg = $data['error']['message'] ?? trim(strip_tags($body));
        return mb_substr($msg !== '' ? $msg : 'no details', 0, 300);
    }

    /** @return array{status: int, body: string, headers: array<string,string>, error: ?string} */
    public static function httpPost(string $url, array $headers, string $payload): array {
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => $error ?: 'request failed'];
        }
        return ['status' => $status, 'body' => (string) $body, 'headers' => $responseHeaders, 'error' => null];
    }
}

/**
 * Google Gemini API (generateContent) over plain curl — the default
 * provider, because Gemini has a free tier (free-tier prompts may be used
 * by Google to improve its products). Requests JSON output with the
 * schema; if a model rejects the schema it retries once with JSON mode
 * only, and AgencyQualifier still validates the reply. Retries 429 / 5xx
 * with backoff, at most 3 attempts per model. When the chosen model stays
 * overloaded, rate-limited or unavailable, the next model in
 * $fallbackModels is tried (free-tier models are often briefly overloaded).
 */
class GeminiClient implements AiClient {
    public const DEFAULT_MODEL = 'gemini-3.8-flash';
    public const DEFAULT_FALLBACK_MODELS = ['gemini-3.5-flash', 'gemini-3.5-flash-lite'];
    public const DEFAULT_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta';
    private const MAX_OUTPUT_TOKENS = 16000;
    private const MAX_ATTEMPTS = 3;

    private string $apiKey;
    private string $model;
    /** @var string[] */
    private array $fallbackModels;
    private string $endpoint;
    /** @var callable(string, string[], string): array{status: int, body: string, headers: array<string,string>, error: ?string} */
    private $transport;
    /** @var callable(int): void */
    private $sleep;

    public function __construct(
        string $apiKey,
        string $model = self::DEFAULT_MODEL,
        string $endpoint = self::DEFAULT_ENDPOINT,
        ?callable $transport = null,
        ?callable $sleep = null,
        array $fallbackModels = self::DEFAULT_FALLBACK_MODELS
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->fallbackModels = $fallbackModels;
        $this->endpoint = rtrim($endpoint, '/');
        $this->transport = $transport ?? [AnthropicClient::class, 'httpPost'];
        $this->sleep = $sleep ?? function (int $seconds) { sleep($seconds); };
    }

    public function complete(string $system, string $user, array $schema): array {
        $failures = [];
        $allOutOfQuota = true;
        foreach (array_values(array_unique(array_merge([$this->model], $this->fallbackModels))) as $model) {
            try {
                return $this->completeWithModel($model, $system, $user, $schema);
            } catch (AiModelUnavailable $e) {
                $failures[] = "$model: " . $e->getMessage();
                $allOutOfQuota = $allOutOfQuota && $e instanceof AiDailyQuotaUsed;
            }
        }
        if ($allOutOfQuota) {
            throw new AiQuotaExhausted('The free daily AI quota is used up for every model. ' . implode(' | ', $failures));
        }
        throw new AiRequestException('No Gemini model could answer. ' . implode(' | ', $failures));
    }

    private function completeWithModel(string $model, string $system, string $user, array $schema): array {
        $url = $this->endpoint . '/models/' . rawurlencode($model) . ':generateContent';
        // The key goes in a header, not the URL, so it never shows up in logs or error messages.
        $headers = ['x-goog-api-key: ' . $this->apiKey, 'content-type: application/json'];
        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $schema,
                'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
            ],
        ];

        $lastError = 'request not sent';
        $schemaDropped = false;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $res = ($this->transport)($url, $headers, $payload);
            $status = $res['status'];

            // A per-minute limit clears in seconds and is worth retrying; a per-day one isn't.
            if ($status === 429 && self::isDailyQuota($res['body'])) {
                throw new AiDailyQuotaUsed('daily quota used up (HTTP 429)');
            }
            if ($status === 0 || $status === 429 || $status >= 500) {
                $lastError = $status === 0 ? 'Connection failed: ' . ($res['error'] ?? 'unknown error') : "HTTP $status: " . self::errorMessage($res['body']);
                if ($attempt < self::MAX_ATTEMPTS) {
                    ($this->sleep)(self::backoffSeconds($attempt, $res));
                }
                continue;
            }
            // Some models don't accept every JSON Schema feature — fall back to plain JSON mode once.
            if ($status === 400 && !$schemaDropped && stripos($res['body'], 'schema') !== false) {
                unset($body['generationConfig']['responseJsonSchema']);
                $schemaDropped = true;
                $attempt--;
                continue;
            }
            // Retired or unknown model (e.g. "no longer available to new users"): try the next one.
            if ($status === 404) {
                throw new AiModelUnavailable("HTTP 404: " . self::errorMessage($res['body']));
            }
            if ($status !== 200) {
                $e = new AiRequestException("AI API error (HTTP $status): " . self::errorMessage($res['body']));
                $e->raw = $res['body'];
                throw $e;
            }

            $data = json_decode($res['body'], true);
            if (!is_array($data)) {
                $e = new AiRequestException('AI API returned a response that is not JSON');
                $e->raw = $res['body'];
                throw $e;
            }

            $candidate = $data['candidates'][0] ?? [];
            $text = '';
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (empty($part['thought']) && isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
            $usage = $data['usageMetadata'] ?? [];
            $finish = $candidate['finishReason'] ?? ($data['promptFeedback']['blockReason'] ?? null);
            return [
                'text' => $text,
                'model' => (string) ($data['modelVersion'] ?? $model),
                'input_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
                // Thinking tokens are billed as output.
                'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
                // Normalised so AgencyQualifier can explain a cut-off or blocked reply.
                'stop_reason' => $finish === 'MAX_TOKENS' ? 'max_tokens' : (in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'OTHER'], true) ? 'refusal' : $finish),
                'raw' => $res['body'],
            ];
        }

        throw new AiModelUnavailable('failed after ' . self::MAX_ATTEMPTS . " attempts. Last error: $lastError");
    }

    /** Whether a 429 body names a per-day quota (e.g. "GenerateRequestsPerDayPerProjectPerModel-FreeTier"). */
    private static function isDailyQuota(string $body): bool {
        foreach (json_decode($body, true)['error']['details'] ?? [] as $detail) {
            foreach ($detail['violations'] ?? [] as $violation) {
                if (stripos((string) ($violation['quotaId'] ?? ''), 'PerDay') !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Retry-After header, else the RetryInfo delay Google puts in 429 bodies ("30s"), capped at 60 s; else 2 s then 8 s. */
    private static function backoffSeconds(int $attempt, array $res): int {
        $hint = $res['headers']['retry-after'] ?? null;
        if ($hint === null) {
            foreach (json_decode($res['body'], true)['error']['details'] ?? [] as $detail) {
                if (isset($detail['retryDelay'])) {
                    $hint = rtrim($detail['retryDelay'], 's');
                }
            }
        }
        if ($hint !== null && is_numeric($hint)) {
            return max(1, min(60, (int) ceil((float) $hint)));
        }
        return $attempt === 1 ? 2 : 8;
    }

    private static function errorMessage(string $body): string {
        $data = json_decode($body, true);
        $msg = $data['error']['message'] ?? trim(strip_tags($body));
        return mb_substr($msg !== '' ? $msg : 'no details', 0, 300);
    }
}
