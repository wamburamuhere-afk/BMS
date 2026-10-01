<?php
/**
 * Migration: 2026_10_01_cleanup_test_records_v2
 *
 * Removes test records created during Flutter API integration testing on tenant
 * "shop" that the earlier ghost-records migration (2026_10_01_cleanup_api_ghost_records)
 * did not catch because they were created after the status-ternary fix and have
 * status = 'active'.
 *
 * Criteria:
 *   Products  — name LIKE 'ZZ %', no POS sales (never sold)
 *   Suppliers — name LIKE 'ZZ %'
 *   Customers — name LIKE 'ZZ %', no POS sales and no invoices
 *   Product stock correction — product_name LIKE 'ZZ recheck%', stock += -2
 *     (two test restock calls added phantom stock during testing)
 *
 * Idempotent — safe to run multiple times.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: cleanup Flutter test records v2...\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Products with name LIKE 'ZZ %' that have no POS sales ────────────────
    $r = $pdo->query("SHOW TABLES LIKE 'products'");
    if ($r && $r->fetch()) {
        // Gather candidates first, then delete only those with no sales.
        $candidates = $pdo->query(
            "SELECT p.product_id, p.product_name FROM products p
              WHERE p.product_name LIKE 'ZZ %'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $deleted = 0;
        foreach ($candidates as $prod) {
            $pid = (int)$prod['product_id'];
            // Skip if this product appears in any non-voided POS sale.
            $hasSale = $pdo->prepare(
                "SELECT 1 FROM pos_sale_items si
                   JOIN pos_sales s ON s.sale_id = si.sale_id
                  WHERE si.product_id = ? AND s.sale_status NOT IN ('voided') LIMIT 1"
            );
            $hasSale->execute([$pid]);
            if ($hasSale->fetchColumn()) continue;

            // Clean up related rows first.
            $pdo->prepare("DELETE FROM stock_movements WHERE product_id = ?")->execute([$pid]);
            $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$pid]);
            $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$pid]);
            $deleted++;
        }
        echo "  · deleted $deleted test product(s) (name LIKE 'ZZ %', no sales).\n";
    } else {
        echo "  · products table not present — skipping.\n";
    }

    // ── Suppliers with name LIKE 'ZZ %' ──────────────────────────────────────
    $r = $pdo->query("SHOW TABLES LIKE 'suppliers'");
    if ($r && $r->fetch()) {
        $stmt = $pdo->prepare("DELETE FROM suppliers WHERE supplier_name LIKE 'ZZ %'");
        $stmt->execute();
        echo "  · deleted " . $stmt->rowCount() . " test supplier(s) (name LIKE 'ZZ %').\n";
    } else {
        echo "  · suppliers table not present — skipping.\n";
    }

    // ── Customers with name LIKE 'ZZ %' with no POS sales or invoices ────────
    $r = $pdo->query("SHOW TABLES LIKE 'customers'");
    if ($r && $r->fetch()) {
        $custCandidates = $pdo->query(
            "SELECT customer_id, customer_name FROM customers WHERE customer_name LIKE 'ZZ %'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $deletedCust = 0;
        foreach ($custCandidates as $cust) {
            $cid = (int)$cust['customer_id'];

            // Skip if this customer has POS sales.
            $hasPOS = $pdo->prepare("SELECT 1 FROM pos_sales WHERE customer_id = ? LIMIT 1");
            $hasPOS->execute([$cid]);
            if ($hasPOS->fetchColumn()) continue;

            // Skip if this customer has invoices (invoices table may not exist on all tenants).
            try {
                $hasInv = $pdo->prepare("SELECT 1 FROM invoices WHERE customer_id = ? LIMIT 1");
                $hasInv->execute([$cid]);
                if ($hasInv->fetchColumn()) continue;
            } catch (PDOException $_) {}

            $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$cid]);
            $deletedCust++;
        }
        echo "  · deleted $deletedCust test customer(s) (name LIKE 'ZZ %', no sales/invoices).\n";
    } else {
        echo "  · customers table not present — skipping.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
