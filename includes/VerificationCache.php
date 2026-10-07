<?php
require_once __DIR__ . '/EnrichmentConfig.php';
require_once __DIR__ . '/EmailVerifier.php';

/**
 * Read-through cache in front of any EmailVerifier — 60-day TTL per spec.
 * Same-address re-checks (e.g. two leads independently guessing info@ for
 * the same domain, or re-running enrich) don't burn API credits twice.
 */
class VerificationCache {
    public static function get(PDO $pdo, string $email): ?array {
        $stmt = $pdo->prepare(
            'SELECT status, raw_response FROM verification_cache WHERE email = :email AND expires_at > NOW()'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row ? ['status' => $row['status'], 'raw_response' => $row['raw_response']] : null;
    }

    public static function put(PDO $pdo, string $email, array $result): void {
        $pdo->prepare(
            'INSERT INTO verification_cache (email, status, raw_response, expires_at)
             VALUES (:email, :status, :raw_response, DATE_ADD(NOW(), INTERVAL :ttl DAY))
             ON DUPLICATE KEY UPDATE status = VALUES(status), raw_response = VALUES(raw_response),
                created_at = CURRENT_TIMESTAMP, expires_at = VALUES(expires_at)'
        )->execute([
            'email' => $email,
            'status' => $result['status'],
            'raw_response' => $result['raw_response'] ?? null,
            'ttl' => EnrichmentConfig::VERIFICATION_CACHE_TTL_DAYS,
        ]);
    }

    /**
     * Verify through the cache — the single entry point Enricher/PatternGuesser use.
     */
    public static function verify(PDO $pdo, EmailVerifier $verifier, string $email): array {
        $cached = self::get($pdo, $email);
        if ($cached !== null) {
            return $cached;
        }
        $result = $verifier->verify($email);
        self::put($pdo, $email, $result);
        return $result;
    }
}
