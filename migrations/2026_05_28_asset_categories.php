<?php
/**
 * 2026_05_28_asset_categories.php
 * --------------------------------
 * Foundational depreciation feature — Phase 1 (migration 1 of 3).
 *
 * Creates the asset_categories master table and seeds it with the canonical
 * Tanzanian Revenue Authority (TRA) depreciation classes so users have a
 * standards-aligned starting point. They can add their own categories later.
 *
 * Each category captures sensible defaults (method, useful life, RB rate,
 * salvage %) which auto-fill the asset form when a category is selected.
 * The user can still override per-asset.
 *
 * Idempotent: re-run safe. Existing categories preserved.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: asset_categories...\n";

try {
    // ── Create table ───────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asset_categories (
            category_id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            category_name                VARCHAR(100)  NOT NULL,
            tra_class                    VARCHAR(20)   NULL,
            default_method               ENUM('straight_line','reducing_balance')
                                                       NOT NULL DEFAULT 'straight_line',
            default_useful_life_years    INT           NULL,
            default_annual_rate_percent  DECIMAL(5,2)  NULL,
            default_salvage_percent      DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
            description                  TEXT          NULL,
            status                       ENUM('active','archived')
                                                       NOT NULL DEFAULT 'active',
            created_at                   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at                   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                       ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (category_id),
            UNIQUE KEY uq_category_name (category_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + Table asset_categories created (or already exists).\n";

    // ── Seed canonical TRA classes ─────────────────────────────────────────
    // Defaults chosen for SME-friendly TFRS reporting:
    //   - Method = straight_line (simpler; user can switch to reducing per asset)
    //   - useful_life matches typical SME asset lives
    //   - rb_rate set to TRA-published class rate (used if method switched to RB)
    //   - salvage % = 0 by default (user can override per asset)
    $seed = [
        // [name, tra_class, useful_life_years, rb_rate_percent, description]
        ['Buildings & Structures',       'Class 1', 25, 5.00,
         'Permanent buildings, warehouses, fixed civil structures. TRA Class 1.'],
        ['Heavy Machinery & Plant',      'Class 2', 10, 25.00,
         'Industrial machinery, fixed plant, generators >50KVA. TRA Class 2.'],
        ['Office Equipment & Furniture', 'Class 3',  5, 25.00,
         'Desks, chairs, photocopiers, printers, HVAC. TRA Class 3.'],
        ['Vehicles',                     'Class 4',  4, 25.00,
         'Cars, trucks, motorbikes, light commercial vehicles. TRA Class 4.'],
        ['Computer Hardware & Software', 'Class 5',  3, 33.33,
         'Laptops, servers, networking, software licences. TRA Class 5.'],
    ];

    $check = $pdo->prepare("SELECT COUNT(*) FROM asset_categories WHERE category_name = ?");
    $insert = $pdo->prepare("
        INSERT INTO asset_categories
            (category_name, tra_class, default_method,
             default_useful_life_years, default_annual_rate_percent,
             default_salvage_percent, description, status)
        VALUES (?, ?, 'straight_line', ?, ?, 0.00, ?, 'active')
    ");

    $inserted = 0; $skipped = 0;
    foreach ($seed as [$name, $tra, $life, $rb, $desc]) {
        $check->execute([$name]);
        if ((int)$check->fetchColumn() > 0) {
            $skipped++;
            continue;
        }
        $insert->execute([$name, $tra, $life, $rb, $desc]);
        $inserted++;
    }
    echo "  + Seeded: $inserted new, $skipped already present.\n";

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
