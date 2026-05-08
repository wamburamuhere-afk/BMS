<?php
require 'roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE employees");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $col) {
    if (strpos($col['Field'], 'project') !== false) {
        echo $col['Field'] . "\n";
    }
}
echo "Checked.\n";
