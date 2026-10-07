<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AiClient.php';
require_once __DIR__ . '/includes/AgencyViews.php';
require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/MailSender.php';

$pdo = get_db();
$saved = false;
$error = null;

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
      <p class="hint">Each email is sent only when you click Send and confirm, and a copy is saved to your mailbox's Sent folder.
        Change the limits with <code>smtp_daily_cap</code> / <code>smtp_min_gap</code> (seconds) in <code>config.local.php</code>.</p>
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
