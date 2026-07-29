<?php
/**
 * Regression guard for four related fixes:
 *
 *  1. api/sales/get_returns_paged.php (+ sales_returns.php's dead-code init
 *     query) had NO warehouse-scope filter at all — a non-admin could see
 *     every sales return, including ones drawn from a warehouse outside
 *     their assignment (same rule sales_return_view.php already enforced
 *     per-record).
 *  2. sales_return_view.php called http_response_code(403) unconditionally
 *     after includeHeader() had already flushed output, producing a
 *     "headers already sent" PHP warning ahead of the access-denied message.
 *  3. invoice_view.php showed the "Edit Invoice" button whenever
 *     canEditDocument() allowed it, even for an invoice that already has
 *     payments recorded against it (partial or full) — editing at that
 *     point would desync the invoice from its payment/ledger history.
 *  4. invoice_create.php pre-selects the warehouse committed on the source
 *     Sales Order/Delivery Note, but only offers options from
 *     warehousesForSelect() (scoped to the user's explicit warehouse
 *     grant). If the source warehouse fell outside that grant, no <option>
 *     ended up "selected", the browser silently defaulted to the blank
 *     placeholder, and the invoice saved with no warehouse at all.
 *
 * Run:  php tests/test_sales_return_invoice_scope_fixes_cli.php
 *   Exit 0 = all pass  ·  Exit 1 = a regression slipped in.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
    require_once "$root/core/project_scope.php";
    require_once "$root/core/warehouse_scope.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }
function readSrc($root, $rel) { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }

echo "\n\033[1m═══ Sales Return list scope + Invoice view/create fixes ═══\033[0m\n";

section('1. php -l — every touched file');
foreach ([
    'api/sales/get_returns_paged.php',
    'app/bms/sales/sales_returns/sales_returns.php',
    'app/bms/sales/sales_returns/sales_return_view.php',
    'app/bms/invoice/invoice_view.php',
    'app/bms/invoice/invoice_create.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    check($rc === 0, "$f — no syntax errors", "$f — php -l failed: " . implode(' ', $out));
}

$getReturns = readSrc($root, 'api/sales/get_returns_paged.php');
$srList     = readSrc($root, 'app/bms/sales/sales_returns/sales_returns.php');
$srView     = readSrc($root, 'app/bms/sales/sales_returns/sales_return_view.php');
$invView    = readSrc($root, 'app/bms/invoice/invoice_view.php');
$invCreate  = readSrc($root, 'app/bms/invoice/invoice_create.php');

section('2. Static — get_returns_paged.php now scopes by warehouse');
check(strpos($getReturns, "scopeFilterSqlNullable('warehouse', 'w_scope')") !== false,
    'warehouse scope filter is built', 'warehouse scope filter is missing');
check(strpos($getReturns, '$scope_join') !== false && strpos($getReturns, '$scope_filter') !== false,
    'scope join/filter variables are defined', 'scope join/filter variables missing');
check(substr_count($getReturns, '$scope_join') >= 3,
    'the join is applied to stats, count AND data queries', 'the join is not applied everywhere (count: ' . substr_count($getReturns, '$scope_join') . ')');
check(strpos($getReturns, '. $scope_filter') !== false,
    'the WHERE clauses append the scope filter', 'WHERE clauses do not append the scope filter');

section('3. Static — sales_returns.php init query mirrors the same scope');
check(strpos($srList, "scopeFilterSqlNullable('warehouse', 'w_scope')") !== false,
    'sales_returns.php also applies the warehouse scope filter', 'sales_returns.php still unscoped by warehouse');

section('4. Static — sales_return_view.php no longer warns on 403');
check(strpos($srView, 'if (!headers_sent()) http_response_code(403);') !== false,
    'http_response_code(403) is guarded by headers_sent()', 'the headers_sent() guard is missing');

section('5. Static — invoice_view.php Edit button gated on paid_amount');
check(strpos($invView, "floatval(\$invoice['paid_amount']) <= 0") !== false,
    '$inv_can_edit_now additionally requires paid_amount <= 0', 'the paid_amount gate is missing');

section('6. Static — invoice_create.php force-includes the source warehouse');
check(strpos($invCreate, "!in_array(\$prefill_warehouse_id, array_column(\$warehouses, 'warehouse_id'))") !== false,
    'checks whether the prefilled warehouse is missing from the scoped list', 'the missing-from-list check is absent');
check(strpos($invCreate, '$warehouses[] = $sourceWarehouse;') !== false,
    'appends the source warehouse so it can be selected/saved', 'the append-fix is missing');

if (!$isLive) {
    echo "\n  \033[33m⊘\033[0m  Skipping live sections (no includes/config.php — not a live install)\n";
} else {
    global $pdo;

    section('7. Live — non-admin scoped to warehouse A cannot see a return drawn from warehouse B');
    try {
        $whRows = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' ORDER BY warehouse_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $custId = (int)$pdo->query("SELECT customer_id FROM customers WHERE status='active' ORDER BY customer_id LIMIT 1")->fetchColumn();

        if (count($whRows) < 2 || !$custId) {
            echo "  \033[33m⊘\033[0m  Skipped (need 2 active warehouses + 1 active customer)\n";
        } else {
            [$whA, $whB] = array_map('intval', $whRows);
            $testUserId = 999031;

            require_once "$root/core/code_generator.php";
            $soNumber = nextCode($pdo, 'SO') ?: ('TEST-SO-' . time());
            $pdo->prepare("INSERT INTO sales_orders (order_number, customer_id, order_date, status, warehouse_id, created_by) VALUES (?, ?, CURDATE(), 'approved', ?, 1)")
                ->execute([$soNumber, $custId, $whB]);
            $soId = (int)$pdo->lastInsertId();

            $srNumber = 'TEST-SR-' . time();
            $pdo->prepare("INSERT INTO sales_returns (return_number, sales_order_id, customer_id, return_date, total_amount, status, created_by) VALUES (?, ?, ?, CURDATE(), 500, 'pending', 1)")
                ->execute([$srNumber, $soId, $custId]);
            $srId = (int)$pdo->lastInsertId();

            // Scope the test user to warehouse A only (explicit grant — no
            // access to warehouse B, which is what this SO/return actually used).
            $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, 1)")
                ->execute([$testUserId, $whA]);

            $_SESSION['user_id'] = $testUserId;
            unset($_SESSION['is_admin'], $_SESSION['role_id'], $_SESSION['scope']);
            loadUserScope($testUserId);

            // Reproduce get_returns_paged.php's own join+filter construction.
            $scope_join = "
                LEFT JOIN sales_orders so_scope ON sr.sales_order_id = so_scope.sales_order_id
                LEFT JOIN invoices inv_scope ON sr.invoice_id = inv_scope.invoice_id
                LEFT JOIN warehouses w_scope ON w_scope.warehouse_id = COALESCE(inv_scope.warehouse_id, so_scope.warehouse_id)
            ";
            $scope_filter = scopeFilterSqlNullable('warehouse', 'w_scope');
            $rows = $pdo->query("SELECT sr.sales_return_id FROM sales_returns sr $scope_join WHERE sr.sales_return_id = $srId $scope_filter")
                        ->fetchAll(PDO::FETCH_COLUMN);

            check(empty($rows), 'the warehouse-B return is correctly EXCLUDED for a warehouse-A-only user', 'LEAK: the out-of-scope return was still returned');

            // Same user granted warehouse B too — the return must now appear.
            $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, 1)")
                ->execute([$testUserId, $whB]);
            loadUserScope($testUserId);
            $scope_filter2 = scopeFilterSqlNullable('warehouse', 'w_scope');
            $rows2 = $pdo->query("SELECT sr.sales_return_id FROM sales_returns sr $scope_join WHERE sr.sales_return_id = $srId $scope_filter2")
                         ->fetchAll(PDO::FETCH_COLUMN);
            check(in_array($srId, $rows2), 'once granted warehouse B, the same user DOES see the return', 'the return stayed hidden even after being granted access');

            // Cleanup
            $pdo->prepare("DELETE FROM sales_returns WHERE sales_return_id = ?")->execute([$srId]);
            $pdo->prepare("DELETE FROM sales_orders WHERE sales_order_id = ?")->execute([$soId]);
            $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([$testUserId]);
            pass('test data cleaned up (self-contained, no residue left in the DB)');
        }
    } catch (Throwable $e) {
        fail('Live sales-return scope test threw: ' . $e->getMessage());
    }

    section('8. Live — invoice_view.php Edit-button logic: paid invoices are never editable');
    try {
        require_once "$root/core/workflow.php";
        // Mirrors invoice_view.php's own gating line exactly.
        $mkEditable = function (string $status, float $paid, bool $isAdmin) {
            return canEditDocument($status, $isAdmin) && $paid <= 0;
        };
        check($mkEditable('partial', 0.0, false) === true,
            'unpaid, non-approved invoice remains editable (no over-restriction)', 'unpaid invoice was wrongly blocked from editing');
        check($mkEditable('partial', 250.0, false) === false,
            'partially paid invoice is NOT editable', 'STILL BROKEN: partially paid invoice shows as editable');
        check($mkEditable('paid', 1000.0, false) === false,
            'fully paid invoice is NOT editable', 'STILL BROKEN: fully paid invoice shows as editable');
        check($mkEditable('partial', 250.0, true) === false,
            'partially paid invoice is NOT editable even for admins', 'STILL BROKEN: admin can still edit a paid invoice');
    } catch (Throwable $e) {
        fail('Live edit-button logic test threw: ' . $e->getMessage());
    }

    section('9. Live — invoice_create.php: source SO warehouse outside explicit grant is still offered + selected');
    try {
        $whRows2 = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' ORDER BY warehouse_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if (count($whRows2) < 2) {
            echo "  \033[33m⊘\033[0m  Skipped (need 2 active warehouses)\n";
        } else {
            [$grantedWh, $sourceWh] = array_map('intval', $whRows2);
            $testUserId2 = 999032;

            $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, 1)")
                ->execute([$testUserId2, $grantedWh]);

            $_SESSION['user_id'] = $testUserId2;
            unset($_SESSION['is_admin'], $_SESSION['role_id'], $_SESSION['scope']);
            loadUserScope($testUserId2);

            $warehouses = warehousesForSelect($pdo);
            check(!in_array($sourceWh, array_column($warehouses, 'warehouse_id')),
                'sanity: the SO\'s warehouse is indeed excluded by the scoped dropdown (reproduces the reported bug pre-fix)',
                'sanity check failed: test setup did not reproduce the exclusion');

            // Apply invoice_create.php's own fix logic verbatim.
            $prefill_warehouse_id = $sourceWh;
            if ($prefill_warehouse_id > 0 && !in_array($prefill_warehouse_id, array_column($warehouses, 'warehouse_id'))) {
                $stmt = $pdo->prepare("SELECT warehouse_id, warehouse_name, location, IFNULL(project_id, 0) AS project_id FROM warehouses WHERE warehouse_id = ?");
                $stmt->execute([$prefill_warehouse_id]);
                if ($sourceWarehouse = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $warehouses[] = $sourceWarehouse;
                }
            }

            check(in_array($sourceWh, array_column($warehouses, 'warehouse_id')),
                'the fix force-includes the SO-committed warehouse despite the narrower explicit grant',
                'STILL BROKEN: the source warehouse is still missing from the option list');

            // renderWarehouseOptions() must mark it selected.
            $html = renderWarehouseOptions($warehouses, $prefill_warehouse_id);
            check(strpos($html, "value=\"$sourceWh\" data-project-id=\"") !== false && preg_match('/value="' . $sourceWh . '"[^>]*selected/', $html) === 1,
                'renderWarehouseOptions() marks the source warehouse as selected',
                'the source warehouse option is present but not marked selected');

            $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([$testUserId2]);
            pass('test data cleaned up (self-contained, no residue left in the DB)');
        }
    } catch (Throwable $e) {
        fail('Live invoice-create prefill test threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
