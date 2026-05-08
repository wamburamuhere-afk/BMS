<?php
require 'includes/config.php';
$stmt = $pdo->query("SELECT r.region_name, COUNT(d.district_id) as d_count FROM regions r LEFT JOIN districts d ON r.region_id = d.region_id GROUP BY r.region_id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['region_name']}: {$row['d_count']}" . PHP_EOL;
}
