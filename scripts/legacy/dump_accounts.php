<?php
require_once 'roots.php';
$stmt = $pdo->query("SELECT * FROM accounts");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
