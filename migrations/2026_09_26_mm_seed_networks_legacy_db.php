<?php
/**
 * migrations/2026_09_26_mm_seed_networks_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_09_26_mm_seed_networks.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

if (!(bool)$pdo->query("SHOW TABLES LIKE 'mm_networks'")->fetch()) {
    echo "Legacy DB has no mm_networks table — skipping mm_seed_networks migration.\n";
    exit(0);
}

echo "Starting legacy migration: mm_seed_networks...\n";

try {
    $networks = [
        ['MPESA',   'M-Pesa (Vodacom Tanzania)',  'Vodacom Tanzania', '*150*00#', '#e60000', 10],
        ['AIRTEL',  'Airtel Money',                'Airtel Tanzania',  '*150*60#', '#ff0000', 20],
        ['TIGO',    'Tigo Pesa',                   'MIC Tanzania',     '*150*01#', '#0099cc', 30],
        ['HALOTEL', 'HaloPesa',                    'Halotel Tanzania', '*150*88#', '#0066cc', 40],
        ['TPESA',   'T-Pesa (TTCL)',               'TTCL',             '*150*77#', '#003366', 50],
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO mm_networks
            (network_code, network_name, provider, short_code, color_hex, sort_order, status)
        VALUES (?, ?, ?, ?, ?, ?, 'active')
    ");
    foreach ($networks as $n) {
        $stmt->execute($n);
        echo "  · network {$n[0]} ensured.\n";
    }

    $today = date('Y-m-d');
    $mpesa_id = (int)$pdo->query("SELECT network_id FROM mm_networks WHERE network_code = 'MPESA' LIMIT 1")->fetchColumn();
    if ($mpesa_id > 0) {
        $rate_stmt = $pdo->prepare("
            INSERT IGNORE INTO mm_commission_rates
                (network_id, txn_type, amount_from, amount_to, rate_type, rate_value, min_commission, max_commission, effective_from, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $rates = [
            [$mpesa_id, 'cash_in',  500,    2500,    'flat', 150,  0, null, $today],
            [$mpesa_id, 'cash_in',  2501,   5000,    'flat', 250,  0, null, $today],
            [$mpesa_id, 'cash_in',  5001,   15000,   'flat', 500,  0, null, $today],
            [$mpesa_id, 'cash_in',  15001,  35000,   'flat', 750,  0, null, $today],
            [$mpesa_id, 'cash_in',  35001,  100000,  'flat', 1200, 0, null, $today],
            [$mpesa_id, 'cash_in',  100001, 250000,  'flat', 2000, 0, null, $today],
            [$mpesa_id, 'cash_in',  250001, 500000,  'flat', 3000, 0, null, $today],
            [$mpesa_id, 'cash_in',  500001, 1000000, 'flat', 4000, 0, null, $today],
            [$mpesa_id, 'cash_out', 500,    2500,    'flat', 250,  0, null, $today],
            [$mpesa_id, 'cash_out', 2501,   5000,    'flat', 500,  0, null, $today],
            [$mpesa_id, 'cash_out', 5001,   15000,   'flat', 800,  0, null, $today],
            [$mpesa_id, 'cash_out', 15001,  35000,   'flat', 1500, 0, null, $today],
            [$mpesa_id, 'cash_out', 35001,  100000,  'flat', 2500, 0, null, $today],
            [$mpesa_id, 'cash_out', 100001, 250000,  'flat', 4000, 0, null, $today],
            [$mpesa_id, 'cash_out', 250001, 500000,  'flat', 5000, 0, null, $today],
            [$mpesa_id, 'cash_out', 500001, 1000000, 'flat', 6000, 0, null, $today],
        ];
        foreach ($rates as $r) { $rate_stmt->execute($r); }
        echo "  · M-Pesa starter commission rates ensured.\n";
    }

    echo "Migration complete: mm_seed_networks (legacy).\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
