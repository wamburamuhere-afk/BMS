<?php
require 'includes/config.php';

try {
    echo "CLEANING JUNK DATA..." . PHP_EOL;
    // Remove "Sample" wards
    $pdo->query("DELETE FROM wards WHERE ward_name LIKE 'Sample%' OR ward_name LIKE 'Ward %'");
    // Remove "Council" generic names if they look like "X Council" we generated
    // Actually, better to just truncate and start over for Councils since we want real ones
    $pdo->query("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->query("TRUNCATE TABLE councils");
    $pdo->query("TRUNCATE TABLE wards");
    $pdo->query("SET FOREIGN_KEY_CHECKS = 1");

    echo "SEEDING REAL COUNCILS (LEVEL 2)..." . PHP_EOL;

    // We'll map districts to their real Councils where possible, or use a "District Council" default
    $districts = $pdo->query("SELECT d.district_id, d.district_name, r.region_name 
                            FROM districts d 
                            JOIN regions r ON d.region_id = r.region_id 
                            WHERE d.is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($districts as $d) {
        $name = $d['district_name'];
        $region = $d['region_name'];
        $councils = [];

        // Special Cases for Cities/Municipalities
        if ($name == 'Arusha City') $councils[] = 'Arusha City Council';
        elseif ($name == 'Dodoma City') $councils[] = 'Dodoma City Council';
        elseif ($name == 'Mwanza City' || $name == 'Nyamagana District') $councils[] = 'Nyamagana Municipal Council';
        elseif ($name == 'Ilemela District') $councils[] = 'Ilemela Municipal Council';
        elseif ($name == 'Ilala District') $councils[] = 'Ilala Municipal Council';
        elseif ($name == 'Kinondoni District') $councils[] = 'Kinondoni Municipal Council';
        elseif ($name == 'Temeke District') $councils[] = 'Temeke Municipal Council';
        elseif ($name == 'Ubungo District') $councils[] = 'Ubungo Municipal Council';
        elseif ($name == 'Kigamboni District') $councils[] = 'Kigamboni Municipal Council';
        elseif ($name == 'Mbeya City') $councils[] = 'Mbeya City Council';
        elseif ($name == 'Tanga City') $councils[] = 'Tanga City Council';
        elseif ($name == 'Kigoma/Ujiji') $councils[] = 'Kigoma/Ujiji Municipal Council';
        elseif ($name == 'Morogoro Municipal') $councils[] = 'Morogoro Municipal Council';
        elseif ($name == 'Sumbawanga Municipal') $councils[] = 'Sumbawanga Municipal Council';
        elseif ($name == 'Shinyanga Municipal') $councils[] = 'Shinyanga Municipal Council';
        elseif ($name == 'Bukoba Municipal') $councils[] = 'Bukoba Municipal Council';
        elseif ($name == 'Musoma Municipal') $councils[] = 'Musoma Municipal Council';
        elseif ($name == 'Iringa Municipal') $councils[] = 'Iringa Municipal Council';
        elseif ($name == 'Lindi Municipal') $councils[] = 'Lindi Municipal Council';
        elseif ($name == 'Mtwara Municipal') $councils[] = 'Mtwara Municipal Council';
        elseif ($name == 'Singida Municipal') $councils[] = 'Singida Municipal Council';
        elseif ($name == 'Tabora Municipal') $councils[] = 'Tabora Municipal Council';
        elseif ($name == 'Songea Municipal') $councils[] = 'Songea Municipal Council';
        elseif ($name == 'Mpanda Municipal') $councils[] = 'Mpanda Municipal Council';
        elseif ($region == 'Zanzibar' || strpos($region, 'Pemba') !== false || $region == 'Mjini Magharibi') {
            $councils[] = str_replace('District', '', $name) . ' Municipal Council';
        }
        else {
            // Default rule for generic districts
            $clean_name = str_replace('District', '', $name);
            $councils[] = trim($clean_name) . ' District Council';
            
            // Some districts have two councils (e.g. Sengerema has Sengerema DC and Buchosa DC)
            if ($name == 'Sengerema District') $councils[] = 'Buchosa District Council';
            if ($name == 'Geita District') $councils[] = 'Geita Town Council';
            if ($name == 'Bariadi District') $councils[] = 'Bariadi Town Council';
            if ($name == 'Tarime District') $councils[] = 'Tarime Town Council';
            if ($name == 'Masasi District') $councils[] = 'Masasi Town Council';
            if ($name == 'Kondoa District') $councils[] = 'Kondoa Town Council';
            if ($name == 'Nzega District') $councils[] = 'Nzega Town Council';
            if ($name == 'Kahama District') {
                $councils = ['Kahama Town Council', 'Msalala District Council', 'Ushetu District Council'];
            }
        }

        foreach ($councils as $c_name) {
            $pdo->prepare("INSERT INTO councils (council_name, district_id) VALUES (?, ?)")
                ->execute([$c_name, $d['district_id']]);
        }
    }

    echo "Real Councils Seeding Complete!" . PHP_EOL;
    echo "Note: Wards are now empty. Users will type real ward names which will be saved for future use." . PHP_EOL;

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
