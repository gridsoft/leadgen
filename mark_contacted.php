<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AgencyStore.php';

// Marks a prospect as reached out or ignored (or clears either). Posted from the
// dashboard row buttons (mark_id / unmark_id / ignore_id / unignore_id) and from
// the prospect view page (id + action). The two marks are mutually exclusive.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (isset($_POST['mark_id'])) {
    $id = (int) $_POST['mark_id'];
    $action = 'mark';
} elseif (isset($_POST['unmark_id'])) {
    $id = (int) $_POST['unmark_id'];
    $action = 'unmark';
} elseif (isset($_POST['ignore_id'])) {
    $id = (int) $_POST['ignore_id'];
    $action = 'ignore';
} elseif (isset($_POST['unignore_id'])) {
    $id = (int) $_POST['unignore_id'];
    $action = 'unignore';
} else {
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
}

$pdo = get_db();

if ($id > 0 && $action === 'mark') {
    $note = trim($_POST['note'] ?? '');
    // Keep the original contact date if already marked; only the note changes.
    $stmt = $pdo->prepare(
        'UPDATE prospects SET contacted_at = COALESCE(contacted_at, NOW()), contact_note = :note, ignored_at = NULL WHERE id = :id'
    );
    $stmt->execute(['id' => $id, 'note' => $note !== '' ? mb_substr($note, 0, 500) : null]);
    AgencyStore::syncFromProspect($pdo, $id, true); // its analysed outreach agency counts as sent
} elseif ($id > 0 && $action === 'unmark') {
    $stmt = $pdo->prepare('UPDATE prospects SET contacted_at = NULL, contact_note = NULL WHERE id = :id');
    $stmt->execute(['id' => $id]);
    AgencyStore::syncFromProspect($pdo, $id, false);
} elseif ($id > 0 && $action === 'ignore') {
    $stmt = $pdo->prepare('UPDATE prospects SET ignored_at = NOW(), contacted_at = NULL WHERE id = :id');
    $stmt->execute(['id' => $id]);
    AgencyStore::syncFromProspect($pdo, $id, false);
} elseif ($id > 0 && $action === 'unignore') {
    $stmt = $pdo->prepare('UPDATE prospects SET ignored_at = NULL WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

if (($_POST['return_to'] ?? '') === 'view') {
    header('Location: view.php?id=' . $id);
    exit;
}

// Carry the dashboard's filter/sort/page state back, same as analyze_selected.php.
// The dashboard posts its whole query string (filters are multi-select arrays).
parse_str((string) ($_POST['return_query'] ?? ''), $returnParams);
$returnParams = array_intersect_key($returnParams, array_flip(['status', 'category', 'source', 'contacted', 'ai', 'email', 'q', 'sort', 'dir', 'page', 'per_page']));

header('Location: index.php' . ($returnParams ? '?' . http_build_query($returnParams) : ''));
exit;
