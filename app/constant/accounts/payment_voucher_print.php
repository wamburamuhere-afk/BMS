<?php
require_once __DIR__ . '/../../../roots.php';

// Phase 5b — print pages get a canView gate (admin auto-bypass)
if (!isAuthenticated() || !canView('payment_vouchers')) {
    http_response_code(403);
    die("Access Denied");
}

// Get Voucher ID
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    die("Invalid Voucher ID");
}

// Phase C — block printing vouchers on projects not in user scope (HTML-safe)
assertScopeForRecordHtml('payment_vouchers', 'id', $id);

// Fetch Voucher Details
global $pdo;
$stmt = $pdo->prepare("
    SELECT pv.*, u.username as prepared_by_name, u2.username as approved_by_name, ac.category_name,
           ea.account_name AS expense_account_name
    FROM payment_vouchers pv
    LEFT JOIN users u ON pv.prepared_by = u.user_id
    LEFT JOIN users u2 ON pv.approved_by = u2.user_id
    LEFT JOIN account_categories ac ON pv.expense_category_id = ac.category_id
    LEFT JOIN accounts ea ON pv.expense_account_id = ea.account_id
    WHERE pv.id = ?
");
$stmt->execute([$id]);
$v = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$v) {
    die("Voucher Not Found");
}

// Fetch Company Settings
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

// Fetch Payment Settings (bank details)
$pay = [];
try {
    $stmtP = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('bank_name','account_name','account_number','swift_code','check_payable_to','mpesa_paybill','mpesa_account_no')");
    $stmtP->execute();
    while ($r = $stmtP->fetch(PDO::FETCH_ASSOC)) {
        $pay[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {}
$hasBankTransfer = !empty($pay['bank_name']) || !empty($pay['account_number']);
$hasMobile       = !empty($pay['mpesa_paybill']);
$hasCheque       = !empty($pay['check_payable_to']);
$hasPayment      = $hasBankTransfer || $hasMobile || $hasCheque;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Voucher #<?= htmlspecialchars($v['voucher_number']) ?></title>
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
            max-height: 70px;
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
            min-width: 240px;
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

        /* ── AMOUNT BAR ── */
        .amount-bar {
            background: #eef2f7;
            padding: 15px 20px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 25px 0;
            border-right: 5px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .amount-words { flex: 1; padding-right: 20px; }
        .amount-words strong { display: block; font-size: 10px; color: #3498db; text-transform: uppercase; margin-bottom: 3px; }
        .amount-words p { font-style: italic; font-weight: 600; font-size: 12px; color: #2c3e50; }
        .amount-value-box { text-align: right; }
        .amount-value-box strong { display: block; font-size: 10px; color: #3498db; text-transform: uppercase; margin-bottom: 3px; }
        .amount-value-box .val { font-size: 20px; font-weight: 800; color: #0d6efd; }

        /* ── DESCRIPTION ── */
        .description-section {
            margin-bottom: 25px;
            padding: 15px;
            background: #fff;
            border: 1px solid #e4e8ec;
            border-radius: 6px;
        }
        .description-section strong {
            display: block;
            font-size: 11px;
            color: #3498db;
            text-transform: uppercase;
            margin-bottom: 8px;
            border-bottom: 1px solid #e4e8ec;
            padding-bottom: 4px;
        }
        .description-section p { font-size: 12px; color: #2c3e50; min-height: 60px; line-height: 1.6; }

        /* ── SIGNATURE ── */
        .signature-box {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
            gap: 20px;
        }
        .sig-block { flex: 1; text-align: center; }
        .signature-line {
            padding-top: 8px;
            font-size: 11px;
            color: #1a252f;
            font-weight: 700;
            text-transform: uppercase;
        }
        .sig-name { font-size: 10px; color: #7f8c8d; margin-top: 3px; }

        /* ── BANK DETAILS ── */
        .bank-details { flex: 1; background: #f4f6f8; padding: 14px 16px; border-radius: 6px; border-left: 4px solid #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .bank-details h3 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #1a252f; padding-bottom: 7px; margin-bottom: 10px; border-bottom: 1.5px solid #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .bank-section { margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #e4e8ec; }
        .bank-section:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
        .bank-section h4 { font-size: 10px; font-weight: 700; color: #3498db; text-transform: uppercase; margin-bottom: 4px; }
        .bank-section p { margin: 2px 0; font-size: 11px; color: #1a252f; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
        }
    </style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="if(window.location.search.includes('print=true')) window.print()">

    <div class="no-print" style="position:fixed; top:0; left:0; right:0; background:#333; padding:10px; display:flex; gap:10px; z-index:9999;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; background:#3498db; color:#fff; border:none; border-radius:4px; font-weight:bold;">Print Voucher</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; background:#eee; border:none; border-radius:4px;">Close</button>
    </div>

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
                    if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars((string)$comp['tin']);
                    if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars((string)$comp['vrn']);
                    if ($tv): ?>
                    <p><?= implode(' | ', $tv) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="doc-title-box">
            <h2>PAYMENT VOUCHER</h2>
            <p><strong>PV #:</strong> <?= htmlspecialchars($v['voucher_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($v['vouch_date'])) ?></p>
            <p><strong>Status:</strong> <?= strtoupper($v['status']) ?></p>
        </div>
    </div>

    <!-- DETAILS GRID -->
    <div class="details-grid">
        <div class="box">
            <h3>Payee Information</h3>
            <p><strong>Paid To:</strong></p>
            <p style="font-size: 16px; font-weight: 800; color: #0d6efd; margin-top: 2px;"><?= htmlspecialchars($v['payee_name']) ?></p>
            <div style="margin-top: 10px;">
                <p><strong>Expense Category:</strong></p>
                <p><?= htmlspecialchars($v['expense_account_name'] ?: $v['category_name'] ?: 'Uncategorized') ?></p>
            </div>
        </div>
        <div class="box">
            <h3>Payment Details</h3>
            <p><strong>Payment Method:</strong> <?= strtoupper(str_replace('_', ' ', $v['payment_method'])) ?></p>
            <p><strong>Reference No:</strong> <?= htmlspecialchars($v['reference_number'] ?: 'N/A') ?></p>
            <hr style="margin: 8px 0; border: none; border-top: 1px solid #dee2e6;">
            <p><strong>Prepared By:</strong> <?= htmlspecialchars($v['prepared_by_name'] ?? 'Staff') ?></p>
            <p><strong>Approved By:</strong> <?= htmlspecialchars($v['approved_by_name'] ?? 'Authorized Manager') ?></p>
        </div>
    </div>

    <!-- DESCRIPTION -->
    <div class="description-section">
        <strong>Description / Narration:</strong>
        <p><?= nl2br(htmlspecialchars($v['description'])) ?></p>
    </div>

    <!-- AMOUNT BAR -->
    <div class="amount-bar">
        <div class="amount-words">
            <strong>Amount in Words:</strong>
            <p><?= htmlspecialchars((string)($v['amount_in_words'] ?: '------------------------------------------------------------')) ?></p>
        </div>
        <div class="amount-value-box">
            <strong>Total Amount:</strong>
            <div class="val">TSh <?= number_format($v['amount'], 2) ?></div>
        </div>
    </div>

    <?php if ($hasPayment): ?>
    <!-- BANK DETAILS -->
    <div class="bank-details" style="margin-bottom: 25px;">
        <h3>Payment / Bank Details</h3>
        <?php if ($hasBankTransfer): ?>
        <div class="bank-section">
            <h4>Bank Transfer</h4>
            <?php if (!empty($pay['bank_name'])): ?><p><strong>Bank:</strong> <?= htmlspecialchars($pay['bank_name']) ?></p><?php endif; ?>
            <?php if (!empty($pay['account_name'])): ?><p><strong>Account Name:</strong> <?= htmlspecialchars($pay['account_name']) ?></p><?php endif; ?>
            <?php if (!empty($pay['account_number'])): ?><p><strong>Account No:</strong> <?= htmlspecialchars($pay['account_number']) ?></p><?php endif; ?>
            <?php if (!empty($pay['swift_code'])): ?><p><strong>Swift Code:</strong> <?= htmlspecialchars($pay['swift_code']) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($hasMobile): ?>
        <div class="bank-section">
            <h4>Mobile Money</h4>
            <?php if (!empty($pay['mpesa_paybill'])): ?><p><strong>Paybill / Till:</strong> <?= htmlspecialchars($pay['mpesa_paybill']) ?></p><?php endif; ?>
            <?php if (!empty($pay['mpesa_account_no'])): ?><p><strong>Account No:</strong> <?= htmlspecialchars($pay['mpesa_account_no']) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($hasCheque): ?>
        <div class="bank-section">
            <h4>Cheque</h4>
            <p><strong>Payable To:</strong> <?= htmlspecialchars($pay['check_payable_to']) ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- SIGNATURES -->
    <div class="signature-box">
        <div class="sig-block">
            <div class="signature-line">Prepared By</div>
            <div class="sig-name"><?= htmlspecialchars((string)($v['prepared_by_name'] ?? '')) ?></div>
        </div>
        <div class="sig-block">
            <div class="signature-line">Approved By</div>
            <div class="sig-name"><?= htmlspecialchars((string)($v['approved_by_name'] ?? 'Not Approved')) ?></div>
        </div>
        <div class="sig-block">
            <div class="signature-line">Receiver's Signature</div>
            <div class="sig-name"><?= htmlspecialchars((string)($v['payee_name'] ?? '')) ?></div>
        </div>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
