<?php
require_once __DIR__ . '/../../../roots.php';

// Check if user is logged in
if (!isAuthenticated()) {
    header("Location: " . getUrl('login'));
    exit;
}

// Phase 5d — print pages get a canView gate (admin auto-bypass)
if (!canView('stock_adjustments')) die("Access Denied");

if (!isset($_GET['id'])) {
    die("Adjustment ID is required");
}

$adjustment_id = intval($_GET['id']);

try {
    $stmt = $pdo->prepare("
        SELECT 
            sm.*,
            p.product_name,
            p.sku,
            p.barcode,
            u.username as adjusted_by_name,
            w.warehouse_name,
            loc.location_name
        FROM stock_movements sm
        LEFT JOIN products p ON sm.product_id = p.product_id
        LEFT JOIN users u ON sm.created_by = u.user_id
        LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
        LEFT JOIN locations loc ON sm.location_id = loc.location_id
        WHERE sm.movement_id = ?
    ");
    $stmt->execute([$adjustment_id]);
    $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$adjustment) {
        die("Adjustment not found");
    }

    // Fetch Company Settings
    $company = [
        'name' => getSetting('company_name', 'BUSINESS MANAGEMENT SYSTEM'),
        'email' => getSetting('company_email', ''),
        'phone' => getSetting('company_phone', '+255 123 456 789'),
        'address' => getSetting('company_physical_address', getSetting('company_address', 'Dar es Salaam, Tanzania')),
        'postal_address' => getSetting('company_postal_address', ''),
        'website' => getSetting('company_website', ''),
        'tin' => getSetting('company_tin', ''),
        'vrn' => getSetting('company_vrn', ''),
        'logo' => getSetting('company_logo', '')
    ];

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Adjustment #<?= $adjustment['movement_id'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; padding: 20px 20px 0 20px; color: #1a252f; }
        .details table { width: 100%; border-collapse: collapse; margin: 30px 0; }
        .details table th, .details table td { border: 1px solid #e2e8f0; padding: 12px; text-align: left; }
        .details table th { background-color: #f8fafc; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; color: #64748b; }
        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none; }
            body { margin: 0 !important; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="row header mb-5 pb-3 border-bottom border-primary">
        <div class="col-6 text-start">
            <?php if ($company['logo']): ?>
                <img src="<?= htmlspecialchars('../../../' . $company['logo']) ?>" alt="Logo" style="max-height: 80px; width: auto;" class="mb-3">
            <?php endif; ?>
            <h2 class="fw-bold mb-1" style="color: #0d6efd; text-transform: uppercase;"><?= $company['name'] ?></h2>
            <div class="small text-muted">
                <?php if(!empty($company['postal_address'])): ?>
                    <span>P.O. Box <?= htmlspecialchars($company['postal_address']) ?></span><br>
                <?php endif; ?>
                <?php if(!empty($company['address'])): ?>
                    <span><?= htmlspecialchars($company['address']) ?></span><br>
                <?php endif; ?>
                <span>Phone: <?= htmlspecialchars($company['phone']) ?></span><br>
                <?php 
                $web_email = [];
                if (!empty($company['website'])) $web_email[] = "Web: " . htmlspecialchars($company['website']);
                if (!empty($company['email'])) $web_email[] = "Email: " . htmlspecialchars($company['email']);
                if (!empty($web_email)) echo implode(" | ", $web_email) . "<br>";
                
                $tin_vrn = [];
                if (!empty($company['tin'])) $tin_vrn[] = "TIN: " . htmlspecialchars($company['tin']);
                if (!empty($company['vrn'])) $tin_vrn[] = "VRN: " . htmlspecialchars($company['vrn']);
                if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
                ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <h1 class="fw-bold text-uppercase" style="color: #64748b;">Stock Adjustment</h1>
            <p class="mb-1 text-muted"><strong>Ref:</strong> #<?= $adjustment['movement_id'] ?></p>
            <p class="mb-1 text-muted"><strong>Date:</strong> <?= date('d M Y, h:i A', strtotime($adjustment['created_at'])) ?></p>
        </div>
    </div>

    <div class="details">
        <table>
            <tr>
                <th>Product</th>
                <td><?= htmlspecialchars($adjustment['product_name']) ?> (<?= htmlspecialchars($adjustment['sku']) ?>)</td>
            </tr>
            <tr>
                <th>Barcode</th>
                <td><?= htmlspecialchars($adjustment['barcode']) ?></td>
            </tr>
            <tr>
                <th>Warehouse</th>
                <td><?= htmlspecialchars($adjustment['warehouse_name']) ?></td>
            </tr>
            <?php if ($adjustment['location_name']): ?>
            <tr>
                <th>Location</th>
                <td><?= htmlspecialchars($adjustment['location_name']) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Type</th>
                <td><?= ucwords(str_replace('_', ' ', $adjustment['movement_type'])) ?></td>
            </tr>
            <tr>
                <th>Quantity</th>
                <td>
                    <?= $adjustment['quantity'] > 0 ? '+' : '' ?><?= $adjustment['quantity'] ?>
                </td>
            </tr>
            <tr>
                <th>Reason</th>
                <td><?= htmlspecialchars($adjustment['reason']) ?></td>
            </tr>
            <tr>
                <th>Adjusted By</th>
                <td><?= htmlspecialchars($adjustment['adjusted_by_name']) ?></td>
            </tr>
            <?php if ($adjustment['notes']): ?>
            <tr>
                <th>Notes</th>
                <td><?= nl2br(htmlspecialchars($adjustment['notes'])) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <button onclick="window.print()" class="no-print" style="margin-top: 20px; padding: 10px 20px; cursor: pointer;">Print</button>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</body>
</html>
