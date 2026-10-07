<?php
/**
 * Bulk analysis — runs the full per-prospect pipeline (site scan +
 * PageSpeed + opportunity scoring) across every eligible prospect with a
 * website, instead of clicking "Analyze" one at a time. Built as a CLI
 * script like batch_search.php, for the same reason: each prospect means
 * a site fetch plus two PageSpeed calls (mobile + desktop), and doing
 * that for hundreds of prospects takes real minutes, not something a
 * browser request or Apache should sit through.
 *
 * Usage:
 *   php batch_analyze.php [options]
 *
 * Options:
 *   --limit=100                 max prospects to process this run (default 100; use a smaller
 *                                number to test, or a large one to just let it run)
 *   --category=dentist          only analyze prospects with this exact category
 *   --status=phone_only         only analyze prospects with this contact_status
 *   --refresh-after-days=N      also re-analyze prospects last analyzed more than N days ago
 *                                (default: only prospects that have NEVER been analyzed)
 *   --pause=3                   seconds between prospects (default 3 — be a reasonable
 *                                citizen toward both the target sites and the PageSpeed API)
 *   --db=leadgen                override target database (e.g. leadgen_test for a dry run)
 *   --force                     re-analyze everything matched, ignoring refresh-after-days entirely
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("batch_analyze.php is a command-line tool, run it as: php batch_analyze.php [options]\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Analyzer.php';

function arg_option(array $argv, string $name, $default) {
    foreach ($argv as $arg) {
        if (strpos($arg, "--$name=") === 0) {
            return substr($arg, strlen("--$name="));
        }
    }
    return $default;
}

$limit = max(1, (int) arg_option($argv, 'limit', 100));
$category = arg_option($argv, 'category', null);
$status = arg_option($argv, 'status', null);
$refreshAfterDaysRaw = arg_option($argv, 'refresh-after-days', null);
$refreshAfterDays = $refreshAfterDaysRaw !== null ? (int) $refreshAfterDaysRaw : null;
$pause = max(0, (int) arg_option($argv, 'pause', 3));
$dbName = arg_option($argv, 'db', 'leadgen');
$force = in_array('--force', $argv, true);

$c = app_config();
$pdo = new PDO(
    "mysql:host={$c['db_host']};port={$c['db_port']};dbname=$dbName;charset=utf8mb4",
    $c['db_user'],
    $c['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$sql = "SELECT p.*, latest.last_analyzed
     FROM prospects p
     LEFT JOIN (
         SELECT prospect_id, MAX(analyzed_at) AS last_analyzed FROM analyses GROUP BY prospect_id
     ) latest ON latest.prospect_id = p.id
     WHERE p.website IS NOT NULL AND p.website != ''";
$params = [];

if (!$force) {
    if ($refreshAfterDays !== null) {
        $sql .= ' AND (latest.last_analyzed IS NULL OR latest.last_analyzed < DATE_SUB(NOW(), INTERVAL :days DAY))';
        $params['days'] = $refreshAfterDays;
    } else {
        $sql .= ' AND latest.last_analyzed IS NULL';
    }
}
if ($category !== null) {
    $sql .= ' AND p.category = :category';
    $params['category'] = $category;
}
if ($status !== null) {
    $sql .= ' AND p.contact_status = :status';
    $params['status'] = $status;
}
$sql .= ' ORDER BY p.id LIMIT ' . (int) $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$prospects = $stmt->fetchAll();

if (!$prospects) {
    echo "No prospects match — nothing to analyze. (Everything matching your filters may already be analyzed; use --refresh-after-days or --force to re-check.)\n";
    exit(0);
}

printf(
    "Batch analyze: %d prospect(s), db=%s%s%s%s\n\n",
    count($prospects), $dbName,
    $category !== null ? ", category=\"$category\"" : '',
    $status !== null ? ", status=$status" : '',
    $force ? ', --force' : ''
);

$counts = ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'errors' => 0];
$gapCounts = ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
$startTime = time();

foreach ($prospects as $i => $prospect) {
    $n = $i + 1;
    $label = "[$n/" . count($prospects) . "] {$prospect['business_name']}";
    try {
        $result = Analyzer::run($pdo, $prospect);
        if ($result['ok']) {
            $counts[$result['opportunity_level']]++;
            $gapCounts[$result['contact_gap_level']]++;
            $warningNote = $result['warnings'] ? ' (' . implode(' ', $result['warnings']) . ')' : '';
            echo "$label -> redesign:{$result['opportunity_level']} contact_gap:{$result['contact_gap_level']}$warningNote\n";
        } else {
            echo "$label -> skipped: " . implode(' ', $result['warnings']) . "\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "$label FAILED: {$e->getMessage()}\n");
        $counts['errors']++;
    }

    if ($n < count($prospects)) {
        sleep($pause);
    }
}

$elapsed = time() - $startTime;
printf(
    "\nDone in %dm%ds. Redesign opportunity — HIGH: %d, MEDIUM: %d, LOW: %d. Contact gap — HIGH: %d, MEDIUM: %d, LOW: %d. errors: %d.\n",
    intdiv($elapsed, 60), $elapsed % 60,
    $counts['HIGH'], $counts['MEDIUM'], $counts['LOW'],
    $gapCounts['HIGH'], $gapCounts['MEDIUM'], $gapCounts['LOW'],
    $counts['errors']
);
