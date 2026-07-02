<?php
require_once __DIR__ . '/roots.php';
global $pdo;

try {
    $pdo->exec("ALTER TABLE expenses ADD COLUMN budget_id INT NULL AFTER project_id");
    $pdo->exec("ALTER TABLE expenses ADD COLUMN voucher_id INT NULL AFTER budget_id");
    echo "Columns budget_id and voucher_id added successfully to expenses table.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
