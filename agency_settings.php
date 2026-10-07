<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AiClient.php';
require_once __DIR__ . '/includes/AgencyViews.php';
require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/MailSender.php';
require_once __DIR__ . '/includes/AutoSender.php';

$pdo = get_db();
$saved = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'auto_send') {
    $action = $_POST['action'] ?? '';
    if ($action === 'enable_tomorrow') {
        AutoSender::enable($pdo, AutoSender::now()->modify('+1 day')->format('Y-m-d'));
    } elseif ($action === 'enable') {
        AutoSender::enable($pdo);
    } elseif ($action === 'disable') {
        AutoSender::disable($pdo);
    } elseif ($action === 'resume') {
        AutoSender::resume($pdo);
    }
    header('Location: agency_settings.php?auto=' . urlencode($action) . '#auto-send');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gmail = trim((string) ($_POST['gmail_account'] ?? ''));
    if ($gmail !== '' && !filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'That Gmail address doesn\x27t look right.';
    } else {
        Settings::set($pdo, 'gmail_account', strtolower($gmail));
        $saved = 'Sending account saved.';
    }
}
$gmailAccount = $error ? trim((string) $_POST['gmail_account']) : Settings::get($pdo, 'gmail_account');
$ai = AiClientFactory::describeConfig();

$pageTitle = 'Agency outreach settings';
$activeNav = 'agencies';
$topbarActions = '<a class="btn btn-secondary" href="agency_outreach.php">&larr; Agency outreach</a>';
require __DIR__ . '/includes/layout_header.php';
?>

<?php if ($saved): ?><div class="notice notice-ok"><?= h($saved) ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

<?php
$autoOn = AutoSender::enabled($pdo);
$autoPaused = AutoSender::pausedReason($pdo);
$autoLimit = AutoSender::limit($pdo);
$autoWeek = AutoSender::recentBounces($pdo, 7);
$autoGrowth = AutoSender::nextGrowth($pdo);
$autoQueue = has_smtp_config() ? AutoSender::queue($pdo, 5) : [];
$autoNow = AutoSender::now();
$autoNext = AutoSender::nextAt($pdo);
$autoUsage = has_smtp_config() ? MailSender::usage($pdo) : null;
$autoWindow = sprintf('Monday–Friday %02d:00–%02d:00', AutoSender::WINDOW[0], AutoSender::WINDOW[1]);
$autoStart = AutoSender::startDate($pdo);
if (!$autoOn || $autoPaused !== '') {
    $autoNextText = '—';
} elseif ($autoStart > $autoNow->format('Y-m-d')) {
    $autoNextText = 'starts ' . date('l, M j', strtotime($autoStart)) . ' between ' . sprintf('%02d:00', AutoSender::WINDOW[0]) . ' and ' . sprintf('%02d:20', AutoSender::WINDOW[0]);
} elseif ($autoUsage && $autoUsage['today'] >= $autoUsage['cap']) {
    $autoNextText = "next weekday after " . sprintf('%02d:00', AutoSender::WINDOW[0]) . ' (today\'s limit is reached)';
} elseif (!$autoQueue) {
    $autoNextText = 'when an agency is ready to send';
} elseif (!AutoSender::inWindow($autoNow)) {
    $autoNextText = 'at the start of the next sending window';
} elseif ($autoNext > time()) {
    $autoNextText = '≈ ' . (new DateTimeImmutable('@' . $autoNext))->setTimezone(new DateTimeZone(AutoSender::TIMEZONE))->format('H:i');
} else {
    $autoNextText = 'within 5 minutes';
}
?>
<div class="card" id="auto-send">
  <div class="run-head">
    <h2>Automatic sending
      <?php if ($autoPaused !== ''): ?><span class="badge badge-neutral">Paused</span>
      <?php elseif ($autoOn): ?><span class="badge badge-new">On</span>
      <?php else: ?><span class="badge badge-neutral">Off</span><?php endif; ?>
    </h2>
    <form method="post" class="inline-form">
      <input type="hidden" name="form" value="auto_send">
      <?php if ($autoPaused !== ''): ?><button type="submit" name="action" value="resume">Resume sending</button><?php endif; ?>
      <?php if ($autoOn): ?>
        <button type="submit" name="action" value="disable" class="btn-secondary">Turn off</button>
      <?php else: ?>
        <button type="submit" name="action" value="enable_tomorrow" <?= has_smtp_config() ? '' : 'disabled' ?>
          onclick="return confirm('Start sending the Ready to send agencies automatically from tomorrow, best score first, from <?= h(MailSender::fromAddress()) ?>?')">Turn on from tomorrow</button>
        <button type="submit" name="action" value="enable" class="btn-secondary" <?= has_smtp_config() ? '' : 'disabled' ?>
          onclick="return confirm('Start sending automatically right away (today, if it\'s inside the sending window)?')">Start now</button>
      <?php endif; ?>
    </form>
  </div>
  <p class="hint">Emails the <a href="agency_outreach.php?sort=score&amp;dir=desc&amp;decision%5B0%5D=SEND&amp;status%5B0%5D=analyzed&amp;email%5B0%5D=yes">Ready to send</a> agencies, best score first, from <?= h(MailSender::fromAddress() ?: 'the sending mailbox') ?>:
    <?= h($autoWindow) ?> (your time ≈ US business hours), spread out with random gaps. The daily limit starts at <?= AutoSender::START_LIMIT ?>
    and grows by <?= AutoSender::STEP ?> each week up to <?= AutoSender::MAX_LIMIT ?>, only while fewer than <?= round(AutoSender::GROW_MAX_BOUNCE_RATE * 100) ?>% bounce.
    Sending pauses itself if bounces pile up or the mail server refuses an email. To keep an agency out, click Ignore on it.</p>
  <?php if ($autoPaused !== ''): ?><div class="error">Paused: <?= h($autoPaused) ?></div><?php endif; ?>
  <dl class="kv">
    <dt>Today</dt><dd><?= $autoUsage ? (int) $autoUsage['today'] . ' of ' . (int) $autoUsage['cap'] . ' sent' : '—' ?> <span class="muted">(manual sends count too)</span></dd>
    <dt>Next email</dt><dd><?= h($autoNextText) ?></dd>
    <dt>Daily limit</dt><dd><?= $autoLimit !== null ? $autoLimit . ($autoGrowth ? ' <span class="muted">→ ' . min(AutoSender::MAX_LIMIT, $autoLimit + AutoSender::STEP) . ' from ' . h(date('M j', strtotime($autoGrowth))) . ' if bounces stay low</span>' : ' <span class="muted">(maximum)</span>') : 'starts at ' . AutoSender::START_LIMIT . ' when turned on' ?></dd>
    <dt>Last 7 days</dt><dd><?= $autoWeek['sent'] ?> sent, <?= $autoWeek['bounced'] ?> bounced <span class="muted">(<?= round($autoWeek['rate'] * 100, 1) ?>%)</span></dd>
  </dl>
  <h3 class="sub">Next in line</h3>
  <?php if (!$autoQueue): ?>
    <p class="hint">Nothing ready to send right now. The analysis cron job refills the list.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="stats-table">
      <thead><tr><th>Agency</th><th class="num">Score</th><th>To</th></tr></thead>
      <tbody>
      <?php foreach ($autoQueue as $q): ?>
        <tr><td><a href="agency_view.php?id=<?= (int) $q['agency']['id'] ?>"><?= h($q['agency']['agency_name'] ?: $q['agency']['domain']) ?></a>
          <?php if ($q['weak']): ?><div class="agency-url" title="<?= h(implode("\n", $q['weak'])) ?>">Draft below the quality bar: it gets rewritten (re-analyzed) before sending</div><?php endif; ?></td>
          <td class="num"><?= (int) $q['agency']['score'] ?></td><td><?= h($q['to']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <p class="hint">Needs a cron job every 5 minutes (cPanel → Cron Jobs):<br>
    <code><?= h('*/5 * * * * /usr/local/bin/php ' . __DIR__ . '/cron_send.php >> ' . dirname(__DIR__, 2) . '/cron_send.log 2>&1') ?></code></p>
</div>

<div class="agency-grid">
  <div class="card">
    <h2>Sending</h2>
    <p class="hint">"Open in Gmail" opens a new message in this Google account, even when several accounts are signed in to your browser.
      Leave it empty to use the browser's default account. Nothing is sent until you press Send in Gmail.</p>
    <form method="post">
      <input type="hidden" name="form" value="sending">
      <label for="gmail_account">Send from Gmail account</label>
      <input type="text" id="gmail_account" name="gmail_account" value="<?= h($gmailAccount) ?>" placeholder="you@gmail.com">
      <button type="submit">Save sending account</button>
    </form>
    <p class="hint">"Open in mail app" uses whatever account your mail program (Outlook, Apple Mail…) is set up with.</p>
    <h3 class="sub">Send email (from the app)</h3>
    <?php if (has_smtp_config()): $usage = MailSender::usage($pdo); ?>
      <dl class="kv">
        <dt>From</dt><dd><?= h((app_config()['smtp_from_name'] ?? '') . ' <' . MailSender::fromAddress() . '>') ?></dd>
        <dt>Server <code>smtp_host</code></dt><dd><?= h(app_config()['smtp_host']) ?>:<?= (int) app_config()['smtp_port'] ?></dd>
        <dt>Today</dt><dd><?= (int) $usage['today'] ?> of <?= (int) $usage['cap'] ?> sent <span class="muted">· at least <?= round(MailSender::minGap() / 60, 1) ?> min apart</span></dd>
      </dl>
      <p class="hint">A copy of each email is saved to your mailbox's Sent folder.
        <?php if (AutoSender::limit($pdo) !== null): ?>The daily limit is automatic sending's (above) and covers manual sends too.
        <?php else: ?>Change the limits with <code>smtp_daily_cap</code> / <code>smtp_min_gap</code> (seconds) in <code>config.local.php</code>.<?php endif; ?></p>
    <?php else: ?>
      <p class="hint">Not set up. Add the <code>smtp_*</code> settings to <code>config.local.php</code> to send directly from the app.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>AI connection</h2>
    <p class="hint">Set in <code>config.local.php</code> (not editable here, and never stored in the database). See <code>config.local.php.example</code>.
      <?php if ($ai['provider'] === 'gemini'): ?>Get a free Gemini key at <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>. On the free tier Google may use prompts to improve its products.<?php endif; ?></p>
    <dl class="kv">
      <dt>API key <code>ai_api_key</code></dt>
      <dd><?= $ai['key_masked'] ? '<code>' . h($ai['key_masked']) . '</code>' : '<span class="follow-up-due">Not set</span>' ?></dd>
      <dt>Provider <code>ai_provider</code></dt><dd><?= h($ai['provider']) ?></dd>
      <dt>Model <code>ai_model</code></dt><dd><?= h($ai['model']) ?></dd>
      <?php if ($ai['fallback_models'] !== null): ?><dt>Fallback models <code>ai_fallback_models</code></dt><dd><?= $ai['fallback_models'] ? h(implode(', ', $ai['fallback_models'])) : '<span class="muted">none</span>' ?></dd><?php endif; ?>
      <dt>Endpoint <code>ai_endpoint</code></dt><dd class="wrap"><?= h($ai['endpoint']) ?></dd>
      <?php if ($ai['effort'] !== null): ?><dt>Effort <code>ai_effort</code></dt><dd><?= h($ai['effort']) ?></dd><?php endif; ?>
      <?php if ($ai['fallbacks'] !== null): ?><dt>Refusal fallback <code>ai_fallbacks</code></dt><dd><?= $ai['fallbacks'] ? 'On' : 'Off' ?></dd><?php endif; ?>
    </dl>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
