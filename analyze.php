<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/Analyzer.php';

$id = (int) ($_GET['id'] ?? 0);
$pdo = get_db();

$stmt = $pdo->prepare('SELECT * FROM prospects WHERE id = :id');
$stmt->execute(['id' => $id]);
$prospect = $stmt->fetch();

if (!$prospect) {
    http_response_code(404);
    die('Prospect not found.');
}

$result = Analyzer::run($pdo, $prospect);

if (!$result['ok']) {
    header('Location: view.php?id=' . $id . '&error=' . urlencode(implode(' ', $result['warnings'])));
    exit;
}

header('Location: view.php?id=' . $id);
exit;
