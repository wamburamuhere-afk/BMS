<?php
require_once 'roots.php';
global $pdo;

$stmt = $pdo->query("SELECT * FROM system_settings");
foreach($stmt as $row) {
    echo $row['setting_key'] . ": " . $row['setting_value'] . "\n";
}
