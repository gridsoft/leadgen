<?php
/**
 * Replies: what came back from outreach emails — replies, auto-replies and
 * bounces read from the sending mailbox (includes/MailboxSync.php), newest first.
 * Opening an agency marks its replies read.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/MailboxSync.php';
require_once __DIR__ . '/includes/OutreachInbox.php';
require_once __DIR__ . '/includes/MailSender.php';

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = ['kind' => $_POST['kind'] ?? 'reply'];
    if (($_POST['action'] ?? '') === 'check') {
        set_time_limit(180);
        try {
            $r = MailboxSync::run($pdo);
            $back += ['checked' => 1, 'new_replies' => $r['replies'], 'new_auto' => $r['auto_replies'], 'new_bounces' => $r['bounces']];
        } catch (Throwable $e) {
            $back['failed'] = 1; // the message is in settings (mailbox_last_error)
        }
    } elseif (($_POST['action'] ?? '') === 'mark_all_read') {
        OutreachInbox::markAllRead($pdo);
    }
    header('Location: replies.php?' . http_build_query($back));
    exit;
}

$kindParam = $_GET['kind'] ?? 'reply';
$kind = array_key_exists($kindParam, OutreachInbox::KINDS) ? $kindParam : null; // 'all' → null
$counts = OutreachInbox::counts($pdo);
$replies = OutreachInbox::listReplies($pdo, $kind);
$ready = OutreachInbox::ready($pdo);
$syncedAt = (int) Settings::get($pdo, 'mailbox_synced_at', '0');
$lastError = Settings::get($pdo, 'mailbox_last_error');
$h = fn($v) => htmlspecialchars((string) $v);

$pageTitle = 'Replies';
$activeNav = 'replies';
$topbarActions = '<form method="post" action="replies.php" class="inline-form">'
    . '<input type="hidden" name="kind" value="' . $h($kindParam) . '">'
    . ($counts['unread'] ? '<button type="submit" name="action" value="mark_all_read" class="btn-secondary">Mark all as read</button>' : '')
    . '<button type="submit" name="action" value="check"' . (MailboxSync::available() && $ready ? '' : ' disabled') . '>Check mailbox now</button></form>';
require __DIR__ . '/includes/layout_header.php';
?>

<?php if (!$ready): ?>
<div class="notice">
  The replies table doesn't exist yet. Run <code>db/migrations/2026-10-08-outreach-replies.sql</code> once in phpMyAdmin (SQL tab), then reload.
</div>
<?php elseif (!MailboxSync::available()): ?>
<div class="notice">
  The mailbox can't be read here: <?= function_exists('imap_open') ? "the smtp_* settings in config.local.php are missing." : "this server's PHP has no IMAP extension." ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['checked'])):
  $found = (int) $_GET['new_replies'] + (int) $_GET['new_auto'] + (int) $_GET['new_bounces']; ?>
<div class="notice notice-ok">
  Mailbox checked. <?= $found ? (int) $_GET['new_replies'] . ' new repl' . ((int) $_GET['new_replies'] === 1 ? 'y' : 'ies') . ', ' . (int) $_GET['new_auto'] . ' auto-repl' . ((int) $_GET['new_auto'] === 1 ? 'y' : 'ies') . ', ' . (int) $_GET['new_bounces'] . ' bounce' . ((int) $_GET['new_bounces'] === 1 ? '' : 's') . '.' : 'Nothing new.' ?>
</div>
<?php endif; ?>
<?php if ($lastError !== ''): ?>
<div class="error">Last mailbox check failed: <?= $h($lastError) ?></div>
<?php endif; ?>

<p class="hint">
  Read from <?= $h(has_smtp_config() ? MailSender::fromAddress() : 'the sending mailbox') ?> (read-only: nothing is marked read or moved there) every hour by the cron job.
  Last checked: <?= $syncedAt ? $h(date('M j, H:i', $syncedAt)) : 'never' ?>.
  Only emails sent with the app's Send email button can be matched.
</p>

<nav class="tabs" aria-label="Show">
  <?php foreach (OutreachInbox::KINDS + ['all' => 'All'] as $k => $label):
    $n = $k === 'all' ? array_sum(array_intersect_key($counts, OutreachInbox::KINDS)) : $counts[$k]; ?>
    <a href="replies.php?kind=<?= $h($k) ?>" class="<?= $kindParam === $k ? 'active' : '' ?>"<?= $kindParam === $k ? ' aria-current="page"' : '' ?>><?= $h($label) ?> <span class="tab-count"><?= $n ?></span></a>
  <?php endforeach; ?>
</nav>

<div class="table-wrap">
<?php if (!$replies): ?>
  <p class="empty-state"><?= $kind === 'bounce' ? 'No bounces — every email that went out was accepted.' : 'Nothing here yet. Replies show up after the next mailbox check.' ?></p>
<?php else: ?>
  <table class="replies-table">
    <thead><tr><th>Received</th><th>Agency</th><th>From</th><th>Message</th></tr></thead>
    <tbody>
    <?php foreach ($replies as $r):
      [$newText] = MailboxSync::splitQuoted((string) $r['body']);
      $unread = $r['read_at'] === null && $r['kind'] === 'reply'; ?>
      <tr class="<?= $unread ? 'row-unread' : '' ?>" onclick="location.href='agency_view.php?id=<?= (int) $r['agency_id'] ?>#conversation'">
        <td class="nowrap"><?= $h(date('M j, H:i', strtotime((string) $r['received_at']))) ?></td>
        <td><a href="agency_view.php?id=<?= (int) $r['agency_id'] ?>#conversation"><?= $h($r['agency_name']) ?></a></td>
        <td><?= $h($r['from_name'] ?: $r['from_email']) ?><?php if ($r['from_name']): ?><div class="agency-url"><?= $h($r['from_email']) ?></div><?php endif; ?></td>
        <td class="reply-cell">
          <?php if ($unread): ?><span class="badge badge-new">New</span><?php endif; ?>
          <?php if ($r['kind'] !== 'reply'): ?><span class="badge badge-neutral"><?= $r['kind'] === 'bounce' ? 'Bounce' : 'Auto-reply' ?></span><?php endif; ?>
          <?php if ($r['matched_by'] === 'domain'): ?><span class="badge badge-neutral" title="Matched by the sender's company domain, not by the email thread">by domain</span><?php endif; ?>
          <strong><?= $h($r['subject'] ?: '(no subject)') ?></strong>
          <div class="reply-snippet"><?= $h(mb_strimwidth(preg_replace('/\s+/', ' ', $newText), 0, 160, '…')) ?></div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
