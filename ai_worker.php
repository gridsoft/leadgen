<?php
/**
 * AI worker for the "Check with AI" button on the Find emails page.
 *
 * Runs Claude Code headlessly (claude -p) on your own Claude login — no API
 * key — for each queued ai_check job, and stores the result. It has to run
 * in your own Windows session: the web server runs as a service account that
 * can't use your Claude login. Easiest: double-click start_ai_worker.bat.
 *
 * Usage:
 *   php ai_worker.php [options]
 *
 * Options:
 *   --chrome           research in your real Chrome browser (needs the Claude in Chrome
 *                      extension) instead of Claude Code's built-in web fetch/search
 *   --model=opus       Claude Code model alias or full name (default: your Claude Code default)
 *   --timeout=600      seconds before a single check is abandoned
 *   --once             process the queue once and exit instead of waiting for more
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("ai_worker.php is a command-line tool. Double-click start_ai_worker.bat to run it.\n");
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AiChecker.php';

function arg_option(array $argv, string $name, $default) {
    foreach ($argv as $arg) {
        if (strpos($arg, "--$name=") === 0) {
            return substr($arg, strlen("--$name="));
        }
    }
    return $default;
}

/**
 * The Claude Code executable: config 'claude_cli_path', else `claude` on
 * PATH, else the copy bundled with the VS Code extension (newest version).
 */
function find_claude_cli(): ?string {
    $configured = app_config()['claude_cli_path'] ?? '';
    if ($configured !== '' && is_file($configured)) {
        return $configured;
    }
    $onPath = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where claude 2>NUL' : 'command -v claude 2>/dev/null'));
    if ($onPath !== '') {
        return strtok($onPath, "\r\n");
    }
    $home = getenv('USERPROFILE') ?: getenv('HOME');
    $bundled = glob($home . '/.vscode/extensions/anthropic.claude-code-*/resources/native-binary/claude{.exe,}', GLOB_BRACE) ?: [];
    usort($bundled, 'version_compare');
    return $bundled ? end($bundled) : null;
}

/**
 * Runs one headless Claude Code session. The prompt goes in on stdin, so no
 * shell quoting is involved; the command is passed as an array (no shell).
 *
 * @return array{ok: bool, result?: array, meta?: array, error?: string}
 */
function run_claude(string $cli, string $prompt, bool $chrome, ?string $model, int $timeout, string $workDir, callable $tick): array {
    $cmd = [
        $cli, '-p',
        '--output-format', 'json',
        '--json-schema', json_encode(AiChecker::OUTPUT_SCHEMA),
        '--no-session-persistence',
    ];
    if ($chrome) {
        $cmd[] = '--chrome';
        $cmd[] = '--allowedTools';
        $cmd[] = 'mcp__claude-in-chrome';
    } else {
        $cmd[] = '--allowedTools';
        $cmd[] = 'WebFetch';
        $cmd[] = 'WebSearch';
    }
    if ($model) {
        $cmd[] = '--model';
        $cmd[] = $model;
    }

    // Output goes to temp files, not pipes: on Windows, pipe reads block
    // until the process exits (non-blocking mode is ignored), which would
    // stop the heartbeat ticks for the whole check.
    $outFile = tempnam($workDir, 'out');
    $errFile = tempnam($workDir, 'err');
    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['file', $outFile, 'w'],
        2 => ['file', $errFile, 'w'],
    ], $pipes, $workDir);
    if (!is_resource($proc)) {
        @unlink($outFile);
        @unlink($errFile);
        return ['ok' => false, 'error' => 'Could not start Claude Code'];
    }

    fwrite($pipes[0], $prompt);
    fclose($pipes[0]);

    $start = time();
    $lastTick = 0;
    $timedOut = false;
    while (proc_get_status($proc)['running']) {
        if (time() - $lastTick >= 5) {
            $tick();
            $lastTick = time();
        }
        if (time() - $start > $timeout) {
            // Kill the whole process tree: claude.exe may have spawned helpers.
            $pid = proc_get_status($proc)['pid'];
            PHP_OS_FAMILY === 'Windows' ? exec("taskkill /F /T /PID $pid 2>NUL") : proc_terminate($proc);
            $timedOut = true;
            break;
        }
        usleep(250000);
    }
    proc_close($proc);
    $stdout = (string) file_get_contents($outFile);
    $stderr = (string) file_get_contents($errFile);
    @unlink($outFile);
    @unlink($errFile);
    if ($timedOut) {
        return ['ok' => false, 'error' => "Timed out after {$timeout}s"];
    }

    $out = json_decode($stdout, true);
    if (!is_array($out)) {
        $msg = trim($stderr !== '' ? $stderr : $stdout);
        return ['ok' => false, 'error' => 'Claude Code failed: ' . mb_substr($msg !== '' ? $msg : 'no output', 0, 500)];
    }
    if (!empty($out['is_error']) || !is_array($out['structured_output'] ?? null)) {
        $msg = $out['result'] ?? ($out['subtype'] ?? 'unknown error');
        return ['ok' => false, 'error' => 'Claude Code: ' . mb_substr((string) $msg, 0, 500)];
    }

    $models = array_keys($out['modelUsage'] ?? []);
    return [
        'ok' => true,
        'result' => $out['structured_output'],
        'meta' => [
            'model' => $models ? end($models) : null,
            'duration_seconds' => (int) round(($out['duration_ms'] ?? 0) / 1000),
            'cost_usd' => isset($out['total_cost_usd']) ? round((float) $out['total_cost_usd'], 4) : null,
        ],
    ];
}

$chrome = in_array('--chrome', $argv, true);
$model = arg_option($argv, 'model', null);
$timeout = max(60, (int) arg_option($argv, 'timeout', 600));
$once = in_array('--once', $argv, true);

$cli = find_claude_cli();
if ($cli === null) {
    fwrite(STDERR, "Couldn't find Claude Code. Install it, or set 'claude_cli_path' in config.local.php.\n");
    exit(1);
}

// Run Claude Code from an empty folder so it doesn't pick up this project's
// files or settings — it only needs the web.
$workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'leadgen-ai-worker';
if (!is_dir($workDir)) {
    mkdir($workDir, 0777, true);
}

$pdo = get_db();

// One worker at a time: the page's Start button can fire while one is already up.
if (AiChecker::workerOnline($pdo)) {
    echo "An AI worker is already running. Closing this one.\n";
    exit(0);
}
AiChecker::clearStopRequest($pdo);

// Jobs left 'running' by a worker that was closed mid-check go back in the queue.
$pdo->exec("UPDATE jobs SET status = 'pending' WHERE type = '" . AiChecker::JOB_TYPE . "' AND status = 'running'");

echo "AI worker ready (" . ($chrome ? 'Chrome browser' : 'Claude Code web tools') . ")\n";
echo "Claude Code: $cli\n";
echo "Leave this window open while you use \"Check with AI\". Stop it from the page, or close this window.\n\n";

$claim = $pdo->prepare("UPDATE jobs SET status = 'running', attempts = attempts + 1 WHERE id = :id AND status = 'pending'");
$finish = $pdo->prepare('UPDATE jobs SET status = :status, last_error = :error WHERE id = :id');
$getProspect = $pdo->prepare('SELECT * FROM prospects WHERE id = :id');

while (true) {
    // The page's Stop button: finish the current check (never mid-check), then exit.
    if (AiChecker::stopRequested($pdo)) {
        echo date('H:i:s') . "  Stopped from the page.\n";
        break;
    }
    AiChecker::workerHeartbeat($pdo, $chrome ? 'chrome' : 'web');

    $job = $pdo->query(
        "SELECT * FROM jobs WHERE type = '" . AiChecker::JOB_TYPE . "' AND status = 'pending' ORDER BY id LIMIT 1"
    )->fetch();

    if (!$job) {
        if ($once) {
            break;
        }
        sleep(3);
        continue;
    }

    $claim->execute(['id' => $job['id']]);
    if ($claim->rowCount() === 0) {
        continue; // another worker took it
    }

    $payload = json_decode($job['payload'], true);
    $getProspect->execute(['id' => (int) $payload['prospect_id']]);
    $prospect = $getProspect->fetch();
    if (!$prospect) {
        $finish->execute(['id' => $job['id'], 'status' => 'failed', 'error' => 'Prospect no longer exists']);
        continue;
    }

    echo date('H:i:s') . "  Checking {$prospect['business_name']} ... ";

    $prompt = AiChecker::buildPrompt($prospect, (string) ($payload['context'] ?? ''));
    // Keep the heartbeat fresh during a long check so the page doesn't
    // report the worker as offline.
    $res = run_claude($cli, $prompt, $chrome, $model, $timeout, $workDir, function () use ($pdo, $chrome) {
        AiChecker::workerHeartbeat($pdo, $chrome ? "chrome" : "web");
    });

    if ($res['ok']) {
        AiChecker::saveResult($pdo, $prospect, $res['result'], $res['meta']);
        $finish->execute(['id' => $job['id'], 'status' => 'done', 'error' => null]);
        echo "{$res['result']['verdict']}" . (!empty($res['result']['email']) ? "  ({$res['result']['email']})" : '') . "  [{$res['meta']['duration_seconds']}s]\n";
    } else {
        $finish->execute(['id' => $job['id'], 'status' => 'failed', 'error' => $res['error']]);
        echo "FAILED: {$res['error']}\n";
    }
}

AiChecker::markWorkerOffline($pdo);
