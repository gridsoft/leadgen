<?php
/**
 * Hosting diagnostics: PHP limits, outbound HTTP timings, stuck agencies, and
 * (on request) a timed scrape of one agency and a tiny AI call. For finding
 * out why something that works locally is slow or never finishes on the host.
 *
 *   diagnostics.php            — settings, network, stuck agencies
 *   diagnostics.php?id=41      — also scrape agency 41 (timed, nothing saved)
 *   diagnostics.php?ai=1       — also make one small AI call (timed)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AgencyStore.php';
require_once __DIR__ . '/includes/Settings.php';

set_time_limit(0);
$pdo = get_db();

function timed_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_NOBODY => false,
        CURLOPT_CAINFO => __DIR__ . '/includes/cacert.pem',
    ]);
    curl_exec($ch);
    $result = [
        'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'ip' => curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        'dns' => curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME),
        'connect' => curl_getinfo($ch, CURLINFO_CONNECT_TIME),
        'tls' => curl_getinfo($ch, CURLINFO_APPCONNECT_TIME),
        'total' => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
        'error' => curl_error($ch),
    ];
    return $result;
}

$limitBefore = ini_get('max_execution_time');
@set_time_limit(0);
$curl = curl_version();
$settings = [
    'Server software' => $_SERVER['SERVER_SOFTWARE'] ?? '?',
    'PHP / SAPI' => PHP_VERSION . ' / ' . PHP_SAPI,
    'max_execution_time (before → after set_time_limit(0))' => $limitBefore . ' → ' . ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit'),
    'Disabled functions that matter' => implode(', ', array_intersect(
        array_map('trim', explode(',', (string) ini_get('disable_functions'))),
        ['set_time_limit', 'ignore_user_abort', 'curl_exec', 'curl_multi_exec', 'sleep', 'usleep']
    )) ?: 'none',
    'LiteSpeed noabort / noconntimeout' => (getenv('noabort') ?: ($_SERVER['noabort'] ?? 'not set')) . ' / ' . (getenv('noconntimeout') ?: ($_SERVER['noconntimeout'] ?? 'not set')),
    'cURL / SSL / IPv6' => $curl['version'] . ' / ' . $curl['ssl_version'] . ' / ' . (($curl['features'] & CURL_VERSION_IPV6) ? 'yes' : 'no'),
    'Server time (PHP / MySQL)' => date('Y-m-d H:i:s') . ' / ' . $pdo->query('SELECT NOW()')->fetchColumn(),
    'IMAP (reading replies)' => function_exists('imap_open') ? 'available' : 'MISSING — replies and bounces can\'t be read',
    'Mailbox last checked' => (($t = (int) Settings::get($pdo, 'mailbox_synced_at', '0')) ? date('Y-m-d H:i', $t) : 'never')
        . ((($err = Settings::get($pdo, 'mailbox_last_error')) !== '') ? ' — last error: ' . $err : ''),
];

$ai = AiClientFactory::describeConfig();
$targets = [
    'Google (baseline)' => 'https://www.google.com/',
    'AI endpoint (' . $ai['provider'] . ')' => $ai['endpoint'],
    'dmmbs.com' => 'https://dmmbs.com/',
];
$agency = null;
if (isset($_GET['id'])) {
    $agency = AgencyStore::getAgency($pdo, (int) $_GET['id']);
    if ($agency) {
        $targets['Agency #' . $agency['id'] . ' homepage'] = $agency['normalized_url'];
    }
}
$network = [];
foreach ($targets as $label => $url) {
    $network[$label] = ['url' => $url] + timed_get($url);
}

$stuck = $pdo->query(
    "SELECT id, domain, status, updated_at, TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS minutes, last_error
     FROM agencies WHERE status IN ('pending', 'analyzing') ORDER BY updated_at DESC LIMIT 20"
)->fetchAll();
$failed = $pdo->query(
    "SELECT id, domain, status, updated_at, last_error
     FROM agencies WHERE status IN ('fetch_failed', 'ai_failed') ORDER BY updated_at DESC LIMIT 10"
)->fetchAll();

$scrape = null;
if ($agency) {
    $t = microtime(true);
    $result = (new AgencyScraper())->scrape($agency['normalized_url']);
    $scrape = [
        'seconds' => round(microtime(true) - $t, 1),
        'ok' => $result['ok'],
        'error' => $result['error'],
        'pages' => array_keys($result['pages']),
        'warnings' => $result['warnings'],
    ];
}

$aiTest = null;
if (!empty($_GET['ai'])) {
    $t = microtime(true);
    try {
        $reply = AiClientFactory::fromConfig()->complete(
            'You are a health check. Reply with JSON only.',
            'Reply with {"ok": true}.',
            ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']]
        );
        $aiTest = ['seconds' => round(microtime(true) - $t, 1), 'model' => $reply['model'], 'text' => $reply['text'], 'error' => null];
    } catch (Throwable $e) {
        $aiTest = ['seconds' => round(microtime(true) - $t, 1), 'model' => null, 'text' => null, 'error' => $e->getMessage()];
    }
}

$pageTitle = 'Diagnostics';
$activeNav = '';
require __DIR__ . '/includes/layout_header.php';
$h = fn($v) => htmlspecialchars((string) $v);
?>
<div class="card">
  <h2>Server</h2>
  <table>
    <?php foreach ($settings as $k => $v): ?><tr><th style="text-align:left"><?= $h($k) ?></th><td><?= $h($v) ?></td></tr><?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Outbound connections (seconds)</h2>
  <table>
    <tr><th style="text-align:left">Target</th><th>HTTP</th><th>IP</th><th>DNS</th><th>Connect</th><th>TLS</th><th>Total</th><th style="text-align:left">Error</th></tr>
    <?php foreach ($network as $label => $n): ?>
    <tr><td title="<?= $h($n['url']) ?>"><?= $h($label) ?></td><td><?= $h($n['status']) ?></td><td><?= $h($n['ip']) ?></td>
      <td><?= round($n['dns'], 2) ?></td><td><?= round($n['connect'], 2) ?></td><td><?= round($n['tls'], 2) ?></td><td><b><?= round($n['total'], 2) ?></b></td><td><?= $h($n['error']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if ($scrape): ?>
<div class="card">
  <h2>Scrape of agency #<?= (int) $agency['id'] ?> (<?= $h($agency['domain']) ?>) — nothing saved</h2>
  <p><b><?= $scrape['seconds'] ?> s</b>, <?= $scrape['ok'] ? 'ok' : 'failed: ' . $h($scrape['error']) ?>.
    Pages: <?= $h(implode(', ', $scrape['pages']) ?: 'none') ?>.
    <?= $scrape['warnings'] ? 'Warnings: ' . $h(implode('; ', $scrape['warnings'])) : '' ?></p>
</div>
<?php endif; ?>

<?php if ($aiTest): ?>
<div class="card">
  <h2>AI call (<?= $h($ai['provider']) ?>, <?= $h($ai['model']) ?>)</h2>
  <p><b><?= $aiTest['seconds'] ?> s</b> — <?= $aiTest['error'] ? 'failed: ' . $h($aiTest['error']) : 'ok, answered by ' . $h($aiTest['model']) . ': ' . $h($aiTest['text']) ?></p>
</div>
<?php endif; ?>

<div class="card">
  <h2>Agencies waiting or analyzing</h2>
  <?php if (!$stuck): ?><p>None.</p><?php else: ?>
  <table>
    <tr><th style="text-align:left">Agency</th><th>Status</th><th>Last change</th><th>Minutes ago</th><th style="text-align:left">Last error</th></tr>
    <?php foreach ($stuck as $r): ?>
    <tr><td><a href="agency_view.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?> <?= $h($r['domain']) ?></a></td><td><?= $h($r['status']) ?></td><td><?= $h($r['updated_at']) ?></td><td><?= (int) $r['minutes'] ?></td><td><?= $h($r['last_error']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Recent failures</h2>
  <?php if (!$failed): ?><p>None.</p><?php else: ?>
  <table>
    <?php foreach ($failed as $r): ?>
    <tr><td><a href="agency_view.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?> <?= $h($r['domain']) ?></a></td><td><?= $h($r['status']) ?></td><td><?= $h($r['updated_at']) ?></td><td><?= $h($r['last_error']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<p>
  <a class="btn btn-secondary" href="diagnostics.php?id=<?= (int) ($_GET['id'] ?? 41) ?>&amp;ai=1">Run full test (agency #<?= (int) ($_GET['id'] ?? 41) ?> scrape + AI call)</a>
</p>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
