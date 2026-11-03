<?php
// File: app/bms/invoice/invoice_print_wave.php
// "Wave" template — bold diagonal banner header, inspired by a blue
// wave-banner invoice design. Same data source and fields as
// invoice_print.php; presentation only differs.
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/workflow.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';

if (!isAuthenticated()) die("Unauthorized");
if (!canView('invoices')) die("Access Denied");

$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($invoice_id <= 0) die("Invalid Invoice ID");

assertScopeForRecordHtml('invoices', 'invoice_id', $invoice_id);

global $pdo;

try {
    $stmt = $pdo->prepare("
        SELECT
            i.*,
            c.customer_name,
            c.company_name,
            c.email as c_email,
            c.phone as c_phone,
            c.address as c_address,
            c.postal_address as c_postal_address,
            c.tax_id as c_tin,
            c.vat_number as c_vrn,
            CONCAT(uc.first_name, ' ', uc.last_name) AS creator_name,
            uc.username AS creator_username,
            COALESCE(uc.user_role, uc.role) AS creator_role,
            CONCAT(ur.first_name, ' ', ur.last_name) AS reviewer_name,
            COALESCE(ur.user_role, ur.role) AS reviewer_role,
            CONCAT(ua.first_name, ' ', ua.last_name) AS approver_name,
            COALESCE(ua.user_role, ua.role) AS approver_role,
            w.warehouse_name
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN warehouses w ON i.warehouse_id = w.warehouse_id
        LEFT JOIN users uc ON i.created_by  = uc.user_id
        LEFT JOIN users ur ON i.reviewed_by = ur.user_id
        LEFT JOIN users ua ON i.approved_by = ua.user_id
        WHERE i.invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) die("Invoice Not Found");

    // Phase 6 (pos_upgrade_plan.md): gate directly on warehouse scope, not
    // just project — a user granted only some of a project's warehouses
    // shouldn't be able to print an invoice drawn from a different one.
    if (!empty($invoice['warehouse_id']) && !userCan('warehouse', (int)$invoice['warehouse_id'])) {
        http_response_code(403);
        die('Access denied: this warehouse is not in your assigned scope.');
    }

    $stmtItems = $pdo->prepare("
        SELECT ii.*, p.sku, p.unit, p.is_service AS svc_is_service, p.description AS svc_description
        FROM invoice_items ii
        LEFT JOIN products p ON ii.product_id = p.product_id
        WHERE ii.invoice_id = ?
    ");
    $stmtItems->execute([$invoice_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    // Every line a service = created with the Service switch on: print Service Name + Description, no Product Code.
    $svcLayout = !empty($items) && !array_filter($items, fn($r) => (int)($r['svc_is_service'] ?? 0) !== 1);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$currency = $invoice['currency'] ?? bmsCompanyCurrency();

$inv_postal  = trim($invoice['c_postal_address'] ?? '');
$inv_address = trim($invoice['c_address'] ?? '');
if ($inv_postal !== '' && $inv_address !== ''
    && stripos($inv_address, $inv_postal) !== false) {
    $inv_postal = '';
}

$comp =['name'=>'Business Management System','email'=>'','phone'=>'','address'=>'','postal_address'=>'','website'=>'','tin'=>'','vrn'=>'','logo'=>''];
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

require_once ROOT_DIR . '/core/payment_accounts.php';
$pa = getPaymentAccounts($pdo);
$has_bank = paymentAccountsHasAny($pa);

$creator_name  = trim($invoice['creator_name']  ?? '');
if ($creator_name === '') $creator_name = trim($invoice['creator_username'] ?? '');
$creator_role  = trim($invoice['creator_role']  ?? '');
$reviewer_name = trim($invoice['reviewer_name'] ?? '');
$reviewer_role = trim($invoice['reviewer_role'] ?? '');
$approver_name = trim($invoice['approver_name'] ?? '');
$approver_role = trim($invoice['approver_role'] ?? '');
$creator_label = $creator_name ? $creator_name . ($creator_role ? ' (' . ucfirst($creator_role) . ')' : '') : 'Unknown';

$wf_status = $invoice['status'] ?? 'pending';
$inv_id_for_sig = $invoice['invoice_id'] ?? 0;
$wf_sigs = $inv_id_for_sig ? getWorkflowSignatures($pdo, 'invoice', $inv_id_for_sig) : [];
$wf = [
    'created_by_name'    => $creator_name,
    'created_by_role'    => $creator_role,
    'reviewed_by_name'   => $reviewer_name,
    'reviewed_by_role'   => $reviewer_role,
    'approved_by_name'   => $approver_name,
    'approved_by_role'   => $approver_role,
    'created_sig_path'   => $wf_sigs['created']['sig_path']   ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']  ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']  ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at'] ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']  ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at'] ?? null,
    '__include_css'      => true,
];

$dn_ref = null;
if (!empty($invoice['delivery_id'])) {
    $dnNumStmt = $pdo->prepare("SELECT delivery_number FROM deliveries WHERE delivery_id = ?");
    $dnNumStmt->execute([$invoice['delivery_id']]);
    $dn_ref = $dnNumStmt->fetchColumn();
}
$lpo_ref = null;
if (!empty($invoice['customer_lpo_id'])) {
    $lpoNumStmt = $pdo->prepare("SELECT lpo_number FROM customer_lpos WHERE lpo_id = ?");
    $lpoNumStmt->execute([$invoice['customer_lpo_id']]);
    $lpo_ref = $lpoNumStmt->fetchColumn();
}

$accent = getSetting('print_template_color_inv_wave', '#164a91');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tax Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            color: #1a252f;
            line-height: 1.5;
            padding: 0 24px 0 24px;
            background: #fff;
        }

        .wave-header {
            position: relative;
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            margin: 0 -24px 22px -24px;
            padding: 24px 24px 30px 24px;
            border-radius: 0 0 50% 8px / 0 0 30px 8px;
            color: #fff;
        }
        .wave-header .top-row { display: flex; justify-content: space-between; align-items: flex-start; }
        .wave-header .doc-title { font-size: 26px; font-weight: 800; letter-spacing: 1px; }
        .wave-header .doc-no { font-size: 11px; text-align: right; opacity: 0.9; }
        .wave-header .company-block { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
        .wave-header img { max-height: 34px; width: auto; background: #fff; padding: 2px; border-radius: 3px; }
        .wave-header .company-block .name { font-size: 12px; font-weight: 700; }
        .wave-header .company-block .sub { font-size: 9px; opacity: 0.85; }

        .panel-row { display: flex; justify-content: space-between; gap: 14px; margin-bottom: 18px; }
        .panel { flex: 1; }
        .panel h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent); font-weight: 700; margin-bottom: 6px; }
        .panel p { font-size: 11px; margin: 3px 0; }
        .panel strong { font-weight: 600; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            color: #fff;
            font-weight: 600;
            font-size: 10.5px;
            text-transform: uppercase;
            padding: 8px 10px;
            text-align: left;
        }
        tbody tr { border-bottom: 1px solid #e4e8ec; }
        tbody tr td { padding: 6px 10px; font-size: 11.5px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        .bottom-row { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
        .bank-details { flex: 1; }
        .bank-details h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent); font-weight: 700; margin-bottom: 6px; }
        .bank-details p { font-size: 10.5px; margin: 2px 0; }
        .bank-details strong { display: block; font-size: 10.5px; margin-bottom: 2px; }
        .bank-section { margin-bottom: 8px; }
        .bank-section:last-child { margin-bottom: 0; }

        .totals { width: 300px; }
        .totals-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 11.5px; border-bottom: 1px solid #e4e8ec; }
        .totals-row.grand-total {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            color: #fff;
            border-bottom: none;
            padding: 8px 10px;
            font-size: 13px;
            font-weight: 700;
            margin-top: 6px;
        }
        .totals-row.balance-due { border-top: 2px solid #e0403a; border-bottom: none; margin-top: 6px; padding-top: 8px; font-size: 13px; font-weight: 700; color: #e0403a; }

        .thank-you { text-align: right; font-size: 16px; font-style: italic; color: var(--accent); margin-bottom: 16px; }

        .terms-block { margin: 16px 0; font-size: 11px; }
        .terms-block strong { display: block; margin-bottom: 4px; font-size: 11.5px; color: var(--accent); }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
        }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_autofit.php'; ?>
</head>
<body onload="bmsAutoFitPrint()">

    <div class="no-print" style="margin:16px 0; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>


    <div class="print-scale-wrapper">
    <div class="wave-header">
        <div class="top-row">
            <div class="doc-title">INVOICE</div>
            <div class="doc-no">
                NO: <?= htmlspecialchars($invoice['invoice_number']) ?><br>
                Date: <?= date('d M Y', strtotime($invoice['invoice_date'])) ?><br>
                Due: <?= date('d M Y', strtotime($invoice['due_date'])) ?><br>
                Status: <?= strtoupper($invoice['status']) ?>
            </div>
        </div>
        <div class="company-block">
            <?php if (!empty($comp['logo'])): ?>
            <img src="<?= htmlspecialchars('../../../' . $comp['logo']) ?>" alt="Logo">
            <?php endif; ?>
            <div>
                <div class="name"><?= htmlspecialchars($comp['name']) ?></div>
                <div class="sub">
                    <?php
                    $parts = [];
                    if (!empty($comp['address'])) $parts[] = htmlspecialchars($comp['address']);
                    if (!empty($comp['phone'])) $parts[] = 'Tel: ' . htmlspecialchars($comp['phone']);
                    if (!empty($comp['email'])) $parts[] = htmlspecialchars($comp['email']);
                    $tv = [];
                    if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                    if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                    if ($tv) $parts[] = implode(' | ', $tv);
                    echo implode(' &bull; ', $parts);
                    ?>
                </div>
            </div>
        </div>
    </div>

    <div class="panel-row">
        <div class="panel">
            <h3>Bill To</h3>
            <p><strong><?= caseFormat($invoice['customer_name']) ?></strong></p>
            <?php if (!empty($invoice['company_name'])): ?><p><?= caseFormat($invoice['company_name']) ?></p><?php endif; ?>
            <?php if (!empty($inv_postal)): ?><p><?= preg_match('/^\s*p\.?\s*o\.?\s*box/i', $inv_postal) ? htmlspecialchars($inv_postal) : 'P.O. Box ' . htmlspecialchars($inv_postal) ?></p><?php endif; ?>
            <?php if (!empty($inv_address)): ?><p><?= htmlspecialchars($inv_address) ?></p><?php endif; ?>
            <?php if (!empty($invoice['c_phone'])): ?><p><?= htmlspecialchars($invoice['c_phone']) ?></p><?php endif; ?>
            <?php if (!empty($invoice['c_email'])): ?><p><?= htmlspecialchars($invoice['c_email']) ?></p><?php endif; ?>
            <?php
            $c_tv = [];
            if (!empty($invoice['c_tin'])) $c_tv[] = 'TIN: ' . htmlspecialchars($invoice['c_tin']);
            if (!empty($invoice['c_vrn'])) $c_tv[] = 'VRN: ' . htmlspecialchars($invoice['c_vrn']);
            if ($c_tv): ?>
            <p><?= implode(' | ', $c_tv) ?></p>
            <?php endif; ?>
        </div>
        <div class="panel">
            <h3>From</h3>
            <p><strong><?= htmlspecialchars($comp['name']) ?></strong></p>
            <?php if (!empty($invoice['reference_number'])): ?><p><strong>Reference:</strong> <?= htmlspecialchars($invoice['reference_number']) ?></p><?php endif; ?>
            <?php if (!empty($invoice['payment_terms'])): ?><p><strong>Payment Terms:</strong> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $invoice['payment_terms']))) ?></p><?php endif; ?>
            <?php if ($dn_ref): ?><p><strong>DN Ref:</strong> <?= htmlspecialchars($dn_ref) ?></p><?php endif; ?>
            <?php if ($lpo_ref): ?><p><strong>LPO Ref:</strong> <?= htmlspecialchars($lpo_ref) ?></p><?php endif; ?>
            <?php if (!empty($invoice['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($invoice['warehouse_name']) ?></p><?php endif; ?>
            <p><strong>Created By:</strong> <?= htmlspecialchars($creator_label) ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:34px;">S/NO</th>
                <?php if ($svcLayout): ?>
                <th class="text-center" style="width:30%;">Service Name</th>
                <th class="text-center">Description</th>
                <?php else: ?>
                <th class="text-center" style="width:90px;">Product Code</th>
                <th class="text-center">Item / Description</th>
                <?php endif; ?>
                <th class="text-right" style="width:70px;">Qty</th>
                <th class="text-right" style="width:95px;">Unit Price</th>
                <th class="text-right" style="width:105px;">Total (<?= $currency ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $subtotal = 0;
            foreach ($items as $i => $item):
                $lineTotal = floatval($item['quantity']) * floatval($item['unit_price']);
                $subtotal += $lineTotal;
                $unit = !empty($item['unit']) && empty($item['price_term_label']) ? ' ' . htmlspecialchars($item['unit']) : '';
            ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <?php if ($svcLayout):
                    $svcDesc = trim((string)($item['description'] ?? '')) !== '' ? (string)$item['description'] : (string)($item['svc_description'] ?? ''); ?>
                <td style="overflow-wrap:anywhere;word-break:break-word;"><?= caseFormat($item['product_name'] ?? $item['item_name'] ?? '', 'Unknown Service') ?></td>
                <td style="overflow-wrap:anywhere;word-break:break-word;white-space:pre-line;"><?= htmlspecialchars(trim($svcDesc), ENT_QUOTES, 'UTF-8') ?></td>
                <?php else: ?>
                <td class="text-center"><?= !empty($item['sku']) ? htmlspecialchars($item['sku']) : '—' ?></td>
                <td>
                    <?= htmlspecialchars($item['product_name'] ?? 'Unknown Product') ?>
                    <?php if (!empty($item['description'])): ?>
                    <br><small style="color:#6c757d; font-size:10px;"><?= htmlspecialchars($item['description']) ?></small>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="text-right"><?= floatval($item['quantity']) ?><?= $unit ?></td>
                <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-right fw-bold"><?= number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="bottom-row">
        <?php if ($has_bank): ?>
        <div class="bank-details">
            <h3>Payment Information</h3>
            <?= renderPaymentAccountsBlock($pa) ?>
        </div>
        <?php else: ?>
        <div style="flex:1;"></div>
        <?php endif; ?>

        <div class="totals">
            <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($subtotal, 2) ?></span></div>
            <?php
            $discount_amount = floatval($invoice['discount_amount'] ?? 0);
            // Stored header VAT (charged after line discounts); older rows fall back to the difference.
            $tax_amount = isset($invoice['tax_amount']) ? floatval($invoice['tax_amount']) : floatval($invoice['grand_total'] ?? 0) - $subtotal + $discount_amount;
            if ($discount_amount > 0.004): ?>
            <div class="totals-row"><span>Discount:</span><span>- <?= $currency ?> <?= number_format($discount_amount, 2) ?></span></div>
            <?php endif;
            if ($tax_amount > 0): ?>
            <div class="totals-row"><span>VAT:</span><span><?= $currency ?> <?= number_format($tax_amount, 2) ?></span></div>
            <?php endif; ?>
            <div class="totals-row grand-total"><span>GRAND TOTAL</span><span><?= $currency ?> <?= number_format($invoice['grand_total'], 2) ?></span></div>
            <?php
            $paid_amount = floatval($invoice['paid_amount'] ?? 0);
            $balance_due = floatval($invoice['balance_due'] ?? $invoice['grand_total']);
            if ($paid_amount > 0): ?>
            <div class="totals-row"><span>Paid:</span><span>-<?= $currency ?> <?= number_format($paid_amount, 2) ?></span></div>
            <div class="totals-row balance-due"><span>BALANCE DUE:</span><span><?= $currency ?> <?= number_format($balance_due, 2) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="thank-you">Thank You!</div>

    <div class="terms-block">
        <?php if (!empty($invoice['notes'])): ?>
        <strong>Notes:</strong>
        <p><?= nl2br(htmlspecialchars($invoice['notes'])) ?></p>
        <?php endif; ?>
        <?php if (!empty($invoice['terms_conditions'])): ?>
        <strong style="margin-top:8px;">Terms &amp; Conditions:</strong>
        <p><?= nl2br(htmlspecialchars($invoice['terms_conditions'])) ?></p>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>
    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
