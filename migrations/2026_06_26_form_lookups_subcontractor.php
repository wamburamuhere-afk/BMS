<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: seed sub_contractor_type into form_lookups...\n";

// Sub-contractor "type" is its own self-growing list (separate from supplier_type);
// payment_terms + currency are shared with suppliers (already seeded). Idempotent.
try {
    $exists = (int)$pdo->query("SHOW TABLES LIKE 'form_lookups'")->rowCount();
    if (!$exists) {
        echo "form_lookups table missing — run 2026_06_26_form_lookups.php first.\n";
        exit(1);
    }

    $rows = [
        ['Manufacturer','Manufacturer'], ['Distributor','Distributor'], ['Wholesaler','Wholesaler'],
        ['Retailer','Retailer'], ['Service Provider','Service Provider'], ['Contractor','Contractor'],
        ['Consultant','Consultant'],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO form_lookups (lookup_key, value, label, sort_order) VALUES ('sub_contractor_type',?,?,?)");
    $n = 0; $i = 0;
    foreach ($rows as $r) { $ins->execute([$r[0], $r[1], $i++]); $n += $ins->rowCount(); }
    echo "Seeded $n sub_contractor_type option(s).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
