<?php
/**
 * Command-line version of the "Find emails" page (email_finder.php):
 * scans each lead's own website for email addresses and stores the best one.
 * See includes/EmailFinder.php for how the address is chosen.
 *
 * Usage:
 *   php find_emails.php [options]
 *
 * Options:
 *   --niche=...      only leads with this exact category
 *   --city=...       only leads with this exact city
 *   --limit=500      max leads this run (default 500)
 *   --rescan         also re-scan leads that already have an email
 *   --pause=0        seconds between leads
 *   --dry-run        print what would be stored without writing
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("find_emails.php is a command-line tool, run it as: php find_emails.php [options]\n");
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/EmailFinder.php';

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
$limit = max(1, (int) arg_option($argv, 'limit', 500));
$pause = max(0, (int) arg_option($argv, 'pause', 0));
$rescan = in_array('--rescan', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

$pdo = get_db();
$prospects = EmailFinder::candidates($pdo, $niche, $city, $rescan, $limit);

printf("Find emails: %d lead(s)%s\n\n", count($prospects), $dryRun ? ' (dry run, nothing written)' : '');

$finder = new EmailFinder();
$counts = ['found' => 0, 'none' => 0, 'failed' => 0];
$start = time();

foreach ($prospects as $i => $p) {
    $prefix = sprintf('[%d/%d] %s', $i + 1, count($prospects), $p['business_name']);
    try {
        $result = $finder->findForProspect($pdo, $p, $dryRun);
        $counts[$result['outcome']]++;
        if ($result['outcome'] === 'found') {
            echo "$prefix -> {$result['email']}" . ($result['others'] ? '  (also: ' . implode(', ', $result['others']) . ')' : '') . "\n";
        } else {
            echo "$prefix -> {$result['note']}\n";
        }
    } catch (Throwable $e) {
        $counts['failed']++;
        echo "$prefix -> error: {$e->getMessage()}\n";
    }
    if ($pause) {
        sleep($pause);
    }
}

$elapsed = time() - $start;
printf("\nDone in %dm%02ds. Emails found: %d, no email on site: %d, site failed: %d.\n",
    intdiv($elapsed, 60), $elapsed % 60, $counts['found'], $counts['none'], $counts['failed']);
