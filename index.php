<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ContactStatus.php';
require_once __DIR__ . '/includes/FilterBar.php';

$pdo = get_db();

const SOURCE_LABELS = [
    'osm' => 'OpenStreetMap',
    'places' => 'Google Places',
    'foursquare' => 'Foursquare',
    'clutch' => 'Clutch',
    'manual' => 'Manual',
];
const EMAIL_LABELS = ['yes' => 'Has email', 'no' => 'No email'];
const OUTREACH_LABELS = ['no' => 'Not reached out', 'yes' => 'Reached out', 'ignored' => 'Ignored'];
// Agency Outreach: has the AI scored this business and drafted an email (Create outreach)?
const AI_ANALYSIS_LABELS = ['analyzed' => 'Outreach analyzed', 'not_analyzed' => 'Not analyzed yet'];
const AI_ANALYZED_SQL = 'EXISTS (SELECT 1 FROM agencies ag2 WHERE ag2.domain = p.website_domain AND ag2.analyzed_at IS NOT NULL)';

$searchQuery = trim($_GET['q'] ?? '');

// Whitelisted sort columns — never build ORDER BY from raw user input.
$sortColumns = [
    'business_name' => 'p.business_name',
    'category' => 'p.category',
    'city' => 'p.city',
    'source' => 'p.source',
    'website' => 'p.website',
    'phone' => 'p.phone',
    'email' => 'p.contact_email',
    'status' => 'p.contact_status',
    'reviews' => 'p.review_count',
    'opportunity' => "FIELD(a.opportunity_level, 'HIGH', 'MEDIUM', 'LOW')",
    'contact_gap' => "FIELD(a.contact_gap_level, 'HIGH', 'MEDIUM', 'LOW')",
    'mobile_score' => 'a.mobile_performance_score',
    'contacted' => 'COALESCE(p.contacted_at, p.ignored_at)',
    'created' => 'p.created_at',
];
$sortKey = $_GET['sort'] ?? 'created';
if (!array_key_exists($sortKey, $sortColumns)) {
    $sortKey = 'created';
}
$sortDir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'ASC' : 'DESC';

$requestedPerPage = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($requestedPerPage, [10, 25, 50, 100], true) ? $requestedPerPage : 25;
$page = max(1, (int) ($_GET['page'] ?? 1));

$counts = $pdo->query('SELECT contact_status, COUNT(*) AS n FROM prospects GROUP BY contact_status')
    ->fetchAll(PDO::FETCH_KEY_PAIR);

$contactedCount = (int) $pdo->query('SELECT COUNT(*) FROM prospects WHERE contacted_at IS NOT NULL')->fetchColumn();
$ignoredCount = (int) $pdo->query('SELECT COUNT(*) FROM prospects WHERE ignored_at IS NOT NULL')->fetchColumn();
$emailCount = (int) $pdo->query("SELECT COUNT(*) FROM prospects WHERE contact_email IS NOT NULL AND contact_email != ''")->fetchColumn();

$totalCount = array_sum($counts);

$categoryCounts = $pdo->query(
    "SELECT category, COUNT(*) AS n FROM prospects WHERE category IS NOT NULL AND category != '' GROUP BY category ORDER BY category"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// source holds a comma-separated list ("osm, foursquare") when a business was
// found by several providers, so count each provider separately.
$sourceCounts = [];
foreach ($pdo->query("SELECT source, COUNT(*) FROM prospects WHERE source IS NOT NULL AND source != '' GROUP BY source")->fetchAll(PDO::FETCH_KEY_PAIR) as $sourceList => $n) {
    foreach (array_map('trim', explode(',', $sourceList)) as $s) {
        $sourceCounts[$s] = ($sourceCounts[$s] ?? 0) + $n;
    }
}
ksort($sourceCounts);

$statusFilter = multi_param('status', array_keys(ContactStatus::LABELS));
$categoryFilter = multi_param('category', array_map('strval', array_keys($categoryCounts)));
$sourceFilter = multi_param('source', array_keys($sourceCounts));
$emailFilter = multi_param('email', array_keys(EMAIL_LABELS));
// Legacy single-value link: ?contacted=everything meant "all three".
$contactedFilter = ($_GET['contacted'] ?? null) === 'everything'
    ? array_keys(OUTREACH_LABELS)
    : multi_param('contacted', array_keys(OUTREACH_LABELS));
$aiFilter = multi_param('ai', array_keys(AI_ANALYSIS_LABELS));
// Prospects whose website has a finished outreach analysis (agencies are unique by domain).
$aiAnalyzedCount = (int) $pdo->query('SELECT COUNT(*) FROM prospects p WHERE ' . AI_ANALYZED_SQL)->fetchColumn();

$conditions = [];
$params = [];
/** Adds "column IN (:key0, :key1, …)" for a multi-select filter. */
$addIn = function (string $column, string $key, array $values) use (&$conditions, &$params) {
    if (!$values) {
        return;
    }
    $placeholders = [];
    foreach ($values as $i => $v) {
        $placeholders[] = ":{$key}{$i}";
        $params["{$key}{$i}"] = $v;
    }
    $conditions[] = "$column IN (" . implode(', ', $placeholders) . ')';
};
$addIn('p.contact_status', 'status', $statusFilter);
$addIn('p.category', 'category', $categoryFilter);
if ($sourceFilter) {
    $parts = [];
    foreach ($sourceFilter as $i => $s) {
        $parts[] = "FIND_IN_SET(:source{$i}, REPLACE(p.source, ' ', ''))";
        $params["source{$i}"] = $s;
    }
    $conditions[] = '(' . implode(' OR ', $parts) . ')';
}
// Both email options selected is the same as no email filter.
if ($emailFilter === ['yes']) {
    $conditions[] = "p.contact_email IS NOT NULL AND p.contact_email != ''";
} elseif ($emailFilter === ['no']) {
    $conditions[] = "(p.contact_email IS NULL OR p.contact_email = '')";
}
// Ignored prospects stay hidden unless "Ignored" is one of the selected outreach options.
if (!$contactedFilter) {
    $conditions[] = 'p.ignored_at IS NULL';
} elseif (count($contactedFilter) < count(OUTREACH_LABELS)) {
    $outreachSql = [
        'yes' => 'p.contacted_at IS NOT NULL',
        'no' => '(p.contacted_at IS NULL AND p.ignored_at IS NULL)',
        'ignored' => 'p.ignored_at IS NOT NULL',
    ];
    $conditions[] = '(' . implode(' OR ', array_map(fn($v) => $outreachSql[$v], $contactedFilter)) . ')';
}
// Both outreach-analysis options ticked is the same as no filter.
if ($aiFilter === ['analyzed']) {
    $conditions[] = AI_ANALYZED_SQL;
} elseif ($aiFilter === ['not_analyzed']) {
    $conditions[] = 'NOT ' . AI_ANALYZED_SQL;
}
if ($searchQuery !== '') {
    $conditions[] = '(p.business_name LIKE :q1 OR p.city LIKE :q2 OR p.category LIKE :q3
        OR p.contact_email LIKE :q4 OR p.phone LIKE :q5 OR p.website LIKE :q6 OR p.address LIKE :q7)';
    $like = '%' . $searchQuery . '%';
    foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7'] as $qParam) {
        $params[$qParam] = $like;
    }
}
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM prospects p$whereSql");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT p.*, a.opportunity_level, a.contact_gap_level, a.mobile_performance_score, ag.id AS agency_id
     FROM prospects p
     LEFT JOIN (
         SELECT a1.* FROM analyses a1
         INNER JOIN (
             SELECT prospect_id, MAX(analyzed_at) AS max_date FROM analyses GROUP BY prospect_id
         ) latest ON a1.prospect_id = latest.prospect_id AND a1.analyzed_at = latest.max_date
     ) a ON a.prospect_id = p.id
     LEFT JOIN agencies ag ON ag.domain = p.website_domain
     $whereSql
     ORDER BY {$sortColumns[$sortKey]} $sortDir, p.id DESC
     LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$prospects = $stmt->fetchAll();

// Helpers to build links that preserve the other active query params.
function build_url(array $overrides): string {
    // Flash-message params are one-shot — never carry them into subsequent links.
    $base = array_diff_key($_GET, array_flip(['analyzed', 'analyze_skipped', 'analyze_errors']));
    $params = array_merge($base, $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}
// The current view's query string, posted back by the row/bulk action forms
// so analyze_selected.php and mark_contacted.php can return here.
function return_query(): string {
    return http_build_query(array_diff_key($_GET, array_flip(['analyzed', 'analyze_skipped', 'analyze_errors'])));
}
function sort_url(string $key, string $currentKey, string $currentDir): string {
    $newDir = ($key === $currentKey && $currentDir === 'ASC') ? 'desc' : 'asc';
    return build_url(['sort' => $key, 'dir' => $newDir, 'page' => null]);
}
function sort_arrow(string $key, string $currentKey, string $currentDir): string {
    if ($key !== $currentKey) {
        return '';
    }
    return '<span class="sort-arrow">' . ($currentDir === 'ASC' ? '&#9650;' : '&#9660;') . '</span>';
}
// Windowed page number list with null markers for ellipses.
function page_range(int $current, int $total): array {
    if ($total <= 7) {
        return range(1, $total);
    }
    $pages = [1];
    if ($current > 3) $pages[] = null;
    for ($i = max(2, $current - 1); $i <= min($total - 1, $current + 1); $i++) {
        $pages[] = $i;
    }
    if ($current < $total - 2) $pages[] = null;
    $pages[] = $total;
    return $pages;
}

$analyzed = isset($_GET['analyzed']) ? (int) $_GET['analyzed'] : null;
$analyzeErrors = isset($_GET['analyze_errors']) ? (int) $_GET['analyze_errors'] : 0;
$analyzeSkipped = isset($_GET['analyze_skipped']) ? (int) $_GET['analyze_skipped'] : 0;

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/layout_header.php';
?>

<?php if ($analyzed !== null): ?>
<div class="notice">
  Analyzed <?= $analyzed ?> prospect(s).
  <?php if ($analyzeSkipped): ?> <?= $analyzeSkipped ?> skipped (no website).<?php endif; ?>
  <?php if ($analyzeErrors): ?> <?= $analyzeErrors ?> failed — see individual prospects for details.<?php endif; ?>
</div>
<?php endif; ?>

<?php
// One entry per filter dropdown: options are value => [label, count].
$filterDefs = [
    'status' => [
        'label' => 'Status',
        'empty' => 'All',
        'selected' => $statusFilter,
        'options' => array_map(fn($label, $s) => [$label, $counts[$s] ?? 0], ContactStatus::LABELS, array_keys(ContactStatus::LABELS)),
        'values' => array_keys(ContactStatus::LABELS),
    ],
    'category' => [
        'label' => 'Business type',
        'empty' => 'All',
        'selected' => $categoryFilter,
        'options' => array_map(fn($c, $n) => [(string) $c, $n], array_keys($categoryCounts), $categoryCounts),
        'values' => array_map('strval', array_keys($categoryCounts)),
    ],
    'source' => [
        'label' => 'Source',
        'empty' => 'All',
        'selected' => $sourceFilter,
        'options' => array_map(fn($s, $n) => [SOURCE_LABELS[$s] ?? ucfirst($s), $n], array_keys($sourceCounts), $sourceCounts),
        'values' => array_keys($sourceCounts),
    ],
    'email' => [
        'label' => 'Email',
        'empty' => 'All',
        'selected' => $emailFilter,
        'options' => [[EMAIL_LABELS['yes'], $emailCount], [EMAIL_LABELS['no'], $totalCount - $emailCount]],
        'values' => ['yes', 'no'],
    ],
    'contacted' => [
        'label' => 'Outreach',
        'empty' => 'All except ignored',
        'selected' => $contactedFilter,
        'options' => [
            [OUTREACH_LABELS['no'], $totalCount - $contactedCount - $ignoredCount],
            [OUTREACH_LABELS['yes'], $contactedCount],
            [OUTREACH_LABELS['ignored'], $ignoredCount],
        ],
        'values' => array_keys(OUTREACH_LABELS),
        'section' => 'Contact',
    ],
    // Rendered as a second section inside the Outreach dropdown ('in'), but filters on its own:
    // a separate URL key and chip, ANDed with the contact status above.
    'ai' => [
        'label' => 'Outreach analysis',
        'in' => 'contacted',
        'empty' => 'All',
        'selected' => $aiFilter,
        'options' => [
            [AI_ANALYSIS_LABELS['analyzed'], $aiAnalyzedCount],
            [AI_ANALYSIS_LABELS['not_analyzed'], $totalCount - $aiAnalyzedCount],
        ],
        'values' => array_keys(AI_ANALYSIS_LABELS),
    ],
];
$filterDefs = filter_defs_prepare($filterDefs);
?>

<div class="table-wrap">
<div class="table-head">
  <h2>Prospects <span class="count"><?= $totalRows ?></span></h2>
</div>

<?= render_filter_bar('index.php', $filterDefs, $searchQuery, 'Search name, city, phone, email, website…',
    ['sort' => $sortKey, 'dir' => strtolower($sortDir), 'per_page' => (string) $perPage], 'build_url') ?>
<?= render_active_filters($filterDefs, $searchQuery, 'build_url') ?>

<form method="post" action="analyze_selected.php" id="analyzeForm">
<input type="hidden" name="return_query" value="<?= htmlspecialchars(return_query()) ?>">
<div class="bulk-bar" id="bulkBar">
  <div class="bulk-selection">
    <span class="bulk-count" id="bulkCount">No rows selected</span>
    <button type="button" class="bulk-clear" id="bulkClear" hidden>Clear selection</button>
  </div>
  <div class="bulk-actions">
    <button type="submit" id="analyzeSelectedBtn" class="btn-icon" disabled>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>
      Analyze web page <span class="btn-count" data-count>0</span>
    </button>
    <button type="button" id="findEmailsBtn" class="btn-icon btn-secondary" disabled>
      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
      Find emails <span class="btn-count" data-count>0</span>
    </button>
    <button type="submit" id="outreachBtn" class="btn-icon btn-secondary" formaction="agency_outreach.php" name="create_outreach" value="1" disabled
            title="Add the selected businesses to Agency outreach: the AI qualifies each one and drafts an email">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>
      Create outreach <span class="btn-count" data-count>0</span>
    </button>
  </div>
  <span class="bulk-hint" id="bulkHint">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
    <span>Up to 30 at a time · bigger runs: <a href="bulk_analyze.php">Bulk analyze</a></span>
  </span>
</div>
<div class="table-scroll">
<table>
<thead>
<tr>
  <th class="checkbox-col"><input type="checkbox" id="selectAll"></th>
  <th><a class="sort-link" href="<?= sort_url('business_name', $sortKey, $sortDir) ?>">Business <?= sort_arrow('business_name', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('category', $sortKey, $sortDir) ?>">Type <?= sort_arrow('category', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('city', $sortKey, $sortDir) ?>">City <?= sort_arrow('city', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('source', $sortKey, $sortDir) ?>">Source <?= sort_arrow('source', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('website', $sortKey, $sortDir) ?>">Website <?= sort_arrow('website', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('email', $sortKey, $sortDir) ?>">Email <?= sort_arrow('email', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('status', $sortKey, $sortDir) ?>">Status <?= sort_arrow('status', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('reviews', $sortKey, $sortDir) ?>">Reviews <?= sort_arrow('reviews', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('opportunity', $sortKey, $sortDir) ?>">Opportunity <?= sort_arrow('opportunity', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('contact_gap', $sortKey, $sortDir) ?>">Contact gap <?= sort_arrow('contact_gap', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('mobile_score', $sortKey, $sortDir) ?>">Mobile score <?= sort_arrow('mobile_score', $sortKey, $sortDir) ?></a></th>
  <th><a class="sort-link" href="<?= sort_url('contacted', $sortKey, $sortDir) ?>">Outreach <?= sort_arrow('contacted', $sortKey, $sortDir) ?></a></th>
  <th></th>
</tr>
</thead>
<tbody>
<?php foreach ($prospects as $p): ?>
<tr<?= $p['contacted_at'] ? ' class="row-contacted"' : ($p['ignored_at'] ? ' class="row-ignored"' : '') ?>>
  <td>
    <?php if ($p['website']): ?>
      <input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" class="rowCheck">
    <?php else: ?>
      <input type="checkbox" disabled title="No website — can't analyze">
    <?php endif; ?>
  </td>
  <td><?= htmlspecialchars($p['business_name']) ?></td>
  <td><?= htmlspecialchars($p['category'] ?? '') ?></td>
  <td><?= htmlspecialchars($p['city'] ?? '') ?></td>
  <td><?= htmlspecialchars($p['source'] ?? '') ?></td>
  <td><?php if ($p['website']): ?><a href="<?= htmlspecialchars($p['website']) ?>" target="_blank" rel="noopener">visit</a><?php endif; ?></td>
  <td>
    <?php if ($p['contact_email']): ?>
      <a href="mailto:<?= htmlspecialchars($p['contact_email']) ?>"><?= htmlspecialchars($p['contact_email']) ?></a>
    <?php elseif ($p['email_scan_note']): ?>
      <span class="scan-note scan-<?= htmlspecialchars($p['email_scan_status']) ?>" title="Scanned <?= htmlspecialchars(date('M j, Y g:ia', strtotime($p['email_scanned_at']))) ?>"><?= htmlspecialchars($p['email_scan_note']) ?></span>
    <?php elseif (!$p['website']): ?>
      <span class="scan-note">No website to scan</span>
    <?php else: ?>
      <span class="muted">—</span>
    <?php endif; ?>
  </td>
  <td><?= htmlspecialchars(ContactStatus::label($p['contact_status'])) ?></td>
  <td><?= $p['review_count'] !== null ? (int)$p['review_count'] : '<span class="muted">—</span>' ?></td>
  <td>
    <?php if ($p['opportunity_level']): ?>
      <span class="badge badge-<?= strtolower($p['opportunity_level']) ?>"><?= htmlspecialchars(ucfirst(strtolower($p['opportunity_level']))) ?></span>
    <?php else: ?>
      <span class="badge badge-none">Not analyzed</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($p['contact_gap_level']): ?>
      <span class="badge badge-<?= strtolower($p['contact_gap_level']) ?>"><?= htmlspecialchars(ucfirst(strtolower($p['contact_gap_level']))) ?></span>
    <?php else: ?>
      <span class="badge badge-none">Not analyzed</span>
    <?php endif; ?>
  </td>
  <td><?= $p['mobile_performance_score'] !== null ? (int)$p['mobile_performance_score'] : '<span class="muted">—</span>' ?></td>
  <td>
    <div class="outreach-cell">
    <?php if ($p['contacted_at']): ?>
      <span class="badge badge-done" title="<?= htmlspecialchars($p['contact_note'] ?: 'Reached out ' . date('M j, Y', strtotime($p['contacted_at']))) ?>">
        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7"/></svg>
        <?= date('M j', strtotime($p['contacted_at'])) ?>
        <?php if ($p['contact_note']): ?><span class="note-dot" aria-label="Has note"></span><?php endif; ?>
      </span>
      <button type="submit" class="icon-btn" formaction="mark_contacted.php" name="unmark_id" value="<?= (int)$p['id'] ?>" title="Undo — clear reached-out mark" aria-label="Undo">
        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4.5 4.5l7 7M11.5 4.5l-7 7"/></svg>
      </button>
    <?php elseif ($p['ignored_at']): ?>
      <span class="badge badge-ignored" title="Ignored <?= date('M j, Y', strtotime($p['ignored_at'])) ?>">
        <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="5"/><path d="M4.5 11.5l7-7"/></svg>
        Ignored
      </span>
      <button type="submit" class="icon-btn" formaction="mark_contacted.php" name="unignore_id" value="<?= (int)$p['id'] ?>" title="Undo — stop ignoring" aria-label="Undo">
        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4.5 4.5l7 7M11.5 4.5l-7 7"/></svg>
      </button>
    <?php else: ?>
      <button type="submit" class="btn-mark" formaction="mark_contacted.php" name="mark_id" value="<?= (int)$p['id'] ?>">
        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 3.5v9M3.5 8h9"/></svg>
        Reached out
      </button>
      <button type="submit" class="btn-mark btn-ignore" formaction="mark_contacted.php" name="ignore_id" value="<?= (int)$p['id'] ?>" title="Ignore — hide from the list">
        <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="5"/><path d="M4.5 11.5l7-7"/></svg>
        Ignore
      </button>
    <?php endif; ?>
    </div>
  </td>
  <td class="row-actions">
    <details class="actions-menu">
      <summary>Actions</summary>
      <div class="actions-list" role="menu">
        <a role="menuitem" href="view.php?id=<?= (int)$p['id'] ?>">View details</a>
        <a role="menuitem" href="edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
        <?php if (!$p['opportunity_level'] && $p['website']): ?><a role="menuitem" href="analyze.php?id=<?= (int)$p['id'] ?>">Analyze website</a><?php endif; ?>
        <?php if ($p['agency_id']): ?><a role="menuitem" href="agency_view.php?id=<?= (int) $p['agency_id'] ?>">Open outreach</a>
        <?php elseif ($p['website']): ?><button type="submit" role="menuitem" formaction="agency_outreach.php" name="prospect_id" value="<?= (int)$p['id'] ?>">Create outreach</button><?php endif; ?>
        <?php if ($p['website']): ?><a role="menuitem" href="<?= htmlspecialchars($p['website']) ?>" target="_blank" rel="noopener">Open website &#8599;</a><?php endif; ?>
      </div>
    </details>
  </td>
</tr>
<?php endforeach; ?>
<?php if (empty($prospects)): ?>
<tr><td colspan="14">No prospects match this filter. <a href="search.php">Find some</a> or <a href="add.php">add one manually</a>.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<div class="pagination">
  <div>
    Rows per page:
    <select onchange="location.href=this.options[this.selectedIndex].dataset.url">
      <?php foreach ([10, 25, 50, 100] as $opt): ?>
        <option value="<?= $opt ?>" data-url="<?= htmlspecialchars(build_url(['per_page' => $opt, 'page' => null])) ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
      <?php endforeach; ?>
    </select>
    <span class="showing">Showing <strong><?= $totalRows ? ($offset + 1) : 0 ?>–<?= min($offset + $perPage, $totalRows) ?></strong> of <strong><?= $totalRows ?></strong> entries</span>
  </div>
  <div class="pages">
    <?php if ($page > 1): ?><a href="<?= build_url(['page' => $page - 1]) ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?>
    <?php foreach (page_range($page, $totalPages) as $p2): ?>
      <?php if ($p2 === null): ?><span>&hellip;</span>
      <?php elseif ($p2 === $page): ?><span class="current"><?= $p2 ?></span>
      <?php else: ?><a href="<?= build_url(['page' => $p2]) ?>"><?= $p2 ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($page < $totalPages): ?><a href="<?= build_url(['page' => $page + 1]) ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?>
  </div>
</div>
</form>
</div>

<script>
(function() {
  const selectAll = document.getElementById('selectAll');
  const rowChecks = document.querySelectorAll('.rowCheck');
  const btn = document.getElementById('analyzeSelectedBtn');
  const emailBtn = document.getElementById('findEmailsBtn');
  const outreachBtn = document.getElementById('outreachBtn');
  const MAX = 30;

  const bar = document.getElementById('bulkBar');
  const countLabel = document.getElementById('bulkCount');
  const clearBtn = document.getElementById('bulkClear');

  function updateButton() {
    const checked = document.querySelectorAll('.rowCheck:checked').length;
    const tooMany = checked > MAX;
    countLabel.textContent = checked === 0 ? 'No rows selected' : checked + ' selected';
    bar.classList.toggle('has-selection', checked > 0);
    bar.classList.toggle('too-many', tooMany);
    clearBtn.hidden = checked === 0;
    btn.querySelector('[data-count]').textContent = checked;
    emailBtn.querySelector('[data-count]').textContent = checked;
    emailBtn.disabled = checked === 0;
    outreachBtn.querySelector('[data-count]').textContent = checked;
    outreachBtn.disabled = checked === 0;
    btn.disabled = checked === 0 || tooMany;
    btn.title = tooMany ? 'Web page analysis handles up to ' + MAX + ' at a time — use Bulk analyze for more' : '';
    selectAll.checked = checked > 0 && checked === rowChecks.length;
    selectAll.indeterminate = checked > 0 && checked < rowChecks.length;
  }

  selectAll.addEventListener('change', function() {
    rowChecks.forEach(function(cb) { cb.checked = selectAll.checked; });
    updateButton();
  });
  clearBtn.addEventListener('click', function() {
    rowChecks.forEach(function(cb) { cb.checked = false; });
    updateButton();
  });
  rowChecks.forEach(function(cb) { cb.addEventListener('change', updateButton); });
  emailBtn.addEventListener('click', function() {
    const ids = Array.from(document.querySelectorAll('.rowCheck:checked')).map(function(cb) { return cb.value; });
    location.href = 'email_finder.php?ids=' + ids.join(',');
  });
  updateButton();
})();


// Row "Actions" dropdowns. The table scrolls horizontally, which would clip
// an absolutely positioned menu, so the open menu is placed with fixed
// coordinates instead — and flipped above the button near the bottom of the screen.
(function() {
  const menus = document.querySelectorAll('.actions-menu');

  function place(menu) {
    const list = menu.querySelector('.actions-list');
    const r = menu.querySelector('summary').getBoundingClientRect();
    list.style.left = Math.max(8, r.right - list.offsetWidth) + 'px';
    const below = r.bottom + 6;
    list.style.top = (below + list.offsetHeight > window.innerHeight - 8 ? r.top - 6 - list.offsetHeight : below) + 'px';
  }

  function closeAll(except) {
    menus.forEach(function(m) { if (m !== except) m.open = false; });
  }

  menus.forEach(function(menu) {
    menu.addEventListener('toggle', function() {
      if (menu.open) { closeAll(menu); place(menu); }
    });
  });
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.actions-menu')) closeAll(null);
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAll(null);
  });
  // Fixed coordinates go stale once the page moves; just close.
  window.addEventListener('scroll', function() { closeAll(null); }, true);
  window.addEventListener('resize', function() { closeAll(null); });
})();
</script>

<script src="assets/filter-bar.js"></script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
