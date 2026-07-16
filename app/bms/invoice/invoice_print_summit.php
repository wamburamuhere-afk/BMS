<?php
// File: app/bms/invoice/invoice_print_summit.php
// "Summit" template — bold cyan boxed layout with a prominent TOTAL DUE
// figure up top, inspired by a bold cyan-boxed invoice design. Same data
// source and fields as invoice_print.php; presentation only differs.
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/workflow.php';

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
            COALESCE(ua.user_role, ua.role) AS approver_role
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN users uc ON i.created_by  = uc.user_id
        LEFT JOIN users ur ON i.reviewed_by = ur.user_id
        LEFT JOIN users ua ON i.approved_by = ua.user_id
        WHERE i.invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) die("Invoice Not Found");

    $stmtItems = $pdo->prepare("
        SELECT ii.*, p.sku, p.unit
        FROM invoice_items ii
        LEFT JOIN products p ON ii.product_id = p.product_id
        WHERE ii.invoice_id = ?
    ");
    $stmtItems->execute([$invoice_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$currency = $invoice['currency'] ?? 'TZS';

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

$bank = ['bank_name'=>'','account_name'=>'','account_number'=>'','swift_code'=>'','check_payable_to'=>'','mpesa_paybill'=>'','mpesa_account_no'=>''];
try {
    $stmtB = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('bank_name','account_name','account_number','swift_code','check_payable_to','mpesa_paybill','mpesa_account_no')");
    $stmtB->execute();
    while ($r = $stmtB->fetch(PDO::FETCH_ASSOC)) {
        $bank[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {}
$has_bank = !empty($bank['bank_name']) || !empty($bank['mpesa_paybill']) || !empty($bank['check_payable_to']);

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

$accent = getSetting('print_template_color_inv_summit', '#12b5c9');
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
            color: #17222b;
            line-height: 1.5;
            padding: 24px 26px 0 26px;
            background: #fff;
        }

        .brand-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .brand-row .company-block { display: flex; align-items: center; gap: 12px; }
        .brand-row img { max-height: 46px; width: auto; object-fit: contain; }
        .brand-row h1 { font-size: 15px; font-weight: 700; }
        .brand-row .sub { font-size: 9.5px; color: #5c6b73; margin-top: 3px; }

        .doc-title { text-align: center; font-size: 30px; font-weight: 800; letter-spacing: 3px; margin: 10px 0 14px 0; }

        .meta-bar {
            display: flex;
            border-top: 2px solid var(--accent);
            border-bottom: 2px solid var(--accent);
            padding: 10px 4px;
            margin-bottom: 20px;
        }
        .meta-bar .cell { flex: 1; }
        .meta-bar .cell:last-child { text-align: right; }
        .meta-bar .lbl { font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.6px; color: #5c6b73; font-weight: 700; }
        .meta-bar .val { font-size: 12px; font-weight: 700; margin-top: 2px; }
        .meta-bar .val.total-due { font-size: 16px; color: var(--accent); }

        .panel-row { display: flex; gap: 14px; margin-bottom: 18px; }
        .panel { flex: 1; }
        .panel h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 6px; }
        .panel p { font-size: 11px; margin: 3px 0; }
        .panel strong { font-weight: 600; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            color: #fff;
            font-weight: 700;
            font-size: 10.5px;
            text-transform: uppercase;
            padding: 8px 10px;
            text-align: left;
        }
        tbody tr td { padding: 8px 10px; font-size: 11.5px; border: 1px solid #d8dee1; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        .bottom-row { display: flex; justify-content: space-between; gap: 16px; margin: 16px 0; align-items: flex-start; }
        .bank-details { flex: 1; }
        .bank-details h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 6px; }
        .bank-details p { font-size: 10.5px; margin: 2px 0; }
        .bank-details strong { display: block; font-size: 10.5px; margin-bottom: 2px; }
        .bank-section { margin-bottom: 8px; }
        .bank-section:last-child { margin-bottom: 0; }

        .totals { width: 300px; }
        .totals-row { display: flex; justify-content: space-between; padding: 5px 10px; font-size: 11.5px; }
        .totals-row.subtotal-row, .totals-row.grand-total, .totals-row.paid-row, .totals-row.balance-due {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            color: #fff;
            margin-bottom: 3px;
        }
        .totals-row.plain { background: transparent; color: #17222b; }
        .totals-row.grand-total { font-weight: 800; font-size: 13px; }
        .totals-row.balance-due { background: #e0403a; }

        .terms-block { margin: 16px 0; font-size: 11px; }
        .terms-block strong { display: block; margin-bottom: 4px; font-size: 11.5px; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
        }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom:16px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>

    <div class="brand-row">
        <div class="company-block">
            <?php if (!empty($comp['logo'])): ?>
            <img src="<?= htmlspecialchars('../../../' . $comp['logo']) ?>" alt="Logo">
            <?php endif; ?>
            <div>
                <h1><?= htmlspecialchars($comp['name']) ?></h1>
                <div class="sub">
                    <?php
                    $parts = [];
                    if (!empty($comp['address'])) $parts[] = htmlspecialchars($comp['address']);
                    if (!empty($comp['postal_address'])) $parts[] = 'P.O. Box ' . htmlspecialchars($comp['postal_address']);
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

    <div class="doc-title">INVOICE</div>

    <div class="meta-bar">
        <div class="cell">
            <div class="lbl">Invoice To</div>
            <div class="val"><?= htmlspecialchars($invoice['customer_name']) ?></div>
        </div>
        <div class="cell">
            <div class="lbl">Date</div>
            <div class="val"><?= date('d/m/Y', strtotime($invoice['invoice_date'])) ?></div>
            <div class="lbl" style="margin-top:4px;">Invoice No</div>
            <div class="val"><?= htmlspecialchars($invoice['invoice_number']) ?></div>
        </div>
        <div class="cell">
            <div class="lbl">Total Due</div>
            <div class="val total-due"><?= $currency ?> <?= number_format($invoice['grand_total'], 2) ?></div>
            <div class="lbl" style="margin-top:4px;">Due Date</div>
            <div class="val"><?= date('d/m/Y', strtotime($invoice['due_date'])) ?></div>
        </div>
    </div>

    <div class="panel-row">
        <div class="panel">
            <h3>Bill To</h3>
            <p><strong><?= htmlspecialchars($invoice['customer_name']) ?></strong></p>
            <?php if (!empty($invoice['company_name'])): ?><p><?= htmlspecialchars($invoice['company_name']) ?></p><?php endif; ?>
            <?php if (!empty($invoice['c_postal_address'])): ?><p>P.O. Box <?= htmlspecialchars($invoice['c_postal_address']) ?></p><?php endif; ?>
            <?php if (!empty($invoice['c_address'])): ?><p><?= htmlspecialchars($invoice['c_address']) ?></p><?php endif; ?>
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
            <h3>Invoice Details</h3>
            <?php if (!empty($invoice['reference_number'])): ?><p><strong>Reference:</strong> <?= htmlspecialchars($invoice['reference_number']) ?></p><?php endif; ?>
            <?php if (!empty($invoice['payment_terms'])): ?><p><strong>Payment Terms:</strong> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $invoice['payment_terms']))) ?></p><?php endif; ?>
            <?php if ($dn_ref): ?><p><strong>DN Ref:</strong> <?= htmlspecialchars($dn_ref) ?></p><?php endif; ?>
            <?php if ($lpo_ref): ?><p><strong>LPO Ref:</strong> <?= htmlspecialchars($lpo_ref) ?></p><?php endif; ?>
            <p><strong>Created By:</strong> <?= htmlspecialchars($creator_label) ?></p>
            <p><strong>Status:</strong> <?= strtoupper($invoice['status']) ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:34px;">S/NO</th>
                <th class="text-center" style="width:90px;">Product Code</th>
                <th class="text-center">Item / Description</th>
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
                $unit = !empty($item['unit']) ? ' ' . htmlspecialchars($item['unit']) : '';
            ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td class="text-center"><?= !empty($item['sku']) ? htmlspecialchars($item['sku']) : '—' ?></td>
                <td>
                    <?= htmlspecialchars($item['product_name'] ?? 'Unknown Product') ?>
                    <?php if (!empty($item['description'])): ?>
                    <br><small style="color:#6c757d; font-size:10px;"><?= htmlspecialchars($item['description']) ?></small>
                    <?php endif; ?>
                </td>
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
            <h3>Payment Method</h3>
            <?php if (!empty($bank['bank_name'])): ?>
            <div class="bank-section">
                <strong>Bank Transfer</strong>
                <p>Bank: <?= htmlspecialchars($bank['bank_name']) ?></p>
                <?php if (!empty($bank['account_name'])): ?><p>Account Name: <?= htmlspecialchars($bank['account_name']) ?></p><?php endif; ?>
                <?php if (!empty($bank['account_number'])): ?><p>Account No: <?= htmlspecialchars($bank['account_number']) ?></p><?php endif; ?>
                <?php if (!empty($bank['swift_code'])): ?><p>SWIFT / BIC: <?= htmlspecialchars($bank['swift_code']) ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bank['mpesa_paybill'])): ?>
            <div class="bank-section">
                <strong>Mobile Money</strong>
                <p>Paybill: <?= htmlspecialchars($bank['mpesa_paybill']) ?></p>
                <?php if (!empty($bank['mpesa_account_no'])): ?><p>Account: <?= htmlspecialchars($bank['mpesa_account_no']) ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bank['check_payable_to'])): ?>
            <div class="bank-section"><strong>Cheque</strong><p>Payable to: <?= htmlspecialchars($bank['check_payable_to']) ?></p></div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="flex:1;"></div>
        <?php endif; ?>

        <div class="totals">
            <div class="totals-row plain"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($subtotal, 2) ?></span></div>
            <?php
            $tax_amount = floatval($invoice['grand_total'] ?? 0) - $subtotal;
            if ($tax_amount > 0): ?>
            <div class="totals-row plain"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($tax_amount, 2) ?></span></div>
            <?php endif; ?>
            <div class="totals-row grand-total"><span>GRAND TOTAL:</span><span><?= $currency ?> <?= number_format($invoice['grand_total'], 2) ?></span></div>
            <?php
            $paid_amount = floatval($invoice['paid_amount'] ?? 0);
            $balance_due = floatval($invoice['balance_due'] ?? $invoice['grand_total']);
            if ($paid_amount > 0): ?>
            <div class="totals-row plain"><span>Paid:</span><span>-<?= $currency ?> <?= number_format($paid_amount, 2) ?></span></div>
            <div class="totals-row balance-due"><span>BALANCE DUE:</span><span><?= $currency ?> <?= number_format($balance_due, 2) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

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
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
