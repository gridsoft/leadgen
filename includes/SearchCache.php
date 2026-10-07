<?php
require_once __DIR__ . '/EnrichmentConfig.php';
require_once __DIR__ . '/SearchProvider.php';

/**
 * Read-through cache in front of any SearchProvider — 30-day TTL per spec.
 */
class SearchCache {
    public static function get(PDO $pdo, string $query): ?array {
        $stmt = $pdo->prepare(
            'SELECT results_json FROM search_cache WHERE query = :query AND expires_at > NOW()'
        );
        $stmt->execute(['query' => $query]);
        $json = $stmt->fetchColumn();
        return $json !== false ? json_decode($json, true) : null;
    }

    public static function put(PDO $pdo, string $query, array $results): void {
        $pdo->prepare(
            'INSERT INTO search_cache (query, results_json, expires_at)
             VALUES (:query, :results_json, DATE_ADD(NOW(), INTERVAL :ttl DAY))
             ON DUPLICATE KEY UPDATE results_json = VALUES(results_json),
                created_at = CURRENT_TIMESTAMP, expires_at = VALUES(expires_at)'
        )->execute([
            'query' => $query,
            'results_json' => json_encode($results),
            'ttl' => EnrichmentConfig::SEARCH_CACHE_TTL_DAYS,
        ]);
    }

    public static function search(PDO $pdo, SearchProvider $provider, string $query, int $limit): array {
        $cached = self::get($pdo, $query);
        if ($cached !== null) {
            return $cached;
        }
        $results = $provider->search($query, $limit);
        self::put($pdo, $query, $results);
        return $results;
    }
}
