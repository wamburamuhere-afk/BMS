<?php
require_once __DIR__ . '/includes/config.php';
$columns = $pdo->query("SHOW FULL COLUMNS FROM audit_logs")->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "{$col['Field']} - {$col['Type']} - Default: {$col['Default']}\n";
}
