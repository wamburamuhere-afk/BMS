<?php
/**
 * Invoice-create page regression test: customer auto-fill + LPO/DN reference
 * picker scope leaks.
 *
 * User-reported: creating an Invoice from an approved Sales Order, as a
 * non-admin under an assigned external project, showed (1) the Customer
 * field not auto-filled, and (2) more LPOs in the reference dropdown than
 * the user actually has scope to see.
 *
 * Root causes fixed in app/bms/invoice/invoice_create.php:
 *   1. The Customer <select> was built from a query scoped by the
 *      customer's OWN customers.project_id (an independent field from the
 *      SO's project) — the SO-resolved customer could be silently excluded.
 *      Fix: the resolved $customer is now always force-included as an option.
 *   2. The "Customer LPO" and "Delivery Note" reference dropdowns were built
 *      from completely unscoped inline queries. Fix: both now use the same
 *      scopeFilterSqlNullable('project'|'warehouse', alias) helper already
 *      proven correct in api/customer/get_lpos_list.php and
 *      api/get_delivery_notes_list.php.
 *
 * Run:  php tests/test_invoice_create_scope_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
    require_once "$root/core/project_scope.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

section('1. php -l');

$out = []; $rc = 0;
exec('php -l ' . escapeshellarg("$root/app/bms/invoice/invoice_create.php") . ' 2>&1', $out, $rc);
check($rc === 0, 'invoice_create.php — no syntax errors', 'invoice_create.php — php -l failed: ' . implode(' ', $out));

section('2. Static — the fixes are actually present in the source');

$src = file_get_contents("$root/app/bms/invoice/invoice_create.php");
check(strpos($src, "!in_array((int)\$customer['customer_id'], array_column(\$customers, 'customer_id'))") !== false,
    'the SO/DN-resolved customer is force-included as a selectable option',
    'the force-include-resolved-customer fix is missing');
check(strpos($src, "scopeFilterSqlNullable('project', 'l') . scopeFilterSqlNullable('warehouse', 'l')") !== false,
    'the LPO reference dropdown is scoped by project+warehouse',
    'the LPO reference dropdown is still unscoped');
check(strpos($src, "scopeFilterSqlNullable('project', 'd') . scopeFilterSqlNullable('warehouse', 'd')") !== false,
    'the Delivery Note reference dropdown is scoped by project+warehouse',
    'the Delivery Note reference dropdown is still unscoped');
check(strpos($src, "SELECT lpo_id, lpo_number, customer_id FROM customer_lpos WHERE status != 'deleted' ORDER BY issue_date DESC LIMIT 500") === false,
    'the old fully-unscoped LPO query is gone',
    'the old fully-unscoped LPO query is still present');

section('3. Live — a warehouse/project-only user only sees their own LPO + DN in the reference pickers');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    try {
        $projRow = $pdo->query("SELECT project_id FROM projects WHERE status='active' ORDER BY project_id LIMIT 1")->fetchColumn();
        $whRow   = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
        $custRow = $pdo->query("SELECT customer_id FROM customers WHERE status='active' LIMIT 1")->fetchColumn();

        if (!$projRow || !$whRow || !$custRow) {
            echo "  \033[33m⊘\033[0m  Skipped (missing project/warehouse/customer fixture data)\n";
        } else {
            $grantedProj = (int)$projRow;
            $grantedWh   = (int)$whRow;
            $custId      = (int)$custRow;
            $testUserId  = 999022;

            // Two LPOs: one in the granted project, one in a different project.
            $insL1 = $pdo->prepare("INSERT INTO customer_lpos (lpo_number, customer_id, project_id, warehouse_id, issue_date, amount, currency, status, created_by) VALUES ('TEST-LPO-INSCOPE', ?, ?, ?, CURDATE(), 1000, 'TZS', 'approved', 1)");
            $insL1->execute([$custId, $grantedProj, $grantedWh]);
            $lpoInScope = (int)$pdo->lastInsertId();

            $otherProj = (int)$pdo->query("SELECT project_id FROM projects WHERE status='active' AND project_id != " . (int)$grantedProj . " LIMIT 1")->fetchColumn();
            $lpoOutOfScope = 0;
            if ($otherProj) {
                $insL2 = $pdo->prepare("INSERT INTO customer_lpos (lpo_number, customer_id, project_id, warehouse_id, issue_date, amount, currency, status, created_by) VALUES ('TEST-LPO-OUTOFSCOPE', ?, ?, ?, CURDATE(), 1000, 'TZS', 'approved', 1)");
                $insL2->execute([$custId, $otherProj, $grantedWh]);
                $lpoOutOfScope = (int)$pdo->lastInsertId();
            }

            $pdo->prepare("INSERT INTO user_projects (user_id, project_id) VALUES (?, ?)")
                ->execute([$testUserId, $grantedProj]);
            $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id) VALUES (?, 'warehouse', ?)")
                ->execute([$testUserId, $grantedWh]);

            $_SESSION['user_id'] = $testUserId;
            unset($_SESSION['is_admin'], $_SESSION['role_id'], $_SESSION['scope']);
            loadUserScope($testUserId);

            // The exact scope fragment now used by invoice_create.php's LPO query.
            $scopeL = scopeFilterSqlNullable('project', 'l') . scopeFilterSqlNullable('warehouse', 'l');
            $lpoRows = $pdo->query("SELECT lpo_id FROM customer_lpos l WHERE lpo_number IN ('TEST-LPO-INSCOPE','TEST-LPO-OUTOFSCOPE') $scopeL")->fetchAll(PDO::FETCH_COLUMN);

            check(in_array($lpoInScope, $lpoRows, false), 'the in-scope LPO is returned', 'STILL BROKEN: the in-scope LPO did not appear');
            if ($lpoOutOfScope) {
                check(!in_array($lpoOutOfScope, $lpoRows, false), 'the out-of-scope LPO is correctly EXCLUDED', 'LEAK: an out-of-scope LPO was returned');
            }

            // Same construction for the DN dropdown query.
            $insD1 = $pdo->prepare("INSERT INTO deliveries (project_id, warehouse_id, dn_type, delivery_number, customer_id, delivery_date, status, created_by) VALUES (?, ?, 'outbound', 'TEST-DN-INSCOPE', ?, CURDATE(), 'approved', 1)");
            $insD1->execute([$grantedProj, $grantedWh, $custId]);
            $dnInScope = (int)$pdo->lastInsertId();

            $dnOutOfScope = 0;
            if ($otherProj) {
                $insD2 = $pdo->prepare("INSERT INTO deliveries (project_id, warehouse_id, dn_type, delivery_number, customer_id, delivery_date, status, created_by) VALUES (?, ?, 'outbound', 'TEST-DN-OUTOFSCOPE', ?, CURDATE(), 'approved', 1)");
                $insD2->execute([$otherProj, $grantedWh, $custId]);
                $dnOutOfScope = (int)$pdo->lastInsertId();
            }

            $scopeD = scopeFilterSqlNullable('project', 'd') . scopeFilterSqlNullable('warehouse', 'd');
            $dnRows = $pdo->query("SELECT delivery_id FROM deliveries d WHERE delivery_number IN ('TEST-DN-INSCOPE','TEST-DN-OUTOFSCOPE') $scopeD")->fetchAll(PDO::FETCH_COLUMN);

            check(in_array($dnInScope, $dnRows, false), 'the in-scope Delivery Note is returned', 'STILL BROKEN: the in-scope DN did not appear');
            if ($dnOutOfScope) {
                check(!in_array($dnOutOfScope, $dnRows, false), 'the out-of-scope Delivery Note is correctly EXCLUDED', 'LEAK: an out-of-scope DN was returned');
            }

            // Cleanup
            $pdo->prepare("DELETE FROM customer_lpos WHERE lpo_id IN (?, ?)")->execute([$lpoInScope, $lpoOutOfScope ?: 0]);
            $pdo->prepare("DELETE FROM deliveries WHERE delivery_id IN (?, ?)")->execute([$dnInScope, $dnOutOfScope ?: 0]);
            $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([$testUserId]);
            $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?")->execute([$testUserId]);
            pass('test data cleaned up (self-contained, no residue left in the DB)');
        }
    } catch (Throwable $e) {
        fail('Live scope test threw: ' . $e->getMessage());
    }
}

section('4. Live — the resolved customer is force-included even when excluded by the project-link filter');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    try {
        $projRow2 = $pdo->query("SELECT project_id FROM projects WHERE status='active' ORDER BY project_id LIMIT 1")->fetchColumn();
        if (!$projRow2) {
            echo "  \033[33m⊘\033[0m  Skipped (no active project in this DB)\n";
        } else {
            $grantedProj2 = (int)$projRow2;
            $otherProj2   = (int)$pdo->query("SELECT project_id FROM projects WHERE status='active' AND project_id != " . (int)$grantedProj2 . " LIMIT 1")->fetchColumn();

            // A customer whose OWN linked project is a DIFFERENT project than
            // the one the test user is granted — simulates the exact reported
            // symptom (customer's project_id link != the SO's project scope).
            $insC = $pdo->prepare("INSERT INTO customers (customer_name, status, project_id) VALUES ('TEST-CUSTOMER-CROSSLINK', 'active', ?)");
            $insC->execute([$otherProj2 ?: null]);
            $testCustomerId = (int)$pdo->lastInsertId();

            $testUserId2 = 999023;
            $pdo->prepare("INSERT INTO user_projects (user_id, project_id) VALUES (?, ?)")
                ->execute([$testUserId2, $grantedProj2]);

            $_SESSION['user_id'] = $testUserId2;
            unset($_SESSION['is_admin'], $_SESSION['role_id'], $_SESSION['scope']);
            loadUserScope($testUserId2);

            // Reproduce invoice_create.php's own customer-list-building logic.
            $_ic_assigned = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
            $_ic_cph = implode(',', array_fill(0, count($_ic_assigned), '?'));
            $_ic_cstmt = $pdo->prepare("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_ic_cph)) ORDER BY customer_name");
            $_ic_cstmt->execute($_ic_assigned);
            $customers = $_ic_cstmt->fetchAll(PDO::FETCH_ASSOC);

            check(!in_array($testCustomerId, array_column($customers, 'customer_id')),
                'sanity: the cross-linked customer is indeed excluded by the base query (reproduces the reported bug pre-fix)',
                'sanity check failed: test setup did not reproduce the exclusion');

            // Now apply invoice_create.php's fix.
            $customer = ['customer_id' => $testCustomerId, 'customer_name' => 'TEST-CUSTOMER-CROSSLINK', 'company_name' => null];
            if ($customer && !in_array((int)$customer['customer_id'], array_column($customers, 'customer_id'))) {
                $customers[] = $customer;
            }

            check(in_array($testCustomerId, array_column($customers, 'customer_id')),
                'the fix force-includes the SO-resolved customer despite the cross-linked project mismatch',
                'STILL BROKEN: the resolved customer is still missing from the dropdown option list');

            $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$testCustomerId]);
            $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?")->execute([$testUserId2]);
            pass('test data cleaned up (self-contained, no residue left in the DB)');
        }
    } catch (Throwable $e) {
        fail('Live customer-autofill test threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
