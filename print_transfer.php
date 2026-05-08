<?php
// print_transfer.php
require_once __DIR__ . '/roots.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    die("Unauthorized or missing ID");
}

$transfer_id = intval($_GET['id']);

// Get Transfer Header
$query = "
    SELECT t.*, fw.warehouse_name as from_wh, tw.warehouse_name as to_wh, u.username as created_by_name
    FROM stock_transfers t
    JOIN warehouses fw ON t.from_warehouse_id = fw.warehouse_id
    JOIN warehouses tw ON t.to_warehouse_id = tw.warehouse_id
    JOIN users u ON t.created_by = u.user_id
    WHERE t.transfer_id = ?
";
$stmt = $pdo->prepare($query);
$stmt->execute([$transfer_id]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transfer) die("Transfer not found");

// Get Items
$query = "
    SELECT ti.*, p.product_name, p.sku, p.unit
    FROM stock_transfer_items ti
    JOIN products p ON ti.product_id = p.product_id
    WHERE ti.transfer_id = ?
";
$stmt = $pdo->prepare($query);
$stmt->execute([$transfer_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get System Settings for header
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_address', 'company_phone', 'company_email')");
while($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transfer Note - <?= $transfer['transfer_number'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-size: 14px; color: #333; }
        .print-header { border-bottom: 2px solid #333; margin-bottom: 20px; padding-bottom: 10px; }
        .company-name { font-size: 24px; font-weight: bold; text-transform: uppercase; }
        .transfer-label { font-size: 18px; font-weight: bold; color: #555; }
        .info-box { background: #f9f9f9; padding: 15px; border-radius: 5px; height: 100%; border: 1px solid #eee; }
        .table thead { background-color: #f2f2f2; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; margin: 0; }
            .container { width: 100%; max-width: 100%; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="container my-5">
        <div class="no-print mb-4">
            <button class="btn btn-primary" onclick="window.print()">Print</button>
            <button class="btn btn-secondary" onclick="window.close()">Close</button>
        </div>

        <div class="print-header d-flex justify-content-between align-items-center">
            <div>
                <div class="company-name"><?= htmlspecialchars($settings['company_name'] ?? 'BMS') ?></div>
                <div><?= nl2br(htmlspecialchars($settings['company_address'] ?? '')) ?></div>
                <div>Phone: <?= htmlspecialchars($settings['company_phone'] ?? '') ?></div>
            </div>
            <div class="text-end">
                <div class="transfer-label">STOCK TRANSFER NOTE</div>
                <div class="h5 mb-0">#<?= $transfer['transfer_number'] ?></div>
                <div class="text-muted">Date: <?= date('d M, Y', strtotime($transfer['transfer_date'])) ?></div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-6">
                <div class="info-box">
                    <small class="text-muted text-uppercase fw-bold">From Warehouse</small>
                    <div class="h5 mb-1"><?= htmlspecialchars($transfer['from_wh']) ?></div>
                </div>
            </div>
            <div class="col-6">
                <div class="info-box">
                    <small class="text-muted text-uppercase fw-bold">To Warehouse</small>
                    <div class="h5 mb-1"><?= htmlspecialchars($transfer['to_wh']) ?></div>
                </div>
            </div>
        </div>

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th width="60">#</th>
                    <th>Product Description / SKU</th>
                    <th class="text-center" width="120">Quantity</th>
                    <th class="text-center" width="100">Unit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $index => $item): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td>
                        <div class="fw-bold"><?= htmlspecialchars($item['product_name']) ?></div>
                        <small class="text-muted">SKU: <?= htmlspecialchars($item['sku'] ?: 'N/A') ?></small>
                    </td>
                    <td class="text-center fw-bold"><?= number_format($item['quantity'], 2) ?></td>
                    <td class="text-center"><?= htmlspecialchars($item['unit'] ?: 'Pcs') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!empty($transfer['notes'])): ?>
        <div class="mt-4">
            <div class="fw-bold border-bottom mb-2">Notes:</div>
            <div class="text-muted"><?= nl2br(htmlspecialchars($transfer['notes'])) ?></div>
        </div>
        <?php endif; ?>

        <div class="row mt-5 pt-5">
            <div class="col-4 text-center">
                <div class="border-top pt-2">Issued By</div>
                <div class="fw-bold pt-1 small"><?= htmlspecialchars($transfer['created_by_name']) ?></div>
            </div>
            <div class="col-4 text-center">
                <div class="border-top pt-2">Authorized By</div>
                <div class="pt-1 small">_________________</div>
            </div>
            <div class="col-4 text-center">
                <div class="border-top pt-2">Received By</div>
                <div class="pt-1 small">_________________</div>
            </div>
        </div>

        <div class="mt-5 text-center text-muted x-small">
            Generated on <?= date('Y-m-d H:i:s') ?>
        </div>
    </div>
</body>
</html>
