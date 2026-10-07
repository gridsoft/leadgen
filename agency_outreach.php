<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AgencyStore.php';
require_once __DIR__ . '/includes/AgencyViews.php';
require_once __DIR__ . '/includes/FilterBar.php';
require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/AutoSender.php';

$pdo = get_db();

// Paste → add → redirect (so a reload doesn't re-submit). The page then
// processes everything pending, one agency per request.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // From the dashboard: one row's "Create outreach" (prospect_id) or the bulk button (checked ids[]).
    if (isset($_POST['prospect_id'])) {
        $res = AgencyStore::addFromProspects($pdo, [(int) $_POST['prospect_id']]);
    } elseif (isset($_POST['create_outreach'])) {
        $res = AgencyStore::addFromProspects($pdo, (array) ($_POST['ids'] ?? []));
    } else {
        $res = AgencyStore::addUrls($pdo, (string) ($_POST['urls'] ?? ''));
    }
    // One agency (a row's "Create outreach", or a single checked row): open its page, which starts
    // the analysis right away — or just shows it, if it was analysed before.
    $ids = array_merge($res['added'], $res['existing']);
    if (count($ids) === 1 && !$res['invalid'] && (isset($_POST['prospect_id']) || isset($_POST['create_outreach']))) {
        header('Location: agency_view.php?id=' . $ids[0]);
        exit;
    }
    $query = [
        'added' => count($res['added']),
        'existing' => implode(',', $res['existing']),
        'invalid' => implode("\n", array_slice($res['invalid'], 0, 10)),
    ];
    header('Location: agency_outreach.php?' . http_build_query(array_filter($query, fn($v) => $v !== '' && $v !== 0)));
    exit;
}

// Filters — same multi-select filter bar as the dashboard (includes/FilterBar.php).
$counts = AgencyStore::counts($pdo);
// Background analysis (cron_analyze.php): its last run, and whether it's waiting for the AI quota to reset.
$cronLast = json_decode(Settings::get($pdo, 'cron_analyze_last'), true);
$quotaResetAt = (int) Settings::get($pdo, 'ai_quota_reset_at', '0');
$readyToSend = AgencyStore::readyToSendCount($pdo);
$statusGroups = [
    'pending' => ['pending', 'analyzing'],
    'analyzed' => ['analyzed'],
    'sent' => ['sent'],
    'replied' => ['replied'],
    'not_interested' => ['not_interested'],
    'ignored' => ['ignored'],
    'failed' => ['fetch_failed', 'ai_failed'],
];
$decisionLabels = AGENCY_DECISION_LABELS + ['none' => 'Not analyzed'];
$followUpLabels = ['due' => 'Follow-up due', 'upcoming' => 'Follow-up scheduled'];
$platforms = array_keys($counts['platform']);

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$decisionFilter = multi_param('decision', array_keys($decisionLabels));
$statusFilter = multi_param('status', array_keys($statusGroups));
// Showing only emailed agencies (e.g. the sidebar's Sent): the date column is the sent date, not the analyzed one.
$dateColumn = $statusFilter && !array_diff($statusFilter, ['sent', 'replied']) ? 'sent' : 'analyzed';
// ?overdue=1 is the older "Follow-up due" link.
$followUpFilter = ($_GET['overdue'] ?? '') === '1' ? ['due'] : multi_param('follow_up', array_keys($followUpLabels));
$platformFilter = multi_param('platform', $platforms);
$emailLabels = ['yes' => 'Has email', 'no' => 'No email'];
$emailFilter = multi_param('email', array_keys($emailLabels));
$sort = $_GET['sort'] ?? 'created';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$agencies = AgencyStore::listAgencies($pdo, [
    'decision' => $decisionFilter,
    'status' => array_merge([], ...array_map(fn($g) => $statusGroups[$g], $statusFilter)),
    'follow_up' => $followUpFilter,
    'platform' => $platformFilter,
    'email' => $emailFilter,
    'q' => $searchQuery,
], $sort, $dir);

$filterDefs = filter_defs_prepare([
    'decision' => [
        'label' => 'Decision', 'empty' => 'All', 'selected' => $decisionFilter,
        'values' => array_keys($decisionLabels),
        'options' => array_map(fn($d, $label) => [$label, (int) ($counts['decision'][$d] ?? 0)], array_keys($decisionLabels), $decisionLabels),
    ],
    'status' => [
        'label' => 'Status', 'empty' => 'All', 'selected' => $statusFilter,
        'values' => array_keys($statusGroups),
        'options' => array_map(fn($g, $statuses) => [
            $g === 'failed' ? 'Failed' : ($g === 'pending' ? 'Pending / analyzing' : AGENCY_STATUS_LABELS[$g]),
            array_sum(array_map(fn($st) => (int) ($counts['status'][$st] ?? 0), $statuses)),
        ], array_keys($statusGroups), $statusGroups),
    ],
    'follow_up' => [
        'label' => 'Follow-up', 'empty' => 'All', 'selected' => $followUpFilter,
        'values' => array_keys($followUpLabels),
        'options' => [[$followUpLabels['due'], $counts['overdue']], [$followUpLabels['upcoming'], $counts['upcoming']]],
    ],
    'platform' => [
        'label' => 'Platform', 'empty' => 'All', 'selected' => $platformFilter,
        'values' => $platforms,
        'options' => array_map(fn($p) => [$p === 'unknown' ? 'Unknown' : $p, (int) $counts['platform'][$p]], $platforms),
    ],
    'email' => [
        'label' => 'Email', 'empty' => 'All', 'selected' => $emailFilter,
        'values' => array_keys($emailLabels),
        'options' => array_map(fn($e, $label) => [$label, (int) ($counts['email'][$e] ?? 0)], array_keys($emailLabels), $emailLabels),
    ],
]);
$pendingIds = AgencyStore::pendingIds($pdo);

$added = (int) ($_GET['added'] ?? 0);
$existingIds = array_filter(array_map('intval', explode(',', (string) ($_GET['existing'] ?? ''))));
$invalid = array_filter(explode("\n", (string) ($_GET['invalid'] ?? '')));
$existing = [];
foreach ($existingIds as $eid) {
    if ($row = AgencyStore::getAgency($pdo, $eid)) {
        $existing[] = $row;
    }
}

function list_url(array $overrides): string {
    $params = array_merge(array_intersect_key($_GET, array_flip(['q', 'decision', 'status', 'follow_up', 'platform', 'email', 'overdue', 'sort', 'dir'])), $overrides);
    // A filter removed by the bar also drops the legacy ?overdue=1.
    if (array_key_exists('follow_up', $overrides)) {
        $params['overdue'] = null;
    }
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== []);
    return 'agency_outreach.php' . ($params ? '?' . http_build_query($params) : '');
}
function sort_link(string $key, string $label, string $sort, string $dir): string {
    // Score and dates read best highest/newest first.
    $firstDir = in_array($key, ['name', 'status'], true) ? 'asc' : 'desc';
    $newDir = $key === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : $firstDir;
    $arrow = $key === $sort ? '<span class="sort-arrow">' . ($dir === 'asc' ? '&#9650;' : '&#9660;') . '</span>' : '';
    return '<a class="sort-link" href="' . h(list_url(['sort' => $key, 'dir' => $newDir])) . '">' . $label . ' ' . $arrow . '</a>';
}

$pageTitle = 'Agency outreach';
$activeNav = 'agencies';
$topbarActions = '<a class="btn btn-secondary" href="agency_settings.php">Settings</a>';
require __DIR__ . '/includes/layout_header.php';
?>

<?php if (!has_ai_api_key()): ?>
<div class="notice">
  No AI API key configured yet, so agencies can be added but not analyzed. Get a free Google Gemini key at
  <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a> and add it to
  <code>config.local.php</code> as <code>'ai_api_key' =&gt; '…'</code> (see <code>config.local.php.example</code> and
  <a href="agency_settings.php">Settings</a>).
</div>
<?php endif; ?>

<?php if (is_array($cronLast)): ?>
<p class="hint">
  <strong><?= $readyToSend ?> ready to send</strong><?= isset($cronLast['pool']) ? ' (keeping ' . (int) $cronLast['pool'] . ' in stock)' : '' ?>.
  Background analysis: last run <?= date('M j, H:i', (int) $cronLast['at']) ?> —
  <?= (int) $cronLast['analyzed'] ?> analyzed, <?= (int) $cronLast['failed'] ?> failed (<?= htmlspecialchars((string) $cronLast['stopped']) ?>).
  <?php if ($quotaResetAt > time()): ?>Paused until <?= date('M j, H:i', $quotaResetAt) ?>, when the free AI quota resets.<?php endif; ?>
</p>
<?php endif; ?>
<?php if (AutoSender::enabled($pdo) && has_smtp_config()): $sendUsage = MailSender::usage($pdo); $sendPaused = AutoSender::pausedReason($pdo); ?>
<p class="hint">
  Automatic sending: <?php if ($sendPaused !== ''): ?><strong class="follow-up-due">paused</strong> — <?= htmlspecialchars($sendPaused) ?><?php else: ?>on<?php endif; ?>,
  <?= (int) $sendUsage['today'] ?> of <?= (int) $sendUsage['cap'] ?> sent today. <a href="agency_settings.php#auto-send">Details</a>
</p>
<?php endif; ?>

<?php if ($added): ?><div class="notice notice-ok">Added <?= $added ?> agenc<?= $added === 1 ? 'y' : 'ies' ?>. Analyzing now; keep this page open until it finishes.</div><?php endif; ?>
<?php if ($existing): ?>
<div class="notice">
  Already in your list, so not added again:
  <?php foreach ($existing as $i => $e): ?><?= $i ? ', ' : '' ?><a href="agency_view.php?id=<?= (int) $e['id'] ?>"><?= h($e['agency_name'] ?: $e['domain']) ?></a><?php endforeach; ?>.
  Open one and use <strong>Re-analyze</strong> to run it again.
</div>
<?php endif; ?>
<?php if ($invalid): ?><div class="error">Skipped, no usable website address: <?= h(implode(', ', $invalid)) ?></div><?php endif; ?>

<div class="card run-card" id="runCard" hidden>
  <div class="run-head">
    <strong id="runTitle">Analyzing…</strong>
    <button type="button" class="btn-secondary" id="runStop">Stop after this one</button>
  </div>
  <div class="progress"><div class="progress-bar" id="runBar"></div></div>
  <div class="hint" id="runDetail">Each agency takes about a minute: up to 6 page fetches, then the AI call. You can keep working in another tab.</div>
</div>

<?php // Most agencies come from the dashboard's "Create outreach"; pasting URLs is the secondary path. ?>
<details class="card add-card"<?= $counts['total'] ? '' : ' open' ?>>
  <summary><h2>Add agencies by website address</h2><span class="muted">or select businesses on the <a href="index.php">Dashboard</a> and use Create outreach</span></summary>
  <form method="post">
    <label for="urls">Agency websites, one per line</label>
    <textarea id="urls" name="urls" rows="4" class="url-input" placeholder="https://agency-one.com&#10;agency-two.co.uk/?utm_source=clutch&#10;www.agency-three.com/" required></textarea>
    <p class="hint">Tracking tags (<code>utm_*</code> and similar) are removed and every address becomes https. A site that's already in the list isn't added twice.
      The AI only drafts emails; nothing is ever sent from here.</p>
    <button type="submit">Analyze</button>
  </form>
</details>

<div class="table-wrap">
  <div class="table-head">
    <h2>Agencies <span class="count"><?= count($agencies) ?></span></h2>
  </div>
  <?= render_filter_bar('agency_outreach.php', $filterDefs, $searchQuery, 'Search agency, website, email, notes…', ['sort' => $sort, 'dir' => $dir], 'list_url') ?>
  <?= render_active_filters($filterDefs, $searchQuery, 'list_url') ?>
  <div class="table-scroll">
  <table class="agency-table">
    <thead><tr>
      <th><?= sort_link('name', 'Agency', $sort, $dir) ?></th>
      <th>Decision</th>
      <th><?= sort_link('score', 'Score', $sort, $dir) ?></th>
      <th>Contact email</th>
      <th><?= sort_link('status', 'Status', $sort, $dir) ?></th>
      <th><?= $dateColumn === 'sent' ? sort_link('sent', 'Sent', $sort, $dir) : sort_link('analyzed', 'Analyzed', $sort, $dir) ?></th>
      <th><?= sort_link('follow_up', 'Follow-up', $sort, $dir) ?></th>
    </tr></thead>
    <tbody id="agencyRows">
      <?php foreach ($agencies as $a): ?><?= render_agency_row($a, $dateColumn) ?><?php endforeach; ?>
      <?php if (!$agencies): ?>
        <tr><td colspan="7" class="muted"><?= $counts['total'] ? 'No agencies match these filters.' : 'No agencies yet. Paste some websites above.' ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
// Processes every pending agency one at a time, swapping each finished row in place.
(function() {
  const queue = <?= json_encode($pendingIds) ?>;
  const hasKey = <?= has_ai_api_key() ? 'true' : 'false' ?>;
  if (!queue.length || !hasKey) return;

  const card = document.getElementById('runCard');
  const title = document.getElementById('runTitle');
  const bar = document.getElementById('runBar');
  const detail = document.getElementById('runDetail');
  const stopBtn = document.getElementById('runStop');
  let stopped = false;
  let done = 0;
  let failed = 0;
  card.hidden = false;
  stopBtn.addEventListener('click', function() {
    stopped = true;
    stopBtn.disabled = true;
    stopBtn.textContent = 'Stopping…';
  });

  function setRowStatus(id, html) {
    const row = document.getElementById('agency-' + id);
    if (!row) return;
    const cell = row.querySelector('.agency-status');
    if (cell) cell.outerHTML = html;
    row.classList.add('row-busy');
  }

  function progress() {
    bar.style.width = Math.round(done / queue.length * 100) + '%';
  }

  async function run() {
    for (const id of queue) {
      if (stopped) break;
      const row = document.getElementById('agency-' + id);
      const name = row ? row.querySelector('.agency-name').textContent : 'agency #' + id;
      title.textContent = 'Analyzing ' + (done + 1) + ' of ' + queue.length + ': ' + name;
      setRowStatus(id, '<span class="agency-status status-analyzing">Analyzing…</span>');
      try {
        const res = await fetch('agency_process.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: 'id=' + encodeURIComponent(id),
        });
        const data = await res.json();
        if (!data.ok && data.error === 'no_key') {
          detail.textContent = data.message;
          stopped = true;
          break;
        }
        if (data.ok && row) {
          row.outerHTML = data.row_html;
        }
        if (!data.ok || data.status === 'fetch_failed' || data.status === 'ai_failed') failed++;
      } catch (e) {
        failed++;
        setRowStatus(id, '<span class="agency-status status-ai_failed">Request failed. Reload to retry</span>');
      }
      done++;
      progress();
    }
    stopBtn.hidden = true;
    title.textContent = stopped && done < queue.length
      ? 'Stopped after ' + done + ' of ' + queue.length + '. Reload the page to continue.'
      : 'Done: ' + done + ' analyzed' + (failed ? ', ' + failed + ' failed' : '') + '.';
    if (!stopped) detail.textContent = 'Click an agency to review its draft email.';
  }
  progress();
  run();
})();
</script>

<script>
// Row buttons ("Got reply", "Ignore", "Undo"): run the action and swap in the re-rendered row.
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.btn-row-action');
  if (!btn) return;
  btn.disabled = true;
  fetch('agency_action.php', {method: 'POST', body: new URLSearchParams({id: btn.dataset.id, action: btn.dataset.action, ajax: '1', date_column: <?= json_encode($dateColumn) ?>})})
    .then(function(r) { return r.json(); })
    .then(function(data) {
      const row = document.getElementById('agency-' + btn.dataset.id);
      if (data.ok && data.row_html && row) { row.outerHTML = data.row_html; } else { btn.disabled = false; }
    })
    .catch(function() { btn.disabled = false; });
});
</script>

<script src="assets/filter-bar.js"></script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
