<?php

/**
 * The "For analysis" list: exactly the leads the background worker
 * (cron_analyze.php) analyzes next, newest first. One SQL condition serves the
 * worker and the dashboard (index.php?for_analysis=1, the sidebar link), so
 * what the page lists is what gets picked.
 */
final class ForAnalysis {
    /**
     * Targeted business types: the Business type typed in Find prospects, stored
     * as typed (matched without regard to upper/lower case).
     */
    public const CATEGORIES = ['web development agency', 'digital marketing agency', 'seo agency'];

    /** Dashboard parameter that switches index.php to this list. */
    public const PARAM = 'for_analysis';

    public static function dashboardUrl(): string {
        return 'index.php?' . http_build_query([self::PARAM => 1, 'sort' => 'created', 'dir' => 'desc', 'per_page' => 25]);
    }

    /**
     * The worker's rules as one condition on prospects `p`, with its named
     * parameters: a targeted type, an email address, not contacted or ignored,
     * a website — and no agency for that website yet in any state (queued
     * agencies are processed separately, and failed ones aren't retried).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function condition(): array {
        $placeholders = [];
        $params = [];
        foreach (self::CATEGORIES as $i => $category) {
            $placeholders[] = ":fa_cat$i";
            $params["fa_cat$i"] = $category;
        }
        $sql = '(p.category IN (' . implode(', ', $placeholders) . ")
            AND p.contact_email IS NOT NULL AND p.contact_email != ''
            AND p.contacted_at IS NULL AND p.ignored_at IS NULL
            AND p.website_domain IS NOT NULL AND p.website_domain != ''
            AND NOT EXISTS (SELECT 1 FROM agencies a WHERE a.domain = p.website_domain))";
        return [$sql, $params];
    }

    /**
     * The next prospects on the list, in the dashboard's default order (newest first).
     *
     * @param int[] $exclude prospect IDs to leave out
     * @return int[]
     */
    public static function nextProspectIds(PDO $pdo, int $limit, array $exclude = []): array {
        [$where, $params] = self::condition();
        $exclude = array_map('intval', $exclude);
        $stmt = $pdo->prepare(
            "SELECT p.id FROM prospects p WHERE $where"
            . ($exclude ? ' AND p.id NOT IN (' . implode(',', $exclude) . ')' : '')
            . ' ORDER BY p.created_at DESC, p.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function count(PDO $pdo): int {
        [$where, $params] = self::condition();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM prospects p WHERE $where");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
