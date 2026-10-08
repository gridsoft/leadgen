<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/Analyzer.php';

// Analyzing several prospects means several site fetches plus PageSpeed
// calls in one synchronous request. 30 prospects at worst case (a slow
// site plus two 60s PageSpeed timeouts each) can exceed even a raised
// 300s limit, which is what actually happened — remove the cap entirely
// rather than raise it again.
set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
$ids = array_slice(array_unique($ids), 0, 30); // hard cap matches the UI's max

if (empty($ids)) {
    header('Location: index.php');
    exit;
}

$pdo = get_db();
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT * FROM prospects WHERE id IN ($placeholders)");
$stmt->execute($ids);
$prospects = $stmt->fetchAll();

$analyzed = 0;
$skipped = 0;
$errors = 0;

foreach ($prospects as $prospect) {
    try {
        $result = Analyzer::run($pdo, $prospect);
        if ($result['ok']) {
            $analyzed++;
        } else {
            $skipped++;
        }
    } catch (Throwable $e) {
        $errors++;
    }
}

// Carry the dashboard's filter/sort/page state back so the user lands
// where they were, not reset to page 1 with no filters.
// The dashboard posts its whole query string (filters are multi-select arrays).
parse_str((string) ($_POST['return_query'] ?? ''), $returnParams);
$returnParams = array_intersect_key($returnParams, array_flip(['for_analysis', 'status', 'category', 'source', 'contacted', 'ai', 'email', 'q', 'sort', 'dir', 'page', 'per_page']));

header('Location: index.php?' . http_build_query(array_merge($returnParams, [
    'analyzed' => $analyzed,
    'analyze_skipped' => $skipped,
    'analyze_errors' => $errors,
])));
exit;
