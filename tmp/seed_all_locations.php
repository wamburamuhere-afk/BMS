<?php
require 'includes/config.php';

try {
    echo "SEEDING BROAD LOCATION DATA..." . PHP_EOL;

    // Fetch all districts
    $districts = $pdo->query("SELECT district_id, district_name FROM districts WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($districts as $d) {
        $district_id = $d['district_id'];
        $district_name = $d['district_name'];

        // Conventionally, every district has at least one Council (usually named after it)
        // Check if a council exists for this district
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM councils WHERE district_id = ?");
        $stmt->execute([$district_id]);
        
        if ($stmt->fetchColumn() == 0) {
            // Create a default Council for this district
            // If it's "Ilala District", create "Ilala Municipal Council" or similar
            $council_name = str_replace(['District', 'City'], '', $district_name);
            $council_name = trim($council_name) . " Council"; 
            
            $pdo->prepare("INSERT INTO councils (council_name, district_id) VALUES (?, ?)")
                ->execute([$council_name, $district_id]);
                
            $council_id = $pdo->lastInsertId();
            
            // Add a few sample wards
            $pdo->prepare("INSERT INTO wards (ward_name, council_id) VALUES (?, ?), (?, ?)")
                ->execute(['Ward Alpha', $council_id, 'Ward Beta', $council_id]);
        }
    }

    echo "Data seeded for all districts!" . PHP_EOL;
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
