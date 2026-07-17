<?php
// File: app/bms/sales/sales_returns/print_sales_return_intake.php
// "Intake" template — adapted from Delivery Note (Outbound)'s "Custody" design:
// earthy panels, a colored title bar, and a Returned-By/Received-By
// acknowledgment block — the same handover-acknowledgment structure, mirrored
// for goods coming back INTO the company instead of going out. Same data as
// print_sales_return.php, plus a Ref Invoice line and Refund Status (both
// already in the DB but unused on the standard print); presentation only
// differs otherwise. Own accent color, separate from Register and Meridian.
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

// Phase C — sales_returns has no direct project_id; resolve via invoice/SO.
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
    $description = "$user_name printed Sales Return #{$return['return_number']} (Intake template)";
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

$accent = getSetting('print_template_color_sr_intake', '#5f7052');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SALES RETURN #<?= htmlspecialchars($return['return_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #2c2c2c; line-height: 1.5; padding: 0 26px 0 26px; background: #fff; }

        .top-strip { display: flex; justify-content: space-between; font-size: 9.5px; color: #6b6b6b; padding: 14px 0 10px 0; }

        .title-bar { background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; color: #fff; text-align: center; font-size: 22px; font-weight: 700; letter-spacing: 3px; padding: 12px 0; margin: 0 -26px 16px -26px; }

        .company-block { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .company-block img { max-height: 40px; width: auto; object-fit: contain; }
        .company-block h1 { font-size: 14px; font-weight: 700; }

        .panel-row { display: flex; gap: 14px; margin-bottom: 14px; }
        .panel { flex: 1; background: #f2ede9; print-color-adjust: exact; -webkit-print-color-adjust: exact; border-radius: 6px; padding: 12px 14px; }
        .panel h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 8px; color: #4a4a3f; }
        .panel .field-line { font-size: 11px; margin: 3px 0; display: flex; }
        .panel .field-line .flabel { min-width: 70px; color: #6b6b5f; }

        .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #4a4a3f; margin: 16px 0 8px 0; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.items-table thead th { border-bottom: 2px solid var(--accent); padding: 6px 4px; font-size: 10.5px; text-transform: uppercase; text-align: left; color: #4a4a3f; }
        table.items-table tbody td { padding: 6px 4px; font-size: 11.5px; border-bottom: 1px solid #e5e0da; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        .totals { width: 300px; float: right; background: #f2ede9; print-color-adjust: exact; -webkit-print-color-adjust: exact; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; }
        .totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 11.5px; }
        .totals-row.grand-total { border-top: 2px solid var(--accent); margin-top: 6px; padding-top: 8px; font-size: 13px; font-weight: 800; color: var(--accent); }

        .ack-panel { clear: both; background: #f2ede9; print-color-adjust: exact; -webkit-print-color-adjust: exact; border-radius: 6px; padding: 14px 16px; margin: 16px 0; }
        .ack-panel .section-title { margin-top: 0; }
        .ack-row { display: flex; justify-content: space-between; gap: 30px; }
        .ack-col { flex: 1; }
        .ack-col .ack-label { font-size: 11px; font-weight: 700; margin-bottom: 24px; }

        .notes-section { margin: 16px 0; font-size: 11px; }
        .notes-section strong { display: block; margin-bottom: 4px; color: #4a4a3f; }

        .bottom-bar { height: 10px; margin: 20px -26px 0 -26px; background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; border-radius: 6px 6px 0 0; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print { .no-print { display: none !important; } body { margin: 0 !important; } }
    </style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin:16px 0; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>

    <div class="top-strip">
        <span><?= htmlspecialchars($comp['name']) ?></span>
        <span><?php if (!empty($comp['website'])): ?><?= htmlspecialchars($comp['website']) ?><?php elseif (!empty($comp['email'])): ?><?= htmlspecialchars($comp['email']) ?><?php endif; ?></span>
    </div>

    <div class="title-bar">SALES RETURN</div>

    <div class="company-block">
        <?php if (!empty($comp['logo'])): ?><img src="<?= htmlspecialchars('../../../../' . $comp['logo']) ?>" alt="Logo"><?php endif; ?>
        <div>
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div style="font-size:9.5px; color:#6b6b6b;">
                <?php
                $parts = [];
                if (!empty($comp['address'])) $parts[] = htmlspecialchars($comp['address']);
                if (!empty($comp['phone'])) $parts[] = 'Tel: ' . htmlspecialchars($comp['phone']);
                $tv = [];
                if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                if ($tv) $parts[] = implode(' | ', $tv);
                echo implode(' &bull; ', $parts);
                ?>
            </div>
        </div>
    </div>

    <div class="panel-row">
        <div class="panel">
            <h3>Returned By</h3>
            <div class="field-line"><span class="flabel">Name:</span> <?= htmlspecialchars($return['customer_name']) ?></div>
            <?php if (!empty($return['company_name'])): ?><div class="field-line"><span class="flabel">Company:</span> <?= htmlspecialchars($return['company_name']) ?></div><?php endif; ?>
            <div class="field-line"><span class="flabel">Address:</span> <?= htmlspecialchars($return['c_address'] ?: '') ?></div>
            <div class="field-line"><span class="flabel">Phone:</span> <?= htmlspecialchars($return['c_phone'] ?: '') ?></div>
            <?php if (!empty($return['c_email'])): ?><div class="field-line"><span class="flabel">E-mail:</span> <?= htmlspecialchars($return['c_email']) ?></div><?php endif; ?>
        </div>
        <div class="panel">
            <h3>Received By (Company)</h3>
            <div class="field-line"><span class="flabel">Name:</span> <?= htmlspecialchars($comp['name']) ?></div>
            <div class="field-line"><span class="flabel">Address:</span> <?= htmlspecialchars($comp['address'] ?: '') ?></div>
            <div class="field-line"><span class="flabel">Phone:</span> <?= htmlspecialchars($comp['phone'] ?: '') ?></div>
        </div>
    </div>

    <div class="panel">
        <h3>Return Details</h3>
        <div class="field-line"><span class="flabel">Return #:</span> <?= htmlspecialchars($return['return_number']) ?></div>
        <div class="field-line"><span class="flabel">Date:</span> <?= date('d M Y', strtotime($return['return_date'])) ?></div>
        <?php if (!empty($return['order_number'])): ?><div class="field-line"><span class="flabel">Ref Order:</span> <?= htmlspecialchars($return['order_number']) ?></div><?php endif; ?>
        <?php if (!empty($return['invoice_number'])): ?><div class="field-line"><span class="flabel">Ref Invoice:</span> <?= htmlspecialchars($return['invoice_number']) ?></div><?php endif; ?>
        <?php if (!empty($return['warehouse_name'])): ?><div class="field-line"><span class="flabel">Warehouse:</span> <?= htmlspecialchars($return['warehouse_name']) ?></div><?php endif; ?>
        <div class="field-line"><span class="flabel">Status:</span> <?= strtoupper($return['status']) ?></div>
        <?php if ($refund_status): ?><div class="field-line"><span class="flabel">Refund:</span> <?= htmlspecialchars($refund_status) ?></div><?php endif; ?>
        <div class="field-line"><span class="flabel">Prepared By:</span> <?= htmlspecialchars($creator_name ?: 'System') ?></div>
        <div class="field-line"><span class="flabel">Currency:</span> <?= htmlspecialchars($currency) ?></div>
    </div>

    <div class="section-title">Items</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:34px;">No.</th>
                <th style="width:90px;">SKU</th>
                <th>Item Description</th>
                <th class="text-right" style="width:60px;">Qty</th>
                <th class="text-right" style="width:90px;">Unit Price</th>
                <th class="text-center" style="width:50px;">VAT</th>
                <th class="text-right" style="width:105px;">Total (<?= $currency ?>)</th>
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

    <div class="ack-panel">
        <div class="section-title">Acknowledgment</div>
        <div class="ack-row">
            <div class="ack-col">
                <div class="ack-label">Returned By: <?= htmlspecialchars($return['customer_name']) ?></div>
            </div>
            <div class="ack-col">
                <div class="ack-label">Received By: <?= htmlspecialchars($creator_name ?: 'Staff') ?></div>
            </div>
        </div>
    </div>

    <?php if (!empty($return['reason'])): ?>
    <div class="notes-section">
        <strong>Reason for Return:</strong>
        <p><?= nl2br(htmlspecialchars($return['reason'])) ?></p>
    </div>
    <?php endif; ?>

    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>

    <div class="bottom-bar"></div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
