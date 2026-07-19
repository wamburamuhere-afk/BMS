<?php
// File: print_received_invoice.php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/core/workflow.php';

if (!isAuthenticated()) die("Unauthorized");
if (!canView('received_invoices')) die("Access Denied");

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) die("Invalid invoice ID");

// Phase C — block printing received invoices on projects not in user scope.
assertScopeForRecordHtml('supplier_invoices', 'id', $id);

global $pdo;
try {
    $stmt = $pdo->prepare("
        SELECT si.*,
               COALESCE(s.supplier_name, sc.supplier_name)   AS party_name,
               COALESCE(s.address, sc.address)                AS party_address,
               COALESCE(s.phone, sc.phone)                    AS party_phone,
               COALESCE(s.email, sc.email)                    AS party_email,
               COALESCE(s.tax_id, sc.tax_id)                  AS party_tin,
               COALESCE(s.vat_number, sc.vat_number)          AS party_vrn,
               po.order_number                                AS po_number,
               p.project_name,
               CONCAT(u.first_name, ' ', u.last_name)         AS recorded_by_name
        FROM supplier_invoices si
        LEFT JOIN suppliers s        ON si.invoice_type = 'supplier'       AND s.supplier_id  = si.supplier_id
        LEFT JOIN sub_contractors sc ON si.invoice_type = 'sub_contractor' AND sc.supplier_id = si.supplier_id
        LEFT JOIN purchase_orders po ON si.po_id        = po.purchase_order_id
        LEFT JOIN projects p         ON si.project_id   = p.project_id
        LEFT JOIN users u            ON si.recorded_by  = u.user_id
        WHERE si.id = ? AND si.status != 'deleted'
    ");
    $stmt->execute([$id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $inv = null;
}

if (!$inv) die("Invoice not found");

$inv_items = [];
try {
    $iStmt = $pdo->prepare("SELECT item_name, quantity, unit, unit_price, tax_rate, tax_amount, line_total
                              FROM supplier_invoice_items WHERE invoice_id = ? ORDER BY item_id");
    $iStmt->execute([$id]);
    $inv_items = $iStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $inv_items = []; }

$currency = 'TZS';
$subtotal = 0; $taxTotal = 0;
foreach ($inv_items as $item) {
    $lineSub   = (float)$item['quantity'] * (float)$item['unit_price'];
    $subtotal += $lineSub;
    $taxTotal += (float)$item['tax_amount'];
}
if (empty($inv_items)) {
    $subtotal = (float)$inv['amount'];
}
$grandTotal  = $subtotal + $taxTotal;
$amount_paid = (float)($inv['amount_paid'] ?? 0);
$amount_due  = max(0, (float)$inv['amount'] - $amount_paid);

// ── Signature row + DRAFT watermark ──
// Received-invoice statuses run draft -> submitted -> approved -> partial -> paid;
// 'partial'/'paid' are past approval, so treat them as approved for the watermark.
$wf_status = in_array($inv['status'], ['approved', 'partial', 'paid'], true) ? 'approved' : ($inv['status'] ?? 'draft');
$wf_sigs   = getWorkflowSignatures($pdo, 'supplier_invoice', $id);

$wf = [
    '__include_css'      => true,
    'created_by_name'    => $wf_sigs['created']['user_name']   ?: ($inv['recorded_by_name'] ?? ''),
    'created_by_role'    => $wf_sigs['created']['user_role']   ?? '',
    'created_sig_path'   => $wf_sigs['created']['sig_path']    ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']   ?? null,
    'reviewed_by_name'   => $wf_sigs['reviewed']['user_name']  ?? '',
    'reviewed_by_role'   => $wf_sigs['reviewed']['user_role']  ?? '',
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']   ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at']  ?? null,
    'approved_by_name'   => $wf_sigs['approved']['user_name']  ?? '',
    'approved_by_role'   => $wf_sigs['approved']['user_role']  ?? '',
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']   ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at']  ?? null,
];

function rpi_format_terms(string $t): string {
    $labels = ['COD'=>'COD (Due on Receipt)','Net7'=>'Net 7 Days','Net14'=>'Net 14 Days',
               'Net30'=>'Net 30 Days','Net45'=>'Net 45 Days','Net60'=>'Net 60 Days','Custom'=>'Custom'];
    if (isset($labels[$t])) return $labels[$t];
    if (preg_match('/^Net(\d+)$/', $t, $m)) return 'Net ' . $m[1] . ' Days';
    return $t;
}

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

$docTitle = $inv['invoice_type'] === 'sub_contractor' ? 'SUB-CONTRACTOR INVOICE' : 'SUPPLIER INVOICE';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= htmlspecialchars($inv['invoice_ref']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #1a252f; line-height: 1.5; padding: 20px 20px 0 20px; background: #fff; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; padding-bottom: 18px; border-bottom: 3px solid #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .company-info { flex: 1; padding-right: 20px; }
        .company-info h1 { color: #0d6efd; font-size: 22px; font-weight: 800; text-transform: uppercase; margin: 0 0 10px 0; letter-spacing: 0.5px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .company-addr-row { display: flex; align-items: flex-start; gap: 14px; }
        .company-addr-row img { max-height: 60px; width: auto; flex-shrink: 0; object-fit: contain; }
        .company-addr-info p { margin: 2px 0; color: #1a252f; font-size: 11px; font-weight: 500; }
        .lpo-title { text-align: right; background: #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; padding: 16px 22px; border-radius: 8px; min-width: 195px; }
        .lpo-title h2 { margin: 0 0 10px 0; color: #fff; font-size: 16px; font-weight: 700; letter-spacing: 1px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .lpo-title p { margin: 4px 0; font-size: 12px; color: #fff; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .lpo-title strong { font-weight: 600; }
        .details-grid { display: flex; justify-content: space-between; margin-bottom: 24px; gap: 14px; }
        .box { width: 48%; background: #f4f6f8; padding: 14px 16px; border-radius: 6px; border-left: 4px solid #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .box h3 { font-size: 11px; color: #1a252f; padding-bottom: 7px; margin-bottom: 10px; border-bottom: 1.5px solid #3498db; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .box p { margin: 3px 0; color: #1a252f; font-size: 11.5px; }
        .box strong { color: #1a252f; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #34495e; color: #fff; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 9px 10px; text-align: left; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr { border-bottom: 1px solid #e4e8ec; }
        tbody tr:nth-child(even) { background: #f9fafb; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr:last-child { border-bottom: 2px solid #3498db; }
        tbody tr td { height: 0.75cm; padding: 2px 10px; vertical-align: middle; font-size: 13px; line-height: 1.6; color: #1a252f; }
        .text-right { text-align: right; } .text-center { text-align: center; } .fw-bold { font-weight: 700; }
        .totals { float: right; width: 310px; background: #f4f6f8; padding: 14px 18px; border-radius: 6px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .totals-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 12px; color: #1a252f; border-bottom: 1px solid #e4e8ec; }
        .totals-row:last-child { border-bottom: none; }
        .totals-row.grand-total { border-top: 2px solid #3498db; border-bottom: none; margin-top: 8px; padding-top: 10px; font-size: 14px; font-weight: 700; color: #1a252f; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .totals-row.due { color: #dc3545; font-weight: 700; }
        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div { background: #f4f6f8; padding: 12px 14px; border-radius: 6px; margin-bottom: 10px; border-left: 3px solid #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .notes-section strong { color: #1a252f; display: block; margin-bottom: 5px; font-size: 11.5px; font-weight: 700; }
        .notes-section p { color: #1a252f; font-size: 11px; }
        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print { .no-print { display: none !important; } body { margin: 0 !important; } .box, .totals, .notes-section > div { box-shadow: none; border: 1px solid #e0e0e0; } }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_autofit.php'; ?>
</head>
<body onload="bmsAutoFitPrint()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer;">Print</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>

    <div class="print-scale-wrapper">
    <div class="header">
        <div class="company-info">
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="company-addr-row">
                <?php if (!empty($comp['logo'])): ?>
                <img src="<?= htmlspecialchars('../../../' . $comp['logo']) ?>" alt="Logo">
                <?php endif; ?>
                <div class="company-addr-info">
                    <?php if (!empty($comp['address'])): ?><p><?= htmlspecialchars($comp['address']) ?></p><?php endif; ?>
                    <?php if (!empty($comp['postal_address'])): ?><p><?= htmlspecialchars($comp['postal_address']) ?></p><?php endif; ?>
                    <?php if (!empty($comp['phone'])): ?><p>Phone: <?= htmlspecialchars($comp['phone']) ?></p><?php endif; ?>
                    <?php
                    $we = [];
                    if (!empty($comp['website'])) $we[] = 'Web: '   . htmlspecialchars($comp['website']);
                    if (!empty($comp['email']))   $we[] = 'Email: ' . htmlspecialchars($comp['email']);
                    if ($we): ?><p><?= implode(' | ', $we) ?></p><?php endif; ?>
                    <?php
                    $tv = [];
                    if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                    if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                    if ($tv): ?><p><?= implode(' | ', $tv) ?></p><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="lpo-title">
            <h2><?= htmlspecialchars($docTitle) ?></h2>
            <p><strong>Ref #:</strong> <?= htmlspecialchars($inv['invoice_ref']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($inv['date_raised'])) ?></p>
            <p><strong>Status:</strong> <?= strtoupper($inv['status']) ?></p>
        </div>
    </div>

    <div class="details-grid">
        <div class="box">
            <h3><?= $inv['invoice_type'] === 'sub_contractor' ? 'Sub-Contractor' : 'Supplier' ?></h3>
            <p><strong><?= htmlspecialchars($inv['party_name'] ?? '') ?></strong></p>
            <?php if (!empty($inv['party_address'])): ?><p><?= htmlspecialchars($inv['party_address']) ?></p><?php endif; ?>
            <?php if (!empty($inv['party_phone'])): ?><p><?= htmlspecialchars($inv['party_phone']) ?></p><?php endif; ?>
            <?php if (!empty($inv['party_email'])): ?><p><?= htmlspecialchars($inv['party_email']) ?></p><?php endif; ?>
            <?php
            $p_tv = [];
            if (!empty($inv['party_tin'])) $p_tv[] = 'TIN: ' . htmlspecialchars($inv['party_tin']);
            if (!empty($inv['party_vrn'])) $p_tv[] = 'VRN: ' . htmlspecialchars($inv['party_vrn']);
            if ($p_tv): ?><p><?= implode(' | ', $p_tv) ?></p><?php endif; ?>
        </div>
        <div class="box">
            <h3>Invoice Information</h3>
            <?php if (!empty($inv['po_number'])): ?><p><strong>PO Reference:</strong> <?= htmlspecialchars($inv['po_number']) ?></p><?php endif; ?>
            <?php if (!empty($inv['project_name'])): ?><p><strong>Project:</strong> <?= htmlspecialchars($inv['project_name']) ?></p><?php endif; ?>
            <?php if (!empty($inv['payment_terms'])): ?><p><strong>Payment Terms:</strong> <?= htmlspecialchars(rpi_format_terms($inv['payment_terms'])) ?></p><?php endif; ?>
            <p><strong>Due Date:</strong> <?= !empty($inv['due_date']) ? date('d M Y', strtotime($inv['due_date'])) : 'Not specified' ?></p>
            <p><strong>Recorded By:</strong> <?= htmlspecialchars($inv['recorded_by_name'] ?? 'N/A') ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th class="text-center">Item / Description</th>
                <th class="text-right" style="width:80px;">Qty</th>
                <th class="text-right" style="width:105px;">Unit Price</th>
                <th class="text-right" style="width:70px;">Tax</th>
                <th class="text-right" style="width:115px;">Total (<?= $currency ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($inv_items)): ?>
                <?php foreach ($inv_items as $i => $item):
                    $lt = (float)$item['quantity'] * (float)$item['unit_price']; ?>
                <tr>
                    <td class="text-center"><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($item['item_name']) ?></td>
                    <td class="text-right"><?= floatval($item['quantity']) ?></td>
                    <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                    <td class="text-right"><?= number_format($item['tax_rate'], 1) ?>%</td>
                    <td class="text-right fw-bold"><?= number_format($lt + (float)$item['tax_amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td class="text-center">1</td>
                    <td>Invoice Amount</td>
                    <td class="text-right">1</td>
                    <td class="text-right"><?= number_format($inv['amount'], 2) ?></td>
                    <td class="text-right">0.0%</td>
                    <td class="text-right fw-bold"><?= number_format($inv['amount'], 2) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($subtotal, 2) ?></span></div>
        <div class="totals-row"><span>Total Tax:</span><span><?= $currency ?> <?= number_format($taxTotal, 2) ?></span></div>
        <div class="totals-row grand-total"><span>GRAND TOTAL:</span><span><?= $currency ?> <?= number_format($inv['amount'], 2) ?></span></div>
        <?php if (in_array($inv['status'], ['partial', 'paid'], true)): ?>
        <div class="totals-row"><span>Amount Paid:</span><span><?= $currency ?> <?= number_format($amount_paid, 2) ?></span></div>
        <?php if ($amount_due > 0): ?>
        <div class="totals-row due"><span>Amount Due:</span><span><?= $currency ?> <?= number_format($amount_due, 2) ?></span></div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="notes-section">
        <?php if (!empty($inv['notes'])): ?>
        <div><strong>Internal Notes:</strong><p><?= nl2br(htmlspecialchars($inv['notes'])) ?></p></div>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>

    <div style="clear: both;">
        <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    </div>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
