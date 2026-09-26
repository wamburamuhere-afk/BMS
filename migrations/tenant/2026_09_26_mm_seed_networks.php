<?php
/**
 * migrations/tenant/2026_09_26_mm_seed_networks.php
 *
 * Phase 0 — Mobile Money module: seed Tanzanian networks + starter commission rates.
 * Idempotent — INSERT IGNORE throughout.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: mm_seed_networks...\n";

try {
    // Skip if mm_networks table doesn't exist yet (run core tables first).
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'mm_networks'")->fetch()) {
        echo "  mm_networks table not found — run mm_core_tables migration first.\n";
        exit(1);
    }

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

    // Starter commission rates for M-Pesa cash_in / cash_out (common bands).
    // These are approximate Tanzania 2024 rates — tenant should update from official tariff.
    // All INSERT IGNORE — won't overwrite if tenant has already customised.
    $today = date('Y-m-d');

    // Get M-Pesa network_id
    $mpesa_id = (int)$pdo->query("SELECT network_id FROM mm_networks WHERE network_code = 'MPESA' LIMIT 1")->fetchColumn();
    if ($mpesa_id > 0) {
        $rate_stmt = $pdo->prepare("
            INSERT IGNORE INTO mm_commission_rates
                (network_id, txn_type, amount_from, amount_to, rate_type, rate_value, min_commission, max_commission, effective_from, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");

        // M-Pesa Cash In bands (agent earns commission from Vodacom)
        $cash_in_rates = [
            [$mpesa_id, 'cash_in',  500,       2500,    'flat', 150,  0, null],
            [$mpesa_id, 'cash_in',  2501,      5000,    'flat', 250,  0, null],
            [$mpesa_id, 'cash_in',  5001,      15000,   'flat', 500,  0, null],
            [$mpesa_id, 'cash_in',  15001,     35000,   'flat', 750,  0, null],
            [$mpesa_id, 'cash_in',  35001,     100000,  'flat', 1200, 0, null],
            [$mpesa_id, 'cash_in',  100001,    250000,  'flat', 2000, 0, null],
            [$mpesa_id, 'cash_in',  250001,    500000,  'flat', 3000, 0, null],
            [$mpesa_id, 'cash_in',  500001,    1000000, 'flat', 4000, 0, null],
        ];
        foreach ($cash_in_rates as $r) {
            $r[] = $today;
            $rate_stmt->execute($r);
        }

        // M-Pesa Cash Out bands
        $cash_out_rates = [
            [$mpesa_id, 'cash_out', 500,       2500,    'flat', 250,  0, null],
            [$mpesa_id, 'cash_out', 2501,      5000,    'flat', 500,  0, null],
            [$mpesa_id, 'cash_out', 5001,      15000,   'flat', 800,  0, null],
            [$mpesa_id, 'cash_out', 15001,     35000,   'flat', 1500, 0, null],
            [$mpesa_id, 'cash_out', 35001,     100000,  'flat', 2500, 0, null],
            [$mpesa_id, 'cash_out', 100001,    250000,  'flat', 4000, 0, null],
            [$mpesa_id, 'cash_out', 250001,    500000,  'flat', 5000, 0, null],
            [$mpesa_id, 'cash_out', 500001,    1000000, 'flat', 6000, 0, null],
        ];
        foreach ($cash_out_rates as $r) {
            $r[] = $today;
            $rate_stmt->execute($r);
        }
        echo "  · M-Pesa starter commission rates ensured.\n";
    }

    echo "Migration complete: mm_seed_networks.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
