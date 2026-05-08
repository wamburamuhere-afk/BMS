<?php
// ajax_get_transfer_items.php
require_once __DIR__ . '/roots.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

$transfer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($transfer_id <= 0) {
    echo '<div class="alert alert-danger">Invalid Transfer ID</div>';
    exit;
}

try {
    $query = "
        SELECT ti.*, p.product_name, p.sku, p.unit
        FROM stock_transfer_items ti
        JOIN products p ON ti.product_id = p.product_id
        WHERE ti.transfer_id = ?
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$transfer_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        echo '<div class="alert alert-info">No items found for this transfer.</div>';
        exit;
    }
?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th class="text-center" width="50">S/NO</th>
                    <th>Product Details</th>
                    <th class="text-center">SKU</th>
                    <th class="text-center">Quantity</th>
                    <th class="text-center">Unit</th>
                </tr>
            </thead>
            <tbody>
                <?php $sn = 1; foreach ($items as $item): ?>
                <tr>
                    <td class="text-center text-muted small fw-bold"><?= $sn++ ?></td>
                    <td>
                        <div class="fw-bold text-primary"><?= htmlspecialchars($item['product_name']) ?></div>
                    </td>
                    <td class="text-center"><code class="text-dark"><?= htmlspecialchars($item['sku'] ?: 'N/A') ?></code></td>
                    <td class="text-center fw-bold"><?= number_format($item['quantity'], 2) ?></td>
                    <td class="text-center text-muted"><?= htmlspecialchars($item['unit'] ?: 'Pcs') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
}
