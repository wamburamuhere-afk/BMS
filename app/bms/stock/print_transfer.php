<?php
require_once __DIR__ . '/../../../roots.php';

// Check if transfer ID is provided
if (!isset($_GET['id'])) {
    die("Transfer ID required.");
}

$transfer_id = intval($_GET['id']);

// Fetch transfer details
$query = "
    SELECT t.*, fw.warehouse_name as from_warehouse, fw.warehouse_code as from_code,
           tw.warehouse_name as to_warehouse, tw.warehouse_code as to_code,
           u.username as created_by_name
    FROM stock_transfers t
    LEFT JOIN warehouses fw ON t.from_warehouse_id = fw.warehouse_id
    LEFT JOIN warehouses tw ON t.to_warehouse_id = tw.warehouse_id
    LEFT JOIN users u ON t.created_by = u.user_id
    WHERE t.transfer_id = ?
";
$stmt = $pdo->prepare($query);
$stmt->execute([$transfer_id]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transfer) {
    die("Transfer not found.");
}

// Fetch items
$query = "
    SELECT sti.*, p.product_name, p.sku, p.unit
    FROM stock_transfer_items sti
    LEFT JOIN products p ON sti.product_id = p.product_id
    WHERE sti.transfer_id = ?
";
$stmt = $pdo->prepare($query);
$stmt->execute([$transfer_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once HEADER_FILE;
?>
<div class="container mt-4 mb-4">
    <div class="row">
        <div class="col-12 text-center mb-4">
            <h2 class="fw-bold">STOCK TRANSFER NOTE</h2>
            <h4 class="text-muted">Transfer #: <?= htmlspecialchars($transfer['transfer_number']) ?></h4>
            <div class="d-print-none mt-3">
                <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button>
                <button onclick="window.close()" class="btn btn-secondary"><i class="bi bi-x"></i> Close</button>
            </div>
        </div>
    </div>

    <div class="row mb-4 border p-3 rounded">
        <div class="col-md-6 border-end">
            <h5 class="fw-bold text-uppercase border-bottom pb-2 mb-3">From (Source)</h5>
            <p class="mb-1 fw-bold fs-5"><?= htmlspecialchars($transfer['from_warehouse']) ?></p>
            <p class="text-muted mb-0">Code: <?= htmlspecialchars($transfer['from_code']) ?></p>
        </div>
        <div class="col-md-6 ps-4">
            <h5 class="fw-bold text-uppercase border-bottom pb-2 mb-3">To (Destination)</h5>
            <p class="mb-1 fw-bold fs-5"><?= htmlspecialchars($transfer['to_warehouse']) ?></p>
            <p class="text-muted mb-0">Code: <?= htmlspecialchars($transfer['to_code']) ?></p>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <strong>Transfer Date:</strong><br>
            <?= date('d M Y', strtotime($transfer['transfer_date'])) ?>
        </div>
        <div class="col-md-4">
            <strong>Created By:</strong><br>
            <?= htmlspecialchars($transfer['created_by_name']) ?>
        </div>
        <div class="col-md-4">
            <strong>Status:</strong><br>
            <span class="badge bg-success rounded-pill px-3"><?= ucfirst($transfer['status']) ?></span>
        </div>
    </div>

    <div class="table-responsive mb-4">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 5%">#</th>
                    <th style="width: 15%">SKU</th>
                    <th style="width: 40%">Product Name</th>
                    <th style="width: 15%" class="text-center">Unit</th>
                    <th style="width: 25%" class="text-end">Quantity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $index => $item): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($item['sku']) ?></td>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($item['unit']) ?></td>
                    <td class="text-end fw-bold fs-5"><?= format_number($item['quantity'], 3) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <td colspan="4" class="text-end fw-bold">Total Items:</td>
                    <td class="text-end fw-bold"><?= count($items) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if (!empty($transfer['notes'])): ?>
    <div class="card mb-4">
        <div class="card-header bg-light fw-bold">Notes</div>
        <div class="card-body">
            <?= nl2br(htmlspecialchars($transfer['notes'])) ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="row mt-5 pt-5">
        <div class="col-md-4 text-center">
            <div class="border-top border-dark pt-2 mx-5">
                Authorized Signature
            </div>
        </div>
        <div class="col-md-4 text-center">
            <div class="border-top border-dark pt-2 mx-5">
                Received By
            </div>
        </div>
        <div class="col-md-4 text-center">
            <div class="border-top border-dark pt-2 mx-5">
                Date
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    body { background: white !important; font-size: 12pt; }
    .d-print-none { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
    .card, .table, .row { break-inside: avoid; }
    .btn { display: none !important; }
    header, footer, nav { display: none !important; }
}
</style>

<?php includeFooter(); ?>
