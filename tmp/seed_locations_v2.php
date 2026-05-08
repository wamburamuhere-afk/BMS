<?php
require 'includes/config.php';

try {
    // Districts for Mwanza (ID 17): 100 to 106
    $data = [
        100 => ['Ilemela Municipal Council'],
        101 => ['Kwimba District Council'],
        102 => ['Magu District Council'],
        103 => ['Misungwi District Council'],
        104 => ['Nyamagana Municipal Council'],
        105 => ['Sengerema District Council', 'Buchosa District Council'],
        106 => ['Ukerewe District Council']
    ];

    foreach ($data as $district_id => $councils) {
        foreach ($councils as $c_name) {
            // Check if exists
            $stmt = $pdo->prepare("SELECT council_id FROM councils WHERE council_name = ? AND district_id = ?");
            $stmt->execute([$c_name, $district_id]);
            if (!$stmt->fetch()) {
                $pdo->prepare("INSERT INTO councils (council_name, district_id) VALUES (?, ?)")->execute([$c_name, $district_id]);
            }
        }
    }
    
    // Seed wards for some of these
    $councils_to_seed = $pdo->query("SELECT council_id, council_name FROM councils WHERE district_id IN (100, 101, 102, 103, 104, 105, 106)")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($councils_to_seed as $c) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM wards WHERE council_id = ?");
        $stmt->execute([$c['council_id']]);
        if ($stmt->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO wards (ward_name, council_id) VALUES (?, ?)")->execute(['Sample Ward 1', $c['council_id']]);
            $pdo->prepare("INSERT INTO wards (ward_name, council_id) VALUES (?, ?)")->execute(['Sample Ward 2', $c['council_id']]);
        }
    }

    echo "Extended location data seeded successfully!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
