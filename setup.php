<?php
// One-time setup: creates the database and tables from db/schema.sql.
// Run with: php setup.php  (no mysql CLI required — WAMP doesn't put one on PATH by default)

require_once __DIR__ . '/config.php';

$c = app_config();

try {
    $pdo = new PDO(
        "mysql:host={$c['db_host']};port={$c['db_port']};charset=utf8mb4",
        $c['db_user'],
        $c['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect to MySQL: {$e->getMessage()}\n");
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/db/schema.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));

foreach ($statements as $stmt) {
    if ($stmt === '') {
        continue;
    }
    $pdo->exec($stmt);
}

echo "Database and tables ready.\n";
