<?php
require_once 'includes/config.php';
$stmt = $pdo->query('SELECT role_id, role_name FROM roles');
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['role_id']} | {$row['role_name']}\n";
}
