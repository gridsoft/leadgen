<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AgencyStore.php';
require_once __DIR__ . '/includes/AgencyViews.php';
require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/EmailHealth.php';
require_once __DIR__ . '/includes/MailSender.php';

$pdo = get_db();
$id = (int) ($_GET['id'] ?? 0);
$agency = AgencyStore::getAgency($pdo, $id);
if (!$agency) {
    http_response_code(404);
    $pageTitle = 'Agency not found';
    $activeNav = 'agencies';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="error">That agency doesn\'t exist. <a href="agency_outreach.php">Back to the list</a></div>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$analyses = AgencyStore::analyses($pdo, $id);
$requested = (int) ($_GET['analysis'] ?? 0);
$an = null;
foreach ($analyses as $row) {
    if ($requested ? (int) $row['id'] === $requested : true) {
        $an = $row;
        break;
    }
}
$isLatest = $an && $analyses && (int) $an['id'] === (int) $analyses[0]['id'];
$busy = in_array($agency['status'], ['pending', 'analyzing'], true);

$json = fn(?string $s) => $s ? (json_decode($s, true) ?: []) : [];
$reasons = $an ? $json($an['reasons_json']) : [];
$redFlags = $an ? $json($an['red_flags_json']) : [];
$emails = $an ? $json($an['emails_json']) : [];
$phones = $an ? $json($an['phones_json']) : [];
$pages = $an ? $json($an['pages_json']) : [];
$warnings = $an ? $json($an['warnings_json']) : [];
$hasDraft = $an && $an['decision'] && $an['decision'] !== 'SKIP' && $an['subject'] !== null && $an['body'] !== null;
$subject = $an ? ($an['edited_subject'] ?? $an['subject'] ?? '') : '';
$body = $an ? ($an['edited_body'] ?? $an['body'] ?? '') : '';
// Same rules for drafts written before them: portfolio link always in, sign-off is just the name.
if ($body !== '') {
    $body = EmailDraft::finalize($body, EmailDraft::refSlug($an['ref_slug'], $agency['agency_name'], $agency['domain']));
}
$edited = $an && ($an['edited_subject'] !== null || $an['edited_body'] !== null);
// "Open in Gmail" opens in this Google account (Settings → Sending); empty = the browser's default account.
$gmailAccount = Settings::get($pdo, 'gmail_account');

// Emails already saved for this site in the lead list (dashboard). When the analysis found
// no address on the site, the saved one is used — also for runs analysed before this existed.
$known = AgencyStore::knownEmails($pdo, $agency['domain']);
$toEmail = $an['to_email'] ?? null;
$toSource = $an['to_email_source'] ?? ($toEmail ? 'site' : null);
if ($toEmail === null && $known && $an && $an['decision'] !== 'SKIP' && !$emails) {
    $toEmail = $known[0]['email'];
    $toSource = 'lead_list';
}
// An address that bounced or failed verification is never offered: try the site's other
// addresses, then the lead list. $deadEmail keeps the one that was replaced, for the notice.
$deadEmail = null;
$deadStatus = null;
if ($toEmail !== null && EmailHealth::isBlocked($pdo, $toEmail)) {
    $deadEmail = $toEmail;
    $deadStatus = EmailHealth::status($pdo, $toEmail);
    $toEmail = EmailHealth::firstUsable($pdo, array_diff($emails, [$deadEmail]));
    $toSource = 'site';
    if ($toEmail === null) {
        $toEmail = EmailHealth::firstUsable($pdo, array_diff(array_column($known, 'email'), [$deadEmail]));
        $toSource = $toEmail ? 'lead_list' : null;
    }
}
// Deliverability of the address we'd send to (cached; null = not checked yet → checked by the page script).
$toStatus = $toEmail ? EmailHealth::status($pdo, $toEmail) : null;
$toSourceInfo = null;
foreach ($known as $k) {
    if ($k['email'] === $toEmail) {
        $toSourceInfo = $k['source'];
    }
}
// e.g. info@cre8media.com for cre8.agency: possibly an old domain — worth a look before sending.
$toDomain = $toEmail ? substr(strrchr($toEmail, '@'), 1) : '';
$toOtherDomain = $toEmail && $toDomain !== $agency['domain'] && substr($toDomain, -strlen('.' . $agency['domain'])) !== '.' . $agency['domain'];

// Sending from the app (slobodan@dmmbs.com). Null when smtp_* isn't configured.
$canSend = has_smtp_config();
$sendUsage = $canSend ? MailSender::usage($pdo) : null;
$appSent = MailSender::lastSentTo($pdo, $id);

// Never email the same agency twice: earlier contact from this page or from the dashboard.
$isSent = in_array($agency['status'], ['sent', 'replied'], true);
$contactedBefore = [];
if ($isSent && $agency['sent_at']) {
    $contactedBefore[] = 'emailed from here on ' . agency_date($agency['sent_at']);
}
$dash = $pdo->prepare('SELECT contacted_at, contact_note FROM prospects WHERE website_domain = :d AND contacted_at IS NOT NULL ORDER BY contacted_at LIMIT 1');
$dash->execute(['d' => $agency['domain']]);
if (($row = $dash->fetch()) && $row['contact_note'] !== AgencyStore::AUTO_CONTACT_NOTE) {
    // A reached-out mark set by hand on the dashboard (not the one this page sets).
    $contactedBefore[] = 'marked Reached out on the dashboard on ' . agency_date($row['contacted_at']) . ($row['contact_note'] ? ' ("' . $row['contact_note'] . '")' : '');
}

/** Rough list-price estimate, USD per million tokens. Input includes cached tokens, so this errs high. */
function estimated_cost(?string $model, ?int $in, ?int $out): ?float {
    $prices = [
        'claude-fable-5-1' => [10, 50], 'claude-opus-5-5' => [4, 20], 'claude-opus-5' => [5, 25],
        'claude-sonnet-5-5' => [2, 10], 'claude-sonnet-5' => [2, 10], 'claude-haiku-4-5' => [1, 5],
    ];
    if (!$model || !isset($prices[$model]) || $in === null) {
        // Gemini on the free tier costs nothing; paid Gemini prices vary by model and aren't tracked here.
        return null;
    }
    return ($in * $prices[$model][0] + (int) $out * $prices[$model][1]) / 1e6;
}

$flash = [
    'saved' => 'Email edits saved.',
    'reset' => 'Email reset to the AI version.',
    'sent' => 'Marked as sent and as Reached out on the dashboard. Follow-up is due in ' . AgencyStore::FOLLOW_UP_DAYS . ' days.',
    'replied' => 'Marked as replied.',
    'not_interested' => 'Marked as not interested.',
    'ignored' => 'Ignored: it no longer shows in Ready to send and won\'t be emailed. Undo under Outreach brings it back.',
    'outreach_reset' => 'Outreach status cleared.',
    'emailed' => 'Email sent from ' . MailSender::fromAddress() . '. Marked as sent and as Reached out on the dashboard; follow-up is due in ' . AgencyStore::FOLLOW_UP_DAYS . ' days.',
    'bounced' => 'Marked as bounced. That address is blocked for good, and the agency counts as not contacted (Not reached out on the dashboard).',
    'notes' => 'Notes saved.',
][$_GET['done'] ?? ''] ?? null;

$pageTitle = $agency['agency_name'] ?: $agency['domain'];
$activeNav = 'agencies';
$topbarActions = '<a class="btn btn-secondary" href="agency_outreach.php">&larr; All agencies</a>';
require __DIR__ . '/includes/layout_header.php';
?>

<?php if ($flash): ?><div class="notice notice-ok"><?= h($flash) ?></div><?php endif; ?>

<div class="card agency-header">
  <div class="agency-header-main">
    <div>
      <a class="agency-site" href="<?= h($agency['normalized_url']) ?>" target="_blank" rel="noopener"><?= h($agency['normalized_url']) ?> &#8599;</a>
      <div class="agency-meta">
        <?= agency_status_badge($agency['status']) ?>
        <?php if ($an && $an['platform']): ?><span class="meta-item">Platform: <strong><?= h($an['platform']) ?></strong></span><?php endif; ?>
        <?php if ($agency['analyzed_at']): ?><span class="meta-item">Analyzed <?= agency_date($agency['analyzed_at'], 'M j, Y g:ia') ?></span><?php endif; ?>
      </div>
    </div>
    <?php if ($an && $an['decision']): ?>
    <div class="agency-verdict">
      <?= agency_decision_badge($an['decision']) ?>
      <div class="verdict-score score-<?= $an['score'] >= 60 ? 'high' : ($an['score'] >= 35 ? 'mid' : 'low') ?>"><?= (int) $an['score'] ?><span>/100</span></div>
    </div>
    <?php endif; ?>
  </div>
  <form method="post" action="agency_action.php" class="agency-header-actions">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <button type="submit" name="action" value="reanalyze" class="btn-secondary" <?= $busy ? 'disabled' : '' ?>
      title="Scrape the site and ask the AI again. The current result stays in History.">Re-analyze</button>
  </form>
</div>

<?php if ($busy): ?>
<div class="card analyzing-card" id="busyCard">
  <div class="run-head">
    <strong id="busyStage">Reading <?= h(preg_replace('#^https://#', '', $agency['normalized_url'])) ?>…</strong>
    <span class="muted" id="busyTime">0s</span>
    <form method="post" action="agency_action.php">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <button type="submit" name="action" value="stop_analysis" class="btn-secondary"
        title="Stop waiting for this analysis. A run already going on the server may still finish and save its result.">Stop</button>
    </form>
  </div>
  <div class="progress progress-indeterminate"><div class="progress-bar"></div></div>
  <div class="hint" id="busyNotice">Reads the homepage plus its About, Contact, Careers and Services pages, then the AI scores the agency and drafts the email. Usually under a minute; the draft appears here when it's done.</div>
</div>
<?php endif; ?>

<?php if (!$isLatest && $an): ?>
<div class="notice">You're viewing an older analysis from <?= agency_date($an['created_at'], 'M j, Y g:ia') ?>. <a href="agency_view.php?id=<?= (int) $id ?>">Show the latest</a></div>
<?php endif; ?>

<?php if ($an && $an['error']): ?>
<div class="error">
  <strong><?= $an['decision'] ? 'Note' : ($pages ? 'AI failed' : 'Fetch failed') ?>:</strong> <?= h($an['error']) ?>
</div>
<?php elseif (!$an && $agency['last_error']): ?>
<div class="error"><?= h($agency['last_error']) ?></div>
<?php endif; ?>

<?php if ($warnings): ?>
<div class="notice"><?php foreach ($warnings as $w): ?><div><?= h($w) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (!$an && !$busy): ?>
<div class="card"><p class="muted">Not analyzed yet. Use <strong>Re-analyze</strong> to run it.</p></div>
<?php endif; ?>

<?php if ($an && $an['decision']): ?>
<div class="agency-grid">
  <div class="card">
    <h2>Assessment</h2>
    <h3 class="sub">Reasons</h3>
    <?php if ($reasons): ?><ul class="reasons"><?php foreach ($reasons as $r): ?><li><?= h($r) ?></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted">None given.</p><?php endif; ?>
    <h3 class="sub">Red flags</h3>
    <?php if ($redFlags): ?><ul class="reasons red-flags"><?php foreach ($redFlags as $r): ?><li><?= h($r) ?></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted">None.</p><?php endif; ?>
  </div>

  <div class="card">
    <h2>Contact</h2>
    <dl class="kv">
      <dt>Person</dt>
      <dd><?= $an['contact_name'] ? h($an['contact_name']) . ($an['contact_role'] ? ' <span class="muted">· ' . h($an['contact_role']) . '</span>' : '') : '<span class="muted">Not named</span>' ?></dd>
      <dt>Send to</dt>
      <dd><?php if ($deadEmail): ?>
            <div class="dead-email"><s><?= h($deadEmail) ?></s> <?= $deadStatus === 'bounced' ? 'bounced: the address does not exist' : 'does not accept mail (verification failed)' ?></div>
          <?php endif; ?>
          <?php if ($toEmail): ?><strong><?= h($toEmail) ?></strong> <button type="button" class="btn-copy" data-copy="<?= h($toEmail) ?>">Copy</button>
            <?= agency_address_check($toStatus) ?>
            <div class="email-source">
              <?php if ($toSource === 'lead_list'): ?>
                <span class="badge badge-lowpri">From your lead list</span> not published on the site<?= $toSourceInfo ? ' · source: ' . h($toSourceInfo) : '' ?>
              <?php else: ?>
                <span class="badge badge-send">On the site</span>
              <?php endif; ?>
              <?php if ($toOtherDomain): ?><div class="follow-up-due">Different domain from the website (<?= h($agency['domain']) ?>): check it's still current before sending.</div><?php endif; ?>
            </div>
          <?php elseif ($deadEmail && $an['other_channel']): ?>
            <div class="next-channel">No other email address. Reach them another way:
              <?= preg_match('#^https?://\S+$#', $an['other_channel']) ? '<a href="' . h($an['other_channel']) . '" target="_blank" rel="noopener">' . h($an['other_channel']) . ' &#8599;</a>' : h($an['other_channel']) ?></div>
          <?php else: ?><span class="muted">No usable email on the site or in your lead list</span><?php endif; ?></dd>
      <?php if ($an['other_channel']): ?><dt>Other channel</dt><dd class="wrap"><?= preg_match('#^https?://\S+$#', $an['other_channel']) ? '<a href="' . h($an['other_channel']) . '" target="_blank" rel="noopener">' . h($an['other_channel']) . '</a>' : h($an['other_channel']) ?></dd><?php endif; ?>
      <?php if ($an['follow_up_tip']): ?><dt>Follow-up tip</dt><dd><?= h($an['follow_up_tip']) ?></dd><?php endif; ?>
      <dt>Emails on site</dt><dd class="wrap"><?= $emails ? h(implode(', ', $emails)) : '<span class="muted">none found</span>' ?></dd>
      <?php if ($known): ?><dt>In your lead list</dt><dd class="wrap"><?= h(implode(', ', array_column($known, 'email'))) ?></dd><?php endif; ?>
      <dt>Phones on site</dt><dd><?= $phones ? h(implode(', ', $phones)) : '<span class="muted">none found</span>' ?></dd>
      <dt>Pages read</dt>
      <dd class="wrap"><?php foreach ($pages as $type => $url): ?><a class="page-link" href="<?= h($url) ?>" target="_blank" rel="noopener"><?= h($type) ?></a><?php endforeach; ?></dd>
    </dl>
  </div>
</div>
<?php endif; ?>

<?php if ($hasDraft): ?>
<div class="card email-card">
  <div class="email-head">
    <h2>Outreach email <?php if ($edited): ?><span class="badge badge-neutral">Edited</span><?php endif; ?></h2>
    <div class="email-actions">
      <button type="button" class="btn-secondary" id="copySubject">Copy subject</button>
      <button type="button" class="btn-secondary" id="copyBody">Copy body</button>
      <?php if ($agency['status'] === 'ignored'): ?>
        <span class="muted">Ignored — not emailed. Undo under Outreach to send it.</span>
      <?php else: ?>
      <?php if ($canSend): ?>
        <button type="button" class="btn-icon" id="sendEmail" title="Sends it now from <?= h(MailSender::fromAddress()) ?>, after you confirm">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>
          Send email
        </button>
      <?php endif; ?>
      <a class="btn btn-secondary" id="openGmail" href="#" target="_blank" rel="noopener">Open in Gmail</a>
      <a class="btn btn-secondary" id="openMail" href="#">Open in mail app</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($deadEmail): ?>
  <div class="notice">
    <strong><?= h($deadEmail) ?> <?= $deadStatus === 'bounced' ? 'bounced' : 'doesn\'t accept mail' ?></strong>, so nothing sent to it was delivered.
    <?php if ($toEmail): ?>The email now goes to <strong><?= h($toEmail) ?></strong> instead.
    <?php elseif ($an['other_channel']): ?>There's no other email address: use <?= preg_match('#^https?://\S+$#', $an['other_channel']) ? '<a href="' . h($an['other_channel']) . '" target="_blank" rel="noopener">their contact form</a>' : h($an['other_channel']) ?><?= $an['contact_name'] ? ' or message ' . h($an['contact_name']) . ' on LinkedIn' : '' ?>, then click <em>Mark as sent</em>.
    <?php else: ?>There's no other email address for this agency.<?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($contactedBefore): ?>
  <div class="error contacted-warning">
    <strong>Already contacted:</strong> <?= h(implode('; ', $contactedBefore)) ?>.
    Opening the email again will ask you to confirm, so this agency isn't emailed twice.
  </div>
  <?php endif; ?>

  <?php // The send state. Opening the email in Gmail / the mail app marks it sent automatically (see script). ?>
  <form method="post" action="agency_action.php" class="send-state<?= $isSent ? ' is-sent' : '' ?>" id="sendState">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <span class="send-state-text" id="sendStateText">
      <?php if ($isSent): ?>
        <strong>&#10003; Sent <?= agency_date($agency['sent_at']) ?></strong><?php if ($appSent): ?> from <?= h(MailSender::fromAddress()) ?> to <?= h($appSent['to_email']) ?> at <?= date('g:ia', strtotime($appSent['sent_at'])) ?><?= $appSent['saved_to_sent'] ? ' (copy in your Sent folder)' : '' ?><?php endif; ?>: marked <em>Reached out</em> on the dashboard<?= $agency['follow_up_at'] && !$agency['replied_at'] ? ', follow-up ' . agency_date($agency['follow_up_at']) : '' ?>.
      <?php else: ?>
        <?php if ($canSend): ?>
          <strong>Not sent yet.</strong> <em>Send email</em> sends it from <?= h(MailSender::fromAddress()) ?> and marks it as sent (and <em>Reached out</em> on the dashboard).
          <span class="send-usage"><?= (int) $sendUsage['today'] ?> of <?= (int) $sendUsage['cap'] ?> sent today</span>
        <?php else: ?>
          <strong>Not sent yet.</strong> Opening the email in Gmail or your mail app marks it as sent (and <em>Reached out</em> on the dashboard) automatically.
        <?php endif; ?>
      <?php endif; ?>
    </span>
    <span class="send-state-actions">
      <input type="hidden" name="email" id="bouncedEmail" value="<?= h($toEmail ?? '') ?>">
      <button type="submit" name="action" value="bounced" class="btn-secondary" id="markBounced" <?= $isSent ? '' : 'hidden' ?>
        title="Got an 'Address not found' / undeliverable reply? The address is blocked for good and the agency counts as not contacted."
        onclick="return confirm('Mark ' + (document.getElementById('emailTo').value || 'this address') + ' as bounced?\n\nIt will never be offered again, and the agency goes back to Not reached out (it was never contacted).')">It bounced</button>
      <button type="submit" name="action" value="reset_outreach" class="btn-secondary" id="undoSent" <?= $isSent ? '' : 'hidden' ?>
        onclick="return confirm('Mark this email as NOT sent? The business goes back to Not reached out on the dashboard.')">Undo, I didn't send it</button>
      <button type="submit" name="action" value="mark_sent" class="btn-secondary" id="markSentManual" <?= $isSent ? 'hidden' : '' ?>
        title="For when you sent it another way, e.g. by copying the text">&#10003; Mark as sent</button>
    </span>
  </form>
  <p class="hint send-from">
    <?php if ($gmailAccount !== ''): ?>Gmail opens as <strong><?= h($gmailAccount) ?></strong>.
    <?php else: ?>Gmail opens in your browser's default Google account; check the From line before sending.<?php endif; ?>
    <a href="agency_settings.php">Change</a>
  </p>
  <form method="post" action="agency_action.php" id="emailForm">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="analysis_id" value="<?= (int) $an['id'] ?>">
    <label for="emailTo">To</label>
    <input type="text" id="emailTo" value="<?= h($toEmail ?? '') ?>" placeholder="No email found on the site or in your lead list — add one yourself" autocomplete="off">
    <label for="emailSubject">Subject</label>
    <input type="text" id="emailSubject" name="subject" value="<?= h($subject) ?>">
    <label for="emailBody">Body <span class="muted label-note">The portfolio line is always included, and the email ends with just your name</span></label>
    <textarea id="emailBody" name="body" rows="18" class="email-body"><?= h($body) ?></textarea>
    <div class="button-row">
      <button type="submit" name="action" value="save_email" id="saveEmail">Save edits</button>
      <?php if ($edited): ?><button type="submit" name="action" value="reset_email" class="btn-secondary" onclick="return confirm('Discard your edits and go back to the AI version?')">Reset to AI version</button><?php endif; ?>
      <span class="hint unsaved" id="unsavedHint" hidden>Unsaved changes</span>
    </div>
  </form>
  <details class="preview">
    <summary>Preview the final email</summary>
    <pre id="emailPreview" class="email-preview"></pre>
  </details>
</div>
<?php elseif ($an && $an['decision'] === 'SKIP'): ?>
<div class="card"><h2>Outreach email</h2><p class="muted">The AI recommends skipping this agency, so no email was drafted.</p></div>
<?php endif; ?>

<div class="agency-grid">
  <div class="card">
    <h2>Outreach</h2>
    <dl class="kv">
      <dt>Status</dt><dd><?= agency_status_badge($agency['status']) ?></dd>
      <?php if ($agency['sent_at']): ?><dt>Sent</dt><dd><?= agency_date($agency['sent_at']) ?></dd><?php endif; ?>
      <?php if ($agency['follow_up_at'] && !$agency['replied_at']): ?><dt>Follow up</dt>
        <dd class="<?= $agency['is_overdue'] ? 'follow-up-due' : '' ?>"><?= agency_date($agency['follow_up_at']) ?><?= $agency['is_overdue'] ? ' (overdue)' : '' ?></dd><?php endif; ?>
      <?php if ($agency['replied_at']): ?><dt>Replied</dt><dd><?= agency_date($agency['replied_at']) ?></dd><?php endif; ?>
    </dl>
    <form method="post" action="agency_action.php" class="button-row wrap-row">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <?php if (!in_array($agency['status'], ['sent', 'replied', 'ignored'], true)): ?>
        <button type="submit" name="action" value="mark_sent" <?= $busy ? 'disabled' : '' ?>>Mark as sent</button>
      <?php endif; ?>
      <?php if ($agency['status'] === 'analyzed'): ?>
        <button type="submit" name="action" value="ignore" class="btn-secondary" title="Don't email this agency: hide it from Ready to send">Ignore</button>
      <?php endif; ?>
      <?php if ($agency['status'] !== 'replied'): ?><button type="submit" name="action" value="mark_replied" class="btn-secondary" <?= $busy ? 'disabled' : '' ?>>Mark as replied</button><?php endif; ?>
      <?php if ($agency['status'] !== 'not_interested'): ?><button type="submit" name="action" value="not_interested" class="btn-secondary" <?= $busy ? 'disabled' : '' ?>>Not interested</button><?php endif; ?>
      <?php if (in_array($agency['status'], AgencyStore::OUTREACH_STATUSES, true)): ?>
        <button type="submit" name="action" value="reset_outreach" class="btn-secondary btn-quiet" title="Clear sent / replied / not interested">Undo</button>
      <?php endif; ?>
    </form>
  </div>

  <div class="card">
    <h2>Notes</h2>
    <form method="post" action="agency_action.php">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <textarea name="notes" rows="4" class="notes" placeholder="Anything worth remembering about this agency…"><?= h($agency['notes'] ?? '') ?></textarea>
      <button type="submit" name="action" value="save_notes" class="btn-secondary">Save notes</button>
    </form>
  </div>
</div>

<?php if ($analyses): ?>
<div class="table-wrap">
  <div class="table-head"><h2>History <span class="count"><?= count($analyses) ?></span></h2></div>
  <div class="table-scroll">
  <table>
    <thead><tr><th>Run</th><th>Decision</th><th>Score</th><th>Model</th><th>Tokens (in / out)</th><th>Est. cost</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($analyses as $row): $cost = estimated_cost($row['model'], $row['input_tokens'] !== null ? (int) $row['input_tokens'] : null, $row['output_tokens'] !== null ? (int) $row['output_tokens'] : null); ?>
      <tr<?= $an && (int) $row['id'] === (int) $an['id'] ? ' class="row-current"' : '' ?>>
        <td class="nowrap"><?= agency_date($row['created_at'], 'M j, Y g:ia') ?></td>
        <td><?= $row['decision'] ? agency_decision_badge($row['decision']) : ($row['pages_json'] && $row['pages_json'] !== '[]' ? agency_status_badge('ai_failed') : agency_status_badge('fetch_failed')) ?></td>
        <td><?= $row['score'] !== null ? (int) $row['score'] : '<span class="muted">—</span>' ?></td>
        <td><?= $row['model'] ? h($row['model']) : '<span class="muted">—</span>' ?></td>
        <td class="nowrap"><?= $row['input_tokens'] !== null ? number_format((int) $row['input_tokens']) . ' / ' . number_format((int) $row['output_tokens']) : '<span class="muted">—</span>' ?></td>
        <td><?= $cost !== null ? '≈ $' . number_format($cost, 3) : ($row['model'] && stripos($row['model'], 'gemini') === 0 ? '<span class="muted">Free tier</span>' : '<span class="muted">—</span>') ?></td>
        <td class="nowrap">
          <?php if (!$an || (int) $row['id'] !== (int) $an['id']): ?><a href="agency_view.php?id=<?= (int) $id ?>&analysis=<?= (int) $row['id'] ?>">View</a><?php else: ?><span class="muted">Showing</span><?php endif; ?>
          <?php if ($row['raw_response']): ?> · <a href="#raw-<?= (int) $row['id'] ?>" class="toggle-raw" data-target="raw-<?= (int) $row['id'] ?>">Raw response</a><?php endif; ?>
        </td>
      </tr>
      <?php if ($row['raw_response']): ?>
      <tr class="raw-row" id="raw-<?= (int) $row['id'] ?>" hidden><td colspan="7"><pre class="raw-response"><?= h($row['raw_response']) ?></pre></td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<script>
(function() {
  // Re-analyze / pending: run the analysis from here, then reload to show it.
  <?php if ($busy && has_ai_api_key()): ?>
  // Elapsed time, and a rough stage label: page fetches take ~5–15 s, the AI the rest.
  const started = Date.now();
  const stage = document.getElementById('busyStage');
  const timer = setInterval(function() {
    const s = Math.round((Date.now() - started) / 1000);
    document.getElementById('busyTime').textContent = s + 's';
    if (s === 12) stage.textContent = 'AI is evaluating the agency and writing the email…';
    if (s === 60) stage.textContent = 'Still working. If the AI model is busy it retries, then switches to a backup model…';
  }, 1000);
  fetch('agency_process.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'id=<?= (int) $id ?>',
  }).then(function(r) { return r.json(); }).then(function(data) {
    clearInterval(timer);
    if (!data.ok && data.message) { stage.textContent = data.message; return; }
    if (data.skipped && data.status === 'analyzing') {
      // Another request owns this run (e.g. from a tab closed mid-run): check
      // back periodically instead of reloading straight into the same answer.
      stage.textContent = 'Another run of this analysis (started <?= htmlspecialchars(date('H:i', strtotime($agency['updated_at']))) ?>) is still going. '
        + 'If it was cut off, it can be restarted 15 minutes after it began. Checking again in 15 s…';
      setTimeout(function() { location.reload(); }, 15000);
      return;
    }
    stage.textContent = 'Done. Loading the result…';
    location.href = 'agency_view.php?id=<?= (int) $id ?>';
  }).catch(function() {
    clearInterval(timer);
    stage.textContent = 'The analysis request failed. Reload the page to try again.';
  });
  <?php elseif ($busy): ?>
  document.getElementById('busyNotice').textContent = "Waiting for an AI API key: add 'ai_api_key' to config.local.php, then reload.";
  <?php endif; ?>

  function copy(text, btn) {
    const done = function() {
      const label = btn.textContent;
      btn.textContent = 'Copied ✓';
      btn.classList.add('copied');
      setTimeout(function() { btn.textContent = label; btn.classList.remove('copied'); }, 1500);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done);
    } else {
      // http://localhost-style origins without the async clipboard API.
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
      done();
    }
  }
  document.querySelectorAll('.btn-copy').forEach(function(b) {
    b.addEventListener('click', function() { copy(b.dataset.copy, b); });
  });
  document.querySelectorAll('.toggle-raw').forEach(function(a) {
    a.addEventListener('click', function(e) {
      e.preventDefault();
      const row = document.getElementById(a.dataset.target);
      row.hidden = !row.hidden;
    });
  });

  const body = document.getElementById('emailBody');
  if (!body) return;
  const subject = document.getElementById('emailSubject');
  const to = document.getElementById('emailTo');
  const gmailAccount = <?= json_encode($gmailAccount) ?>;

  function finalBody() { return body.value.replace(/\r\n/g, '\n'); }
  // Plain-text twin of EmailDraft::toPlain(): Gmail, mail apps and copy/paste can't show **bold**.
  function plainBody() { return finalBody().replace(/\*\*([\s\S]+?)\*\*/g, '$1').replace(/^(\s*)[*•]\s+/gm, '$1• '); }

  function refresh() {
    const b = plainBody();
    const s = subject.value;
    const addr = to.value.trim();
    // authuser picks which signed-in Google account the compose window opens in.
    // (No open/send links on an ignored agency.)
    const gmailLink = document.getElementById('openGmail');
    const mailLink = document.getElementById('openMail');
    if (gmailLink) {
      gmailLink.href = 'https://mail.google.com/mail/?view=cm&fs=1'
        + (gmailAccount ? '&authuser=' + encodeURIComponent(gmailAccount) : '')
        + '&to=' + encodeURIComponent(addr) + '&su=' + encodeURIComponent(s) + '&body=' + encodeURIComponent(b);
    }
    if (mailLink) {
      mailLink.href = 'mailto:' + encodeURIComponent(addr).replace(/%40/g, '@')
        + '?subject=' + encodeURIComponent(s) + '&body=' + encodeURIComponent(b);
    }
    document.getElementById('emailPreview').textContent = 'To: ' + (addr || '(none)') + '\nSubject: ' + s + '\n\n' + b;
  }

  const initial = subject.value + '\u0000' + body.value;
  function markDirty() {
    document.getElementById('unsavedHint').hidden = (subject.value + '\u0000' + body.value) === initial;
    refresh();
  }
  [subject, body].forEach(function(el) { el.addEventListener('input', markDirty); });
  to.addEventListener('input', refresh);
  // The app can't see what happens in Gmail, so opening the email IS the send as far as
  // the app is concerned: it's marked sent (and Reached out on the dashboard) right away.
  // A false "sent" is one click to undo; a missed one could mean emailing an agency twice.
  let isSent = <?= $isSent ? 'true' : 'false' ?>;
  const contactedBefore = <?= json_encode($contactedBefore, JSON_UNESCAPED_UNICODE) ?>;
  const sendState = document.getElementById('sendState');
  const sendStateText = document.getElementById('sendStateText');

  function markSent() {
    const data = new URLSearchParams({id: '<?= (int) $id ?>', action: 'mark_sent', ajax: '1'});
    // keepalive: the request completes even if this tab loses focus or navigates (mailto:).
    return fetch('agency_action.php', {method: 'POST', body: data, keepalive: true})
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.status !== 'sent') throw new Error('not saved');
        isSent = true;
        sendState.classList.add('is-sent');
        sendStateText.innerHTML = '<strong>&#10003; Sent ' + res.sent_at + '</strong>: marked <em>Reached out</em> on the dashboard, follow-up ' + res.follow_up_at + '.';
        document.getElementById('undoSent').hidden = false;
        document.getElementById('markBounced').hidden = false;
        document.getElementById('markSentManual').hidden = true;
      })
      .catch(function() {
        sendState.classList.add('send-error');
        sendStateText.innerHTML = '<strong>Could not record the send.</strong> Click <em>Mark as sent</em> once you have sent it.';
      });
  }

  // Send email: confirm, send from slobodan@dmmbs.com, then reload to show the sent state.
  // The server re-checks everything; for an earlier contact or a dead address it answers with a
  // code, and only an explicit second confirmation sends anyway (force_*).
  const sendBtn = document.getElementById('sendEmail');
  if (sendBtn) {
    const fromAddr = <?= json_encode(MailSender::fromAddress()) ?>;
    function post(force) {
      const data = new URLSearchParams({
        id: '<?= (int) $id ?>', analysis_id: '<?= (int) ($an['id'] ?? 0) ?>', action: 'send_email',
        to: to.value.trim(), subject: subject.value, body: finalBody(),
      });
      Object.keys(force).forEach(function(k) { data.set(k, '1'); });
      return fetch('agency_action.php', {method: 'POST', body: data}).then(function(r) { return r.json(); });
    }
    function attempt(force) {
      sendBtn.disabled = true;
      sendBtn.lastChild.textContent = ' Sending…';
      post(force).then(function(res) {
        if (res.ok) {
          window.__submitting = true; // the edits were saved with the send
          location.href = 'agency_view.php?id=<?= (int) $id ?>&done=emailed';
          return;
        }
        sendBtn.disabled = false;
        sendBtn.lastChild.textContent = ' Send email';
        if (res.code === 'contacted' || res.code === 'blocked') {
          const key = res.code === 'contacted' ? 'force_contacted' : 'force_address';
          if (confirm(res.message + '\n\nSend anyway?')) {
            force[key] = true;
            attempt(force);
          }
          return;
        }
        alert(res.message || 'Sending failed.');
      }).catch(function() {
        sendBtn.disabled = false;
        sendBtn.lastChild.textContent = ' Send email';
        alert('Could not reach the app to send. Nothing was sent; check your connection and try again.');
      });
    }
    sendBtn.addEventListener('click', function() {
      const addr = to.value.trim();
      if (!addr) { alert('Add the recipient\'s email address in the To field first.'); to.focus(); return; }
      if (!confirm('Send this email now?\n\nFrom: ' + fromAddr + '\nTo: ' + addr + '\nSubject: ' + subject.value)) return;
      attempt({});
    });
  }

  // "It bounced" reports whatever address is in the To field right now.
  document.getElementById('markBounced').addEventListener('click', function() {
    document.getElementById('bouncedEmail').value = to.value.trim();
  });

  // Check the address once (Abstract API, cached 60 days) if it hasn't been checked yet.
  const toCheck = document.getElementById('toCheck');
  if (toCheck && toCheck.dataset.pending) {
    fetch('agency_action.php', {method: 'POST', body: new URLSearchParams({id: '<?= (int) $id ?>', action: 'verify_email', email: <?= json_encode($toEmail ?? '') ?>})})
      .then(function(r) { return r.json(); })
      .then(function(res) {
        const labels = {
          valid: ['ok', '✓ Deliverable'],
          catch_all: ['warn', "Server accepts any address, so the inbox can't be confirmed"],
          unknown: ['warn', "Couldn't confirm the inbox"],
          disposable: ['bad', 'Disposable address'],
          invalid: ['bad', "✗ Doesn't accept mail"],
          bounced: ['bad', '✗ Bounced before'],
        };
        if (res.status === 'invalid' || res.status === 'bounced') {
          location.reload(); // the page offers the next usable address
          return;
        }
        const l = labels[res.status] || ['warn', 'Not checked (no verification credits)'];
        toCheck.className = 'addr-check addr-' + l[0];
        toCheck.dataset.status = res.status || '';
        toCheck.textContent = l[1];
        delete toCheck.dataset.pending;
      })
      .catch(function() { toCheck.textContent = 'Not checked'; });
  }

  ['openGmail', 'openMail'].forEach(function(btnId) {
    const link = document.getElementById(btnId);
    if (!link) return;
    link.addEventListener('click', function(e) {
      const check = document.getElementById('toCheck');
      const bad = check && ['invalid', 'bounced', 'disposable'].includes(check.dataset.status);
      if (bad && to.value.trim() === <?= json_encode($toEmail ?? '') ?> &&
          !confirm(to.value.trim() + ' does not accept mail (' + check.textContent.replace(/^✗ /, '') + ').\n\nThe email would bounce. Open it anyway?')) {
        e.preventDefault();
        return;
      }
      if (contactedBefore.length || isSent) {
        const when = contactedBefore.length ? contactedBefore.join('; ') : 'already marked as sent';
        if (!confirm('This agency was already contacted (' + when + ').\n\nOpen the email anyway? Sending it would contact them a second time.')) {
          e.preventDefault();
          return;
        }
      }
      if (!isSent) markSent();
    });
  });
  document.getElementById('copySubject').addEventListener('click', function() { copy(subject.value, this); });
  document.getElementById('copyBody').addEventListener('click', function() { copy(plainBody(), this); });
  window.addEventListener('beforeunload', function(e) {
    if (!document.getElementById('unsavedHint').hidden && !window.__submitting) { e.preventDefault(); e.returnValue = ''; }
  });
  document.getElementById('emailForm').addEventListener('submit', function() { window.__submitting = true; });
  refresh();
})();
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
