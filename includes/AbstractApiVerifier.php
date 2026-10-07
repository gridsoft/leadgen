<?php
require_once __DIR__ . '/EmailVerifier.php';
require_once __DIR__ . '/EnrichmentConfig.php';
require_once __DIR__ . '/../config.php';

/**
 * Abstract API — Email Reputation (https://www.abstractapi.com/), 100 free
 * calls/month. Note this is the "Email Reputation" product specifically
 * (emailreputation.abstractapi.com), not Abstract's separate "Email
 * Validation" product — each Abstract product has its own key, and the
 * two return differently-shaped JSON. mapResponse() is confirmed against
 * a real live response (verified 2026-09-11: deliverable/undeliverable
 * cases both checked), not recollection.
 */
class AbstractApiVerifier implements EmailVerifier {
    private const ENDPOINT = 'https://emailreputation.abstractapi.com/v1/';
    private const PROVIDER_NAME = 'abstractapi';

    private string $apiKey;
    private PDO $pdo;
    private static ?float $lastCallAt = null;

    public function __construct(PDO $pdo) {
        if (!has_abstractapi_key()) {
            throw new RuntimeException(
                'Abstract API key not configured. Copy config.local.php.example to config.local.php ' .
                'and add an Abstract API Email Validation key.'
            );
        }
        $this->apiKey = app_config()['abstractapi_key'];
        $this->pdo = $pdo;
    }

    public function verify(string $email): array {
        $this->enforceDailyCap();
        $this->pace();

        $url = self::ENDPOINT . '?' . http_build_query([
            'api_key' => $this->apiKey,
            'email' => $email,
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
            throw new RuntimeException("Abstract API request failed: $curlError");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("Abstract API error ($httpCode): $response");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException("Abstract API returned unparseable response: $response");
        }

        return [
            'status' => $this->mapResponse($data),
            'raw_response' => $response,
        ];
    }

    /**
     * Real response shape (confirmed live):
     * {
     *   "email_deliverability": {"status": "deliverable"|"undeliverable", "is_format_valid": bool, ...},
     *   "email_quality": {"is_disposable": bool, "is_catchall": bool, ...},
     *   ...
     * }
     */
    private function mapResponse(array $data): string {
        $deliverability = $data['email_deliverability'] ?? [];
        $quality = $data['email_quality'] ?? [];

        if (($quality['is_disposable'] ?? false) === true) {
            return 'disposable';
        }
        if (($quality['is_catchall'] ?? false) === true) {
            return 'catch_all';
        }

        $status = strtolower((string) ($deliverability['status'] ?? ''));
        $validFormat = $deliverability['is_format_valid'] ?? null;

        if ($status === 'deliverable' && $validFormat !== false) {
            return 'valid';
        }
        if ($status === 'undeliverable' || $validFormat === false) {
            return 'invalid';
        }

        return 'unknown';
    }

    private function pace(): void {
        if (self::$lastCallAt !== null) {
            $elapsed = microtime(true) - self::$lastCallAt;
            $wait = EnrichmentConfig::VERIFICATION_MIN_INTERVAL_SECONDS - $elapsed;
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }
        self::$lastCallAt = microtime(true);
    }

    private function enforceDailyCap(): void {
        $stmt = $this->pdo->prepare(
            'SELECT request_count FROM api_usage WHERE usage_date = CURDATE() AND provider = :provider'
        );
        $stmt->execute(['provider' => self::PROVIDER_NAME]);
        $count = (int) ($stmt->fetchColumn() ?: 0);

        if ($count >= EnrichmentConfig::DAILY_VERIFICATION_CAP) {
            throw new RuntimeException(
                'Daily verification cap (' . EnrichmentConfig::DAILY_VERIFICATION_CAP . ') reached for ' . self::PROVIDER_NAME
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
