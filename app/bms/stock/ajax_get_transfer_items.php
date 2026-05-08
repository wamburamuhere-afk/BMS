<?php
require_once __DIR__ . '/../../../roots.php';
require_once HEADER_FILE;

if (!isset($_GET['id'])) {
    echo '<div class="alert alert-danger">Invalid Transfer ID</div>';
    exit();
}

$transfer_id = intval($_GET['id']);

$query = "
    SELECT sti.*, p.product_name, p.sku, p.unit
    FROM stock_transfer_items sti
    JOIN products p ON sti.product_id = p.product_id
    WHERE sti.transfer_id = ?
";
$stmt = $pdo->prepare($query);
$stmt->execute([$transfer_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) {
    echo '<div class="alert alert-info">No items found for this transfer.</div>';
    exit();
}
?>

<div class="table-responsive">
    <table class="table table-bordered table-striped">
        <thead class="table-light">
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th class="text-center">Quantity</th>
                <th class="text-center">Unit</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['product_name']) ?></td>
                <td><?= htmlspecialchars($item['sku']) ?></td>
                <td class="text-center fw-bold"><?= format_number($item['quantity'], 3) ?></td>
                <td class="text-center"><?= htmlspecialchars($item['unit']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
