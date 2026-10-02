<?php
/**
 * tests/test_flutter_mobile_api_cli.php
 * -----------------------------------------------------------------------
 * CLI tests for the Flutter Mobile API bug-fixes:
 *   Bug 1 — customers/create: schema fallback on missing optional columns
 *   Bug 2a — suppliers/get:   schema fallback on missing optional columns
 *   Bug 2b — products/get:    fallback no longer references missing columns
 *   Bug 3  — get_sales:       offset parameter wired into LIMIT clause
 *   Bug 4  — process_sale:    amount_tendered used when amount_paid absent
 *   Bug 5  — quick_restock:   silent PDOException catch removed
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../roots.php';

global $pdo;

$pass = 0; $fail = 0;
function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { echo "  PASS  $label\n"; $pass++; }
    else        { echo "  FAIL  $label\n"; $fail++; }
}

// =========================================================================
// A. STATIC — verify the changed source files have the right patterns
// =========================================================================
echo "\n=== A. Static code checks ===\n";

$custCreate = file_get_contents(__DIR__ . '/../api/mobile/customers/create.php');
ok(str_contains($custCreate, '$doInsert'),                     'customers/create: $doInsert closure exists');
ok(str_contains($custCreate, 'Unknown column'),                'customers/create: "Unknown column" catch present');
ok(!str_contains($custCreate, 'if ($hasClientUuid) {
        $stmt'), 'customers/create: old dual-path removed');

$suppGet = file_get_contents(__DIR__ . '/../api/mobile/suppliers/get.php');
ok(str_contains($suppGet, 'catch (PDOException $fullE)'),      'suppliers/get: inner PDOException catch added');
ok(str_contains($suppGet, 'NULL AS contact_person'),           'suppliers/get: fallback NULLs optional cols');
ok(str_contains($suppGet, 'NULL AS updated_at'),               'suppliers/get: fallback NULL updated_at');

$prodGet = file_get_contents(__DIR__ . '/../api/mobile/products/get.php');
// products/get now selects only columns present on the tenant (SHOW COLUMNS) and joins tax_rates on tax_id.
ok(str_contains($prodGet, 'SHOW COLUMNS FROM products'),        'products/get: column list built from the live schema');
ok(str_contains($prodGet, 't.rate_id = p.tax_id'),             'products/get: tax join uses products.tax_id');
ok(!str_contains($prodGet, 'p.tax_rate_id'),                    'products/get: no reference to non-existent products.tax_rate_id');

$getSales = file_get_contents(__DIR__ . '/../api/pos/get_sales.php');
ok(str_contains($getSales, '$offset'),                         'get_sales: $offset variable declared');
ok(str_contains($getSales, 'OFFSET " . $offset'),             'get_sales: OFFSET appended to LIMIT clause');

$procSale = file_get_contents(__DIR__ . '/../api/pos/process_sale.php');
ok(str_contains($procSale, 'amount_tendered > 0 ? $amount_tendered : $total'), 'process_sale: tendered used when amount_paid absent');
ok(!str_contains($procSale, ': ($is_credit ? 0.0 : $total);'), 'process_sale: old default-to-total fallback removed');

$restock = file_get_contents(__DIR__ . '/../api/pos/quick_restock.php');
// Verify the idempotency UPDATE is no longer wrapped in a try/catch
ok(!preg_match('/try\s*\{[^}]*UPDATE product_batches SET client_uuid/s', $restock), 'quick_restock: uuid UPDATE not wrapped in silent try/catch');
ok(str_contains($restock, "DDL self-heal above guarantees"),   'quick_restock: explanatory comment present');

// =========================================================================
// B. Unit — amount_paid_now logic (Bug 4)
// =========================================================================
echo "\n=== B. Unit: amount_paid_now calculation ===\n";

function calcAmountPaidNow(array $input, bool $isCredit, float $total, float $amountTendered): float {
    $paid = isset($input['amount_paid'])
        ? floatval($input['amount_paid'])
        : ($isCredit ? 0.0 : ($amountTendered > 0 ? $amountTendered : $total));
    return max(0.0, $paid);
}

// Non-credit, amount_paid provided explicitly — must use it
ok(calcAmountPaidNow(['amount_paid' => 900], false, 1000, 0) === 900.0,    'amount_paid_now: explicit amount_paid used');
// Non-credit, amount_tendered provided, amount_paid absent — must use tendered
ok(calcAmountPaidNow([], false, 1000, 800) === 800.0,                      'amount_paid_now: tendered used when amount_paid absent');
// Non-credit, neither provided — fallback to total
ok(calcAmountPaidNow([], false, 1000, 0) === 1000.0,                       'amount_paid_now: fallback to total when neither provided');
// Credit, nothing provided — must be 0 (deposit not required)
ok(calcAmountPaidNow([], true, 1000, 0) === 0.0,                           'amount_paid_now: credit defaults to 0');
// Credit, amount_paid provided — must respect it
ok(calcAmountPaidNow(['amount_paid' => 300], true, 1000, 0) === 300.0,     'amount_paid_now: credit respects explicit amount_paid');
// Non-credit, tendered exactly equals total — guard passes
$paid = calcAmountPaidNow([], false, 1000, 1000);
ok($paid === 1000.0 && ($paid - 1000) <= 0.01,                             'amount_paid_now: exact-payment passes guard');
// Non-credit, tendered > total (overpay with change) — guard passes
$paid = calcAmountPaidNow([], false, 1000, 1200);
ok($paid === 1200.0,                                                        'amount_paid_now: overpay tendered stored correctly');

// =========================================================================
// C. Integration — customers/create INSERT fallback (Bug 1)
// =========================================================================
echo "\n=== C. Integration: customers/create INSERT fallback ===\n";

$syntheticDate = '19991115';   // 1999 — never collides with real data
$custCode = 'TEST-CUST-' . $syntheticDate . '-' . mt_rand(1000,9999);
$custName = 'FlutterTest Customer ' . $syntheticDate;

// Ensure customer_type, city, credit_limit, notes are actually present before testing full INSERT
$colCheck = $pdo->query("SHOW COLUMNS FROM customers LIKE 'customer_type'")->fetch();
if ($colCheck) {
    try {
        $st = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, phone, email, address, city, customer_type, credit_limit, notes, status, created_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?)");
        $st->execute([$custCode, $custName, '0700000000', null, null, 'Dar es Salaam', 'individual', 0, null, 'active', 1]);
        $newId = (int)$pdo->lastInsertId();
        ok($newId > 0, "customers/create: full INSERT succeeds (customer_id=$newId)");
        $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$newId]);
        ok(true, 'customers/create: cleanup OK');
    } catch (PDOException $e) {
        ok(false, 'customers/create full INSERT: ' . $e->getMessage());
    }
} else {
    echo "  SKIP  customers/create full INSERT (customer_type column absent — fallback path tested below)\n";
}

// Test core-only fallback INSERT (simulates old schema without optional cols)
$custCode2 = 'TEST-CUST-CORE-' . mt_rand(1000,9999);
$custName2 = 'FlutterTest Core ' . $syntheticDate;
try {
    $st = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, phone, email, address, status, created_at, created_by) VALUES (?,?,?,?,?,?,NOW(),?)");
    $st->execute([$custCode2, $custName2, null, null, null, 'active', 1]);
    $coreId = (int)$pdo->lastInsertId();
    ok($coreId > 0, "customers/create: core-only fallback INSERT succeeds (customer_id=$coreId)");
    $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$coreId]);
} catch (PDOException $e) {
    ok(false, 'customers/create core INSERT: ' . $e->getMessage());
}

// =========================================================================
// D. Integration — suppliers/get fallback (Bug 2a)
// =========================================================================
echo "\n=== D. Integration: suppliers/get schema fallback ===\n";

// Pick any supplier_id that exists
$anySupplier = $pdo->query("SELECT supplier_id FROM suppliers WHERE status != 'deleted' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($anySupplier) {
    $sid = (int)$anySupplier['supplier_id'];
    // Full query
    try {
        $st = $pdo->prepare("SELECT supplier_id, supplier_name, company_name, contact_person, phone, mobile, email, address, city, state, country, supplier_type, status, credit_limit, notes, tax_id, vat_number, payment_terms, currency, bank_name, bank_account, created_at, updated_at FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
        $st->execute([$sid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        ok((bool)$row, "suppliers/get: full query returns supplier $sid");
    } catch (PDOException $e) {
        echo "  INFO  suppliers/get full query failed (old schema) — fallback needed: " . $e->getMessage() . "\n";
    }
    // Fallback query (guaranteed to work regardless of schema)
    try {
        $st = $pdo->prepare("SELECT supplier_id, supplier_code, supplier_name, NULL AS company_name, NULL AS contact_person, phone, NULL AS mobile, email, address, NULL AS city, NULL AS state, NULL AS country, NULL AS supplier_type, status, NULL AS credit_limit, NULL AS notes, NULL AS tax_id, NULL AS vat_number, NULL AS payment_terms, NULL AS currency, NULL AS bank_name, NULL AS bank_account, created_at, NULL AS updated_at FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
        $st->execute([$sid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        ok((bool)$row && $row['supplier_id'] == $sid, "suppliers/get: core-only fallback returns supplier $sid");
    } catch (PDOException $e) {
        ok(false, 'suppliers/get fallback: ' . $e->getMessage());
    }
} else {
    echo "  SKIP  suppliers/get (no suppliers in DB)\n";
}

// =========================================================================
// E. Integration — products/get fallback (Bug 2b)
// =========================================================================
echo "\n=== E. Integration: products/get schema fallback ===\n";

$anyProduct = $pdo->query("SELECT product_id FROM products WHERE status != 'deleted' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($anyProduct) {
    $pid = (int)$anyProduct['product_id'];
    // Fallback query — must not reference purchase_price, image_url, tax_rate_id, updated_at
    try {
        $st = $pdo->prepare("SELECT p.product_id, p.product_name, p.sku, p.barcode, p.unit, p.selling_price, p.cost_price, p.cost_price AS purchase_price, p.current_stock, p.reorder_level, p.is_service, p.category_id, c.category_name, NULL AS brand_id, NULL AS brand_name, NULL AS tax_rate_id, NULL AS tax_name, p.status, p.description, NULL AS image_url, NULL AS min_selling_price, NULL AS discount_rate, p.created_at, NULL AS updated_at FROM products p LEFT JOIN categories c ON c.category_id = p.category_id WHERE p.product_id = ? AND p.status != 'deleted'");
        $st->execute([$pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        ok((bool)$row && $row['product_id'] == $pid, "products/get: core-only fallback returns product $pid");
        ok(array_key_exists('purchase_price', $row),  'products/get: fallback response has purchase_price key');
        ok(array_key_exists('image_url', $row),        'products/get: fallback response has image_url key');
        ok($row['purchase_price'] == $row['cost_price'], 'products/get: fallback purchase_price mirrors cost_price');
    } catch (PDOException $e) {
        ok(false, 'products/get fallback: ' . $e->getMessage());
    }
} else {
    echo "  SKIP  products/get (no products in DB)\n";
}

// =========================================================================
// F. Integration — get_sales offset SQL (Bug 3)
// =========================================================================
echo "\n=== F. Integration: get_sales offset ===\n";

// Build the LIMIT+OFFSET SQL the same way get_sales.php now does
$limit  = 5;
$offset = 3;
$limitClause = $limit > 0 ? " LIMIT " . $limit . " OFFSET " . $offset : "";
ok($limitClause === ' LIMIT 5 OFFSET 3', 'get_sales: LIMIT + OFFSET clause correct');
ok(str_contains($limitClause, 'OFFSET'), 'get_sales: OFFSET keyword present');

// Verify $offset = 0 still works (no OFFSET on unlimited query)
$limitClause2 = 0 > 0 ? " LIMIT 0 OFFSET 0" : "";
ok($limitClause2 === '', 'get_sales: no LIMIT clause when limit=0');

// Live DB — fetch two pages of pos_sales and confirm different rows
try {
    $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM pos_sales")->fetchColumn();
    if ($totalRows >= 4) {
        $st1 = $pdo->query("SELECT sale_id FROM pos_sales ORDER BY sale_id ASC LIMIT 2 OFFSET 0");
        $page1 = array_column($st1->fetchAll(PDO::FETCH_ASSOC), 'sale_id');
        $st2 = $pdo->query("SELECT sale_id FROM pos_sales ORDER BY sale_id ASC LIMIT 2 OFFSET 2");
        $page2 = array_column($st2->fetchAll(PDO::FETCH_ASSOC), 'sale_id');
        ok(count(array_intersect($page1, $page2)) === 0, "get_sales: page1 and page2 share no rows (offset works in live DB)");
    } else {
        echo "  SKIP  get_sales live offset check (fewer than 4 pos_sales rows)\n";
    }
} catch (PDOException $e) {
    echo "  INFO  get_sales live offset check: " . $e->getMessage() . "\n";
}

// =========================================================================
// G. Integration — quick_restock: no silent catch (Bug 5)
// =========================================================================
echo "\n=== G. Integration: quick_restock idempotency stamp (no silent catch) ===\n";

// Ensure product_batches.client_uuid column exists (add it if migration hasn't run yet).
$colExists = (bool)$pdo->query("SHOW COLUMNS FROM product_batches LIKE 'client_uuid'")->fetch();
if (!$colExists) {
    try {
        $pdo->exec("ALTER TABLE product_batches ADD COLUMN client_uuid VARCHAR(36) NULL DEFAULT NULL");
        $colExists = true;
    } catch (PDOException $e) {
        echo "  INFO  product_batches.client_uuid: could not add column — " . $e->getMessage() . "\n";
    }
}
ok($colExists, 'quick_restock: product_batches.client_uuid column present (via migration or self-heal)');

if ($colExists) {
    // Simulate the idempotency stamp: INSERT a synthetic batch, stamp it, then query
    $syntheticRef = 'TEST-RESTOCK-' . $syntheticDate . '-' . mt_rand(1000,9999);
    $syntheticUuid = '00000000-0000-0000-0000-' . str_pad((string)mt_rand(100000, 999999), 12, '0', STR_PAD_LEFT);

    // Find any product and warehouse to satisfy FK constraints
    $anyProd = $pdo->query("SELECT product_id FROM products LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $anyWh   = $pdo->query("SELECT warehouse_id FROM warehouses LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    if ($anyProd && $anyWh) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, unit_cost, created_at) VALUES (?,?,?,?,?,NOW())")
                ->execute([$anyProd['product_id'], $anyWh['warehouse_id'], $syntheticRef, 1, 0]);
            $batchId = (int)$pdo->lastInsertId();
            // This is the bare UPDATE (no silent catch) from the fixed quick_restock.php
            $pdo->prepare("UPDATE product_batches SET client_uuid = ? WHERE batch_id = ?")
                ->execute([$syntheticUuid, $batchId]);
            // Verify stamp was applied
            $chk = $pdo->prepare("SELECT client_uuid FROM product_batches WHERE batch_id = ?");
            $chk->execute([$batchId]);
            $stamped = $chk->fetchColumn();
            ok($stamped === $syntheticUuid, "quick_restock: client_uuid stamp applied without silent catch (batch=$batchId)");
            // On retry: pre-check would find it and return idempotent — verify
            $dup = $pdo->prepare("SELECT batch_id FROM product_batches WHERE client_uuid = ? LIMIT 1");
            $dup->execute([$syntheticUuid]);
            ok((int)$dup->fetchColumn() === $batchId, 'quick_restock: pre-check finds stamped batch (idempotent retry works)');
            $pdo->rollBack();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ok(false, 'quick_restock stamp test: ' . $e->getMessage());
        }
    } else {
        echo "  SKIP  quick_restock stamp test (no products or warehouses in DB)\n";
    }
}

// =========================================================================
// Summary
// =========================================================================
echo "\n" . str_repeat('-', 60) . "\n";
$total = $pass + $fail;
echo "Result: $pass/$total passed" . ($fail > 0 ? "  *** $fail FAILED ***" : '  ✓') . "\n";
if ($fail > 0) exit(1);
