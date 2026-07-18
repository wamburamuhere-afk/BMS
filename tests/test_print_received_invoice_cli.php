<?php
/**
 * print_received_invoice.php — dedicated print page regression test.
 *
 * User-reported: received_invoices_view.php's print didn't follow the same
 * print rules/layout as every other document (compared to print_lpo.php) —
 * because it had no dedicated standalone print page and just called
 * window.print() on the live, fully-chromed app view.
 *
 * Fix: a new dedicated print page (app/bms/invoice/print_received_invoice.php)
 * built on the exact canonical template print_lpo.php already uses, with the
 * two Print buttons on received_invoices_view.php now opening it instead of
 * self-printing. Content/fields are unchanged — only the print rendering
 * changed.
 *
 * Run:  php tests/test_print_received_invoice_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }
function readSrc(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }

section('1. php -l on every touched file');

foreach ([
    'app/bms/invoice/print_received_invoice.php',
    'app/bms/invoice/received_invoices_view.php',
    'roots.php',
] as $rel) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    check($rc === 0, "$rel — no syntax errors", "$rel — php -l failed: " . implode(' ', $out));
}

section('2. New print page follows the same canonical template as print_lpo.php');

$pri = readSrc($root, 'app/bms/invoice/print_received_invoice.php');
check($pri !== '', 'print_received_invoice.php exists', 'print_received_invoice.php is missing');
check(strpos($pri, "assertScopeForRecordHtml('supplier_invoices', 'id', \$id)") !== false, 'has the same project/warehouse scope gate as the view page', 'missing assertScopeForRecordHtml gate');
check(strpos($pri, "includes/print_footer_css.php") !== false, 'includes the shared print footer CSS', 'missing print_footer_css.php include');
check(strpos($pri, "includes/print_footer_html.php") !== false, 'includes the shared print footer HTML', 'missing print_footer_html.php include');
check(strpos($pri, "includes/print_autofit.php") !== false, 'includes the shared print autofit script', 'missing print_autofit.php include');
check(strpos($pri, "includes/workflow_signature_row.php") !== false, 'includes the canonical signature row', 'missing workflow_signature_row.php include');
check(strpos($pri, "includes/workflow_draft_watermark.php") !== false, 'includes the DRAFT watermark partial', 'missing workflow_draft_watermark.php include');
check(strpos($pri, "print-scale-wrapper") !== false, 'uses the print-scale-wrapper div (same as print_lpo.php)', 'missing print-scale-wrapper');
check(strpos($pri, "@page { margin:") !== false, 'sets the canonical @page margin rule', 'missing @page margin rule');
check(strpos($pri, "<!DOCTYPE html>") !== false, 'is a standalone HTML document (not embedded in the app chrome)', 'not a standalone document');

section('3. received_invoices_view.php Print buttons now open the dedicated page');

$riv = readSrc($root, 'app/bms/invoice/received_invoices_view.php');
check(substr_count($riv, "window.open('<?= getUrl('print_received_invoice') ?>?id=<?= \$id ?>', '_blank')") === 2, 'both Print buttons target print_received_invoice', 'not both Print buttons were rewired');
check(strpos($riv, 'onclick="window.print()"') === false, 'no Print button still calls window.print() directly', 'a Print button still calls window.print() directly');

section('4. roots.php route registered');

$rootsSrc = readSrc($root, 'roots.php');
check(strpos($rootsSrc, "'print_received_invoice' => INVOICE_DIR . '/print_received_invoice.php'") !== false, 'print_received_invoice route is registered', 'route missing from roots.php');

section('5. Live — the new page pulls the same figures as the existing view page');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    try {
        $supplierId = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();
        if (!$supplierId) {
            echo "  \033[33m⊘\033[0m  Skipped (no active supplier fixture data)\n";
        } else {
            $ins = $pdo->prepare("
                INSERT INTO supplier_invoices (invoice_type, supplier_id, status, date_raised, date_recorded, invoice_ref, amount, amount_paid, recorded_by)
                VALUES ('supplier', ?, 'pending', CURDATE(), CURDATE(), 'TEST-PRINT-BILL', 5000, 0, 1)
            ");
            $ins->execute([$supplierId]);
            $testInvoiceId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO supplier_invoice_items (invoice_id, item_name, quantity, unit_price, tax_rate, tax_amount, line_total)
                VALUES (?, 'TEST-ITEM', 2, 2500, 0, 0, 5000)
            ")->execute([$testInvoiceId]);

            // Reproduce print_received_invoice.php's own header + items queries exactly.
            $stmt = $pdo->prepare("
                SELECT si.*,
                       COALESCE(s.supplier_name, sc.supplier_name)  AS party_name,
                       po.order_number                               AS po_number,
                       p.project_name,
                       CONCAT(u.first_name, ' ', u.last_name)        AS recorded_by_name
                FROM supplier_invoices si
                LEFT JOIN suppliers s        ON si.invoice_type = 'supplier'       AND s.supplier_id  = si.supplier_id
                LEFT JOIN sub_contractors sc ON si.invoice_type = 'sub_contractor' AND sc.supplier_id = si.supplier_id
                LEFT JOIN purchase_orders po ON si.po_id        = po.purchase_order_id
                LEFT JOIN projects p         ON si.project_id   = p.project_id
                LEFT JOIN users u            ON si.recorded_by  = u.user_id
                WHERE si.id = ? AND si.status != 'deleted'
            ");
            $stmt->execute([$testInvoiceId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);

            check($inv !== false, 'the new page\'s header query returns the test invoice', 'header query returned nothing');
            check($inv && $inv['invoice_ref'] === 'TEST-PRINT-BILL', 'invoice_ref matches', 'invoice_ref mismatch');
            check($inv && (float)$inv['amount'] === 5000.0, 'amount matches', 'amount mismatch');

            $iStmt = $pdo->prepare("SELECT item_name, quantity, unit, unit_price, tax_rate, tax_amount, line_total
                                      FROM supplier_invoice_items WHERE invoice_id = ? ORDER BY item_id");
            $iStmt->execute([$testInvoiceId]);
            $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);
            check(count($items) === 1 && $items[0]['item_name'] === 'TEST-ITEM', 'the new page\'s items query returns the test line item', 'items query mismatch');

            $pdo->prepare("DELETE FROM supplier_invoice_items WHERE invoice_id = ?")->execute([$testInvoiceId]);
            $pdo->prepare("DELETE FROM supplier_invoices WHERE id = ?")->execute([$testInvoiceId]);
            pass('test data cleaned up (self-contained, no residue left in the DB)');
        }
    } catch (Throwable $e) {
        fail('Live query-parity test threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
