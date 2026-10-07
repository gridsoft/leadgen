<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ContactStatus.php';

/**
 * "Check with AI": Claude Code (running on the user's own Claude login, no
 * API key) researches one prospect on the live web and recommends whether
 * it's worth contacting, plus any published email and where it was found.
 *
 * The web page can't run Claude Code itself — Apache runs as a Windows
 * service account without the user's login — so the page only queues jobs
 * (jobs table, type 'ai_check') and ai_worker.php, started by the user,
 * does the work and stores results in ai_checks.
 */
class AiChecker {
    public const JOB_TYPE = 'ai_check';
    private const WORKER_NAME = 'ai_worker';
    // Worker writes a heartbeat every few seconds while idle or between jobs.
    private const WORKER_STALE_SECONDS = 20;

    public const DEFAULT_CONTEXT =
        "I sell web development services and I'm reaching out to these businesses by cold email. "
        . "I want leads that are real, active, and actually based in the area I searched.";

    public const OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'verdict' => ['type' => 'string', 'enum' => ['worth_trying', 'maybe', 'skip']],
            'summary' => ['type' => 'string', 'description' => 'One or two sentences: what this business really is and why the verdict.'],
            'email' => ['type' => ['string', 'null'], 'description' => 'Best published contact email, or null if none found.'],
            'email_source_url' => ['type' => ['string', 'null'], 'description' => 'URL of the page where the email was published.'],
            'other_contacts' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Other useful contact routes: other emails, contact form URL, LinkedIn, phone.'],
            'based_in' => ['type' => 'string', 'description' => 'Where the business is actually based, e.g. "Chicago, IL" or "Ahmedabad, India (Chicago address is a virtual office)".'],
            'location_matches_search' => ['type' => 'boolean', 'description' => 'True if the business genuinely operates in the area it is listed under.'],
            'red_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'evidence' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Short facts with their source, e.g. "Team page lists 12 staff, all in Pune (site/about)".'],
        ],
        'required' => ['verdict', 'summary', 'email', 'email_source_url', 'other_contacts', 'based_in', 'location_matches_search', 'red_flags', 'evidence'],
        'additionalProperties' => false,
    ];

    public static function buildPrompt(array $prospect, string $outreachContext): string {
        $outreachContext = trim($outreachContext) !== '' ? trim($outreachContext) : self::DEFAULT_CONTEXT;
        $lead = json_encode([
            'business_name' => $prospect['business_name'],
            'category_searched' => $prospect['category'],
            'location_searched' => $prospect['city'],
            'website' => $prospect['website'],
            'address_on_google' => $prospect['address'],
            'phone_on_google' => $prospect['phone'],
            'google_review_count' => $prospect['review_count'],
            'email_we_already_have' => $prospect['contact_email'],
            'our_own_scan_result' => $prospect['email_scan_note'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<TXT
You're researching a sales lead for a small business owner doing cold outreach. The lead comes from a
Google Places search.

Owner's outreach goal:
$outreachContext

Lead:
$lead

Investigate the lead on the live web:
- Open the business's website (homepage, then contact / about / team pages as needed).
- Search the web when the site doesn't settle it: other listings, LinkedIn, Clutch, directories.

Work out:
1. A contact email address. Prefer one published by the business itself (site, footer, contact page,
   a PDF, a directory listing they control). Never invent or guess an address from a name pattern;
   if you can't find one published anywhere, return null and say where you looked.
2. Where the business is actually based and operates. Google Places listings are often virtual
   offices or lead-gen fronts, e.g. an "agency in Chicago" whose team, phone numbers and address are
   really in India, Pakistan, Eastern Europe, etc. Look at the about page, team bios, phone country
   codes, company registration and LinkedIn employee locations.
3. Anything else that changes whether outreach is worthwhile for the owner's goal: site down or
   parked, business closed or acquired, one-person shell, directory/aggregator rather than a real
   business, a franchise HQ, or too large to care (big enterprise).

Then recommend "worth_trying", "maybe", or "skip", judged against the owner's outreach goal.
Be concrete and brief. Base every claim on something you actually saw, and say when you're unsure.
TXT;
    }

    /**
     * Queues a check (or returns the already-queued one for this prospect).
     */
    public static function enqueue(PDO $pdo, int $prospectId, string $outreachContext): int {
        $existing = $pdo->prepare(
            "SELECT id FROM jobs WHERE type = :type AND status IN ('pending', 'running')
                AND CAST(JSON_EXTRACT(payload, '$.prospect_id') AS UNSIGNED) = :pid ORDER BY id DESC LIMIT 1"
        );
        $existing->execute(['type' => self::JOB_TYPE, 'pid' => $prospectId]);
        if ($id = $existing->fetchColumn()) {
            return (int) $id;
        }
        $pdo->prepare('INSERT INTO jobs (type, payload) VALUES (:type, :payload)')->execute([
            'type' => self::JOB_TYPE,
            'payload' => json_encode(['prospect_id' => $prospectId, 'context' => $outreachContext]),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param int[] $jobIds
     * @return array<int, array{status: string, error: ?string, prospect_id: int, ai: ?array}>
     */
    public static function jobStatuses(PDO $pdo, array $jobIds): array {
        $jobIds = array_values(array_unique(array_filter(array_map('intval', $jobIds))));
        if (!$jobIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
        $stmt = $pdo->prepare("SELECT id, status, payload, last_error FROM jobs WHERE id IN ($placeholders)");
        $stmt->execute($jobIds);
        $rows = $stmt->fetchAll();

        $prospectIds = array_map(fn($r) => (int) json_decode($r['payload'], true)['prospect_id'], $rows);
        $latest = self::latestFor($pdo, $prospectIds);

        $out = [];
        foreach ($rows as $r) {
            $pid = (int) json_decode($r['payload'], true)['prospect_id'];
            $out[(int) $r['id']] = [
                'status' => $r['status'],
                'error' => $r['last_error'],
                'prospect_id' => $pid,
                'ai' => $r['status'] === 'done' ? ($latest[$pid] ?? null) : null,
            ];
        }
        return $out;
    }

    public static function workerHeartbeat(PDO $pdo, string $info): void {
        $pdo->prepare(
            'INSERT INTO workers (name, last_seen, info) VALUES (:name, NOW(), :info)
             ON DUPLICATE KEY UPDATE last_seen = NOW(), info = VALUES(info)'
        )->execute(['name' => self::WORKER_NAME, 'info' => $info]);
    }

    // Windows scheduled task (created by setup_ai_worker.bat) that runs
    // start_ai_worker.bat as the user, in their desktop session, with their
    // Claude login. The web server can't do that directly — it runs as a
    // service account — but it can trigger this task.
    public const WORKER_TASK = 'Leadgen AI worker';

    /**
     * @return array{ok: bool, message: string}
     */
    public static function startWorker(PDO $pdo): array {
        if (self::workerOnline($pdo)) {
            return ['ok' => true, 'message' => 'Already running'];
        }
        self::clearStopRequest($pdo);
        exec('schtasks /Run /TN ' . escapeshellarg(self::WORKER_TASK) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            return ['ok' => false, 'message' => 'Could not start the worker task. Run setup_ai_worker.bat once, then try again. ('
                . trim(implode(' ', $output)) . ')'];
        }
        return ['ok' => true, 'message' => 'Starting…'];
    }

    public static function requestStop(PDO $pdo): void {
        $pdo->prepare('UPDATE workers SET stop_requested = 1 WHERE name = :name')->execute(['name' => self::WORKER_NAME]);
    }

    public static function clearStopRequest(PDO $pdo): void {
        $pdo->prepare('UPDATE workers SET stop_requested = 0 WHERE name = :name')->execute(['name' => self::WORKER_NAME]);
    }

    public static function stopRequested(PDO $pdo): bool {
        $stmt = $pdo->prepare('SELECT stop_requested FROM workers WHERE name = :name');
        $stmt->execute(['name' => self::WORKER_NAME]);
        return (bool) $stmt->fetchColumn();
    }

    public static function markWorkerOffline(PDO $pdo): void {
        $pdo->prepare('UPDATE workers SET last_seen = NULL, stop_requested = 0 WHERE name = :name')->execute(['name' => self::WORKER_NAME]);
    }

    public static function workerOnline(PDO $pdo): bool {
        $stmt = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, last_seen, NOW()) FROM workers WHERE name = :name');
        $stmt->execute(['name' => self::WORKER_NAME]);
        $age = $stmt->fetchColumn();
        // false = no row yet; null = worker marked itself offline (last_seen NULL).
        return $age !== false && $age !== null && (int) $age <= self::WORKER_STALE_SECONDS;
    }

    /**
     * Stores a finished check. Fills the prospect's email only if it has
     * none, so an address the user may already have reviewed is never replaced.
     */
    public static function saveResult(PDO $pdo, array $prospect, array $result, array $meta): void {
        $email = isset($result['email']) && filter_var($result['email'], FILTER_VALIDATE_EMAIL)
            ? strtolower($result['email']) : null;

        $emailSaved = false;
        if ($email !== null && empty($prospect['contact_email'])) {
            $pdo->prepare(
                "UPDATE prospects SET contact_email = :email, email_source = 'ai', email_verification = NULL,
                    email_confidence = NULL, contact_status = :status WHERE id = :id"
            )->execute([
                'id' => $prospect['id'],
                'email' => $email,
                'status' => ContactStatus::compute($prospect['website'], $prospect['phone'], $email),
            ]);
            $emailSaved = true;
        }

        $result['email_saved'] = $emailSaved;
        $pdo->prepare(
            'INSERT INTO ai_checks (prospect_id, verdict, summary, email, email_source_url, based_in,
                location_matches_search, result_json, model, duration_seconds, cost_usd)
             VALUES (:pid, :verdict, :summary, :email, :src, :based_in, :loc, :json, :model, :secs, :cost)'
        )->execute([
            'pid' => $prospect['id'],
            'verdict' => in_array($result['verdict'] ?? '', ['worth_trying', 'maybe', 'skip'], true) ? $result['verdict'] : 'maybe',
            'summary' => mb_substr((string) ($result['summary'] ?? ''), 0, 1000),
            'email' => $email,
            'src' => isset($result['email_source_url']) ? mb_substr((string) $result['email_source_url'], 0, 500) : null,
            'based_in' => mb_substr((string) ($result['based_in'] ?? ''), 0, 255),
            'loc' => !empty($result['location_matches_search']) ? 1 : 0,
            'json' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'model' => $meta['model'] ?? null,
            'secs' => $meta['duration_seconds'] ?? null,
            'cost' => $meta['cost_usd'] ?? null,
        ]);
    }

    /**
     * Most recent AI check per prospect, keyed by prospect id.
     *
     * @param int[] $prospectIds
     * @return array<int, array<string, mixed>>
     */
    public static function latestFor(PDO $pdo, array $prospectIds): array {
        $prospectIds = array_values(array_unique(array_filter(array_map('intval', $prospectIds))));
        if (!$prospectIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($prospectIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT c.* FROM ai_checks c
             INNER JOIN (SELECT prospect_id, MAX(id) AS max_id FROM ai_checks WHERE prospect_id IN ($placeholders) GROUP BY prospect_id) latest
                ON latest.max_id = c.id"
        );
        $stmt->execute($prospectIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $result = json_decode($row['result_json'], true) ?: [];
            $out[(int) $row['prospect_id']] = [
                'verdict' => $row['verdict'],
                'summary' => $row['summary'],
                'email' => $row['email'],
                'email_source_url' => $row['email_source_url'],
                'email_saved' => !empty($result['email_saved']),
                'based_in' => $row['based_in'],
                'location_matches_search' => (bool) $row['location_matches_search'],
                'other_contacts' => $result['other_contacts'] ?? [],
                'red_flags' => $result['red_flags'] ?? [],
                'evidence' => $result['evidence'] ?? [],
                'duration_seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
                'checked_at' => $row['created_at'],
            ];
        }
        return $out;
    }
}
