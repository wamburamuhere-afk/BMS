<?php
require 'includes/config.php';

try {
    $pdo->exec("INSERT INTO councils (council_name, district_id) VALUES 
        ('Ilemela Municipal Council', 100),
        ('Nyamagana Municipal Council', 104)
    ");
    
    $ilemela_id = $pdo->lastInsertId() - 1; // Assuming they are 1 and 2
    $nyamagana_id = $pdo->lastInsertId();
    
    // Actually, safer to fetch IDs
    $ilemela_id = $pdo->query("SELECT council_id FROM councils WHERE council_name = 'Ilemela Municipal Council'")->fetchColumn();
    $nyamagana_id = $pdo->query("SELECT council_id FROM councils WHERE council_name = 'Nyamagana Municipal Council'")->fetchColumn();

    $pdo->exec("INSERT INTO wards (ward_name, council_id) VALUES 
        ('Kirumba', $ilemela_id),
        ('Nyamhongolo', $ilemela_id),
        ('Buhongwa', $nyamagana_id),
        ('Igogo', $nyamagana_id)
    ");
    
    echo "Sample location data inserted successfully!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
