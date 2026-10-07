<?php
/**
 * Runs each matched lead through the enrichment pipeline (Modules A/B/C —
 * see dev-prompt-website-discovery-email.md), giving every lead a final
 * outreach_status and status_reason. Phase 1 wires only what already
 * exists (dataset email, Step 2 site scan); leads that would need Module A
 * (website discovery) or Module B (pattern verification) land in
 * `needs_review` with a `pending_reason` identifying which — not a
 * fabricated terminal status. No lead reaches `email_ready` yet: that
 * requires a real `valid` verification, and no verifier is wired in until
 * Phase 2.
 *
 * Usage:
 *   php enrich.php [options]
 *
 * Options (spec-named where the spec gives an exact flag):
 *   --niche=roofing        only leads with this exact category
 *   --city=Austin          only leads with this exact city
 *   --region=TX            only leads with this exact region (not populated by any
 *                            source yet — reserved, matches spec's CLI contract)
 *   --limit=200             max leads to process this run (default 200)
 *   --refresh-after-days=N  also re-process leads enriched more than N days ago
 *                            (default: only leads never enriched)
 *   --force                 re-process everything matched, ignoring refresh-after-days
 *   --dry-run               run the full pipeline but roll back all writes — prints
 *                            what would happen without changing anything
 *   --pause=1                seconds between leads (site fetches only in this phase,
 *                            so lighter than batch_analyze.php's PageSpeed pacing)
 *   --db=leadgen             override target database (e.g. leadgen_test for a dry run)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("enrich.php is a command-line tool, run it as: php enrich.php [options]\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Enricher.php';

function arg_option(array $argv, string $name, $default) {
    foreach ($argv as $arg) {
        if (strpos($arg, "--$name=") === 0) {
            return substr($arg, strlen("--$name="));
        }
    }
    return $default;
}

$niche = arg_option($argv, 'niche', null);
$city = arg_option($argv, 'city', null);
$region = arg_option($argv, 'region', null);
$limit = max(1, (int) arg_option($argv, 'limit', 200));
$refreshAfterDaysRaw = arg_option($argv, 'refresh-after-days', null);
$refreshAfterDays = $refreshAfterDaysRaw !== null ? (int) $refreshAfterDaysRaw : null;
$pause = max(0, (int) arg_option($argv, 'pause', 1));
$dbName = arg_option($argv, 'db', 'leadgen');
$force = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

$c = app_config();
$pdo = new PDO(
    "mysql:host={$c['db_host']};port={$c['db_port']};dbname=$dbName;charset=utf8mb4",
    $c['db_user'],
    $c['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$sql = 'SELECT * FROM prospects WHERE 1=1';
$params = [];

if (!$force) {
    if ($refreshAfterDays !== null) {
        $sql .= ' AND (enriched_at IS NULL OR enriched_at < DATE_SUB(NOW(), INTERVAL :days DAY))';
        $params['days'] = $refreshAfterDays;
    } else {
        $sql .= ' AND enriched_at IS NULL';
    }
}
if ($niche !== null) {
    $sql .= ' AND category = :category';
    $params['category'] = $niche;
}
if ($city !== null) {
    $sql .= ' AND city = :city';
    $params['city'] = $city;
}
if ($region !== null) {
    $sql .= ' AND region = :region';
    $params['region'] = $region;
}
$sql .= ' ORDER BY id LIMIT ' . (int) $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$prospects = $stmt->fetchAll();

if (!$prospects) {
    echo "No leads match — nothing to enrich. (Everything matching your filters may already be enriched; use --refresh-after-days or --force to re-check.)\n";
    exit(0);
}

printf(
    "Enrich: %d lead(s), db=%s%s%s%s%s\n\n",
    count($prospects), $dbName,
    $niche !== null ? ", niche=\"$niche\"" : '',
    $city !== null ? ", city=\"$city\"" : '',
    $force ? ', --force' : '',
    $dryRun ? ', --dry-run (no writes will be kept)' : ''
);

$counts = [
    Classifier::STATUS_EMAIL_READY => 0,
    Classifier::STATUS_PHONE_ONLY => 0,
    Classifier::STATUS_NEEDS_REVIEW => 0,
    Classifier::STATUS_EXCLUDED => 0,
    'errors' => 0,
];
$startTime = time();

foreach ($prospects as $i => $prospect) {
    $n = $i + 1;
    $label = "[$n/" . count($prospects) . "] {$prospect['business_name']}";
    try {
        $pdo->beginTransaction();
        $result = Enricher::run($pdo, $prospect);
        if ($dryRun) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
        $counts[$result['status']]++;
        $reasonNote = $result['reason'] ? " ({$result['reason']})" : '';
        echo "$label -> {$result['status']}$reasonNote\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "$label FAILED: {$e->getMessage()}\n");
        $counts['errors']++;
    }

    if ($n < count($prospects)) {
        sleep($pause);
    }
}

$elapsed = time() - $startTime;
printf(
    "\nDone in %dm%ds. email_ready: %d, phone_only: %d, needs_review: %d, excluded: %d, errors: %d.\n",
    intdiv($elapsed, 60), $elapsed % 60,
    $counts[Classifier::STATUS_EMAIL_READY], $counts[Classifier::STATUS_PHONE_ONLY],
    $counts[Classifier::STATUS_NEEDS_REVIEW], $counts[Classifier::STATUS_EXCLUDED], $counts['errors']
);
