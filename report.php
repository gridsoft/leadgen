<?php
/**
 * Counts by outreach status and reason, plus today's API usage against the
 * daily caps. The "suggested blocklist" section (domains flagged by the
 * Module A5 chain/aggregator guard) lands here once Module A exists
 * (Phase 3) — nothing to report on yet in Phase 1.
 *
 * Usage: php report.php [--db=leadgen]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("report.php is a command-line tool, run it as: php report.php\n");
}

require_once __DIR__ . '/config.php';

function arg_option(array $argv, string $name, $default) {
    foreach ($argv as $arg) {
        if (strpos($arg, "--$name=") === 0) {
            return substr($arg, strlen("--$name="));
        }
    }
    return $default;
}

$dbName = arg_option($argv, 'db', 'leadgen');

$c = app_config();
$pdo = new PDO(
    "mysql:host={$c['db_host']};port={$c['db_port']};dbname=$dbName;charset=utf8mb4",
    $c['db_user'],
    $c['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$total = (int) $pdo->query('SELECT COUNT(*) FROM prospects')->fetchColumn();
$enriched = (int) $pdo->query('SELECT COUNT(*) FROM prospects WHERE enriched_at IS NOT NULL')->fetchColumn();

echo "=== Leadgen enrichment report ($dbName) ===\n\n";
printf("%d total leads, %d enriched (%d not yet processed)\n\n", $total, $enriched, $total - $enriched);

echo "By outreach_status:\n";
$byStatus = $pdo->query(
    "SELECT COALESCE(outreach_status, '(not enriched)') AS status, COUNT(*) AS n
     FROM prospects GROUP BY outreach_status ORDER BY n DESC"
)->fetchAll();
foreach ($byStatus as $row) {
    printf("  %-16s %d\n", $row['status'], $row['n']);
}

echo "\nBy status_reason (enriched leads only):\n";
$byReason = $pdo->query(
    "SELECT COALESCE(status_reason, '(none)') AS reason, COUNT(*) AS n
     FROM prospects WHERE enriched_at IS NOT NULL GROUP BY status_reason ORDER BY n DESC"
)->fetchAll();
foreach ($byReason as $row) {
    printf("  %-20s %d\n", $row['reason'], $row['n']);
}

echo "\nAPI usage today:\n";
$usage = $pdo->prepare('SELECT provider, request_count FROM api_usage WHERE usage_date = CURDATE()');
$usage->execute();
$usageRows = $usage->fetchAll();
if (!$usageRows) {
    echo "  (none recorded)\n";
} else {
    foreach ($usageRows as $row) {
        printf("  %-20s %d\n", $row['provider'], $row['request_count']);
    }
}

echo "\nSuggested blocklist domains: none yet — this section fills in once Module A (website discovery) ships.\n";
