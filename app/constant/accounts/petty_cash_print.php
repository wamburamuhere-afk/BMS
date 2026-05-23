<?php
require_once __DIR__ . '/../../../roots.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) die("Invalid Transaction ID");

if (!isAuthenticated()) die("Access Denied: Please log in.");

global $pdo;
$stmt = $pdo->prepare("
    SELECT pt.*, u.username, u.first_name, u.last_name, ac.category_name
    FROM petty_cash_transactions pt
    LEFT JOIN users u  ON pt.user_id    = u.user_id
    LEFT JOIN account_categories ac ON pt.category_id = ac.category_id
    WHERE pt.id = ?
");
$stmt->execute([$id]);
$t = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$t) die("Transaction Not Found");

// Company info
$company = [
    'name'           => getSetting('company_name',            'BUSINESS MANAGEMENT SYSTEM'),
    'email'          => getSetting('company_email',           ''),
    'phone'          => getSetting('company_phone',           ''),
    'address'        => getSetting('company_physical_address', getSetting('company_address', '')),
    'postal_address' => getSetting('company_postal_address',  ''),
    'website'        => getSetting('company_website',         ''),
    'tin'            => getSetting('company_tin',             ''),
    'vrn'            => getSetting('company_vrn',             ''),
    'logo'           => getSetting('company_logo',            ''),
];

// Amount in words
function amountToWords($n) {
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
             'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen',
             'Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $n = (int) floor($n);
    if ($n === 0) return 'Zero';
    $w = '';
    if ($n >= 1000000000) { $w .= amountToWords((int)($n/1000000000)) . ' Billion '; $n %= 1000000000; }
    if ($n >= 1000000)    { $w .= amountToWords((int)($n/1000000))    . ' Million '; $n %= 1000000; }
    if ($n >= 1000)       { $w .= amountToWords((int)($n/1000))       . ' Thousand '; $n %= 1000; }
    if ($n >= 100)        { $w .= $ones[(int)($n/100)] . ' Hundred '; $n %= 100; }
    if ($n >= 20)         { $w .= $tens[(int)($n/10)] . ' '; $n %= 10; }
    if ($n > 0)           { $w .= $ones[$n] . ' '; }
    return trim($w);
}
$amount_words = amountToWords($t['amount']) . ' Shillings Only';

// Prepared by full name
$prepared_by = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')) ?: ($t['username'] ?? 'Unknown');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Petty Cash Voucher #PCV-<?= str_pad($t['id'], 5, '0', STR_PAD_LEFT) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= getUrl('assets/css/responsive.css') ?>">
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <style>
        /* ── SCREEN STYLES ──────────────────────────────────── */
        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f0f2f5;
            color: #212529;
        }
        .preview-bar {
            position: fixed; top: 0; left: 0; width: 100%;
            background: #1a1a2e; color: #fff;
            padding: 8px 20px; z-index: 9999;
            display: flex; justify-content: space-between; align-items: center;
        }
        .voucher-wrapper {
            max-width: 820px;
            margin: 70px auto 40px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        /* ── VOUCHER SECTIONS ───────────────────────────────── */
        .v-section      { padding: 14px 20px; border-bottom: 1px solid #dee2e6; }
        .v-section:last-child { border-bottom: none; }
        .v-label        { font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
                          color: #6c757d; letter-spacing: 0.5px; margin-bottom: 2px; }
        .v-value        { font-size: 0.95rem; font-weight: 600; color: #212529; }
        .v-title        { font-size: 1.3rem; font-weight: 900; color: #212529;
                          text-transform: uppercase; letter-spacing: 1px; }
        .voucher-number { font-size: 1rem; font-weight: 700; color: #495057; }
        .company-name   { font-size: 1.1rem; font-weight: 900; color: #0d6efd;
                          text-transform: uppercase; }
        .company-detail { font-size: 0.78rem; color: #495057; line-height: 1.6; }

        .amount-section { background: #f8f9fa; text-align: center; padding: 18px 20px; }
        .amount-value   { font-size: 2rem; font-weight: 900; color: #212529; letter-spacing: 1px; }
        .amount-words   { font-size: 0.85rem; color: #495057; font-style: italic; margin-top: 4px; }

        .sig-block      { text-align: center; }
        .sig-line       { border-bottom: 1px solid #212529; margin: 30px 10px 6px; }
        .sig-name       { font-size: 0.78rem; color: #495057; }
        .sig-date       { font-size: 0.72rem; color: #6c757d; margin-top: 2px; }
        .stamp-box      { border: 1px dashed #adb5bd; border-radius: 4px;
                          height: 80px; display: flex; align-items: center;
                          justify-content: center; color: #adb5bd;
                          font-size: 0.75rem; text-align: center; padding: 8px; }

        .doc-checkbox   { display: inline-flex; align-items: center; gap: 4px;
                          margin-right: 16px; font-size: 0.85rem; }
        .chk-box        { width: 13px; height: 13px; border: 1.5px solid #495057;
                          display: inline-block; text-align: center; line-height: 11px;
                          font-size: 10px; font-weight: 900; }
        .chk-checked    { background: #212529; color: #fff; }

        /* ── PRINT STYLES ───────────────────────────────────── */
        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            body { background: #fff !important; margin: 0 !important; padding: 0 !important; }
            .preview-bar { display: none !important; }
            .voucher-wrapper {
                margin: 0 !important; border: none !important;
                box-shadow: none !important; border-radius: 0 !important;
                max-width: 100% !important;
            }
            .amount-section { background: #f8f9fa !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important; }
            .company-name { color: #0d6efd !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important; }
            .chk-checked  { background: #212529 !important; color: #fff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important; }
            .row { display: flex !important; flex-wrap: wrap !important; }
            .col-4  { flex: 0 0 33.333% !important; max-width: 33.333% !important; }
            .col-6  { flex: 0 0 50%      !important; max-width: 50%      !important; }
            .col-8  { flex: 0 0 66.666% !important; max-width: 66.666% !important; }
            .col-12 { flex: 0 0 100%     !important; max-width: 100%     !important; }
        }
    </style>
</head>
<body>

<!-- Preview Bar (hidden on print) -->
<div class="preview-bar">
    <span><i class="bi bi-printer me-2"></i> Petty Cash Voucher Preview &mdash;
        <strong>#PCV-<?= str_pad($t['id'], 5, '0', STR_PAD_LEFT) ?></strong>
    </span>
    <div>
        <button onclick="window.print()" class="btn btn-sm btn-primary px-3 fw-bold me-2">
            <i class="bi bi-printer me-1"></i> Print
        </button>
        <button onclick="window.close()" class="btn btn-sm btn-outline-light px-3">
            <i class="bi bi-x me-1"></i> Close
        </button>
    </div>
</div>

<div class="voucher-wrapper">

    <!-- ① HEADER: Title left | Company right -->
    <div class="v-section">
        <div class="row align-items-center">
            <div class="col-6">
                <div class="v-title">Petty Cash Voucher</div>
                <div class="voucher-number mt-1">#PCV-<?= str_pad($t['id'], 5, '0', STR_PAD_LEFT) ?></div>
                <div class="mt-2">
                    <span class="badge <?= $t['type'] === 'deposit' ? 'bg-success' : 'bg-danger' ?> text-uppercase px-3">
                        <?= htmlspecialchars($t['type']) ?>
                    </span>
                </div>
            </div>
            <div class="col-6 text-end">
                <?php if ($company['logo']): ?>
                    <img src="<?= getUrl($company['logo']) ?>" alt="Logo" style="max-height:55px; margin-bottom:6px;">
                <?php endif; ?>
                <div class="company-name"><?= htmlspecialchars($company['name']) ?></div>
                <div class="company-detail">
                    <?php if ($company['address'])        echo htmlspecialchars($company['address'])                   . '<br>'; ?>
                    <?php if ($company['postal_address']) echo 'P.O. Box ' . htmlspecialchars($company['postal_address']) . '<br>'; ?>
                    <?php if ($company['phone'])          echo htmlspecialchars($company['phone'])                     . '<br>'; ?>
                    <?php
                        $we = [];
                        if ($company['website']) $we[] = 'Web: ' . $company['website'];
                        if ($company['email'])   $we[] = 'Email: ' . $company['email'];
                        if ($we) echo implode(' | ', $we) . '<br>';
                        $tv = [];
                        if ($company['tin']) $tv[] = 'TIN: ' . $company['tin'];
                        if ($company['vrn']) $tv[] = 'VRN: ' . $company['vrn'];
                        if ($tv) echo implode(' | ', $tv);
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ② TRANSACTION META -->
    <div class="v-section">
        <div class="row g-3">
            <div class="col-4">
                <div class="v-label">Transaction Date</div>
                <div class="v-value"><?= date('d F Y', strtotime($t['transaction_date'])) ?></div>
            </div>
            <div class="col-4">
                <div class="v-label">Department</div>
                <div class="v-value"><?= htmlspecialchars($t['department'] ?: '—') ?></div>
            </div>
            <div class="col-4">
                <div class="v-label">Payment Mode</div>
                <div class="v-value">
                    <?php
                        $mode = strtolower($t['payment_mode'] ?? 'cash');
                        echo $mode === 'cheque'
                            ? 'Cheque &mdash; <span style="color:#495057;">' . htmlspecialchars($t['cheque_number'] ?: 'N/A') . '</span>'
                            : 'Cash';
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ③ RECEIVED BY -->
    <div class="v-section" style="background:#fafafa;">
        <div class="v-label">Paid To / Received By</div>
        <div style="font-size:1.05rem; font-weight:700; color:#212529; margin-top:4px;">
            <?= htmlspecialchars($t['received_by'] ?: '—') ?>
        </div>
    </div>

    <!-- ④ CATEGORY / REFERENCE / RECEIPT -->
    <div class="v-section">
        <div class="row g-3">
            <div class="col-4">
                <div class="v-label">Category / Account</div>
                <div class="v-value"><?= htmlspecialchars($t['category_name'] ?: 'General') ?></div>
            </div>
            <div class="col-4">
                <div class="v-label">Reference No.</div>
                <div class="v-value"><?= htmlspecialchars($t['reference_number'] ?: '—') ?></div>
            </div>
            <div class="col-4">
                <div class="v-label">Receipt / Invoice No.</div>
                <div class="v-value"><?= htmlspecialchars($t['receipt_number'] ?: '—') ?></div>
            </div>
        </div>
    </div>

    <!-- ⑤ DESCRIPTION -->
    <div class="v-section">
        <div class="v-label">Description / Purpose</div>
        <div class="v-value mt-1" style="font-weight:400; line-height:1.6; min-height:36px;">
            <?= nl2br(htmlspecialchars($t['description'] ?: '—')) ?>
        </div>
    </div>

    <!-- ⑥ AMOUNT -->
    <div class="amount-section">
        <div class="v-label" style="color:#495057;">Total Amount</div>
        <div class="amount-value">TSh <?= number_format($t['amount'], 2) ?></div>
        <div class="amount-words">(<?= htmlspecialchars($amount_words) ?>)</div>
    </div>

    <!-- ⑦ SUPPORTING DOCUMENTS -->
    <div class="v-section">
        <div class="v-label mb-2">Supporting Documents</div>
        <div class="d-flex align-items-center flex-wrap gap-2">
            <?php
                $rtype = strtolower($t['receipt_type'] ?? '');
                $types = ['receipt' => 'Receipt', 'invoice' => 'Invoice', 'other' => 'Other'];
                foreach ($types as $key => $label):
                    $checked = ($rtype === $key);
            ?>
            <span class="doc-checkbox">
                <span class="chk-box <?= $checked ? 'chk-checked' : '' ?>"><?= $checked ? '✓' : '' ?></span>
                <?= $label ?>
            </span>
            <?php endforeach; ?>

            <?php if ($t['receipt_number']): ?>
                <span class="ms-3 text-muted" style="font-size:0.82rem;">
                    <strong>No.:</strong> <?= htmlspecialchars($t['receipt_number']) ?>
                </span>
            <?php endif; ?>

            <?php if ($t['receipt_file']): ?>
                <span class="ms-3" style="font-size:0.82rem;">
                    <i class="bi bi-paperclip text-warning"></i>
                    <strong>Attachment:</strong>
                    <a href="<?= getUrl('api/petty_cash/get_attachment.php') ?>?id=<?= $t['id'] ?>" target="_blank" class="d-print-none">
                        View File
                    </a>
                    <span class="d-none d-print-inline">File attached</span>
                </span>
            <?php else: ?>
                <span class="ms-3 text-muted" style="font-size:0.82rem;">
                    <i class="bi bi-x-circle"></i> No attachment
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ⑧ SIGNATURES -->
    <div class="v-section">
        <div class="row">
            <div class="col-4">
                <div class="sig-block">
                    <div class="v-label text-center mb-1">Prepared By</div>
                    <div class="sig-line"></div>
                    <div class="sig-name"><?= htmlspecialchars($prepared_by) ?></div>
                    <div class="sig-date">Date: ___________________</div>
                </div>
            </div>
            <div class="col-4">
                <div class="sig-block">
                    <div class="v-label text-center mb-1">Verified By</div>
                    <div class="sig-line"></div>
                    <div class="sig-name">______________________</div>
                    <div class="sig-date">Date: ___________________</div>
                </div>
            </div>
            <div class="col-4">
                <div class="sig-block">
                    <div class="v-label text-center mb-1">Approved By</div>
                    <div class="sig-line"></div>
                    <div class="sig-name">______________________</div>
                    <div class="sig-date">Date: ___________________</div>
                </div>
            </div>
        </div>
        <!-- Company Stamp -->
        <div class="row mt-3">
            <div class="col-8"></div>
            <div class="col-4">
                <div class="stamp-box">
                    <span>OFFICIAL<br>COMPANY STAMP</span>
                </div>
            </div>
        </div>
    </div>


</div><!-- end voucher-wrapper -->

<?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
