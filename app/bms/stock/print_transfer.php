<?php
require_once __DIR__ . '/../../../roots.php';

if (!isAuthenticated()) {
    header("Location: " . getUrl('login'));
    exit;
}

// Phase 5d — print pages get a canView gate (admin auto-bypass)
if (!canView('stock_adjustments')) die("Access Denied");

if (!isset($_GET['id'])) {
    die("Transfer ID required.");
}

$transfer_id = intval($_GET['id']);

$stmt = $pdo->prepare("
    SELECT t.*, fw.warehouse_name as from_warehouse, fw.warehouse_code as from_code,
           tw.warehouse_name as to_warehouse, tw.warehouse_code as to_code,
           u.username as created_by_name
    FROM stock_transfers t
    LEFT JOIN warehouses fw ON t.from_warehouse_id = fw.warehouse_id
    LEFT JOIN warehouses tw ON t.to_warehouse_id = tw.warehouse_id
    LEFT JOIN users u ON t.created_by = u.user_id
    WHERE t.transfer_id = ?
");
$stmt->execute([$transfer_id]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transfer) {
    die("Transfer not found.");
}

$stmt = $pdo->prepare("
    SELECT sti.*, p.product_name, p.sku, p.unit
    FROM stock_transfer_items sti
    LEFT JOIN products p ON sti.product_id = p.product_id
    WHERE sti.transfer_id = ?
");
$stmt->execute([$transfer_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$comp = [
    'name'           => getSetting('company_name', 'BUSINESS MANAGEMENT SYSTEM'),
    'email'          => getSetting('company_email', ''),
    'phone'          => getSetting('company_phone', ''),
    'address'        => getSetting('company_physical_address', getSetting('company_address', '')),
    'postal_address' => getSetting('company_postal_address', ''),
    'website'        => getSetting('company_website', ''),
    'tin'            => getSetting('company_tin', ''),
    'vrn'            => getSetting('company_vrn', ''),
    'logo'           => getSetting('company_logo', ''),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Transfer #<?= htmlspecialchars($transfer['transfer_number']) ?></title>
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
        .company-addr-row { display: flex; align-items: flex-start; gap: 14px; }
        .company-addr-row img { max-height: 60px; width: auto; flex-shrink: 0; object-fit: contain; }
        .company-addr-info p { margin: 2px 0; color: #1a252f; font-size: 11px; font-weight: 500; }
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
        .details-grid { display: flex; justify-content: space-between; margin-bottom: 24px; gap: 14px; }
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
        tbody tr { border-bottom: 1px solid #e4e8ec; }
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
        tfoot td {
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
            background: #f4f6f8;
            color: #1a252f;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .text-right  { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold     { font-weight: 700; }
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
        .signature-box {
            margin-top: 46px;
            display: flex;
            justify-content: space-around;
            gap: 40px;
        }
        .signature-line {
            width: 210px;
            padding-top: 7px;
            text-align: center;
            border-top: 1.5px solid #1a252f;
            font-size: 11px;
            color: #1a252f;
            font-weight: 600;
        }
        .signature-line small {
            display: block;
            margin-top: 4px;
            font-size: 10px;
            font-weight: 400;
            color: #495057;
        }
        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
            .box, .notes-section > div { box-shadow: none; border: 1px solid #e0e0e0; }
        }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>

    <!-- HEADER -->
    <div class="header">
        <div class="company-info">
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="company-addr-row">
                <?php if (!empty($comp['logo'])): ?>
                <img src="<?= htmlspecialchars(getUrl($comp['logo'])) ?>" alt="Logo">
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
                    <?php if (!empty($comp['website'])): ?>
                    <p>Web: <?= htmlspecialchars($comp['website']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($comp['email'])): ?>
                    <p>Email: <?= htmlspecialchars($comp['email']) ?></p>
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
            <h2>Stock Transfer Note</h2>
            <p><strong>Transfer #:</strong> <?= htmlspecialchars($transfer['transfer_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($transfer['transfer_date'])) ?></p>
            <p><strong>Status:</strong> <?= ucfirst(htmlspecialchars($transfer['status'])) ?></p>
        </div>
    </div>

    <!-- FROM / TO WAREHOUSE DETAILS -->
    <div class="details-grid">
        <div class="box">
            <h3>From (Source Warehouse)</h3>
            <p><strong><?= htmlspecialchars($transfer['from_warehouse']) ?></strong></p>
            <p>Code: <?= htmlspecialchars($transfer['from_code']) ?></p>
        </div>
        <div class="box">
            <h3>To (Destination Warehouse)</h3>
            <p><strong><?= htmlspecialchars($transfer['to_warehouse']) ?></strong></p>
            <p>Code: <?= htmlspecialchars($transfer['to_code']) ?></p>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars($transfer['created_by_name']) ?></p>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">#</th>
                <th style="width:110px;">SKU</th>
                <th>Product Name</th>
                <th class="text-center" style="width:80px;">Unit</th>
                <th class="text-right" style="width:100px;">Quantity</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $index => $item): ?>
            <tr>
                <td class="text-center"><?= $index + 1 ?></td>
                <td><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
                <td><?= htmlspecialchars($item['product_name']) ?></td>
                <td class="text-center"><?= htmlspecialchars($item['unit'] ?? '—') ?></td>
                <td class="text-right fw-bold"><?= format_number($item['quantity'], 3) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="text-right">Total Items:</td>
                <td class="text-right"><?= count($items) ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- NOTES -->
    <?php if (!empty($transfer['notes'])): ?>
    <div class="notes-section">
        <div>
            <strong>Notes:</strong>
            <p><?= nl2br(htmlspecialchars($transfer['notes'])) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- SIGNATURES -->
    <div class="signature-box">
        <div class="signature-line">
            Authorized Signature<br>
            <small>&nbsp;</small>
        </div>
        <div class="signature-line">
            Received By<br>
            <small>&nbsp;</small>
        </div>
        <div class="signature-line">
            Date<br>
            <small>&nbsp;</small>
        </div>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</body>
</html>
