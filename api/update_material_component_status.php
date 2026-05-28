<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');

    if (!canEdit('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to change material component status');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS nip_material_component_status (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        component_product_id INT NOT NULL UNIQUE,
        status               ENUM('pending','approved') NOT NULL DEFAULT 'pending',
        updated_by           INT NULL DEFAULT NULL,
        updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (component_product_id) REFERENCES products(product_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $component_id = intval($_POST['component_product_id'] ?? 0);
    $status       = $_POST['status'] ?? '';

    if (!$component_id) throw new Exception('Invalid component.');
    if (!in_array($status, ['pending', 'approved'])) throw new Exception('Invalid status.');

    // Phase E — project-scope gate on the component product
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('products', 'product_id', $component_id);
    }

    $stmt = $pdo->prepare("
        INSERT INTO nip_material_component_status (component_product_id, status, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)
    ");
    $stmt->execute([$component_id, $status, $_SESSION['user_id']]);

    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], "Material component #$component_id status set to $status");

    echo json_encode(['success' => true, 'message' => 'Status updated.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
