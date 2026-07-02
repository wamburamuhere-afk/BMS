<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE projects");
foreach($stmt as $r) echo $r['Field'] . " (" . $r['Type'] . ")\n";
