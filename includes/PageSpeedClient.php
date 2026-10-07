<?php
require_once __DIR__ . '/../config.php';

class PageSpeedClient {
    private string $apiKey;

    public function __construct() {
        if (!has_google_api_key()) {
            throw new RuntimeException(
                'Google API key not configured. Copy config.local.php.example to config.local.php ' .
                'and add a key with PageSpeed Insights API enabled.'
            );
        }
        $this->apiKey = app_config()['google_api_key'];
    }

    /**
     * @param string $strategy 'mobile' or 'desktop'
     * @return array<string, mixed>
     */
    public function analyze(string $url, string $strategy = 'mobile'): array {
        $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
        $query = http_build_query([
            'url' => $url,
            'key' => $this->apiKey,
            'strategy' => $strategy,
            'category' => 'performance',
        ]);

        $ch = curl_init("$endpoint?$query");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("PageSpeed API request failed: $curlError");
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200) {
            $msg = $data['error']['message'] ?? $response;
            throw new RuntimeException("PageSpeed API error ($httpCode): $msg");
        }

        $lh = $data['lighthouseResult'] ?? [];
        $audits = $lh['audits'] ?? [];

        return [
            'performance_score' => isset($lh['categories']['performance']['score'])
                ? (int) round($lh['categories']['performance']['score'] * 100)
                : null,
            'total_byte_weight' => $audits['total-byte-weight']['numericValue'] ?? null,
            'image_byte_weight' => $this->extractImageBytes($audits),
            'largest_contentful_paint_ms' => $audits['largest-contentful-paint']['numericValue'] ?? null,
        ];
    }

    private function extractImageBytes(array $audits): ?float {
        $items = $audits['resource-summary']['details']['items'] ?? [];
        foreach ($items as $item) {
            if (($item['resourceType'] ?? '') === 'image') {
                return (float) ($item['transferSize'] ?? 0);
            }
        }
        return null;
    }
}
