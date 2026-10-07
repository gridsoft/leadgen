<?php
/**
 * Background agency analysis, run by a cron job on the host. Keeps a stock
 * of --pool agencies ready to email (AgencyStore::readyToSendCount) by
 * analyzing the next ones from the For analysis list (includes/ForAnalysis.php)
 * whenever the stock is short. Agencies already waiting (queued by hand, or
 * abandoned mid-run) are always finished first, whatever the stock.
 *
 * A run stops after --max agencies or --minutes, or when every AI model's
 * free daily quota is used up; later runs then do nothing until the quota
 * resets (Google resets it at midnight Pacific time).
 *
 *   php cron_analyze.php [--pool=40] [--max=5] [--minutes=10]
 *
 * Analyzing by hand in the browser keeps working alongside this.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line: php cron_analyze.php\n");
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AgencyStore.php';
require_once __DIR__ . '/includes/ForAnalysis.php';
require_once __DIR__ . '/includes/Settings.php';

set_time_limit(0);
$opts = getopt('', ['pool:', 'max:', 'minutes:']);
$pool = max(0, (int) ($opts['pool'] ?? 40));
$max = max(1, (int) ($opts['max'] ?? 5));
$deadline = time() + 60 * max(1, (int) ($opts['minutes'] ?? 10));

function out(string $line): void {
    echo date('Y-m-d H:i:s') . "  $line\n";
}

// One run at a time: a slow run must not overlap the next cron tick.
$lock = fopen(sys_get_temp_dir() . '/leadgen_cron_analyze_' . md5(__DIR__) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    out('The previous run is still going; skipping this one.');
    exit(0);
}

if (!has_ai_api_key()) {
    out("No AI API key configured ('ai_api_key' in config.local.php).");
    exit(1);
}

$pdo = get_db();
$resetAt = (int) Settings::get($pdo, 'ai_quota_reset_at', '0');
if ($resetAt > time()) {
    out('Free daily AI quota is used up; waiting until ' . date('Y-m-d H:i', $resetAt) . '.');
    exit(0);
}

/**
 * Next agency to analyze: one already waiting, else (when $topUp) a new one
 * from the For analysis list. Null when there's none.
 */
function next_agency(PDO $pdo, bool $topUp, array &$skipAgencies, array &$skipProspects): ?int {
    foreach (AgencyStore::pendingIds($pdo) as $id) {
        if (!in_array($id, $skipAgencies, true)) {
            return $id;
        }
    }
    if (!$topUp) {
        return null;
    }
    while ($prospectIds = ForAnalysis::nextProspectIds($pdo, 10, $skipProspects)) {
        foreach ($prospectIds as $prospectId) {
            $skipProspects[] = $prospectId;
            $added = AgencyStore::addFromProspects($pdo, [$prospectId])['added'];
            if ($added) {
                return $added[0];
            }
            // No usable website, or the site already has an agency under another spelling: leave it.
        }
    }
    return null;
}

$analyzed = 0;
$failed = 0;
$skipAgencies = [];
$skipProspects = [];
$stopped = 'nothing left to analyze';

while (true) {
    if ($analyzed + $failed >= $max) {
        $stopped = "reached the limit of $max per run";
        break;
    }
    if (time() >= $deadline) {
        $stopped = 'reached the time limit';
        break;
    }
    $ready = AgencyStore::readyToSendCount($pdo);
    $id = next_agency($pdo, $ready < $pool, $skipAgencies, $skipProspects);
    if ($id === null) {
        $stopped = $ready >= $pool ? "$ready ready to send, target is $pool" : 'nothing left to analyze';
        break;
    }
    $skipAgencies[] = $id;
    if (!AgencyStore::claim($pdo, $id)) {
        continue; // the browser started it a moment ago
    }
    $domain = AgencyStore::getAgency($pdo, $id)['domain'] ?? "#$id";
    $started = microtime(true);
    try {
        $status = AgencyStore::process($pdo, $id, new AgencyScraper(), function () {
            return new AgencyQualifier(AiClientFactory::fromConfig());
        }, true);
    } catch (AiQuotaExhausted $e) {
        $resetAt = (new DateTimeImmutable('tomorrow', new DateTimeZone('America/Los_Angeles')))->getTimestamp();
        Settings::set($pdo, 'ai_quota_reset_at', (string) $resetAt);
        out("#$id $domain: free daily AI quota used up, left in the queue.");
        $stopped = 'free daily AI quota used up until ' . date('Y-m-d H:i', $resetAt);
        break;
    }
    $status === 'analyzed' ? $analyzed++ : $failed++;
    $error = $status === 'analyzed' ? '' : ' — ' . (AgencyStore::getAgency($pdo, $id)['last_error'] ?? '');
    out(sprintf('#%d %s: %s in %ds%s', $id, $domain, $status, round(microtime(true) - $started), $error));
}

Settings::set($pdo, 'cron_analyze_last', json_encode([
    'at' => time(),
    'analyzed' => $analyzed,
    'failed' => $failed,
    'stopped' => $stopped,
    'pool' => $pool,
]));
out("Done: $analyzed analyzed, $failed failed; stopped: $stopped.");
