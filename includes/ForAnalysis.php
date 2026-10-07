<?php

/**
 * The "For analysis" list: agencies of the targeted business types with an
 * email address that haven't been contacted or analyzed yet. Used by the
 * sidebar link (as dashboard filters) and by cron_analyze.php (as SQL) — keep
 * the two in step, and in step with the matching filters in index.php.
 */
final class ForAnalysis {
    /**
     * index.php filter parameters. The categories are the Business type typed in
     * Find prospects, which is stored as typed — search with exactly these words.
     */
    public const FILTERS = [
        'category' => ['web development agency', 'digital marketing agency', 'seo agency'],
        'email' => ['yes'],
        'contacted' => ['no'],
        'ai' => ['not_analyzed'],
    ];

    public static function dashboardUrl(): string {
        return 'index.php?' . http_build_query(['sort' => 'created', 'dir' => 'desc', 'per_page' => 25, 'q' => ''] + self::FILTERS);
    }

    /**
     * The next prospects on the list, newest first like the dashboard link.
     * Skips any whose website already has an agency in any state: waiting
     * ones are queued already, and failed ones aren't retried automatically.
     *
     * @param int[] $exclude prospect IDs to leave out
     * @return int[]
     */
    public static function nextProspectIds(PDO $pdo, int $limit, array $exclude = []): array {
        $exclude = array_map('intval', $exclude);
        $categories = self::FILTERS['category'];
        $stmt = $pdo->prepare(
            'SELECT p.id FROM prospects p
             WHERE p.category IN (' . implode(', ', array_fill(0, count($categories), '?')) . ")
               AND p.contact_email IS NOT NULL AND p.contact_email != ''
               AND p.contacted_at IS NULL AND p.ignored_at IS NULL
               AND p.website_domain IS NOT NULL AND p.website_domain != ''
               AND NOT EXISTS (SELECT 1 FROM agencies a WHERE a.domain = p.website_domain)"
            . ($exclude ? ' AND p.id NOT IN (' . implode(',', $exclude) . ')' : '')
            . ' ORDER BY p.created_at DESC, p.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute($categories);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
