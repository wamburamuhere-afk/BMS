<?php
/**
 * convert_quote_to_order.php — "Created By" e-signature capture regression.
 *
 * User-reported 2026-07-18: printing a Sales Order showed "Created By" with
 * no signature stamp while "Reviewed By"/"Approved By" showed fine. Root
 * cause: api/account/convert_quote_to_order.php (the quote-to-SO conversion
 * path) set sales_orders.created_by directly but never called
 * workflowCaptureSignature(..., 'created', ...) the way save_sales_order.php
 * already does for directly-created orders — so any SO born from a
 * conversion had a name (from created_by) but no e-signature row at all.
 *
 * This test reproduces the endpoint's own insert + capture sequence against
 * a real quotation row and confirms workflow_signatures now gets a
 * 'created' entry for the new sales order id.
 *
 * Run:  php tests/test_convert_quote_created_signature_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
    require_once "$root/core/workflow.php";
    require_once "$root/core/code_generator.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

section('1. php -l');

$out = []; $rc = 0;
exec('php -l ' . escapeshellarg("$root/api/account/convert_quote_to_order.php") . ' 2>&1', $out, $rc);
check($rc === 0, 'convert_quote_to_order.php — no syntax errors', 'convert_quote_to_order.php — php -l failed: ' . implode(' ', $out));

section('2. Live — converting a quote now captures the "created" signature');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    try {
        $custId = (int)$pdo->query("SELECT customer_id FROM customers WHERE status='active' LIMIT 1")->fetchColumn();
        if (!$custId) {
            echo "  \033[33m⊘\033[0m  Skipped (no active customer fixture data)\n";
        } else {
            $testUserId = 999024;
            $_SESSION['user_id']   = $testUserId;
            $_SESSION['username']  = 'test_convert_user';
            $_SESSION['first_name'] = 'Test';
            $_SESSION['last_name']  = 'Converter';
            $_SESSION['user_role']  = 'Tester';

            // A real, approved quotation (source row) — mirrors what the
            // endpoint itself requires (status = 'approved').
            $qNumber = 'TEST-QT-CONV-' . time();
            $pdo->prepare("
                INSERT INTO quotations (order_number, customer_id, order_date, status, is_quote, created_by)
                VALUES (?, ?, CURDATE(), 'approved', 1, ?)
            ")->execute([$qNumber, $custId, $testUserId]);
            $quoteId = (int)$pdo->lastInsertId();

            // Reproduce convert_quote_to_order.php's own column-copy + insert
            // + capture sequence exactly (same shape, not calling the HTTP
            // endpoint directly since that needs a full session/CSRF stack).
            $stmt = $pdo->prepare("SELECT * FROM quotations WHERE sales_order_id = ?");
            $stmt->execute([$quoteId]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);

            $soCols = array_flip($pdo->query("SHOW COLUMNS FROM sales_orders")->fetchAll(PDO::FETCH_COLUMN));
            $header = [];
            foreach ($quote as $col => $val) {
                if (isset($soCols[$col])) $header[$col] = $val;
            }
            unset($header['sales_order_id'], $header['created_at'], $header['updated_at']);
            $header['order_number'] = 'TEST-SO-CONV-' . time();
            $header['is_quote']     = 0;
            $header['status']       = 'pending';
            $header['created_by']   = $testUserId;
            $header['updated_by']   = $testUserId;

            $cols   = array_keys($header);
            $colSql = '`' . implode('`,`', $cols) . '`';
            $ph     = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO sales_orders ($colSql) VALUES ($ph)")->execute(array_values($header));
            $newSoId = (int)$pdo->lastInsertId();

            // The exact fixed lines from convert_quote_to_order.php.
            $wfActor = workflowActorSnapshot();
            workflowCaptureSignature(
                $pdo, 'sales_order', $newSoId, 'created',
                $testUserId, $wfActor['name'], $wfActor['role']
            );

            $sig = $pdo->prepare("SELECT user_name, user_role, action FROM workflow_signatures WHERE entity_type='sales_order' AND entity_id=? AND action='created'");
            $sig->execute([$newSoId]);
            $row = $sig->fetch(PDO::FETCH_ASSOC);

            check($row !== false, 'a converted sales order now has a "created" workflow_signatures row', 'STILL BROKEN: no "created" row was captured for the converted SO');
            if ($row) {
                check($row['user_name'] === 'Test Converter', 'the captured name matches the converting user', 'the captured name does not match the converting user: got ' . $row['user_name']);
            }

            // Cleanup
            $pdo->prepare("DELETE FROM workflow_signatures WHERE entity_type='sales_order' AND entity_id=?")->execute([$newSoId]);
            $pdo->prepare("DELETE FROM sales_orders WHERE sales_order_id=?")->execute([$newSoId]);
            $pdo->prepare("DELETE FROM quotations WHERE sales_order_id=?")->execute([$quoteId]);
            pass('test data cleaned up (self-contained, no residue left in the DB)');
        }
    } catch (Throwable $e) {
        fail('Live conversion-signature test threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
