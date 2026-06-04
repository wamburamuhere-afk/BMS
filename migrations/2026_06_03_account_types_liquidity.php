<?php
/**
 * 2026_06_03_account_types_liquidity
 *
 * Adds a dedicated `liquidity` classification to `account_types` so the
 * Balance Sheet can split Current vs Non-Current assets & liabilities from
 * DATA instead of the brittle account-name `strpos()` heuristics it uses today
 * (e.g. matching 'fixed', 'property', 'long term' in the account name).
 *
 * New column:
 *   account_types.liquidity  ENUM('current','non_current') NULL
 *     - current      → Current Asset / Current Liability
 *     - non_current  → Non-Current (Fixed) Asset / Long-Term Liability
 *     - NULL         → not applicable (equity / revenue / expense / cogs)
 *
 * Seeding strategy (idempotent — only fills rows still NULL):
 *   1. Reuse the signal already encoded by 2026_05_27 in cash_flow_category:
 *        asset      + investing  → non_current
 *        liability  + financing  → non_current
 *      (current asset/liability types were seeded as 'operating'/'cash').
 *   2. Plus a type_name pattern fallback for anything cash_flow_category
 *      didn't disambiguate.
 *   3. Any remaining asset / liability defaults to 'current' (conservative —
 *      the same default the old heuristic used).
 *
 * Servers where the 2026_05_27 classification migration hasn't run yet (no
 * `category` column) get the column added but no seed — the Balance Sheet's
 * name-heuristic fallback keeps working until they classify.
 *
 * Idempotent: SHOW COLUMNS guard on the ALTER; every UPDATE is gated on
 * `liquidity IS NULL`. No raw DDL inside a transaction.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: account_types.liquidity column + seed...\n";

try {
    // ── Guard: account_types table must exist ──────────────────────────
    if (!$pdo->query("SHOW TABLES LIKE 'account_types'")->fetch()) {
        echo "Table 'account_types' not found on this server — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // ── 1. Add the liquidity column (after cash_flow_category if present) ──
    if (!$pdo->query("SHOW COLUMNS FROM account_types LIKE 'liquidity'")->fetch()) {
        $after = $pdo->query("SHOW COLUMNS FROM account_types LIKE 'cash_flow_category'")->fetch()
               ? " AFTER cash_flow_category" : "";
        $pdo->exec("ALTER TABLE account_types ADD COLUMN `liquidity` ENUM('current','non_current') NULL$after");
        echo "✓ Column 'account_types.liquidity' added.\n";
    } else {
        echo "✓ Column 'account_types.liquidity' already exists — skipped.\n";
    }

    // ── 2. Seed — only if the classification columns exist on this server ──
    $hasCategory = (bool) $pdo->query("SHOW COLUMNS FROM account_types LIKE 'category'")->fetch();
    $hasCashFlow = (bool) $pdo->query("SHOW COLUMNS FROM account_types LIKE 'cash_flow_category'")->fetch();

    if (!$hasCategory) {
        echo "⚠ account_types.category not present (2026_05_27 not run) — column added but not seeded.\n";
        echo "  The Balance Sheet name-heuristic fallback remains in effect.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // type_name patterns that indicate a NON-CURRENT asset.
    $assetNonCurrent = "(" . implode(" OR ", array_map(fn($p) => "LOWER(type_name) LIKE '$p'", [
        '%fixed%', '%non-current%', '%non current%', '%long term%', '%long-term%',
        '%property%', '%plant%', '%equipment%', '%machinery%', '%vehicle%',
        '%intangible%', '%goodwill%', '%depreciation%',
    ])) . ")";

    // type_name patterns that indicate a NON-CURRENT (long-term) liability.
    $liabNonCurrent = "(" . implode(" OR ", array_map(fn($p) => "LOWER(type_name) LIKE '$p'", [
        '%long term%', '%long-term%', '%non-current%', '%non current%',
        '%loan%', '%mortgage%', '%bond%', '%debenture%',
    ])) . ")";

    $cf = $hasCashFlow ? "cash_flow_category" : "NULL";

    $steps = [
        "non-current assets" =>
            "UPDATE account_types SET liquidity='non_current'
              WHERE liquidity IS NULL AND category='asset'
                AND (" . ($hasCashFlow ? "$cf='investing' OR " : "") . "$assetNonCurrent)",
        "current assets (default)" =>
            "UPDATE account_types SET liquidity='current'
              WHERE liquidity IS NULL AND category='asset'",
        "non-current liabilities" =>
            "UPDATE account_types SET liquidity='non_current'
              WHERE liquidity IS NULL AND category='liability'
                AND (" . ($hasCashFlow ? "$cf='financing' OR " : "") . "$liabNonCurrent)",
        "current liabilities (default)" =>
            "UPDATE account_types SET liquidity='current'
              WHERE liquidity IS NULL AND category='liability'",
    ];

    foreach ($steps as $label => $sql) {
        $n = $pdo->exec($sql);
        echo "✓ Seeded $n account_types → $label.\n";
    }

    // ── 3. Report remaining unclassified asset/liability types ─────────
    $still = $pdo->query("
        SELECT type_id, type_name, category
          FROM account_types
         WHERE category IN ('asset','liability') AND liquidity IS NULL
    ")->fetchAll(PDO::FETCH_ASSOC);
    if ($still) {
        echo "\n⚠ These asset/liability types remain without liquidity (will use name-heuristic):\n";
        foreach ($still as $r) echo "   - [{$r['type_id']}] {$r['type_name']} ({$r['category']})\n";
    } else {
        echo "\n✓ Every asset & liability type now has a liquidity classification.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
