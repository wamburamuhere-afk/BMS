<?php
require 'includes/config.php';

echo "--- DATA SUMMARY ---" . PHP_EOL;

// Regions with Districts
$stmt = $pdo->query("SELECT r.region_name, COUNT(d.district_id) as d_count 
                    FROM regions r 
                    LEFT JOIN districts d ON r.region_id = d.region_id AND d.is_active = 1
                    WHERE r.is_active = 1 
                    GROUP BY r.region_id 
                    ORDER BY r.region_name");
echo "Regions with Districts:" . PHP_EOL;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- {$row['region_name']}: {$row['d_count']} districts" . PHP_EOL;
}

// Districts with Councils
$stmt = $pdo->query("SELECT d.district_name, r.region_name, COUNT(c.council_id) as c_count 
                    FROM districts d 
                    JOIN regions r ON d.region_id = r.region_id
                    LEFT JOIN councils c ON d.district_id = c.district_id 
                    WHERE d.is_active = 1 
                    GROUP BY d.district_id
                    ORDER BY r.region_name, d.district_name");
echo PHP_EOL . "Districts with Councils (Top 20):" . PHP_EOL;
$count = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($count++ > 20) break;
    echo "- [{$row['region_name']}] {$row['district_name']}: {$row['c_count']} councils" . PHP_EOL;
}
