<?php
/**
 * Migration: 2026_10_02_cleanup_test_warehouses
 *
 * Removes shops/warehouses created by mobile-API testing (name LIKE 'ZZ %')
 * that have no POS sales, no stock rows and no stock movements. Their default
 * locations are removed with them. A row still referenced elsewhere (FK) is
 * skipped, never forced. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: cleanup ZZ test warehouses...\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tableExists = function (string $t) use ($pdo): bool {
        $r = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t));
        return (bool)($r && $r->fetch());
    };
    if (!$tableExists('warehouses')) { echo "  · warehouses table not present — skipping.\n"; exit(0); }

    $refChecks = [];
    foreach (['pos_sales', 'product_stocks', 'stock_movements'] as $t) {
        if ($tableExists($t)) $refChecks[] = $pdo->prepare("SELECT 1 FROM `$t` WHERE warehouse_id = ? LIMIT 1");
    }

    $ids = $pdo->query("SELECT warehouse_id FROM warehouses WHERE warehouse_name LIKE 'ZZ %'")->fetchAll(PDO::FETCH_COLUMN);
    $deleted = 0; $skipped = 0;
    foreach ($ids as $wid) {
        $wid = (int)$wid;
        foreach ($refChecks as $chk) {
            $chk->execute([$wid]);
            if ($chk->fetchColumn()) { $skipped++; continue 2; }
        }
        $pdo->beginTransaction();
        try {
            if ($tableExists('locations')) $pdo->prepare("DELETE FROM locations WHERE warehouse_id = ?")->execute([$wid]);
            $pdo->prepare("DELETE FROM warehouses WHERE warehouse_id = ?")->execute([$wid]);
            $pdo->commit();
            $deleted++;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $skipped++;
            echo "  · skipped warehouse #$wid (still referenced): " . $e->getMessage() . "\n";
        }
    }
    echo "  · deleted $deleted test warehouse(s); skipped $skipped with history.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
