<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: form_lookups (extensible dropdown reference data)...\n";

/*
 * One generic reference-data table powering the self-growing dropdowns
 * (supplier_type, payment_terms, currency, ...) shared by Suppliers,
 * Sub-contractors and Customers. A field stores its chosen VALUE string on the
 * actor row (unchanged columns); the option list lives here and grows when a
 * user types a new value ("Other"). Mirrors how supplier_categories already works.
 */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS form_lookups (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            lookup_key  VARCHAR(64)  NOT NULL,
            value       VARCHAR(191) NOT NULL,
            label       VARCHAR(191) NOT NULL,
            sort_order  INT          NOT NULL DEFAULT 0,
            status      VARCHAR(16)  NOT NULL DEFAULT 'active',
            created_by  INT          NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_key_value (lookup_key, value),
            KEY idx_key_status (lookup_key, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "form_lookups table ready.\n";

    // Seed the current hardcoded options (INSERT IGNORE = idempotent, never duplicates).
    $seed = [
        'supplier_type' => [
            ['Manufacturer','Manufacturer'], ['Distributor','Distributor'], ['Wholesaler','Wholesaler'],
            ['Retailer','Retailer'], ['Service Provider','Service Provider'], ['Contractor','Contractor'],
            ['Consultant','Consultant'],
        ],
        'payment_terms' => [
            ['cod','Cash on Delivery'], ['7_days','7 Days'], ['15_days','15 Days'],
            ['30_days','30 Days'], ['60_days','60 Days'], ['90_days','90 Days'],
        ],
        'currency' => [
            ['TZS','Tanzanian Shilling (TZS)'], ['USD','US Dollar (USD)'], ['EUR','Euro (EUR)'],
            ['GBP','British Pound (GBP)'], ['KES','Kenyan Shilling (KES)'],
        ],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO form_lookups (lookup_key, value, label, sort_order) VALUES (?,?,?,?)");
    $n = 0;
    foreach ($seed as $key => $rows) {
        $i = 0;
        foreach ($rows as $r) { $ins->execute([$key, $r[0], $r[1], $i++]); $n += $ins->rowCount(); }
    }
    echo "Seeded $n option(s).\n";

    $total = (int)$pdo->query("SELECT COUNT(*) FROM form_lookups")->fetchColumn();
    echo "form_lookups now holds $total option(s).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
