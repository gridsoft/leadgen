<?php
/**
 * Analyzes ONE agency (scrape + AI call) and returns its re-rendered list
 * row as JSON. agency_outreach.php and agency_view.php call this once per
 * agency, one after another, so a long batch never has to fit in a single
 * web request (same approach as find_emails.php).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AgencyStore.php';
require_once __DIR__ . '/includes/AgencyViews.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

// Up to 6 page fetches (15 s each) plus an AI call that can take a minute or two.
set_time_limit(0);
// Finish and save the result even if the user closes the tab mid-run.
ignore_user_abort(true);

if (!has_ai_api_key()) {
    echo json_encode([
        'ok' => false,
        'error' => 'no_key',
        'message' => "No AI API key configured. Add 'ai_api_key' to config.local.php, then reload this page.",
    ]);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$pdo = get_db();

if (AgencyStore::claim($pdo, $id)) {
    AgencyStore::process($pdo, $id, new AgencyScraper(), function () {
        return new AgencyQualifier(AiClientFactory::fromConfig());
    });
    $skipped = false;
} else {
    $skipped = true; // already done, or another tab is processing it
}

$rows = AgencyStore::listAgencies($pdo, ['id' => $id], 'created', 'desc');
if (!$rows) {
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}
echo json_encode([
    'ok' => true,
    'skipped' => $skipped,
    'status' => $rows[0]['status'],
    'name' => $rows[0]['agency_name'] ?: $rows[0]['domain'],
    'row_html' => render_agency_row($rows[0]),
]);
