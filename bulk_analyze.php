<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/Analyzer.php';
require_once __DIR__ . '/includes/ContactStatus.php';

/**
 * Web front-end for what batch_analyze.php already does on the command
 * line — every prospect matching the filters, not just the 30-at-a-time
 * cap "Analyze selected" enforces on the dashboard. Runs synchronously and
 * streams progress to the page as it goes (each prospect is a site fetch
 * plus up to two PageSpeed calls, so a few hundred prospects is genuinely
 * minutes, not something to run blind behind a spinner).
 */

$pdo = get_db();

$categories = $pdo->query(
    "SELECT DISTINCT category FROM prospects WHERE category IS NOT NULL AND category != '' ORDER BY category"
)->fetchAll(PDO::FETCH_COLUMN);

$category = trim($_GET['category'] ?? '');
$status = trim($_GET['status'] ?? '');
if (!array_key_exists($status, ContactStatus::LABELS)) {
    $status = '';
}
$limit = max(1, min(2000, (int) ($_GET['limit'] ?? 100)));
$refreshAfterDaysRaw = trim($_GET['refresh_after_days'] ?? '');
$refreshAfterDays = $refreshAfterDaysRaw !== '' ? max(0, (int) $refreshAfterDaysRaw) : null;
$force = isset($_GET['force']) && $_GET['force'] === '1';
$pause = max(0, min(10, (int) ($_GET['pause'] ?? 2)));
$start = isset($_GET['start']) && $_GET['start'] === '1';

$pageTitle = 'Bulk analyze';
$activeNav = 'bulk_analyze';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="card">
<h2>Bulk analyze</h2>
<p class="muted">
  Runs the full site-scan + PageSpeed pipeline across every matching prospect with a website —
  no 30-lead cap. Each prospect takes a few seconds (site fetch, then two PageSpeed calls), so
  this streams progress live below; leave the tab open until it says Done.
</p>
<form method="get" action="bulk_analyze.php">
  <label for="category">Business type</label>
  <select id="category" name="category">
    <option value="">All types</option>
    <?php foreach ($categories as $c): ?>
    <option value="<?= htmlspecialchars($c) ?>" <?= $category === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="status">Contact status</label>
  <select id="status" name="status">
    <option value="">Any status</option>
    <?php foreach (ContactStatus::LABELS as $key => $label): ?>
    <option value="<?= htmlspecialchars($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="limit">Max prospects this run (up to 2000)</label>
  <input type="number" id="limit" name="limit" min="1" max="2000" value="<?= (int) $limit ?>">

  <label for="refresh_after_days">Also re-analyze if last analyzed more than N days ago (blank = only never-analyzed)</label>
  <input type="number" id="refresh_after_days" name="refresh_after_days" min="0" value="<?= htmlspecialchars($refreshAfterDaysRaw) ?>">

  <label style="font-weight:normal">
    <input type="checkbox" name="force" value="1" style="width:auto;display:inline" <?= $force ? 'checked' : '' ?>>
    Force re-analyze everything matched (ignores the option above)
  </label>

  <label for="pause" style="margin-top:1rem">Pause between prospects (seconds — polite to target sites and the PageSpeed API)</label>
  <input type="number" id="pause" name="pause" min="0" max="10" value="<?= (int) $pause ?>">

  <input type="hidden" name="start" value="1">
  <button type="submit">Start bulk analyze</button>
</form>
</div>

<?php if ($start): ?>
<div class="card">
<h2>Progress</h2>
<pre id="log" style="background:var(--bg);color:var(--text);border:1px solid var(--border);padding:1rem;border-radius:8px;max-height:480px;overflow:auto;white-space:pre-wrap;font-size:.82rem"><?php

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}
set_time_limit(0);

// Padding so browsers that hold back rendering until they've seen enough
// bytes start showing progress immediately instead of appearing frozen.
echo str_repeat(' ', 4096), "\n";
@flush();

function bulk_out(string $line): void {
    echo htmlspecialchars($line) . "\n";
    @flush();
}

$sql = "SELECT p.*, latest.last_analyzed
     FROM prospects p
     LEFT JOIN (
         SELECT prospect_id, MAX(analyzed_at) AS last_analyzed FROM analyses GROUP BY prospect_id
     ) latest ON latest.prospect_id = p.id
     WHERE p.website IS NOT NULL AND p.website != ''";
$params = [];

if (!$force) {
    if ($refreshAfterDays !== null) {
        $sql .= ' AND (latest.last_analyzed IS NULL OR latest.last_analyzed < DATE_SUB(NOW(), INTERVAL :days DAY))';
        $params['days'] = $refreshAfterDays;
    } else {
        $sql .= ' AND latest.last_analyzed IS NULL';
    }
}
if ($category !== '') {
    $sql .= ' AND p.category = :category';
    $params['category'] = $category;
}
if ($status !== '') {
    $sql .= ' AND p.contact_status = :status';
    $params['status'] = $status;
}
$sql .= ' ORDER BY p.id LIMIT ' . (int) $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$prospects = $stmt->fetchAll();

if (!$prospects) {
    bulk_out('No prospects match - nothing to analyze. Everything matching your filters may already be analyzed; try "force" or a longer refresh window.');
} else {
    bulk_out('Bulk analyze: ' . count($prospects) . ' prospect(s)' .
        ($category !== '' ? ", category=\"$category\"" : '') .
        ($status !== '' ? ", status=$status" : '') .
        ($force ? ', force' : ''));
    bulk_out('');

    $counts = ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'errors' => 0];
    $gapCounts = ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
    $startTime = time();

    foreach ($prospects as $i => $prospect) {
        $n = $i + 1;
        $label = "[$n/" . count($prospects) . '] ' . $prospect['business_name'];
        try {
            $result = Analyzer::run($pdo, $prospect);
            if ($result['ok']) {
                $counts[$result['opportunity_level']]++;
                $gapCounts[$result['contact_gap_level']]++;
                $warningNote = $result['warnings'] ? ' (' . implode(' ', $result['warnings']) . ')' : '';
                bulk_out("$label -> redesign:{$result['opportunity_level']} contact_gap:{$result['contact_gap_level']}$warningNote");
            } else {
                bulk_out("$label -> skipped: " . implode(' ', $result['warnings']));
            }
        } catch (Throwable $e) {
            bulk_out("$label FAILED: {$e->getMessage()}");
            $counts['errors']++;
        }

        if ($n < count($prospects) && $pause > 0) {
            sleep($pause);
        }
    }

    $elapsed = time() - $startTime;
    bulk_out('');
    bulk_out(sprintf(
        'Done in %dm%ds. Redesign opportunity - HIGH: %d, MEDIUM: %d, LOW: %d. Contact gap - HIGH: %d, MEDIUM: %d, LOW: %d. errors: %d.',
        intdiv($elapsed, 60), $elapsed % 60,
        $counts['HIGH'], $counts['MEDIUM'], $counts['LOW'],
        $gapCounts['HIGH'], $gapCounts['MEDIUM'], $gapCounts['LOW'],
        $counts['errors']
    ));
}
?></pre>
<p>
  <a class="btn" href="index.php">Back to dashboard</a>
  <a class="btn btn-secondary" href="bulk_analyze.php">Run another batch</a>
</p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
