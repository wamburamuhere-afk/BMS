<?php
require_once 'roots.php';
$stmt = $pdo->query("SELECT * FROM account_types");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['type_id']} | {$row['type_name']} | {$row['display_name']}\n";
}
