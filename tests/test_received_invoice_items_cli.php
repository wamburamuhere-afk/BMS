<?php
/**
 * Received Invoices — line items, scope, three-stage workflow + signatures.
 *   php tests/test_received_invoice_items_cli.php
 *
 * Guards everything added to received invoices:
 *   - schema (supplier_invoice_items, warehouse_id, 3-stage status enum)
 *   - money math identical to invoice_create.php (subtotal/VAT/grand -> amount)
 *   - items save + replace on create/update; get_po_items
 *   - get_warehouses strict (project -> that project; none -> company-wide)
 *   - get_projects scope-based (admin sees all active)
 *   - workflow: created=pending + signature; pending->reviewed->approved with
 *     gates + signatures; illegal transition blocked
 *   - sub-contractor flow unchanged (manual amount, no items)
 *   - source contracts for the page + print view
 *
 * API calls run in isolated subprocesses (worker mode) so the endpoint's
 * functions aren't redeclared across calls.
 */
$root = dirname(__DIR__);

// ── Worker mode: run ONE API action from a JSON payload ──────────────────────
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin';
    $_SESSION['first_name'] = 'Test'; $_SESSION['last_name'] = 'Runner';
    $_SESSION['user_role'] = 'Administrator'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
    require_once "$root/roots.php";
    $_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    $in = json_decode(file_get_contents($argv[2]), true) ?: [];
    $_SERVER['REQUEST_METHOD'] = $in['__method'] ?? 'POST';
    unset($in['__method']);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') { $_GET = $in; }
    else { $_POST = $in; $_POST['_csrf'] = $_SESSION['csrf_token']; $_GET['action'] = $in['action'] ?? ''; }
    require "$root/api/received_invoices.php";
    exit;
}

// ── Main ─────────────────────────────────────────────────────────────────────
require_once "$root/roots.php";
require_once "$root/core/workflow.php";
global $pdo;

$pass = 0; $fail = 0; $created = [];
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function approx($a, $b) { return abs((float)$a - (float)$b) <= 0.01; }
function api(array $p) {
    global $root;
    $f = tempnam(sys_get_temp_dir(), 'ri'); file_put_contents($f, json_encode($p));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($f));
    @unlink($f);
    $s = strpos($o, '{'); $j = strpos($o, '[');
    if ($j !== false && ($s === false || $j < $s)) $s = $j;
    return json_decode(substr($o, $s), true);
}
function src($root, $rel) { return @file_get_contents("$root/$rel") ?: ''; }

try {
    // ── 1. Files exist + lint ─────────────────────────────────────────────
    echo "\n── 1. Files exist + lint ──\n";
    foreach ([
        'api/received_invoices.php',
        'app/bms/invoice/received_invoices.php',
        'app/bms/invoice/received_invoices_view.php',
        'migrations/2026_06_02_supplier_invoice_items.php',
        'migrations/2026_06_02_received_invoice_three_stage.php',
    ] as $rel) {
        $path = "$root/$rel";
        $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1');
        ok(is_file($path) && strpos($lint, 'No syntax errors') !== false, "lint clean: $rel");
    }

    // ── 2. Schema ─────────────────────────────────────────────────────────
    echo "\n── 2. Schema ──\n";
    ok($pdo->query("SHOW TABLES LIKE 'supplier_invoice_items'")->fetch() !== false, "supplier_invoice_items table exists");
    ok($pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'warehouse_id'")->fetch() !== false, "supplier_invoices.warehouse_id exists");
    $enum = $pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'status'")->fetch(PDO::FETCH_ASSOC)['Type'];
    ok(strpos($enum, "'pending'") !== false && strpos($enum, "'reviewed'") !== false && strpos($enum, "'approved'") !== false,
       "status enum is three-stage (pending/reviewed/approved)");

    // pick a real supplier
    $sid = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status!='deleted' LIMIT 1")->fetchColumn();

    // ── 3. Money math == invoice_create.php ───────────────────────────────
    echo "\n── 3. Money math (== invoice_create) ──\n";
    // 2x100000 @18% (sub 200000, vat 36000) + 1x50000 @0% (sub 50000) => grand 286000
    $r = api(['action'=>'create','invoice_type'=>'supplier','supplier_id'=>$sid,'invoice_ref'=>'__ri_'.bin2hex(random_bytes(3)),
        'date_raised'=>date('Y-m-d'),'date_recorded'=>date('Y-m-d'),'amount'=>'0',
        'items'=>[ ['item_name'=>'Cement','quantity'=>'2','unit'=>'bag','unit_price'=>'100000','tax_rate'=>'18'],
                   ['item_name'=>'Sand','quantity'=>'1','unit'=>'truck','unit_price'=>'50000','tax_rate'=>'0'] ]]);
    $id = $r['id'] ?? 0; if ($id) $created[] = (int)$id;
    ok(!empty($r['success']) && $id > 0, "create returned success");
    $amt = (float)$pdo->query("SELECT amount FROM supplier_invoices WHERE id=$id")->fetchColumn();
    ok(approx($amt, 286000), "amount derived from items = 286,000 (got $amt)");
    $its = $pdo->query("SELECT item_name,tax_amount,line_total FROM supplier_invoice_items WHERE invoice_id=$id ORDER BY item_id")->fetchAll(PDO::FETCH_ASSOC);
    ok(count($its) === 2, "2 item rows saved");
    ok(approx($its[0]['tax_amount'], 36000), "line1 tax_amount = 36,000 (18%)");
    ok(approx($its[0]['line_total'], 236000), "line1 line_total incl-tax = 236,000");
    ok(approx($its[1]['line_total'], 50000), "line2 line_total = 50,000 (0% tax)");

    // status starts pending + created signature
    ok($pdo->query("SELECT status FROM supplier_invoices WHERE id=$id")->fetchColumn() === 'pending', "new invoice status = pending");
    $sg = getWorkflowSignatures($pdo, 'supplier_invoice', $id);
    ok($sg['created']['user_name'] !== '', "created signature stamped");

    // ── 4. Update replaces items + recomputes amount ──────────────────────
    echo "\n── 4. Update recompute ──\n";
    $g = api(['__method'=>'GET','action'=>'get','id'=>$id]);
    ok(!empty($g['data']['items']) && count($g['data']['items']) === 2, "get returns items for edit/view");
    $u = api(['action'=>'update','id'=>$id,'invoice_ref'=>$g['data']['invoice_ref'],'date_raised'=>date('Y-m-d'),'date_recorded'=>date('Y-m-d'),
        'amount'=>'0','items'=>[['item_name'=>'Steel','quantity'=>'3','unit'=>'ton','unit_price'=>'100000','tax_rate'=>'18']]]);
    $amt2 = (float)$pdo->query("SELECT amount FROM supplier_invoices WHERE id=$id")->fetchColumn();
    $cnt2 = (int)$pdo->query("SELECT COUNT(*) FROM supplier_invoice_items WHERE invoice_id=$id")->fetchColumn();
    ok(!empty($u['success']) && approx($amt2, 354000), "update recomputes amount = 354,000 (got $amt2)");
    ok($cnt2 === 1, "update replaced items (now 1 row)");

    // ── 5. get_po_items ───────────────────────────────────────────────────
    echo "\n── 5. get_po_items ──\n";
    $poid = (int)$pdo->query("SELECT purchase_order_id FROM purchase_order_items GROUP BY purchase_order_id LIMIT 1")->fetchColumn();
    if ($poid) {
        $pi = api(['__method'=>'GET','action'=>'get_po_items','po_id'=>$poid]);
        ok(!empty($pi['success']) && isset($pi['data'][0]['item_name'], $pi['data'][0]['unit_price']),
           "get_po_items returns item_name/unit_price/tax_rate");
    } else { ok(true, "get_po_items (skipped — no PO with items)"); }

    // ── 6. get_warehouses strict ──────────────────────────────────────────
    echo "\n── 6. get_warehouses scope ──\n";
    $wNone = api(['__method'=>'GET','action'=>'get_warehouses','project_id'=>'0']);
    $allCompanyWide = true;
    foreach (($wNone['data'] ?? []) as $w) {
        $pidOf = (int)$pdo->query("SELECT IFNULL(project_id,0) FROM warehouses WHERE warehouse_id=" . (int)$w['id'])->fetchColumn();
        if ($pidOf !== 0) { $allCompanyWide = false; break; }
    }
    ok($allCompanyWide, "no project -> only company-wide warehouses (project_id IS NULL)");
    $projWithWh = $pdo->query("SELECT project_id FROM warehouses WHERE project_id IS NOT NULL AND status='active' LIMIT 1")->fetchColumn();
    if ($projWithWh) {
        $wProj = api(['__method'=>'GET','action'=>'get_warehouses','project_id'=>$projWithWh]);
        $allInProj = true;
        foreach (($wProj['data'] ?? []) as $w) {
            $pidOf = (int)$pdo->query("SELECT IFNULL(project_id,0) FROM warehouses WHERE warehouse_id=" . (int)$w['id'])->fetchColumn();
            if ($pidOf !== (int)$projWithWh) { $allInProj = false; break; }
        }
        ok($allInProj && count($wProj['data'] ?? []) > 0, "project chosen -> only that project's warehouses");
    } else { ok(true, "project warehouse scope (skipped — no project warehouse)"); }

    // ── 7. get_projects scope (admin sees active) ─────────────────────────
    echo "\n── 7. get_projects ──\n";
    $gp = api(['__method'=>'GET','action'=>'get_projects','type'=>'supplier']);
    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE status='active'")->fetchColumn();
    ok(isset($gp['data']) && count($gp['data']) === $activeCount, "admin get_projects returns all active ($activeCount)");

    // ── 8. Three-stage workflow + signatures ──────────────────────────────
    echo "\n── 8. Workflow ──\n";
    $rv = api(['action'=>'change_status','id'=>$id,'new_status'=>'reviewed']);
    ok(!empty($rv['success']) && $pdo->query("SELECT status FROM supplier_invoices WHERE id=$id")->fetchColumn() === 'reviewed', "pending -> reviewed");
    ok(getWorkflowSignatures($pdo,'supplier_invoice',$id)['reviewed']['user_name'] !== '', "reviewed signature stamped");
    $bad = api(['action'=>'change_status','id'=>$id,'new_status'=>'pending']);
    ok(empty($bad['success']), "illegal transition blocked (reviewed -> pending)");
    $ap = api(['action'=>'change_status','id'=>$id,'new_status'=>'approved']);
    ok(!empty($ap['success']) && $pdo->query("SELECT status FROM supplier_invoices WHERE id=$id")->fetchColumn() === 'approved', "reviewed -> approved");
    ok(getWorkflowSignatures($pdo,'supplier_invoice',$id)['approved']['user_name'] !== '', "approved signature stamped");

    // ── 9. Sub-contractor flow unchanged ──────────────────────────────────
    echo "\n── 9. Sub-contractor unchanged ──\n";
    $scid = (int)$pdo->query("SELECT supplier_id FROM sub_contractors WHERE status!='deleted' LIMIT 1")->fetchColumn();
    if ($scid) {
        $sc = api(['action'=>'create','invoice_type'=>'sub_contractor','supplier_id'=>$scid,'invoice_ref'=>'__ri_'.bin2hex(random_bytes(3)),
            'date_raised'=>date('Y-m-d'),'date_recorded'=>date('Y-m-d'),'amount'=>'750000','sc_invoice_basis'=>'IPC','sc_basis_ref'=>'IPC-09']);
        $scIdNew = $sc['id'] ?? 0; if ($scIdNew) $created[] = (int)$scIdNew;
        $scRow = $scIdNew ? $pdo->query("SELECT amount,invoice_type,sc_invoice_basis FROM supplier_invoices WHERE id=$scIdNew")->fetch(PDO::FETCH_ASSOC) : null;
        $scItems = $scIdNew ? (int)$pdo->query("SELECT COUNT(*) FROM supplier_invoice_items WHERE invoice_id=$scIdNew")->fetchColumn() : -1;
        ok(!empty($sc['success']) && $scRow && approx($scRow['amount'], 750000), "SC keeps manual amount = 750,000");
        ok($scRow && $scRow['sc_invoice_basis'] === 'IPC', "SC basis preserved");
        ok($scItems === 0, "SC writes no item rows");
    } else { ok(true, "SC flow (skipped — no sub-contractor)"); ok(true, "SC basis (skipped)"); ok(true, "SC items (skipped)"); }

    // ── 10. Source contracts (UI) ─────────────────────────────────────────
    echo "\n── 10. Source contracts ──\n";
    $page = src($root, 'app/bms/invoice/received_invoices.php');
    ok(strpos($page, 'id="itemsTable"') !== false && strpos($page, 'name="warehouse_id"') !== false, "page has items table + warehouse select");
    ok(strpos($page, '#invoiceModal .modal-content > form') !== false, "page has the modal-scroll fix CSS");
    ok(strpos($page, 'ri-del-btn') !== false && strpos($page, 'bi-trash3') !== false, "page has the red 3-D row delete button");
    ok(strpos($page, 'RI_CAN_REVIEW') !== false && strpos($page, 'Mark Reviewed') !== false, "page has the review stage");
    ok(strpos($page, 'function riCalcTotals') !== false, "page has the items money-math (riCalcTotals)");
    $view = src($root, 'app/bms/invoice/received_invoices_view.php');
    ok(strpos($view, 'workflow_signature_row.php') !== false, "print view includes the canonical signature partial");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
}

// ── Cleanup ──────────────────────────────────────────────────────────────────
foreach ($created as $cid) {
    $pdo->exec("DELETE FROM workflow_signatures WHERE entity_type='supplier_invoice' AND entity_id=$cid");
    $pdo->exec("DELETE FROM supplier_invoice_items WHERE invoice_id=$cid");
    $pdo->exec("DELETE FROM supplier_invoices WHERE id=$cid");
}
$pdo->exec("DELETE FROM supplier_invoices WHERE invoice_ref LIKE '__ri_%'");

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
