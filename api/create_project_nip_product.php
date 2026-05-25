<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized access');
    $user_id = $_SESSION['user_id'];

    if (!canCreate('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to create project NIP products');
    }

    $project_id = intval($_POST['project_id'] ?? 0);
    if (!$project_id) throw new Exception('Missing project_id');

    // Phase D — project-scope gate
    if (function_exists('userCan') && !userCan('project', $project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: project not in your scope.');
    }

    // Auto-add project_id column if it does not exist yet
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('project_id', $cols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN project_id INT NULL DEFAULT NULL");
    }

    $pdo->beginTransaction();

    $selling_price = floatval($_POST['selling_price'] ?? 0);
    $discount_rate = !empty($_POST['discount_rate']) ? floatval($_POST['discount_rate']) : 0.00;
    $calculated_min_price = $selling_price - ($selling_price * $discount_rate / 100);

    $product_data = [
        'product_name'      => trim($_POST['product_name'] ?? ''),
        'sku'               => !empty($_POST['sku'])          ? trim($_POST['sku'])                 : null,
        'description'       => !empty($_POST['description'])  ? trim($_POST['description'])          : null,
        'tax_id'            => !empty($_POST['tax_id'])        ? intval($_POST['tax_id'])             : null,
        'unit'              => $_POST['unit'] ?? 'job',
        'cost_price'        => floatval($_POST['cost_price'] ?? 0),
        'selling_price'     => $selling_price,
        'min_selling_price' => !empty($_POST['min_selling_price']) ? floatval($_POST['min_selling_price']) : $calculated_min_price,
        'status'            => 'active',
        'is_service'        => 1,
        'track_inventory'   => 0,
        'warehouse_id'      => null,
        'project_id'        => $project_id,
        'contract_item_no'  => !empty($_POST['contract_item_no']) ? trim($_POST['contract_item_no']) : null,
        'assembly_quantity' => !empty($_POST['assembly_quantity']) ? floatval($_POST['assembly_quantity']) : 1.00,
        'created_by'        => $user_id,
    ];

    if (empty($product_data['product_name'])) throw new Exception('Product name is required');

    if ($product_data['sku']) {
        $chk = $pdo->prepare("SELECT product_id FROM products WHERE sku = ?");
        $chk->execute([$product_data['sku']]);
        if ($chk->fetch()) throw new Exception('SKU already exists: ' . $product_data['sku']);
    }

    $columns      = implode(', ', array_keys($product_data));
    $placeholders = ':' . implode(', :', array_keys($product_data));
    $stmt = $pdo->prepare("INSERT INTO products ($columns) VALUES ($placeholders)");
    $stmt->execute($product_data);
    $product_id = $pdo->lastInsertId();

    if (isset($_POST['components']) && is_array($_POST['components'])) {
        $compStmt = $pdo->prepare("
            INSERT INTO product_assembly_components
                (parent_product_id, component_product_id, unit, qty_per_unit, total_qty)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($_POST['components'] as $comp) {
            if (empty($comp['product_id'])) continue;
            $compStmt->execute([
                $product_id,
                $comp['product_id'],
                $comp['unit'] ?? 'EA',
                floatval($comp['qty_per_unit'] ?? 1),
                floatval($comp['total_qty'] ?? 1),
            ]);
        }
    }

    $pdo->commit();

    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $user_id, "Created Project NIP Product: " . $product_data['product_name']);

    echo json_encode(['success' => true, 'message' => 'Non-Inventory product created successfully!', 'product_id' => $product_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
