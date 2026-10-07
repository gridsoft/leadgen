<?php
require_once __DIR__ . '/AgencyStore.php';

/**
 * Numbers for stats.php. Leads are dashboard prospects plus agencies added by
 * URL (no prospect on that domain). Agency figures (analyzed, ready, sent,
 * replied) count each agency once, using the same rules as Ready to send
 * (AgencyStore::READY_SQL) and the Sent list.
 */
final class Stats {
    /** Pipeline stages, in order: every lead is in exactly one. */
    public const STAGES = [
        'no_email' => 'No email',
        'to_analyze' => 'To analyze',
        'not_fit' => 'Not a fit / failed',
        'ready' => 'Ready to send',
        'contacted' => 'Contacted',
        'replied' => 'Replied',
    ];

    public const BY_URL = 'Added by URL';

    /** The most advanced stage a lead has reached (SQL CASE); $email/$contacted/$ignored are prospect columns or NULL. */
    private static function stageSql(string $email, string $contacted, string $ignored): string {
        return "CASE
            WHEN a.status = 'replied' THEN 'replied'
            WHEN a.sent_at IS NOT NULL OR $contacted IS NOT NULL THEN 'contacted'
            WHEN a.id IS NOT NULL AND " . AgencyStore::READY_SQL . " THEN 'ready'
            WHEN a.analyzed_at IS NOT NULL OR a.status IN ('fetch_failed', 'ai_failed', 'not_interested', 'ignored') OR $ignored IS NOT NULL THEN 'not_fit'
            WHEN a.id IS NOT NULL OR ($email IS NOT NULL AND $email <> '') THEN 'to_analyze'
            ELSE 'no_email'
        END";
    }

    /**
     * One row per business type, most leads first, then the 'All' row.
     *
     * @return array<int, array{category: string, leads: int, with_email: int, analyzed: int, ready: int, sent: int, replied: int, stages: array<string,int>}>
     */
    public static function byCategory(PDO $pdo): array {
        $flags = 'a.id AS agency_id, (a.analyzed_at IS NOT NULL) AS analyzed, (a.id IS NOT NULL AND ' . AgencyStore::READY_SQL . ') AS ready,
                  (a.sent_at IS NOT NULL OR a.status = \'replied\') AS sent, (a.status = \'replied\') AS replied';
        $leads = "SELECT COALESCE(NULLIF(TRIM(p.category), ''), 'Uncategorized') AS category,
                         (p.contact_email IS NOT NULL AND p.contact_email <> '') AS has_email, $flags,
                         " . self::stageSql('p.contact_email', 'p.contacted_at', 'p.ignored_at') . ' AS stage
                  FROM prospects p
                  LEFT JOIN agencies a ON a.domain = p.website_domain ' . AgencyStore::LATEST_JOIN;
        $byUrl = "SELECT '" . self::BY_URL . "', (" . AgencyStore::TO_EMAIL_SQL . " IS NOT NULL), $flags,
                         " . self::stageSql('NULL', 'NULL', 'NULL') . '
                  FROM agencies a ' . AgencyStore::LATEST_JOIN . '
                  WHERE NOT EXISTS (SELECT 1 FROM prospects p WHERE p.website_domain = a.domain)';
        $stageSums = implode(', ', array_map(fn($s) => "SUM(stage = '$s') AS s_$s", array_keys(self::STAGES)));
        $rows = $pdo->query(
            "SELECT category, COUNT(*) AS leads, SUM(has_email) AS with_email,
                    COUNT(DISTINCT IF(analyzed, agency_id, NULL)) AS analyzed,
                    COUNT(DISTINCT IF(ready, agency_id, NULL)) AS ready,
                    COUNT(DISTINCT IF(sent, agency_id, NULL)) AS sent,
                    COUNT(DISTINCT IF(replied, agency_id, NULL)) AS replied, $stageSums
             FROM ($leads UNION ALL $byUrl) x
             GROUP BY category WITH ROLLUP"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $row = ['category' => $r['category'] ?? 'All'];
            foreach (['leads', 'with_email', 'analyzed', 'ready', 'sent', 'replied'] as $k) {
                $row[$k] = (int) $r[$k];
            }
            foreach (array_keys(self::STAGES) as $s) {
                $row['stages'][$s] = (int) $r["s_$s"];
            }
            $out[] = $row;
        }
        $all = array_pop($out) ?? ['category' => 'All', 'leads' => 0, 'with_email' => 0, 'analyzed' => 0, 'ready' => 0, 'sent' => 0, 'replied' => 0, 'stages' => array_fill_keys(array_keys(self::STAGES), 0)];
        usort($out, fn($x, $y) => $y['leads'] <=> $x['leads']);
        $out[] = $all;
        return $out;
    }

    /**
     * Daily counts for the last $days days (oldest first), by the database's own date.
     *
     * @return array<string, array{analyses: int, sent: int}> keyed Y-m-d
     */
    public static function daily(PDO $pdo, int $days = 30): array {
        $today = new DateTimeImmutable((string) $pdo->query('SELECT CURDATE()')->fetchColumn());
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[$today->modify("-$i day")->format('Y-m-d')] = ['analyses' => 0, 'sent' => 0];
        }
        $since = array_key_first($out);
        $count = function (string $sql) use ($pdo, $since): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['since' => $since]);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        };
        // Every finished AI run counts, re-analyses included; sends by the agency's sent date.
        foreach ($count('SELECT DATE(created_at), COUNT(*) FROM agency_analyses WHERE decision IS NOT NULL AND created_at >= :since GROUP BY 1') as $day => $n) {
            if (isset($out[$day])) {
                $out[$day]['analyses'] = (int) $n;
            }
        }
        foreach ($count('SELECT DATE(sent_at), COUNT(*) FROM agencies WHERE sent_at >= :since GROUP BY 1') as $day => $n) {
            if (isset($out[$day])) {
                $out[$day]['sent'] = (int) $n;
            }
        }
        return $out;
    }

    /** AI verdict of each agency's latest analysis: decision => [count, average score]. */
    public static function verdicts(PDO $pdo): array {
        $out = array_fill_keys(['SEND', 'SEND_LOW_PRIORITY', 'SKIP'], ['count' => 0, 'avg_score' => null]);
        foreach ($pdo->query('SELECT an.decision, COUNT(*) AS n, ROUND(AVG(an.score)) AS avg_score FROM agencies a ' . AgencyStore::LATEST_JOIN . ' WHERE an.decision IS NOT NULL GROUP BY an.decision') as $r) {
            $out[$r['decision']] = ['count' => (int) $r['n'], 'avg_score' => $r['avg_score'] !== null ? (int) $r['avg_score'] : null];
        }
        return $out;
    }

    /** Leads per source, most first. A lead found by several providers ("places, foursquare") counts for each. */
    public static function sources(PDO $pdo): array {
        $out = [];
        foreach ($pdo->query("SELECT source, COUNT(*) FROM prospects WHERE source IS NOT NULL AND source <> '' GROUP BY source")->fetchAll(PDO::FETCH_KEY_PAIR) as $list => $n) {
            foreach (array_map('trim', explode(',', $list)) as $s) {
                $out[$s] = ($out[$s] ?? 0) + (int) $n;
            }
        }
        arsort($out);
        return $out;
    }
}
