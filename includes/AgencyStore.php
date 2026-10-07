<?php
require_once __DIR__ . '/AgencyUrl.php';
require_once __DIR__ . '/AgencyScraper.php';
require_once __DIR__ . '/AgencyQualifier.php';
require_once __DIR__ . '/EmailDraft.php';
require_once __DIR__ . '/EmailHealth.php';

/**
 * Database side of Agency Outreach: the agencies table (one row per site,
 * unique by domain) and agency_analyses (one row per scrape + AI run, so
 * re-analysing keeps history; pages always show the latest row).
 *
 * Dates are computed with MySQL's NOW(), never PHP's clock — on this server
 * the two differ (see JobQueue).
 */
class AgencyStore {
    public const STATUSES = ['pending', 'analyzing', 'fetch_failed', 'ai_failed', 'analyzed', 'sent', 'replied', 'not_interested'];
    public const OUTREACH_STATUSES = ['sent', 'replied', 'not_interested'];
    public const FOLLOW_UP_DAYS = 7;
    /** An 'analyzing' row older than this was abandoned (tab closed, PHP killed) and may be claimed again. */
    private const STALE_MINUTES = 15;

    /** Columns of the latest analysis, joined onto agency rows for the list. */
    private const LATEST_JOIN = 'LEFT JOIN agency_analyses an ON an.id = (SELECT MAX(id) FROM agency_analyses WHERE agency_id = a.id)';

    // The address an email would go to (needs a + LATEST_JOIN an): the AI's pick, else — when it chose
    // none, the verdict isn't SKIP and the site showed no email — the one saved in the lead list (same
    // rule as agency_view.php). An address that bounced (suppression_list) counts as none.
    private const LEAD_LIST_EMAIL_SQL = "(SELECT p.contact_email FROM prospects p WHERE p.website_domain = a.domain
        AND p.contact_email IS NOT NULL AND p.contact_email <> '' ORDER BY p.id LIMIT 1)";
    private const LIST_FALLBACK_SQL = "an.to_email IS NULL AND an.decision <> 'SKIP' AND an.emails_json = '[]'";
    private const CHOSEN_EMAIL_SQL = 'COALESCE(an.to_email, IF(' . self::LIST_FALLBACK_SQL . ', LOWER(' . self::LEAD_LIST_EMAIL_SQL . '), NULL))';
    private const TO_EMAIL_SQL = 'IF(' . self::CHOSEN_EMAIL_SQL . " IN (SELECT value FROM suppression_list WHERE type = 'email'), NULL, " . self::CHOSEN_EMAIL_SQL . ')';

    /**
     * Adds pasted URLs (one per line). Existing domains are not duplicated.
     *
     * @return array{added: int[], existing: int[], invalid: string[]}
     */
    public static function addUrls(PDO $pdo, string $pasted): array {
        $out = ['added' => [], 'existing' => [], 'invalid' => []];
        $find = $pdo->prepare('SELECT id FROM agencies WHERE domain = :domain');
        $insert = $pdo->prepare('INSERT INTO agencies (normalized_url, domain) VALUES (:url, :domain)');

        foreach (preg_split('/\R/', $pasted) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $url = AgencyUrl::normalize($line);
            if ($url === null) {
                $out['invalid'][] = $line;
                continue;
            }
            $domain = AgencyUrl::domain($url);
            $find->execute(['domain' => $domain]);
            $id = $find->fetchColumn();
            if ($id !== false) {
                if (!in_array((int) $id, $out['added'], true)) {
                    $out['existing'][] = (int) $id;
                }
                continue;
            }
            $insert->execute(['url' => $url, 'domain' => $domain]);
            $out['added'][] = (int) $pdo->lastInsertId();
        }
        $out['existing'] = array_values(array_unique($out['existing']));
        return $out;
    }

    /**
     * "Create outreach" from the dashboard: adds the selected prospects'
     * websites as agencies, named after the prospect. Prospects without a
     * usable website are reported back, not added.
     *
     * @param int[] $prospectIds
     * @return array{added: int[], existing: int[], invalid: string[]}
     */
    public static function addFromProspects(PDO $pdo, array $prospectIds): array {
        $out = ['added' => [], 'existing' => [], 'invalid' => []];
        $ids = array_values(array_unique(array_filter(array_map('intval', $prospectIds))));
        if (!$ids) {
            return $out;
        }
        $stmt = $pdo->prepare('SELECT id, business_name, website FROM prospects WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $find = $pdo->prepare('SELECT id FROM agencies WHERE domain = :domain');
        $insert = $pdo->prepare('INSERT INTO agencies (normalized_url, domain, agency_name) VALUES (:url, :domain, :name)');

        foreach ($stmt->fetchAll() as $p) {
            $url = $p['website'] ? AgencyUrl::normalize($p['website']) : null;
            if ($url === null) {
                $out['invalid'][] = $p['business_name'] . ' (no website)';
                continue;
            }
            $domain = AgencyUrl::domain($url);
            $find->execute(['domain' => $domain]);
            $id = $find->fetchColumn();
            if ($id !== false) {
                $out['existing'][] = (int) $id;
                continue;
            }
            // Outreach works from the site's homepage, whatever page the listing linked to.
            $home = 'https://' . parse_url($url, PHP_URL_HOST);
            $insert->execute(['url' => $home, 'domain' => $domain, 'name' => $p['business_name']]);
            $out['added'][] = (int) $pdo->lastInsertId();
        }
        $out['existing'] = array_values(array_unique($out['existing']));
        return $out;
    }

    /**
     * Agencies with their latest analysis, plus is_overdue (sent, no reply, follow-up date passed).
     *
     * Within one filter the values are ORed; different filters are ANDed.
     *
     * @param array{
     *   decision?: string[],   SEND / SEND_LOW_PRIORITY / SKIP, or 'none' for not analysed
     *   status?: string[],     agency statuses
     *   follow_up?: string[],  'due' (overdue) / 'upcoming'
     *   platform?: string[],   platform family: WordPress, Wix… or 'unknown'
     *   email?: string[],      'yes' (has an address to email) / 'no'
     *   overdue?: bool, q?: string, id?: int
     * } $filters
     */
    public static function listAgencies(PDO $pdo, array $filters, string $sort, string $dir): array {
        $sorts = [
            'score' => 'an.score',
            'name' => 'COALESCE(a.agency_name, a.domain)',
            'analyzed' => 'a.analyzed_at',
            'status' => 'a.status',
            'follow_up' => 'a.follow_up_at',
            'sent' => 'a.sent_at',
            'created' => 'a.created_at',
        ];
        $orderBy = $sorts[$sort] ?? $sorts['created'];
        $dir = $dir === 'asc' ? 'ASC' : 'DESC';

        $where = [];
        $params = [];
        $in = function (string $column, string $key, array $values) use (&$params): string {
            $ph = [];
            foreach (array_values($values) as $i => $v) {
                $ph[] = ":{$key}{$i}";
                $params["{$key}{$i}"] = $v;
            }
            return "$column IN (" . implode(', ', $ph) . ')';
        };
        if (!empty($filters['decision'])) {
            $real = array_values(array_diff($filters['decision'], ['none']));
            $parts = $real ? [$in('an.decision', 'decision', $real)] : [];
            if (in_array('none', $filters['decision'], true)) {
                $parts[] = 'an.decision IS NULL';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        if (!empty($filters['status'])) {
            $where[] = $in('a.status', 'status', $filters['status']);
        }
        if (!empty($filters['platform'])) {
            // Only analysed agencies have a platform; "unknown" means analysed but not recognised.
            $where[] = '(an.id IS NOT NULL AND ' . $in(self::PLATFORM_FAMILY_SQL, 'platform', $filters['platform']) . ')';
        }
        $followUp = [];
        if (!empty($filters['overdue']) || in_array('due', $filters['follow_up'] ?? [], true)) {
            $followUp[] = self::overdueSql();
        }
        if (in_array('upcoming', $filters['follow_up'] ?? [], true)) {
            $followUp[] = self::upcomingSql();
        }
        if ($followUp) {
            $where[] = '(' . implode(' OR ', $followUp) . ')';
        }
        if (trim($filters['q'] ?? '') !== '') {
            $where[] = '(a.agency_name LIKE :q1 OR a.domain LIKE :q2 OR an.to_email LIKE :q3 OR a.notes LIKE :q4)';
            foreach (['q1', 'q2', 'q3', 'q4'] as $k) {
                $params[$k] = '%' . trim($filters['q']) . '%';
            }
        }
        if (isset($filters['id'])) {
            $where[] = 'a.id = :id';
            $params['id'] = (int) $filters['id'];
        }

        // Both options ticked is the same as no email filter.
        if (($filters['email'] ?? []) === ['yes']) {
            $where[] = self::TO_EMAIL_SQL . ' IS NOT NULL';
        } elseif (($filters['email'] ?? []) === ['no']) {
            $where[] = self::TO_EMAIL_SQL . ' IS NULL';
        }

        $fallback = self::LIST_FALLBACK_SQL;
        $leadListEmail = self::LEAD_LIST_EMAIL_SQL;
        $sql = "SELECT a.*, an.id AS analysis_id, an.decision, an.score, an.platform, an.warnings_json,
                       " . self::TO_EMAIL_SQL . " AS to_email,
                       IF(an.to_email_source = 'lead_list' OR ($fallback AND $leadListEmail IS NOT NULL), 1, 0) AS to_email_from_list,
                       (" . self::overdueSql() . ') AS is_overdue
                FROM agencies a ' . self::LATEST_JOIN
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            // NULL scores/dates sort last in either direction.
            . " ORDER BY ($orderBy IS NULL), $orderBy $dir, a.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getAgency(PDO $pdo, int $id): ?array {
        $stmt = $pdo->prepare('SELECT a.*, (' . self::overdueSql() . ') AS is_overdue FROM agencies a WHERE a.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Counts per option, for the filter dropdowns. */
    public static function counts(PDO $pdo): array {
        return [
            'status' => $pdo->query('SELECT status, COUNT(*) FROM agencies GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR),
            'decision' => $pdo->query("SELECT COALESCE(an.decision, 'none'), COUNT(*) FROM agencies a " . self::LATEST_JOIN . ' GROUP BY 1')->fetchAll(PDO::FETCH_KEY_PAIR),
            'platform' => $pdo->query('SELECT ' . self::PLATFORM_FAMILY_SQL . ' AS fam, COUNT(*) FROM agencies a ' . self::LATEST_JOIN . ' WHERE an.id IS NOT NULL GROUP BY fam ORDER BY COUNT(*) DESC')->fetchAll(PDO::FETCH_KEY_PAIR),
            'email' => $pdo->query('SELECT IF(' . self::TO_EMAIL_SQL . " IS NULL, 'no', 'yes'), COUNT(*) FROM agencies a " . self::LATEST_JOIN . ' GROUP BY 1')->fetchAll(PDO::FETCH_KEY_PAIR),
            'overdue' => (int) $pdo->query('SELECT COUNT(*) FROM agencies a WHERE ' . self::overdueSql())->fetchColumn(),
            'upcoming' => (int) $pdo->query('SELECT COUNT(*) FROM agencies a WHERE ' . self::upcomingSql())->fetchColumn(),
            'total' => (int) $pdo->query('SELECT COUNT(*) FROM agencies')->fetchColumn(),
        ];
    }

    /**
     * Agencies ready to email: analyzed, not contacted yet, latest verdict SEND, with an address to
     * send to. The sidebar's "Ready to send" link lists the same agencies.
     */
    public static function readyToSendCount(PDO $pdo): int {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM agencies a ' . self::LATEST_JOIN
            . " WHERE a.status = 'analyzed' AND an.decision = 'SEND' AND " . self::TO_EMAIL_SQL . ' IS NOT NULL'
        )->fetchColumn();
    }

    /** "WordPress 7.0.6" → "WordPress"; no analysis or no platform → "unknown". */
    private const PLATFORM_FAMILY_SQL = "SUBSTRING_INDEX(COALESCE(NULLIF(an.platform, ''), 'unknown'), ' ', 1)";

    private static function upcomingSql(): string {
        return "(a.status = 'sent' AND a.replied_at IS NULL AND a.follow_up_at > NOW())";
    }

    /** All runs for one agency, newest first. */
    public static function analyses(PDO $pdo, int $agencyId): array {
        $stmt = $pdo->prepare('SELECT * FROM agency_analyses WHERE agency_id = :id ORDER BY id DESC');
        $stmt->execute(['id' => $agencyId]);
        return $stmt->fetchAll();
    }

    /** IDs waiting to be processed, including abandoned 'analyzing' rows. Oldest first. */
    public static function pendingIds(PDO $pdo): array {
        return array_map('intval', $pdo->query(
            "SELECT id FROM agencies WHERE status = 'pending'
                OR (status = 'analyzing' AND updated_at < NOW() - INTERVAL " . self::STALE_MINUTES . ' MINUTE)
             ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Marks one agency 'analyzing' if it's waiting. False if another request already has it. */
    public static function claim(PDO $pdo, int $id): bool {
        // updated_at is set explicitly: re-claiming an abandoned 'analyzing' row
        // otherwise changes nothing, and MySQL then reports 0 affected rows.
        $stmt = $pdo->prepare(
            "UPDATE agencies SET status = 'analyzing', last_error = NULL, updated_at = NOW() WHERE id = :id AND (status = 'pending'
                OR (status = 'analyzing' AND updated_at < NOW() - INTERVAL " . self::STALE_MINUTES . ' MINUTE))'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Scrapes the site, calls the AI and stores a new analysis row. The
     * agency must already be claimed. Every failure is recorded on the row —
     * this never throws for a bad site or a bad AI reply, so a batch keeps going.
     * The one exception: with $waitForQuota, a used-up daily AI quota puts the
     * agency back to 'pending' (nothing recorded) and throws AiQuotaExhausted.
     *
     * @return string the agency's new status
     */
    public static function process(PDO $pdo, int $id, AgencyScraper $scraper, callable $makeQualifier, bool $waitForQuota = false): string {
        $agency = self::getAgency($pdo, $id);
        if (!$agency) {
            return 'missing';
        }

        $scrape = $scraper->scrape($agency['normalized_url']);
        $scrape['known_emails'] = array_column(self::knownEmails($pdo, $agency['domain']), 'email');
        $siteEmails = $scrape['emails']; // stored as found; the AI only sees addresses not known to bounce
        $usable = fn(array $list) => array_values(array_filter($list, fn($e) => !EmailHealth::isBlocked($pdo, $e)));
        $scrape['emails'] = $usable($scrape['emails']);
        $scrape['known_emails'] = $usable($scrape['known_emails']);
        $row = [
            'pages_json' => json_encode(array_map(fn($p) => $p['url'], $scrape['pages']), JSON_UNESCAPED_SLASHES),
            'emails_json' => json_encode($siteEmails),
            'known_emails_json' => json_encode($scrape['known_emails']),
            'phones_json' => json_encode($scrape['phones'], JSON_UNESCAPED_UNICODE),
            'platform' => $scrape['platform'],
            'warnings_json' => json_encode($scrape['warnings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        if (!$scrape['ok']) {
            self::insertAnalysis($pdo, $id, $row + ['error' => $scrape['error']]);
            return self::finish($pdo, $agency, 'fetch_failed', $scrape['error'], null);
        }

        try {
            /** @var AgencyQualifier $qualifier */
            $qualifier = $makeQualifier();
            $ai = $qualifier->analyze($agency['normalized_url'], $scrape);
        } catch (AiQuotaExhausted $e) {
            if ($waitForQuota) {
                $pdo->prepare("UPDATE agencies SET status = 'pending' WHERE id = :id")->execute(['id' => $id]);
                throw $e;
            }
            $ai = ['ok' => false, 'data' => null, 'error' => $e->getMessage(), 'raw' => '', 'model' => null, 'input_tokens' => 0, 'output_tokens' => 0];
        } catch (Throwable $e) {
            $ai = ['ok' => false, 'data' => null, 'error' => $e->getMessage(), 'raw' => '', 'model' => null, 'input_tokens' => 0, 'output_tokens' => 0];
        }

        $row += [
            'raw_response' => $ai['raw'] !== '' ? $ai['raw'] : null,
            'model' => $ai['model'],
            'input_tokens' => $ai['input_tokens'],
            'output_tokens' => $ai['output_tokens'],
            'error' => $ai['error'],
        ];
        if (!$ai['ok']) {
            self::insertAnalysis($pdo, $id, $row);
            return self::finish($pdo, $agency, 'ai_failed', $ai['error'], null);
        }

        $d = $ai['data'];
        self::insertAnalysis($pdo, $id, $row + [
            'decision' => $d['decision'],
            'score' => $d['score'],
            'reasons_json' => json_encode($d['reasons'], JSON_UNESCAPED_UNICODE),
            'red_flags_json' => json_encode($d['red_flags'], JSON_UNESCAPED_UNICODE),
            'contact_name' => $d['contact_name'],
            'contact_role' => $d['contact_role'],
            'to_email' => $d['to_email'],
            'to_email_source' => $d['to_email_source'] ?? null,
            'other_channel' => $d['other_channel'],
            'ref_slug' => $d['ref_slug'],
            'subject' => $d['subject'],
            // Portfolio link always in, sign-off is just the name (EmailDraft). raw_response keeps the AI's exact text.
            'body' => $d['body'] !== null
                ? EmailDraft::finalize($d['body'], EmailDraft::refSlug($d['ref_slug'], $d['agency_name'] ?? $agency['agency_name'], $agency['domain']))
                : null,
            'follow_up_tip' => $d['follow_up_tip'],
        ]);
        return self::finish($pdo, $agency, 'analyzed', null, $d['agency_name']);
    }

    /**
     * Emails the user already has for this website in the lead list: the
     * dashboard's contact email and any "Check with AI" findings, for every
     * prospect on the same domain.
     *
     * @return array<int, array{email: string, source: string, prospect: string}>
     */
    public static function knownEmails(PDO $pdo, string $domain): array {
        $stmt = $pdo->prepare(
            "SELECT p.business_name, p.contact_email AS email, COALESCE(p.email_source, 'lead import') AS source
               FROM prospects p WHERE p.website_domain = :d1 AND p.contact_email IS NOT NULL AND p.contact_email <> ''
             UNION ALL
             SELECT p.business_name, c.email, 'AI check' AS source
               FROM ai_checks c JOIN prospects p ON p.id = c.prospect_id
              WHERE p.website_domain = :d2 AND c.email IS NOT NULL AND c.email <> ''"
        );
        $stmt->execute(['d1' => $domain, 'd2' => $domain]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $email = strtolower(trim($row['email']));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($out[$email])) {
                $out[$email] = ['email' => $email, 'source' => $row['source'], 'prospect' => $row['business_name']];
            }
        }
        return array_values($out);
    }

    /** Queues a fresh run; an agency already contacted keeps its outreach status afterwards. */
    public static function queueReanalysis(PDO $pdo, int $id): void {
        $pdo->prepare(
            "UPDATE agencies SET resume_status = IF(status IN ('sent', 'replied', 'not_interested'), status, resume_status),
                status = 'pending', last_error = NULL
             WHERE id = :id AND status <> 'analyzing'"
        )->execute(['id' => $id]);
    }

    /**
     * Abandons a waiting or running analysis: back to the outreach status it
     * had, else 'analyzed' if it has a result, else 'ai_failed' so it can be
     * re-run by hand. A request still running on the server may finish later
     * and save its result anyway.
     */
    public static function stopAnalysis(PDO $pdo, int $id): void {
        $pdo->prepare(
            "UPDATE agencies SET status = COALESCE(resume_status, IF(analyzed_at IS NOT NULL, 'analyzed', 'ai_failed')),
                resume_status = NULL, last_error = 'Analysis stopped before it finished.'
             WHERE id = :id AND status IN ('pending', 'analyzing')"
        )->execute(['id' => $id]);
    }

    /*
     * The outreach flow is: pick a business on the dashboard → analyse it here →
     * send the email → it counts as "Reached out" on the dashboard. So the agency's
     * sent/replied state and the dashboard's contacted_at (prospects on the same
     * domain) are kept in step, in both directions.
     */

    /** contact_note written when this feature marks a prospect reached out — so undo only clears its own mark. */
    public const AUTO_CONTACT_NOTE = 'Outreach email sent (Agency outreach)';

    public static function markSent(PDO $pdo, int $id): void {
        $pdo->prepare(
            "UPDATE agencies SET status = 'sent', sent_at = NOW(),
                follow_up_at = NOW() + INTERVAL " . self::FOLLOW_UP_DAYS . ' DAY, replied_at = NULL WHERE id = :id'
        )->execute(['id' => $id]);
        self::markProspectsReachedOut($pdo, $id);
    }

    public static function markReplied(PDO $pdo, int $id): void {
        $pdo->prepare("UPDATE agencies SET status = 'replied', replied_at = NOW() WHERE id = :id")->execute(['id' => $id]);
        self::markProspectsReachedOut($pdo, $id); // a reply means they were contacted
    }

    public static function markNotInterested(PDO $pdo, int $id): void {
        $pdo->prepare("UPDATE agencies SET status = 'not_interested' WHERE id = :id")->execute(['id' => $id]);
    }

    /**
     * The email bounced, so the agency was never actually reached: the address is
     * blocked for good (EmailHealth), the agency goes back to "not sent" and the
     * business to "Not reached out" — and the bounce is noted on the agency.
     */
    public static function markBounced(PDO $pdo, int $id, string $email): void {
        EmailHealth::recordBounce($pdo, $email);
        self::resetOutreach($pdo, $id);
        $pdo->prepare(
            "UPDATE agencies SET notes = TRIM(CONCAT(:line, IFNULL(CONCAT('\n', notes), ''))) WHERE id = :id"
        )->execute(['line' => '[' . date('M j') . '] Email to ' . strtolower(trim($email)) . ' bounced (address does not exist).', 'id' => $id]);
    }

    /** Undo for the outreach buttons: back to a plain analyzed agency, and not reached out on the dashboard. */
    public static function resetOutreach(PDO $pdo, int $id): void {
        $pdo->prepare(
            "UPDATE agencies SET status = 'analyzed', sent_at = NULL, follow_up_at = NULL, replied_at = NULL
             WHERE id = :id AND status IN ('sent', 'replied', 'not_interested')"
        )->execute(['id' => $id]);
        // Only clears the mark this feature set; a reached-out mark the user entered by hand stays.
        $pdo->prepare(
            'UPDATE prospects p JOIN agencies a ON a.domain = p.website_domain
                SET p.contacted_at = NULL, p.contact_note = NULL
              WHERE a.id = :id AND p.contact_note = :note'
        )->execute(['id' => $id, 'note' => self::AUTO_CONTACT_NOTE]);
    }

    private static function markProspectsReachedOut(PDO $pdo, int $agencyId): void {
        $pdo->prepare(
            'UPDATE prospects p JOIN agencies a ON a.domain = p.website_domain
                SET p.contact_note = IF(p.contacted_at IS NULL, :note, p.contact_note),
                    p.contacted_at = COALESCE(p.contacted_at, NOW()),
                    p.ignored_at = NULL
              WHERE a.id = :id'
        )->execute(['id' => $agencyId, 'note' => self::AUTO_CONTACT_NOTE]);
    }

    /**
     * The other direction, for the dashboard's "Reached out" / undo buttons
     * (mark_contacted.php): an analysed agency on the same domain becomes
     * sent (with its follow-up date), or goes back to analysed.
     */
    public static function syncFromProspect(PDO $pdo, int $prospectId, bool $reachedOut): void {
        if ($reachedOut) {
            $pdo->prepare(
                "UPDATE agencies a JOIN prospects p ON a.domain = p.website_domain
                    SET a.status = 'sent', a.sent_at = COALESCE(p.contacted_at, NOW()),
                        a.follow_up_at = COALESCE(p.contacted_at, NOW()) + INTERVAL " . self::FOLLOW_UP_DAYS . " DAY
                  WHERE p.id = :id AND a.status = 'analyzed'"
            )->execute(['id' => $prospectId]);
        } else {
            $pdo->prepare(
                "UPDATE agencies a JOIN prospects p ON a.domain = p.website_domain
                    SET a.status = 'analyzed', a.sent_at = NULL, a.follow_up_at = NULL
                  WHERE p.id = :id AND a.status = 'sent'"
            )->execute(['id' => $prospectId]);
        }
    }

    public static function saveNotes(PDO $pdo, int $id, string $notes): void {
        $pdo->prepare('UPDATE agencies SET notes = :notes WHERE id = :id')
            ->execute(['notes' => trim($notes) !== '' ? $notes : null, 'id' => $id]);
    }

    /**
     * Stores the user's edits next to the AI's original. Text identical to
     * the original is stored as "no edit" (NULL).
     */
    public static function saveEmailEdits(PDO $pdo, int $analysisId, string $subject, string $body): void {
        $stmt = $pdo->prepare('SELECT subject, body FROM agency_analyses WHERE id = :id');
        $stmt->execute(['id' => $analysisId]);
        $orig = $stmt->fetch();
        if (!$orig) {
            return;
        }
        $norm = fn($s) => trim(str_replace("\r\n", "\n", (string) $s));
        $pdo->prepare('UPDATE agency_analyses SET edited_subject = :s, edited_body = :b WHERE id = :id')->execute([
            's' => $norm($subject) === $norm($orig['subject']) ? null : $norm($subject),
            'b' => $norm($body) === $norm($orig['body']) ? null : $norm($body),
            'id' => $analysisId,
        ]);
    }

    public static function resetEmailEdits(PDO $pdo, int $analysisId): void {
        $pdo->prepare('UPDATE agency_analyses SET edited_subject = NULL, edited_body = NULL WHERE id = :id')
            ->execute(['id' => $analysisId]);
    }

    private static function overdueSql(): string {
        return "(a.status = 'sent' AND a.replied_at IS NULL AND a.follow_up_at IS NOT NULL AND a.follow_up_at <= NOW())";
    }

    private static function insertAnalysis(PDO $pdo, int $agencyId, array $row): void {
        $row['agency_id'] = $agencyId;
        $cols = array_keys($row);
        $pdo->prepare(
            'INSERT INTO agency_analyses (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')'
        )->execute($row);
    }

    /** Final status for a run; a re-analysed agency that was already contacted goes back to its outreach status. */
    private static function finish(PDO $pdo, array $agency, string $status, ?string $error, ?string $name): string {
        $final = $agency['resume_status'] ?: $status;
        $pdo->prepare(
            'UPDATE agencies SET status = :status, resume_status = NULL, last_error = :error,
                agency_name = COALESCE(:name, agency_name), analyzed_at = IF(:ran, NOW(), analyzed_at)
             WHERE id = :id'
        )->execute([
            'status' => $final,
            'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
            'name' => $name,
            'ran' => $status === 'analyzed' ? 1 : 0,
            'id' => $agency['id'],
        ]);
        return $final;
    }
}
