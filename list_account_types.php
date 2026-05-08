<?php
require_once __DIR__ . '/roots.php';
$stmt = $pdo->query("SELECT type_id, type_name FROM account_types");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['type_id']} | Name: {$row['type_name']}\n";
}
