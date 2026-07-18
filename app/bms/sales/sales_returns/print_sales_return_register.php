<?php
// File: app/bms/sales/sales_returns/print_sales_return_register.php
// "Register" template — adapted from Sales Order's "Ledger" design: a colored
// header-bar, bordered accent panels, and a status-strip pill. Same data as
// print_sales_return.php, plus a Ref Invoice line and Refund Status (both
// already in the DB but unused on the standard print); presentation only
// differs otherwise. Own accent color, separate from Intake and Meridian.
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/permissions.php';
require_once __DIR__ . '/../../../../core/workflow.php';
require_once __DIR__ . '/../../../../core/warehouse_scope.php';

if (!isAuthenticated()) die("Unauthorized");
if (!canView('sales_returns')) die("Access Denied");

$return_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($return_id <= 0) die("Invalid Return ID");

global $pdo;

$_p = $pdo->prepare("
    SELECT COALESCE(i.project_id, so.project_id) AS pid
    FROM sales_returns sr
    LEFT JOIN invoices i      ON sr.invoice_id      = i.invoice_id
    LEFT JOIN sales_orders so ON sr.sales_order_id  = so.sales_order_id
    WHERE sr.sales_return_id = ? LIMIT 1
");
$_p->execute([$return_id]);
$_pid = $_p->fetchColumn();
if ($_pid && !userCan('project', (int)$_pid)) {
    http_response_code(403);
    die('Access denied: this return belongs to a project not in your scope.');
}

try {
    $stmt = $pdo->prepare("
        SELECT
            sr.sales_return_id, sr.return_number, sr.return_date,
            sr.total_amount, COALESCE(sr.total_tax, 0) as total_tax,
            COALESCE(sr.grand_total, sr.total_amount) as grand_total,
            sr.reason, sr.status, sr.payment_status,
            so.currency, so.order_number,
            inv.invoice_number,
            c.customer_name, c.company_name, c.email as c_email, c.phone as c_phone,
            c.address as c_address, c.postal_address as c_postal_address,
            c.tax_id as c_tin, c.vat_number as c_vrn,
            u.first_name as creator_first, u.last_name as creator_last, u.username as creator_username,
            COALESCE(u.user_role,  u.role)                                          AS creator_role,
            TRIM(CONCAT(COALESCE(ur.first_name,''),' ',COALESCE(ur.last_name,''))) AS reviewer_name,
            COALESCE(ur.user_role, ur.role)                                        AS reviewer_role,
            TRIM(CONCAT(COALESCE(ua.first_name,''),' ',COALESCE(ua.last_name,''))) AS approver_name,
            COALESCE(ua.user_role, ua.role)                                        AS approver_role,
            w.warehouse_id AS resolved_warehouse_id, w.warehouse_name
        FROM sales_returns sr
        LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id
        LEFT JOIN invoices inv    ON sr.invoice_id = inv.invoice_id
        LEFT JOIN warehouses w    ON w.warehouse_id = COALESCE(inv.warehouse_id, so.warehouse_id)
        LEFT JOIN customers c ON sr.customer_id = c.customer_id
        LEFT JOIN users u  ON sr.created_by  = u.user_id
        LEFT JOIN users ur ON sr.reviewed_by = ur.user_id
        LEFT JOIN users ua ON sr.approved_by = ua.user_id
        WHERE sr.sales_return_id = ?
    ");
    $stmt->execute([$return_id]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$return) die("Return not found");

    // Phase 6 (pos_upgrade_plan.md): gate directly on warehouse scope (the
    // source invoice/sales order's warehouse), not just project.
    if (!empty($return['resolved_warehouse_id']) && !userCan('warehouse', (int)$return['resolved_warehouse_id'])) {
        http_response_code(403);
        die('Access denied: this warehouse is not in your assigned scope.');
    }

    $action = "Print Sales Return";
    $user_name = $_SESSION['username'] ?? 'User';
    $description = "$user_name printed Sales Return #{$return['return_number']} (Register template)";
    require_once __DIR__ . '/../../../../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], $action, $description);

    $stmtItems = $pdo->prepare("
        SELECT sri.*, p.product_name, p.sku, p.unit
        FROM sales_return_items sri
        LEFT JOIN products p ON sri.product_id = p.product_id
        WHERE sri.sales_return_id = ?
    ");
    $stmtItems->execute([$return_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$currency = $return['currency'] ?? 'TZS';

$comp = ['name'=>'Business Management System','email'=>'','phone'=>'','address'=>'','postal_address'=>'','website'=>'','tin'=>'','vrn'=>'','logo'=>''];
try {
    $stmtC = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'company_%'");
    $stmtC->execute();
    while ($r = $stmtC->fetch(PDO::FETCH_ASSOC)) {
        $k = str_replace('company_', '', $r['setting_key']);
        if ($k === 'physical_address') $comp['address'] = $r['setting_value'];
        elseif ($k === 'logo')         $comp['logo']    = $r['setting_value'];
        else                           $comp[$k]        = $r['setting_value'];
    }
} catch (Exception $e) {}

$creator_name = trim(($return['creator_first'] ?? '') . ' ' . ($return['creator_last'] ?? ''))
                ?: ($return['creator_username'] ?? '');
$wf_sigs = getWorkflowSignatures($pdo, 'sales_return', $return_id);
$wf = [
    'created_by_name'    => $creator_name,
    'created_by_role'    => trim($return['creator_role']  ?? ''),
    'reviewed_by_name'   => trim($return['reviewer_name'] ?? ''),
    'reviewed_by_role'   => trim($return['reviewer_role'] ?? ''),
    'approved_by_name'   => trim($return['approver_name'] ?? ''),
    'approved_by_role'   => trim($return['approver_role'] ?? ''),
    'created_sig_path'   => $wf_sigs['created']['sig_path']   ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']  ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']  ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at'] ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']  ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at'] ?? null,
    '__include_css'      => true,
];

$payment_status_labels = ['pending' => 'Pending', 'partial' => 'Partially Refunded', 'refunded' => 'Refunded'];
$refund_status = $payment_status_labels[$return['payment_status'] ?? ''] ?? null;

$accent = getSetting('print_template_color_sr_register', '#2c3e5c');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SALES RETURN #<?= htmlspecialchars($return['return_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #1a1f2b; line-height: 1.5; padding: 20px 22px 0 22px; background: #fff; }

        .header-bar { background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; color: #fff; padding: 16px 20px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .header-bar .company-block { display: flex; align-items: center; gap: 12px; }
        .header-bar img { max-height: 46px; width: auto; background: #fff; padding: 3px; border-radius: 4px; }
        .header-bar h1 { font-size: 17px; font-weight: 700; }
        .header-bar .title-block { text-align: right; }
        .header-bar .title-block h2 { font-size: 18px; letter-spacing: 1px; font-weight: 800; }
        .header-bar .title-block p { font-size: 11px; opacity: 0.9; }

        .contact-strip { font-size: 10.5px; color: #4a5568; margin-bottom: 16px; }
        .contact-strip span { margin-right: 14px; }

        .status-strip { display: inline-block; background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; color: #fff; font-size: 10.5px; font-weight: 700; letter-spacing: 0.5px; padding: 5px 14px; border-radius: 4px; margin-bottom: 16px; }

        .panel-row { display: flex; gap: 12px; margin-bottom: 16px; }
        .panel { flex: 1; border: 1.5px solid var(--accent); border-radius: 6px; padding: 12px 14px; }
        .panel h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent); font-weight: 700; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #dfe3ea; }
        .panel p { font-size: 11px; margin: 3px 0; }
        .panel strong { font-weight: 600; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th { background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; color: #fff; font-weight: 700; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.4px; padding: 9px 10px; text-align: left; }
        tbody tr { border-bottom: 1px solid #e4e8ec; }
        tbody tr:nth-child(even) { background: #f4f6f9; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr td { padding: 8px 10px; font-size: 11.5px; }
        .text-right { text-align: right; } .text-center { text-align: center; } .fw-bold { font-weight: 700; }

        .totals { width: 300px; float: right; margin: 16px 0; border: 1.5px solid var(--accent); border-radius: 6px; overflow: hidden; }
        .totals-row { display: flex; justify-content: space-between; padding: 7px 14px; font-size: 11.5px; border-bottom: 1px solid #dfe3ea; }
        .totals-row:last-child { border-bottom: none; }
        .totals-row.grand-total { background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; color: #fff; font-weight: 800; font-size: 13px; }

        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div { border-left: 3px solid var(--accent); padding-left: 12px; margin-bottom: 10px; }
        .notes-section strong { display: block; margin-bottom: 4px; font-size: 11.5px; font-weight: 700; color: var(--accent); }
        .notes-section p { font-size: 11px; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print { .no-print { display: none !important; } body { margin: 0 !important; } }
    </style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_autofit.php'; ?>
</head>
<body onload="bmsAutoFitPrint()">

    <div class="no-print" style="margin-bottom:16px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>


    <div class="print-scale-wrapper">
    <div class="header-bar">
        <div class="company-block">
            <?php if (!empty($comp['logo'])): ?><img src="<?= htmlspecialchars('../../../../' . $comp['logo']) ?>" alt="Logo"><?php endif; ?>
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
        </div>
        <div class="title-block">
            <h2>SALES RETURN</h2>
            <p><?= htmlspecialchars($return['return_number']) ?> &bull; <?= date('d M Y', strtotime($return['return_date'])) ?></p>
        </div>
    </div>

    <div class="contact-strip">
        <?php if (!empty($comp['address'])): ?><span><?= htmlspecialchars($comp['address']) ?></span><?php endif; ?>
        <?php if (!empty($comp['phone'])): ?><span>Tel: <?= htmlspecialchars($comp['phone']) ?></span><?php endif; ?>
        <?php if (!empty($comp['email'])): ?><span><?= htmlspecialchars($comp['email']) ?></span><?php endif; ?>
        <?php if (!empty($comp['tin'])): ?><span>TIN: <?= htmlspecialchars($comp['tin']) ?></span><?php endif; ?>
        <?php if (!empty($comp['vrn'])): ?><span>VRN: <?= htmlspecialchars($comp['vrn']) ?></span><?php endif; ?>
    </div>

    <div class="status-strip"><?= strtoupper($return['status']) ?><?= $refund_status ? ' &bull; REFUND: ' . strtoupper($refund_status) : '' ?></div>

    <div class="panel-row">
        <div class="panel">
            <h3>Returned By</h3>
            <p><strong><?= htmlspecialchars($return['customer_name']) ?></strong></p>
            <?php if (!empty($return['company_name'])): ?><p><?= htmlspecialchars($return['company_name']) ?></p><?php endif; ?>
            <?php if (!empty($return['c_postal_address'])): ?><p>P.O. Box <?= htmlspecialchars($return['c_postal_address']) ?></p><?php endif; ?>
            <?php if (!empty($return['c_address'])): ?><p><?= htmlspecialchars($return['c_address']) ?></p><?php endif; ?>
            <?php if (!empty($return['c_phone'])): ?><p><?= htmlspecialchars($return['c_phone']) ?></p><?php endif; ?>
            <?php if (!empty($return['c_email'])): ?><p><?= htmlspecialchars($return['c_email']) ?></p><?php endif; ?>
            <?php $ctv = [];
                if (!empty($return['c_tin'])) $ctv[] = 'TIN: ' . htmlspecialchars($return['c_tin']);
                if (!empty($return['c_vrn'])) $ctv[] = 'VRN: ' . htmlspecialchars($return['c_vrn']);
                if ($ctv): ?><p><?= implode(' | ', $ctv) ?></p><?php endif; ?>
        </div>
        <div class="panel">
            <h3>Return Information</h3>
            <?php if (!empty($return['order_number'])): ?><p><strong>Ref Order:</strong> <?= htmlspecialchars($return['order_number']) ?></p><?php endif; ?>
            <?php if (!empty($return['invoice_number'])): ?><p><strong>Ref Invoice:</strong> <?= htmlspecialchars($return['invoice_number']) ?></p><?php endif; ?>
            <?php if (!empty($return['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($return['warehouse_name']) ?></p><?php endif; ?>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars($creator_name ?: 'System') ?></p>
            <p><strong>Currency:</strong> <?= htmlspecialchars($currency) ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th class="text-center" style="width:100px;">Product Code</th>
                <th class="text-center">Item / Description</th>
                <th class="text-right" style="width:70px;">Qty</th>
                <th class="text-right" style="width:95px;">Unit Price</th>
                <th class="text-center" style="width:55px;">VAT</th>
                <th class="text-right" style="width:110px;">Total (<?= $currency ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item):
                $lineTotal = floatval($item['quantity']) * floatval($item['unit_price']);
                $unit = !empty($item['unit']) ? ' ' . htmlspecialchars($item['unit']) : '';
            ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td class="text-center"><?= !empty($item['sku']) ? htmlspecialchars($item['sku']) : '—' ?></td>
                <td><?= htmlspecialchars($item['product_name'] ?? 'Unknown Product') ?></td>
                <td class="text-right"><?= floatval($item['quantity']) ?><?= $unit ?></td>
                <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-center"><?= (isset($item['tax_rate']) && (float)$item['tax_rate'] == 18) ? '18%' : '—' ?></td>
                <td class="text-right fw-bold"><?= number_format($item['total_amount'] ?? $lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($return['total_amount'], 2) ?></span></div>
        <div class="totals-row"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($return['total_tax'], 2) ?></span></div>
        <div class="totals-row grand-total"><span>TOTAL REFUND:</span><span><?= $currency ?> <?= number_format($return['grand_total'], 2) ?></span></div>
    </div>

    <div class="notes-section">
        <?php if (!empty($return['reason'])): ?>
        <div><strong>Reason for Return</strong><p><?= nl2br(htmlspecialchars($return['reason'])) ?></p></div>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
