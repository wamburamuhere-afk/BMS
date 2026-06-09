<?php
/**
 * api/account/print_receipt.php
 * Standalone A4 print page for a customer payment receipt.
 * Opened in a new tab from the Receive Payment success dialog.
 * Styled to match payment_voucher_print.php conventions.
 *
 *   ?payment_id=N
 */
function number_to_words_tz(float $amount, string $currency = 'TZS'): string {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
             'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
             'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $conv = function (int $n) use ($ones, $tens, &$conv): string {
        if ($n < 20)         return $ones[$n];
        if ($n < 100)        return $tens[(int)($n/10)] . ($n%10 ? ' '.$ones[$n%10] : '');
        if ($n < 1000)       return $ones[(int)($n/100)] . ' Hundred' . ($n%100 ? ' '.$conv($n%100) : '');
        if ($n < 1000000)    return $conv((int)($n/1000)) . ' Thousand' . ($n%1000 ? ' '.$conv($n%1000) : '');
        if ($n < 1000000000) return $conv((int)($n/1000000)) . ' Million' . ($n%1000000 ? ' '.$conv($n%1000000) : '');
        return $conv((int)($n/1000000000)) . ' Billion' . ($n%1000000000 ? ' '.$conv($n%1000000000) : '');
    };
    $int  = (int)$amount;
    $cen  = (int)round(($amount - $int) * 100);
    $out  = ($int === 0 ? 'Zero' : $conv($int)) . ' ' . $currency;
    if ($cen > 0) $out .= ' and ' . $conv($cen) . ' Cents';
    return $out . ' Only';
}

require_once __DIR__ . '/../../roots.php';

if (!isAuthenticated() || !canView('invoices')) {
    http_response_code(403);
    die('Access Denied');
}

$payment_id = (int)($_GET['payment_id'] ?? 0);
if ($payment_id <= 0) die('Invalid receipt ID');

global $pdo;
require_once __DIR__ . '/../../core/project_scope.php';
assertScopeForRecordHtml('payments', 'payment_id', $payment_id);

// Fetch payment + customer + received-into account
$stmt = $pdo->prepare("
    SELECT p.*,
           c.customer_name, c.phone AS customer_phone, c.email AS customer_email,
           c.address AS customer_address,
           a.account_name AS received_account,
           u.username AS received_by_name
      FROM payments p
      LEFT JOIN customers c  ON p.customer_id = c.customer_id
      LEFT JOIN accounts  a  ON p.received_into_account_id = a.account_id
      LEFT JOIN users     u  ON p.received_by = u.user_id
     WHERE p.payment_id = ?
");
$stmt->execute([$payment_id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) die('Receipt not found');

// Fetch allocated invoices
$allocStmt = $pdo->prepare("
    SELECT i.invoice_number, i.invoice_date, i.grand_total, pa.allocated_amount
      FROM payment_allocations pa
      JOIN invoices i ON pa.target_id = i.invoice_id AND pa.target_type = 'invoice'
     WHERE pa.payment_id = ?
     ORDER BY i.invoice_date ASC, i.invoice_id ASC
");
$allocStmt->execute([$payment_id]);
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

// If no allocation rows (legacy single-invoice payment), show the linked invoice
if (empty($allocations) && $p['invoice_id']) {
    $legStmt = $pdo->prepare("SELECT invoice_number, invoice_date, grand_total FROM invoices WHERE invoice_id = ?");
    $legStmt->execute([$p['invoice_id']]);
    $leg = $legStmt->fetch(PDO::FETCH_ASSOC);
    if ($leg) $allocations = [array_merge($leg, ['allocated_amount' => $p['amount']])];
}

// Company settings
$comp = ['name' => 'Business Management System', 'email' => '', 'phone' => '', 'address' => '', 'postal_address' => '', 'website' => '', 'tin' => '', 'vrn' => '', 'logo' => ''];
try {
    $cs = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'company_%'");
    $cs->execute();
    while ($r = $cs->fetch(PDO::FETCH_ASSOC)) {
        $k = str_replace('company_', '', $r['setting_key']);
        if ($k === 'physical_address') $comp['address'] = $r['setting_value'];
        elseif ($k === 'logo')         $comp['logo']    = $r['setting_value'];
        else                           $comp[$k]        = $r['setting_value'];
    }
} catch (Exception $e) {}

$method_label = ucwords(str_replace('_', ' ', $p['payment_method'] ?? 'cash'));
$currency = $p['currency'] ?: get_setting('currency', 'TZS');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt <?= htmlspecialchars($p['payment_number']) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
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
            margin-bottom: 26px;
            padding-bottom: 16px;
            border-bottom: 3px solid #0d6efd;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .company-info { flex: 1; padding-right: 20px; }
        .company-info h1 {
            color: #0d6efd;
            font-size: 21px;
            font-weight: 800;
            text-transform: uppercase;
            margin: 0 0 9px 0;
            letter-spacing: .5px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .company-addr-row { display:flex; align-items:flex-start; gap:14px; }
        .company-addr-row img { max-height:68px; width:auto; flex-shrink:0; object-fit:contain; }
        .company-addr-info p { margin:2px 0; color:#1a252f; font-size:11px; font-weight:500; }

        /* ── TITLE BOX ── */
        .doc-title-box {
            text-align: right;
            background: #0d6efd;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
            padding: 16px 22px;
            border-radius: 8px;
            min-width: 230px;
        }
        .doc-title-box h2 { margin:0 0 8px 0; color:#fff; font-size:15px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
        .doc-title-box p { margin:4px 0; font-size:12px; color:#fff; print-color-adjust:exact; -webkit-print-color-adjust:exact; }

        /* ── INFO BOXES ── */
        .details-grid { display:flex; justify-content:space-between; margin-bottom:20px; gap:14px; }
        .box {
            width: 48%;
            background: #f4f6f8;
            padding: 13px 15px;
            border-radius: 6px;
            border-left: 4px solid #0d6efd;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .box h3 { font-size:10.5px; color:#1a252f; padding-bottom:6px; margin-bottom:9px; border-bottom:1.5px solid #0d6efd; font-weight:700; text-transform:uppercase; letter-spacing:.5px; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
        .box p { margin:3px 0; color:#1a252f; font-size:11.5px; }
        .box strong { font-weight:600; }

        /* ── ALLOCATION TABLE ── */
        .alloc-table { width:100%; border-collapse:collapse; margin-bottom:20px; }
        .alloc-table th { background:#0d6efd; color:#fff; padding:7px 10px; font-size:10.5px; text-transform:uppercase; letter-spacing:.4px; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
        .alloc-table td { padding:7px 10px; border-bottom:1px solid #e4e8ec; font-size:11.5px; }
        .alloc-table tr:last-child td { border-bottom:none; }
        .alloc-table .text-right { text-align:right; }
        .alloc-table .text-center { text-align:center; }

        /* ── AMOUNT BAR ── */
        .amount-bar {
            background: #eef2f7;
            padding: 14px 18px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
            border-right: 5px solid #0d6efd;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .amount-words { flex:1; padding-right:20px; }
        .amount-words strong { display:block; font-size:10px; color:#0d6efd; text-transform:uppercase; margin-bottom:3px; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
        .amount-words p { font-style:italic; font-weight:600; font-size:12px; color:#2c3e50; }
        .amount-value-box { text-align:right; }
        .amount-value-box strong { display:block; font-size:10px; color:#0d6efd; text-transform:uppercase; margin-bottom:3px; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
        .amount-value-box .val { font-size:20px; font-weight:800; color:#0d6efd; print-color-adjust:exact; -webkit-print-color-adjust:exact; }

        /* ── SIGNATURES ── */
        .signature-box { margin-top:46px; display:flex; justify-content:space-between; gap:20px; }
        .sig-block { flex:1; text-align:center; }
        .sig-line-rule { border-top:1px solid #1a252f; margin:0 10px 5px; }
        .sig-label { font-size:11px; color:#1a252f; font-weight:700; text-transform:uppercase; }
        .sig-name { font-size:10px; color:#7f8c8d; margin-top:2px; }

        @page { margin:10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display:none !important; }
            body { margin:0 !important; }
        }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="if(window.location.search.includes('print=true')) window.print()">

    <div class="no-print" style="position:fixed;top:0;left:0;right:0;background:#333;padding:10px;display:flex;gap:10px;z-index:9999;">
        <button onclick="window.print()" style="padding:6px 18px;cursor:pointer;background:#0d6efd;color:#fff;border:none;border-radius:4px;font-weight:bold;">&#x1F5A8; Print Receipt</button>
        <button onclick="window.close()" style="padding:6px 18px;cursor:pointer;background:#eee;border:none;border-radius:4px;">Close</button>
    </div>

    <!-- HEADER -->
    <div class="header">
        <div class="company-info">
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="company-addr-row">
                <?php if (!empty($comp['logo'])): ?>
                <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
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
                    if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars((string)$comp['tin']);
                    if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars((string)$comp['vrn']);
                    if ($tv): ?>
                    <p><?= implode(' | ', $tv) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="doc-title-box">
            <h2>Payment Receipt</h2>
            <p><strong>Receipt #:</strong> <?= htmlspecialchars($p['payment_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($p['payment_date'])) ?></p>
            <p><strong>Method:</strong> <?= htmlspecialchars($method_label) ?></p>
        </div>
    </div>

    <!-- DETAILS GRID -->
    <div class="details-grid">
        <div class="box">
            <h3>Received From</h3>
            <p style="font-size:15px;font-weight:800;color:#0d6efd;margin-top:2px;"><?= htmlspecialchars($p['customer_name'] ?? '—') ?></p>
            <?php if (!empty($p['customer_phone'])): ?>
            <p style="margin-top:8px;"><strong>Phone:</strong> <?= htmlspecialchars($p['customer_phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($p['customer_email'])): ?>
            <p><strong>Email:</strong> <?= htmlspecialchars($p['customer_email']) ?></p>
            <?php endif; ?>
            <?php if (!empty($p['customer_address'])): ?>
            <p><strong>Address:</strong> <?= htmlspecialchars($p['customer_address']) ?></p>
            <?php endif; ?>
        </div>
        <div class="box">
            <h3>Payment Details</h3>
            <p><strong>Payment Method:</strong> <?= htmlspecialchars($method_label) ?></p>
            <p><strong>Received Into:</strong> <?= htmlspecialchars($p['received_account'] ?: '—') ?></p>
            <?php if (!empty($p['reference_number'])): ?>
            <p><strong>Reference:</strong> <?= htmlspecialchars($p['reference_number']) ?></p>
            <?php endif; ?>
            <hr style="margin:8px 0;border:none;border-top:1px solid #dee2e6;">
            <p><strong>Received By:</strong> <?= htmlspecialchars($p['received_by_name'] ?? 'Staff') ?></p>
            <?php if (!empty($p['notes'])): ?>
            <p><strong>Notes:</strong> <?= htmlspecialchars($p['notes']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ALLOCATED INVOICES TABLE -->
    <table class="alloc-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Invoice Number</th>
                <th class="text-center">Invoice Date</th>
                <th class="text-right">Invoice Total</th>
                <th class="text-right">Amount Allocated</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allocations as $i => $a): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($a['invoice_number']) ?></strong></td>
                <td class="text-center"><?= !empty($a['invoice_date']) ? date('d M Y', strtotime($a['invoice_date'])) : '—' ?></td>
                <td class="text-right"><?= $currency ?> <?= number_format((float)$a['grand_total'], 2) ?></td>
                <td class="text-right"><strong><?= $currency ?> <?= number_format((float)$a['allocated_amount'], 2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- AMOUNT BAR -->
    <div class="amount-bar">
        <div class="amount-words">
            <strong>Amount in Words:</strong>
            <p><?= htmlspecialchars(number_to_words_tz((float)$p['amount'], $currency)) ?></p>
        </div>
        <div class="amount-value-box">
            <strong>Total Amount Received:</strong>
            <div class="val"><?= $currency ?> <?= number_format((float)$p['amount'], 2) ?></div>
        </div>
    </div>

    <!-- SIGNATURES -->
    <div class="signature-box">
        <div class="sig-block">
            <div class="sig-line-rule"></div>
            <div class="sig-label">Prepared By</div>
            <div class="sig-name"><?= htmlspecialchars($p['received_by_name'] ?? '') ?></div>
        </div>
        <div class="sig-block">
            <div class="sig-line-rule"></div>
            <div class="sig-label">Authorised By</div>
            <div class="sig-name">&nbsp;</div>
        </div>
        <div class="sig-block">
            <div class="sig-line-rule"></div>
            <div class="sig-label">Customer Signature</div>
            <div class="sig-name"><?= htmlspecialchars($p['customer_name'] ?? '') ?></div>
        </div>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
