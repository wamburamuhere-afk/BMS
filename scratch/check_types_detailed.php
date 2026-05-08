<?php
require_once __DIR__ . '/../roots.php';
$stmt = $pdo->query("DESCRIBE leaves");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    printf("%-20s %-20s\n", $row['Field'], $row['Type']);
}
