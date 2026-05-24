<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');
    $user_id = $_SESSION['user_id'];

    if (!canCreate('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to create material lists');
    }

    // Auto-create tables if they don't exist yet
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nip_material_lists (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(500) NOT NULL,
            project_id INT NULL DEFAULT NULL,
            created_by INT NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nip_material_list_nips (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            material_list_id INT NOT NULL,
            nip_product_id   INT NOT NULL,
            quantity         DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (material_list_id) REFERENCES nip_material_lists(id) ON DELETE CASCADE,
            FOREIGN KEY (nip_product_id)   REFERENCES products(product_id)   ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nip_material_component_status (
            id                   INT AUTO_INCREMENT PRIMARY KEY,
            component_product_id INT NOT NULL UNIQUE,
            status               ENUM('pending','approved') NOT NULL DEFAULT 'pending',
            updated_by           INT NULL DEFAULT NULL,
            updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (component_product_id) REFERENCES products(product_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Auto-migrate: add warehouse_id and list_no if not yet present
    try { $pdo->exec("ALTER TABLE nip_material_lists ADD COLUMN warehouse_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE nip_material_lists ADD COLUMN list_no VARCHAR(50) NULL DEFAULT NULL"); } catch (Exception $e) {}

    $name         = trim($_POST['name']           ?? '');
    $project_id   = !empty($_POST['project_id'])  ? intval($_POST['project_id'])   : null;
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : null;

    if (empty($name)) throw new Exception('Material List Name is required.');

    // Collect and validate NIP rows
    $nip_rows = [];
    if (isset($_POST['nips']) && is_array($_POST['nips'])) {
        foreach ($_POST['nips'] as $row) {
            $pid = intval($row['product_id'] ?? 0);
            $qty = floatval($row['quantity'] ?? 0);
            if (!$pid) continue;
            if ($qty <= 0) $qty = 1;
            $nip_rows[] = ['product_id' => $pid, 'quantity' => $qty];
        }
    }
    if (empty($nip_rows)) throw new Exception('Select at least one Non-Inventory Product.');

    $pdo->beginTransaction();

    // Generate list_no: ML-YYYYMMDD-NNNN (based on current max id)
    $max_id  = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM nip_material_lists")->fetchColumn();
    $list_no = 'ML-' . date('Ymd') . '-' . str_pad($max_id + 1, 4, '0', STR_PAD_LEFT);

    // Insert material list
    $stmt = $pdo->prepare("
        INSERT INTO nip_material_lists (name, project_id, warehouse_id, list_no, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$name, $project_id, $warehouse_id, $list_no, $user_id]);
    $list_id = $pdo->lastInsertId();

    // Insert NIP rows
    $nipStmt = $pdo->prepare("
        INSERT INTO nip_material_list_nips (material_list_id, nip_product_id, quantity)
        VALUES (?, ?, ?)
    ");
    foreach ($nip_rows as $row) {
        $nipStmt->execute([$list_id, $row['product_id'], $row['quantity']]);
    }

    $pdo->commit();

    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $user_id, "Created Material List: $name");

    echo json_encode(['success' => true, 'message' => 'Material list created successfully!', 'id' => $list_id, 'list_no' => $list_no]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
