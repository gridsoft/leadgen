<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ContactStatus.php';

$id = (int) ($_GET['id'] ?? 0);
$pdo = get_db();

$stmt = $pdo->prepare('SELECT * FROM prospects WHERE id = :id');
$stmt->execute(['id' => $id]);
$prospect = $stmt->fetch();

if (!$prospect) {
    http_response_code(404);
    die('Prospect not found.');
}

$analysisStmt = $pdo->prepare('SELECT * FROM analyses WHERE prospect_id = :id ORDER BY analyzed_at DESC LIMIT 1');
$analysisStmt->execute(['id' => $id]);
$analysis = $analysisStmt->fetch();

$error = $_GET['error'] ?? null;

$pageTitle = $prospect['business_name'];
$activeNav = 'dashboard';
require __DIR__ . '/includes/layout_header.php';
?>

<?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
<h2><?= htmlspecialchars($prospect['business_name']) ?>
  <span class="badge badge-neutral"><?= htmlspecialchars(ContactStatus::label($prospect['contact_status'])) ?></span>
</h2>
<p>
<?php if ($prospect['category']): ?><?= htmlspecialchars($prospect['category']) ?> · <?php endif; ?>
<?= htmlspecialchars($prospect['city'] ?? '') ?><?php if ($prospect['website']): ?>
 · <a href="<?= htmlspecialchars($prospect['website']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($prospect['website']) ?></a>
<?php endif; ?>
<?php if ($prospect['phone']): ?>
 · <a href="tel:<?= htmlspecialchars($prospect['phone']) ?>"><?= htmlspecialchars($prospect['phone']) ?></a>
<?php endif; ?>
<?php if ($prospect['review_count'] !== null): ?>
 · <?= (int)$prospect['review_count'] ?> reviews
<?php endif; ?>
<?php if ($prospect['facebook_url']): ?>
 · <a href="<?= htmlspecialchars($prospect['facebook_url']) ?>" target="_blank" rel="noopener">Facebook page</a>
<?php endif; ?>
<?php if ($prospect['contact_email']): ?>
 · <a href="mailto:<?= htmlspecialchars($prospect['contact_email']) ?>"><?= htmlspecialchars($prospect['contact_email']) ?></a>
<?php endif; ?>
<?php if ($prospect['source']): ?>
 · found via <?= htmlspecialchars($prospect['source']) ?>
<?php endif; ?>
</p>
<?php if (!$analysis): ?>
<a class="btn" href="analyze.php?id=<?= $id ?>">Run analysis</a>
<?php else: ?>
<a class="btn" href="analyze.php?id=<?= $id ?>">Re-analyze</a>
<?php endif; ?>
<a class="btn btn-secondary" href="edit.php?id=<?= $id ?>">Edit</a>
</div>

<div class="card">
<h2>Outreach
  <?php if ($prospect['ignored_at']): ?>
  <span class="badge badge-ignored">Ignored <?= htmlspecialchars(date('M j, Y', strtotime($prospect['ignored_at']))) ?></span>
  <?php elseif ($prospect['contacted_at']): ?>
  <span class="badge badge-done"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7"/></svg> Reached out <?= htmlspecialchars(date('M j, Y', strtotime($prospect['contacted_at']))) ?></span>
  <?php else: ?>
  <span class="badge badge-none">Not reached out yet</span>
  <?php endif; ?>
</h2>
<form method="post" action="mark_contacted.php">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="return_to" value="view">
  <label for="note">Note (optional — how you reached out, what they said&hellip;)</label>
  <input type="text" id="note" name="note" maxlength="500" value="<?= htmlspecialchars($prospect['contact_note'] ?? '') ?>">
  <button type="submit" name="action" value="mark"><?= $prospect['contacted_at'] ? 'Save note' : 'Mark as reached out' ?></button>
  <?php if ($prospect['contacted_at']): ?>
  <button type="submit" name="action" value="unmark" class="btn-secondary">Clear</button>
  <?php endif; ?>
  <?php if ($prospect['ignored_at']): ?>
  <button type="submit" name="action" value="unignore" class="btn-secondary">Stop ignoring</button>
  <?php else: ?>
  <button type="submit" name="action" value="ignore" class="btn-secondary">Ignore</button>
  <?php endif; ?>
</form>
</div>

<?php if ($analysis): ?>
<div class="card">
<h2>Analysis
  <span class="badge badge-<?= strtolower($analysis['opportunity_level']) ?>"><?= htmlspecialchars(ucfirst(strtolower($analysis['opportunity_level']))) ?> redesign opportunity</span>
  <?php if ($analysis['contact_gap_level']): ?>
  <span class="badge badge-<?= strtolower($analysis['contact_gap_level']) ?>"><?= htmlspecialchars(ucfirst(strtolower($analysis['contact_gap_level']))) ?> contact gap</span>
  <?php else: ?>
  <span class="badge badge-none">Contact gap unknown</span>
  <?php endif; ?>
</h2>
<?php if ($analysis['error_message']): ?>
<div class="notice"><?= htmlspecialchars($analysis['error_message']) ?></div>
<?php endif; ?>
<p class="muted" style="margin-top:-.5rem">Redesign opportunity: performance/dated-platform signals (would a rebuild pitch land). Contact gap: how reachable the business's own site is (missing email/form/chat).</p>
<ul class="reasons">
<?php foreach (explode("\n", $analysis['opportunity_reasons']) as $reason): ?>
  <li><?= htmlspecialchars($reason) ?></li>
<?php endforeach; ?>
<?php if ($analysis['contact_gap_reasons'] === null): ?>
  <li>Not yet analyzed with the contact-gap check — re-analyze to get this.</li>
<?php else: ?>
<?php foreach (explode("\n", $analysis['contact_gap_reasons']) as $reason): ?>
  <li><?= htmlspecialchars($reason) ?></li>
<?php endforeach; ?>
<?php endif; ?>
</ul>
<table class="kv-table">
<tbody>
<tr><th>Mobile performance</th><td><?= $analysis['mobile_performance_score'] !== null ? $analysis['mobile_performance_score'] . '/100' : 'unavailable' ?></td></tr>
<tr><th>Mobile load time (LCP)</th><td><?= $analysis['mobile_lcp_ms'] !== null ? round($analysis['mobile_lcp_ms'] / 1000, 1) . 's' : 'unavailable' ?></td></tr>
<tr><th>Desktop performance</th><td><?= $analysis['desktop_performance_score'] !== null ? $analysis['desktop_performance_score'] . '/100' : 'unavailable' ?></td></tr>
<tr><th>Desktop load time (LCP)</th><td><?= $analysis['desktop_lcp_ms'] !== null ? round($analysis['desktop_lcp_ms'] / 1000, 1) . 's' : 'unavailable' ?></td></tr>
<tr><th>Total page weight (mobile)</th><td><?= $analysis['total_byte_weight'] !== null ? round($analysis['total_byte_weight'] / 1_000_000, 2) . ' MB' : 'unavailable' ?></td></tr>
<tr><th>Image weight (mobile)</th><td><?= $analysis['image_byte_weight'] !== null ? round($analysis['image_byte_weight'] / 1_000_000, 2) . ' MB' : 'unavailable' ?></td></tr>
<tr><th>Total page weight (desktop)</th><td><?= $analysis['desktop_total_byte_weight'] !== null ? round($analysis['desktop_total_byte_weight'] / 1_000_000, 2) . ' MB' : 'unavailable' ?></td></tr>
<tr><th>Image weight (desktop)</th><td><?= $analysis['desktop_image_byte_weight'] !== null ? round($analysis['desktop_image_byte_weight'] / 1_000_000, 2) . ' MB' : 'unavailable' ?></td></tr>
<tr><th>WordPress</th><td><?= $analysis['is_wordpress'] === null ? 'unknown' : ($analysis['is_wordpress'] ? 'yes' . ($analysis['theme_name'] ? " (theme: {$analysis['theme_name']})" : '') : 'no') ?></td></tr>
<tr><th>Online booking</th><td><?= $analysis['has_booking_signal'] === null ? 'unknown' : ($analysis['has_booking_signal'] ? 'detected' : 'not detected') ?></td></tr>
<tr><th>Emails found</th><td><?= $analysis['emails_found'] ? htmlspecialchars($analysis['emails_found']) : 'none' ?></td></tr>
<tr><th>Phone found on site</th><td><?= $analysis['phone_found'] ? htmlspecialchars($analysis['phone_found']) : 'none' ?></td></tr>
<tr><th>Contact form</th><td><?= $analysis['has_contact_form'] === null ? 'unknown' : ($analysis['has_contact_form'] ? 'detected' : 'not detected') ?></td></tr>
<tr><th>Chat widget</th><td><?= $analysis['has_chat_widget'] === null ? 'unknown' : ($analysis['has_chat_widget'] ? 'detected' : 'not detected') ?></td></tr>
<tr><th>Analyzed</th><td><?= htmlspecialchars($analysis['analyzed_at']) ?></td></tr>
</tbody>
</table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
