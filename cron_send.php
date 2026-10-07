<?php
/**
 * Automatic sending, run by a cron job every 5 minutes. Each run sends at most
 * one email, and only when AutoSender allows it: switched on in Agency outreach
 * → Settings, not paused, inside the sending window, under today's limit and
 * past the planned gap since the last email (includes/AutoSender.php).
 * Prints a line only when something happens, so the log stays short.
 *
 *   php cron_send.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line: php cron_send.php\n");
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AutoSender.php';

set_time_limit(0);

// One run at a time, so two ticks can never send at once.
$lock = fopen(sys_get_temp_dir() . '/leadgen_cron_send_' . md5(__DIR__) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$line = AutoSender::tick(get_db());
if ($line !== null) {
    echo date('Y-m-d H:i:s') . "  $line\n";
}
