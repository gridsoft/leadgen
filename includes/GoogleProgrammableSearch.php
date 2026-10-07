<?php
require_once __DIR__ . '/SearchProvider.php';
require_once __DIR__ . '/EnrichmentConfig.php';
require_once __DIR__ . '/../config.php';

/**
 * Google Programmable Search (Custom Search JSON API). Schema confirmed
 * against Google's own API reference (items[].link/title/snippet;
 * key/cx/q/num params) before writing this, unlike AbstractApiVerifier.
 */
class GoogleProgrammableSearch implements SearchProvider {
    private const ENDPOINT = 'https://www.googleapis.com/customsearch/v1';
    private const PROVIDER_NAME = 'google_search';

    private string $apiKey;
    private string $engineId;
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        if (!has_google_search_key()) {
            throw new RuntimeException(
                'Google Custom Search key not configured. Copy config.local.php.example to config.local.php ' .
                'and add google_search_api_key + google_search_engine_id.'
            );
        }
        $c = app_config();
        $this->apiKey = $c['google_search_api_key'];
        $this->engineId = $c['google_search_engine_id'];
        $this->pdo = $pdo;
    }

    public function search(string $query, int $limit): array {
        $this->enforceDailyCap();

        $url = self::ENDPOINT . '?' . http_build_query([
            'key' => $this->apiKey,
            'cx' => $this->engineId,
            'q' => $query,
            'num' => max(1, min($limit, 10)), // Google's own hard max per request
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => EnrichmentConfig::REQUEST_TOTAL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => EnrichmentConfig::REQUEST_CONNECT_TIMEOUT,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
            CURLOPT_USERAGENT => EnrichmentConfig::USER_AGENT,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->recordUsage();

        if ($response === false) {
            throw new RuntimeException("Google Search API request failed: $curlError");
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200) {
            $msg = $data['error']['message'] ?? $response;
            throw new RuntimeException("Google Search API error ($httpCode): $msg");
        }

        $results = [];
        foreach ($data['items'] ?? [] as $item) {
            $results[] = [
                'url' => $item['link'] ?? '',
                'title' => $item['title'] ?? '',
                'snippet' => $item['snippet'] ?? '',
            ];
        }
        return $results;
    }

    private function enforceDailyCap(): void {
        $stmt = $this->pdo->prepare(
            'SELECT request_count FROM api_usage WHERE usage_date = CURDATE() AND provider = :provider'
        );
        $stmt->execute(['provider' => self::PROVIDER_NAME]);
        $count = (int) ($stmt->fetchColumn() ?: 0);

        if ($count >= EnrichmentConfig::DAILY_SEARCH_CAP) {
            throw new RuntimeException(
                'Daily search cap (' . EnrichmentConfig::DAILY_SEARCH_CAP . ') reached for ' . self::PROVIDER_NAME
            );
        }
    }

    private function recordUsage(): void {
        $this->pdo->prepare(
            'INSERT INTO api_usage (usage_date, provider, request_count) VALUES (CURDATE(), :provider, 1)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1'
        )->execute(['provider' => self::PROVIDER_NAME]);
    }
}
