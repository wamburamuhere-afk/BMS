<?php
require 'roots.php';
$stmt = $pdo->query('DESCRIBE permissions');
while($row = $stmt->fetch()) echo $row['Field']." - ".$row['Type']."\n";
