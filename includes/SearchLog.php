<?php

/**
 * Remembers which (category, location, source) combinations have already
 * been searched. No search API here gives more results for the same
 * combo on a later request — the only way to get more leads over time is
 * to cover new ground, and this is what lets a batch run skip ground
 * that's already covered instead of burning calls to re-fetch the same
 * capped result set.
 */
class SearchLog {
    /**
     * @return array{searched_at: string, result_count: int, new_count: int}|null
     */
    public static function find(PDO $pdo, string $category, string $location, string $source): ?array {
        $stmt = $pdo->prepare(
            'SELECT searched_at, result_count, new_count FROM search_log
             WHERE category = :category AND location = :location AND source = :source'
        );
        $stmt->execute([
            'category' => trim($category),
            'location' => trim($location),
            'source' => $source,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function record(
        PDO $pdo,
        string $category,
        string $location,
        string $source,
        int $resultCount,
        int $newCount
    ): void {
        $pdo->prepare(
            'INSERT INTO search_log (category, location, source, result_count, new_count)
             VALUES (:category, :location, :source, :result_count, :new_count)
             ON DUPLICATE KEY UPDATE result_count = VALUES(result_count), new_count = VALUES(new_count),
                searched_at = CURRENT_TIMESTAMP'
        )->execute([
            'category' => trim($category),
            'location' => trim($location),
            'source' => $source,
            'result_count' => $resultCount,
            'new_count' => $newCount,
        ]);
    }

    /**
     * True if this combo was searched within the last $days days (or ever, if $days is null).
     */
    public static function isFresh(PDO $pdo, string $category, string $location, string $source, ?int $days): bool {
        $found = self::find($pdo, $category, $location, $source);
        if ($found === null) {
            return false;
        }
        if ($days === null) {
            return true;
        }
        $age = time() - strtotime($found['searched_at']);
        return $age < $days * 86400;
    }
}
