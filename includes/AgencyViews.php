<?php
/**
 * Markup shared by agency_outreach.php and agency_process.php — the
 * processing endpoint returns a re-rendered list row so the page can swap
 * it in when an agency finishes.
 */

const AGENCY_STATUS_LABELS = [
    'pending' => 'Pending',
    'analyzing' => 'Analyzing…',
    'fetch_failed' => 'Fetch failed',
    'ai_failed' => 'AI failed',
    'analyzed' => 'Analyzed',
    'sent' => 'Sent',
    'replied' => 'Replied',
    'not_interested' => 'Not interested',
    'ignored' => 'Ignored',
];

const AGENCY_DECISION_LABELS = [
    'SEND' => 'Send',
    'SEND_LOW_PRIORITY' => 'Low priority',
    'SKIP' => 'Skip',
];

function h($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function agency_decision_badge(?string $decision): string {
    if ($decision === null) {
        return '<span class="badge badge-none">—</span>';
    }
    $class = ['SEND' => 'badge-send', 'SEND_LOW_PRIORITY' => 'badge-lowpri', 'SKIP' => 'badge-skip'][$decision] ?? 'badge-neutral';
    return '<span class="badge ' . $class . '">' . h(AGENCY_DECISION_LABELS[$decision] ?? $decision) . '</span>';
}

function agency_status_badge(string $status): string {
    return '<span class="agency-status status-' . h($status) . '">' . h(AGENCY_STATUS_LABELS[$status] ?? $status) . '</span>';
}

/** Deliverability badge for an outreach address (EmailHealth status). Null = not checked yet: the page script checks it. */
function agency_address_check(?string $status): string {
    $map = [
        'valid' => ['ok', '✓ Deliverable'],
        'catch_all' => ['warn', 'Server accepts any address, so the inbox can\'t be confirmed'],
        'unknown' => ['warn', 'Couldn\'t confirm the inbox'],
        'disposable' => ['bad', 'Disposable address'],
        'invalid' => ['bad', '✗ Doesn\'t accept mail'],
        'bounced' => ['bad', '✗ Bounced before'],
    ];
    if ($status === null) {
        return '<span class="addr-check" id="toCheck" data-pending="1">Checking address…</span>';
    }
    [$class, $label] = $map[$status] ?? ['warn', $status];
    return '<span class="addr-check addr-' . $class . '" id="toCheck" data-status="' . h($status) . '">' . h($label) . '</span>';
}

function agency_date(?string $ts, string $format = 'M j, Y'): string {
    return $ts ? date($format, strtotime($ts)) : '';
}

/**
 * One <tr> of the agency list. $a is a row from AgencyStore::listAgencies().
 * $dateColumn: 'analyzed' (default) or 'sent' — which date the date column shows.
 */
function render_agency_row(array $a, string $dateColumn = 'analyzed'): string {
    $date = $dateColumn === 'sent' ? $a['sent_at'] : $a['analyzed_at'];
    $name = $a['agency_name'] ?: $a['domain'];
    $classes = [];
    if (!empty($a['is_overdue'])) {
        $classes[] = 'row-overdue';
    }
    if (in_array($a['status'], ['pending', 'analyzing'], true)) {
        $classes[] = 'row-busy';
    }
    $warnings = json_decode($a['warnings_json'] ?? '[]', true) ?: [];
    $lowText = in_array(AgencyScraper::LOW_TEXT_WARNING, $warnings, true);

    ob_start(); ?>
<tr id="agency-<?= (int) $a['id'] ?>" data-id="<?= (int) $a['id'] ?>" data-status="<?= h($a['status']) ?>"<?= $classes ? ' class="' . implode(' ', $classes) . '"' : '' ?>>
  <td>
    <a class="agency-name" href="agency_view.php?id=<?= (int) $a['id'] ?>"><?= h($name) ?></a>
    <div class="agency-url"><a href="<?= h($a['normalized_url']) ?>" target="_blank" rel="noopener"><?= h(preg_replace('#^https://#', '', $a['normalized_url'])) ?> &#8599;</a></div>
  </td>
  <td><?= agency_decision_badge($a['decision'] ?? null) ?></td>
  <td class="score-cell"><?php if (isset($a['score'])): ?><span class="score score-<?= $a['score'] >= 60 ? 'high' : ($a['score'] >= 35 ? 'mid' : 'low') ?>"><?= (int) $a['score'] ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
  <td>
    <?= !empty($a['to_email']) ? h($a['to_email']) : '<span class="muted">—</span>' ?>
    <?php if (!empty($a['to_email']) && !empty($a['to_email_from_list'])): ?><div class="agency-url" title="Not published on the site; this is the email saved in your lead list">from your lead list</div><?php endif; ?>
  </td>
  <td>
    <?= agency_status_badge($a['status']) ?>
    <?php if ($a['status'] === 'sent'): ?>
      <button type="button" class="btn-mark btn-row-action" data-id="<?= (int) $a['id'] ?>" data-action="mark_replied" title="They wrote back: mark this agency as replied">Got reply</button>
    <?php elseif ($a['status'] === 'analyzed'): ?>
      <button type="button" class="btn-mark btn-ignore btn-row-action" data-id="<?= (int) $a['id'] ?>" data-action="ignore" title="Don't email this agency: hide it from Ready to send">Ignore</button>
    <?php elseif ($a['status'] === 'ignored'): ?>
      <button type="button" class="btn-mark btn-row-action" data-id="<?= (int) $a['id'] ?>" data-action="reset_outreach" title="Bring it back as an analyzed agency">Undo</button>
    <?php endif; ?>
    <?php if ($lowText): ?><span class="agency-warn" title="<?= h(AgencyScraper::LOW_TEXT_WARNING) ?>">JS site</span><?php endif; ?>
    <?php if (!empty($a['last_error']) && in_array($a['status'], ['fetch_failed', 'ai_failed'], true)): ?>
      <div class="agency-error" title="<?= h($a['last_error']) ?>"><?= h(mb_strimwidth($a['last_error'], 0, 70, '…')) ?></div>
    <?php endif; ?>
  </td>
  <td class="nowrap"><?= agency_date($date) ?: '<span class="muted">—</span>' ?></td>
  <td class="nowrap">
    <?php if ($a['follow_up_at'] && $a['status'] === 'sent'): ?>
      <span class="<?= !empty($a['is_overdue']) ? 'follow-up-due' : 'muted' ?>"><?= !empty($a['is_overdue']) ? 'Due ' : '' ?><?= agency_date($a['follow_up_at']) ?></span>
    <?php elseif ($a['status'] === 'replied' && $a['replied_at']): ?>
      <span class="muted">Replied <?= agency_date($a['replied_at']) ?></span>
    <?php else: ?><span class="muted">—</span><?php endif; ?>
  </td>
</tr>
<?php
    return ob_get_clean();
}
