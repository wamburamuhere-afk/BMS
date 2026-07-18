<?php
// File: app/bms/invoice/invoice_print.php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/workflow.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';

if (!isAuthenticated()) die("Unauthorized");

// Phase 5a — print pages get a canView gate (admin auto-bypass)
if (!canView('invoices')) die("Access Denied");

$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($invoice_id <= 0) die("Invalid Invoice ID");

// Phase C — block printing invoices on projects not in user scope (HTML-safe)
assertScopeForRecordHtml('invoices', 'invoice_id', $invoice_id);

global $pdo;

try {
    // Fetch Invoice Details
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

    // Fetch Invoice Items
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

// Company Settings
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

// Bank / Payment Settings
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

$creator_label  = $creator_name  ? $creator_name  . ($creator_role  ? ' (' . ucfirst($creator_role)  . ')' : '') : 'Unknown';
$reviewer_label = $reviewer_name ? $reviewer_name . ($reviewer_role ? ' (' . ucfirst($reviewer_role) . ')' : '') : 'Not yet reviewed';
$approver_label = $approver_name ? $approver_name . ($approver_role ? ' (' . ucfirst($approver_role) . ')' : '') : 'Not yet approved';

// Three-approval watermark + e-signature data
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tax Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            color: #1a252f;
            line-height: 1.5;
            padding: 20px 20px 0 20px;
            background: #fff;
        }

        /* ── HEADER ── */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            padding-bottom: 18px;
            border-bottom: 3px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .company-info { flex: 1; padding-right: 20px; }
        .company-info h1 {
            color: #0d6efd;
            font-size: 22px;
            font-weight: 800;
            text-transform: uppercase;
            margin: 0 0 10px 0;
            letter-spacing: 0.5px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .company-addr-row {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .company-addr-row img {
            max-height: 60px;
            width: auto;
            flex-shrink: 0;
            object-fit: contain;
        }
        .company-addr-info p {
            margin: 2px 0;
            color: #1a252f;
            font-size: 11px;
            font-weight: 500;
        }

        /* ── TITLE BOX ── */
        .doc-title-box {
            text-align: right;
            background: #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
            padding: 16px 22px;
            border-radius: 8px;
            min-width: 220px;
        }
        .doc-title-box h2 {
            margin: 0 0 10px 0;
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .doc-title-box p {
            margin: 4px 0;
            font-size: 12px;
            color: #fff;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .doc-title-box strong { font-weight: 600; }

        /* ── INFO BOXES ── */
        .details-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 14px;
        }
        .box {
            width: 48%;
            background: #f4f6f8;
            padding: 14px 16px;
            border-radius: 6px;
            border-left: 4px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .box h3 {
            font-size: 11px;
            color: #1a252f;
            padding-bottom: 7px;
            margin-bottom: 10px;
            border-bottom: 1.5px solid #3498db;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .box p { margin: 3px 0; color: #1a252f; font-size: 11.5px; }
        .box strong { color: #1a252f; font-weight: 600; }

        /* ── ITEMS TABLE ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th {
            background: #34495e;
            color: #fff;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 9px 10px;
            text-align: left;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        tbody tr {
            border-bottom: 1px solid #e4e8ec;
        }
        tbody tr:nth-child(even) {
            background: #f9fafb;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        tbody tr:last-child { border-bottom: 2px solid #3498db; }
        tbody tr td {
            height: 0.75cm;
            padding: 2px 10px;
            vertical-align: middle;
            font-size: 13px;
            line-height: 1.6;
            color: #1a252f;
        }
        .text-right  { text-align: right;  }
        .text-center { text-align: center; }
        .fw-bold     { font-weight: 700;   }

        /* ── TOTALS SECTION (bank details left / amounts right) ── */
        .totals-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 20px;
        }
        .bank-details {
            flex: 1;
            background: #f4f6f8;
            padding: 14px 16px;
            border-radius: 6px;
            border-left: 4px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .bank-details h3 {
            font-size: 11px;
            color: #1a252f;
            padding-bottom: 7px;
            margin-bottom: 10px;
            border-bottom: 1.5px solid #3498db;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .bank-details p { margin: 3px 0; color: #1a252f; font-size: 11px; }
        .bank-details strong { color: #1a252f; font-weight: 700; font-size: 11px; display: block; margin-bottom: 2px; }
        .bank-section { margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #e4e8ec; }
        .bank-section:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }

        /* ── TOTALS ── */
        .totals {
            width: 310px;
            min-width: 260px;
            flex-shrink: 0;
            background: #f4f6f8;
            padding: 14px 18px;
            border-radius: 6px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 12px;
            color: #1a252f;
            border-bottom: 1px solid #e4e8ec;
        }
        .totals-row:last-child { border-bottom: none; }
        .totals-row.grand-total {
            border-top: 2px solid #3498db;
            border-bottom: none;
            margin-top: 8px;
            padding-top: 10px;
            font-size: 14px;
            font-weight: 700;
            color: #1a252f;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .totals-row.balance-due {
            border-top: 2px solid #e74c3c;
            border-bottom: none;
            margin-top: 6px;
            padding-top: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #e74c3c;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .totals-row.paid-row {
            color: #27ae60;
            font-weight: 600;
        }

        /* ── NOTES ── */
        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div {
            background: #f4f6f8;
            padding: 12px 14px;
            border-radius: 6px;
            margin-bottom: 10px;
            border-left: 3px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .notes-section strong {
            color: #1a252f;
            display: block;
            margin-bottom: 5px;
            font-size: 11.5px;
            font-weight: 700;
        }
        .notes-section p { color: #1a252f; font-size: 11px; }

        /* .signature-box / .signature-line CSS lives in workflow_signature_row.php (canonical) */

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
            .box, .totals, .notes-section > div { box-shadow: none; border: 1px solid #e0e0e0; }
        }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_autofit.php'; ?>
</head>
<body onload="bmsAutoFitPrint()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>


    <div class="print-scale-wrapper">
    <!-- HEADER -->
    <div class="header">
        <div class="company-info">
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="company-addr-row">
                <?php if (!empty($comp['logo'])): ?>
                <img src="<?= htmlspecialchars('../../../' . $comp['logo']) ?>" alt="Logo">
                <?php endif; ?>
                <div class="company-addr-info">
                    <?php if (!empty($comp['address'])): ?>
                    <p><?= htmlspecialchars($comp['address']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($comp['postal_address'])): ?>
                    <p>P.O. Box <?= htmlspecialchars($comp['postal_address']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($comp['phone'])): ?>
                    <p>Phone: <?= htmlspecialchars($comp['phone']) ?></p>
                    <?php endif; ?>
                    <?php
                    $we = [];
                    if (!empty($comp['website'])) $we[] = 'Web: '   . htmlspecialchars($comp['website']);
                    if (!empty($comp['email']))   $we[] = 'Email: ' . htmlspecialchars($comp['email']);
                    if ($we): ?>
                    <p><?= implode(' | ', $we) ?></p>
                    <?php endif; ?>
                    <?php
                    $tv = [];
                    if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                    if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                    if ($tv): ?>
                    <p><?= implode(' | ', $tv) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="doc-title-box">
            <h2>TAX INVOICE</h2>
            <p><strong>Invoice #:</strong> <?= htmlspecialchars($invoice['invoice_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($invoice['invoice_date'])) ?></p>
            <p><strong>Due Date:</strong> <?= date('d M Y', strtotime($invoice['due_date'])) ?></p>
            <?php if (!empty($invoice['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($invoice['warehouse_name']) ?></p><?php endif; ?>
            <p><strong>Status:</strong> <?= strtoupper($invoice['status']) ?></p>
        </div>
    </div>

    <!-- CUSTOMER + INVOICE INFO -->
    <div class="details-grid">
        <div class="box">
            <h3>Bill To</h3>
            <p><strong><?= htmlspecialchars($invoice['customer_name']) ?></strong></p>
            <?php if (!empty($invoice['company_name'])): ?>
            <p><?= htmlspecialchars($invoice['company_name']) ?></p>
            <?php endif; ?>
            <?php if (!empty($invoice['c_postal_address'])): ?>
            <p>P.O. Box <?= htmlspecialchars($invoice['c_postal_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($invoice['c_address'])): ?>
            <p><?= htmlspecialchars($invoice['c_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($invoice['c_phone'])): ?>
            <p><?= htmlspecialchars($invoice['c_phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($invoice['c_email'])): ?>
            <p><?= htmlspecialchars($invoice['c_email']) ?></p>
            <?php endif; ?>
            <?php
            $c_tv = [];
            if (!empty($invoice['c_tin'])) $c_tv[] = 'TIN: ' . htmlspecialchars($invoice['c_tin']);
            if (!empty($invoice['c_vrn'])) $c_tv[] = 'VRN: ' . htmlspecialchars($invoice['c_vrn']);
            if ($c_tv): ?>
            <p><?= implode(' | ', $c_tv) ?></p>
            <?php endif; ?>
        </div>
        <div class="box">
            <h3>Invoice Details</h3>
            <?php if (!empty($invoice['reference_number'])): ?>
            <p><strong>Reference:</strong> <?= htmlspecialchars($invoice['reference_number']) ?></p>
            <?php endif; ?>
            <?php if (!empty($invoice['payment_terms'])): ?>
            <p><strong>Payment Terms:</strong> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $invoice['payment_terms']))) ?></p>
            <?php endif; ?>
            <?php if (!empty($invoice['delivery_id'])):
                $dnNumStmt = $pdo->prepare("SELECT delivery_number FROM deliveries WHERE delivery_id = ?");
                $dnNumStmt->execute([$invoice['delivery_id']]);
                $dn_ref = $dnNumStmt->fetchColumn();
            ?>
            <p><strong>DN Ref:</strong> <?= htmlspecialchars($dn_ref ?: ('#' . $invoice['delivery_id'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($invoice['customer_lpo_id'])):
                $lpoNumStmt = $pdo->prepare("SELECT lpo_number FROM customer_lpos WHERE lpo_id = ?");
                $lpoNumStmt->execute([$invoice['customer_lpo_id']]);
                $lpo_ref = $lpoNumStmt->fetchColumn();
            ?>
            <p><strong>LPO Ref:</strong> <?= htmlspecialchars($lpo_ref ?: ('#' . $invoice['customer_lpo_id'])) ?></p>
            <?php endif; ?>
            <p><strong>Created By:</strong> <?= htmlspecialchars($creator_label) ?></p>
            <?php
            $paid = floatval($invoice['paid_amount'] ?? 0);
            $balance = floatval($invoice['balance_due'] ?? 0);
            if ($paid > 0): ?>
            <p style="margin-top:8px; padding-top:8px; border-top:1px solid #dee2e6;"><strong>Paid Amount:</strong> <span style="color:#27ae60; font-weight:700;"><?= $currency ?> <?= number_format($paid, 2) ?></span></p>
            <p><strong>Balance Due:</strong> <span style="color:#e74c3c; font-weight:700;"><?= $currency ?> <?= number_format($balance, 2) ?></span></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th class="text-center" style="width:100px;">Product Code</th>
                <th class="text-center">Item / Description</th>
                <th class="text-right" style="width:80px;">Qty</th>
                <th class="text-right" style="width:105px;">Unit Price</th>
                <th class="text-right" style="width:115px;">Total (<?= $currency ?>)</th>
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

    <!-- TOTALS + BANK DETAILS -->
    <div class="totals-section">

        <!-- Bank Details (left) -->
        <?php if ($has_bank): ?>
        <div class="bank-details">
            <h3>Bank Details</h3>
            <?php if (!empty($bank['bank_name'])): ?>
            <div class="bank-section">
                <strong>Bank Transfer</strong>
                <p>Bank: <?= htmlspecialchars($bank['bank_name']) ?></p>
                <?php if (!empty($bank['account_name'])): ?>
                <p>Account Name: <?= htmlspecialchars($bank['account_name']) ?></p>
                <?php endif; ?>
                <?php if (!empty($bank['account_number'])): ?>
                <p>Account No: <?= htmlspecialchars($bank['account_number']) ?></p>
                <?php endif; ?>
                <?php if (!empty($bank['swift_code'])): ?>
                <p>SWIFT / BIC: <?= htmlspecialchars($bank['swift_code']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bank['mpesa_paybill'])): ?>
            <div class="bank-section">
                <strong>Mobile Money</strong>
                <p>Paybill: <?= htmlspecialchars($bank['mpesa_paybill']) ?></p>
                <?php if (!empty($bank['mpesa_account_no'])): ?>
                <p>Account: <?= htmlspecialchars($bank['mpesa_account_no']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bank['check_payable_to'])): ?>
            <div class="bank-section">
                <strong>Cheque</strong>
                <p>Payable to: <?= htmlspecialchars($bank['check_payable_to']) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="flex:1;"></div>
        <?php endif; ?>

        <!-- Amounts (right) -->
        <div class="totals">
            <div class="totals-row">
                <span>Subtotal:</span>
                <span><?= $currency ?> <?= number_format($subtotal, 2) ?></span>
            </div>
            <?php
            $tax_amount = floatval($invoice['grand_total'] ?? 0) - $subtotal;
            if ($tax_amount > 0): ?>
            <div class="totals-row">
                <span>VAT (18%):</span>
                <span><?= $currency ?> <?= number_format($tax_amount, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="totals-row grand-total">
                <span>GRAND TOTAL:</span>
                <span><?= $currency ?> <?= number_format($invoice['grand_total'], 2) ?></span>
            </div>
            <?php
            $paid_amount = floatval($invoice['paid_amount'] ?? 0);
            $balance_due = floatval($invoice['balance_due'] ?? $invoice['grand_total']);
            if ($paid_amount > 0): ?>
            <div class="totals-row paid-row">
                <span>Paid:</span>
                <span>-<?= $currency ?> <?= number_format($paid_amount, 2) ?></span>
            </div>
            <div class="totals-row balance-due">
                <span>BALANCE DUE:</span>
                <span><?= $currency ?> <?= number_format($balance_due, 2) ?></span>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- NOTES -->
    <div class="notes-section">
        <?php if (!empty($invoice['notes'])): ?>
        <div>
            <strong>Notes:</strong>
            <p><?= nl2br(htmlspecialchars($invoice['notes'])) ?></p>
        </div>
        <?php endif; ?>
        <?php if (!empty($invoice['terms_conditions'])): ?>
        <div>
            <strong>Terms &amp; Conditions:</strong>
            <p><?= nl2br(htmlspecialchars($invoice['terms_conditions'])) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- DRAFT WATERMARK (position:fixed; only when status !== 'approved') -->
    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>

    <!-- SIGNATURE — canonical partial (Created / Reviewed / Approved By + e-signature images) -->
    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    </div>


    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
