<?php
require_once __DIR__ . '/../roots.php';
$stmt = $pdo->query("SELECT * FROM leaves LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo "Columns: " . implode(", ", array_keys($row));
} else {
    echo "No records found in leaves table.";
}
