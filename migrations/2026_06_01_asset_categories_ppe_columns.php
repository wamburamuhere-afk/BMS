<?php
/**
 * 2026_06_01_asset_categories_ppe_columns.php
 * --------------------------------------------
 * Asset Register & PPE Schedule — Phase 1 (1 of 4).
 *
 * Extends the existing asset_categories table into the document's "controller"
 * table. The existing default_method / default_useful_life_years /
 * default_annual_rate_percent / default_salvage_percent columns already serve
 * as the BOOK depreciation area defaults — so we only add the missing pieces:
 *
 *   code_prefix         → asset code generation (e.g. COMP → COMP-0001)
 *   is_depreciable      → 0 for Land; controls whether dep areas apply
 *   tax_rate            → statutory ITA reducing-balance rate (the TAX area)
 *   gl_asset_account    → GL determination (set per real chart of accounts)
 *   gl_accum_account
 *   gl_expense_account
 *
 * Backfill: existing rows get a derived code_prefix, is_depreciable=1, and
 * tax_rate seeded from their existing RB rate. A non-depreciable "Land"
 * category is added (INSERT IGNORE) so the is_depreciable=0 path is usable.
 *
 * GL accounts are left NULL — they are configured in Phase 2 (Category
 * Management) against the real BJP chart of accounts.
 *
 * Idempotent: each ALTER guarded by SHOW COLUMNS; backfill uses UPDATE /
 * INSERT IGNORE. Re-run safe.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: asset_categories PPE columns...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'asset_categories'")->fetch()) {
        echo "  ! asset_categories table not found — run the 2026_05_28 migration first.\n";
        exit(1);
    }

    $cols = [
        ['code_prefix',        "VARCHAR(10) NULL COMMENT 'Asset code prefix, e.g. COMP'"],
        ['is_depreciable',     "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 for Land / non-depreciable'"],
        ['tax_rate',           "DECIMAL(5,2) NULL COMMENT 'Statutory ITA reducing-balance rate (tax area)'"],
        ['gl_asset_account',   "VARCHAR(20) NULL"],
        ['gl_accum_account',   "VARCHAR(20) NULL"],
        ['gl_expense_account', "VARCHAR(20) NULL"],
    ];

    foreach ($cols as [$col, $def]) {
        $exists = $pdo->query("SHOW COLUMNS FROM `asset_categories` LIKE '{$col}'")->fetch();
        if ($exists) {
            echo "  · column {$col} already exists, skipping.\n";
        } else {
            $pdo->exec("ALTER TABLE `asset_categories` ADD COLUMN `{$col}` {$def}");
            echo "  + added column {$col}.\n";
        }
    }

    // ── Backfill code_prefix for known seed categories; generate for others ──
    $prefixMap = [
        'Buildings & Structures'       => 'BLDG',
        'Heavy Machinery & Plant'      => 'MACH',
        'Office Equipment & Furniture' => 'OE',
        'Vehicles'                     => 'MV',
        'Computer Hardware & Software' => 'COMP',
    ];

    $rows = $pdo->query("SELECT category_id, category_name, code_prefix, default_annual_rate_percent
                         FROM asset_categories")->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare("UPDATE asset_categories
                             SET code_prefix = ?, tax_rate = COALESCE(tax_rate, ?)
                           WHERE category_id = ? AND (code_prefix IS NULL OR code_prefix = '')");
    $filled = 0;
    foreach ($rows as $r) {
        if (!empty($r['code_prefix'])) continue;
        $name = $r['category_name'];
        if (isset($prefixMap[$name])) {
            $prefix = $prefixMap[$name];
        } else {
            // Generate: uppercase alphanumerics of the name, first 4 chars.
            $clean  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
            $prefix = substr($clean, 0, 4) ?: 'AST';
        }
        // Seed tax_rate from the existing RB rate as a sensible starting point.
        $upd->execute([$prefix, $r['default_annual_rate_percent'], $r['category_id']]);
        $filled++;
    }
    echo "  + backfilled code_prefix / tax_rate on {$filled} existing categories.\n";

    // ── Ensure a non-depreciable Land category exists (for the is_depreciable=0 path) ──
    $land = $pdo->prepare("INSERT IGNORE INTO asset_categories
        (category_name, tra_class, default_method, default_useful_life_years,
         default_annual_rate_percent, default_salvage_percent, description, status,
         code_prefix, is_depreciable, tax_rate)
        VALUES ('Land', NULL, 'straight_line', NULL, NULL, 0.00,
                'Freehold land — not depreciated. PPE schedule shows cost only.',
                'active', 'LAND', 0, NULL)");
    $land->execute();
    echo $land->rowCount() > 0
        ? "  + added non-depreciable 'Land' category.\n"
        : "  · 'Land' category already present.\n";

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
