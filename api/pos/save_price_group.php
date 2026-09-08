<?php
// scope-audit: skip — price groups are a small global lookup table (no project/warehouse scope), same as tax_rates/pos_registers
/**
 * API: Create/Update a POS Price Group
 * POST: price_group_id (blank = create), name
 * Permission: canEdit('pos_config_settings').
 * Entitlement: Phase 14 (pos_upgrade_plan.md §8) — gated behind 'pos_advanced'.
 * The seeded "Retail" group's name is protected (it's is_default — every
 * product without a group override falls back to it implicitly via
 * products.selling_price, so renaming it would be confusing, not dangerous,
 * but there is no reason to allow it).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated())              { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos_advanced'))        { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Price groups are not included in your plan.')]); exit; }
if (!canEdit('pos_config_settings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$price_group_id = (int)($_POST['price_group_id'] ?? 0);
$name = trim($_POST['name'] ?? '');

if ($name === '') {
    echo json_encode(['success' => false, 'message' => t('Name required')]);
    exit;
}

try {
    if ($price_group_id > 0) {
        $stmt = $pdo->prepare("SELECT is_default FROM price_groups WHERE price_group_id = ?");
        $stmt->execute([$price_group_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => t('Price group not found.')]);
            exit;
        }
        if ((int)$row['is_default'] === 1 && $name !== 'Retail') {
            echo json_encode(['success' => false, 'message' => t('The default price group cannot be renamed.')]);
            exit;
        }
    }

    $dupSql = "SELECT COUNT(*) FROM price_groups WHERE name = ?" . ($price_group_id > 0 ? " AND price_group_id != ?" : "");
    $dupStmt = $pdo->prepare($dupSql);
    $dupStmt->execute($price_group_id > 0 ? [$name, $price_group_id] : [$name]);
    if ($dupStmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => t('A price group with this name already exists. Please use a different name.')]);
        exit;
    }

    if ($price_group_id > 0) {
        $pdo->prepare("UPDATE price_groups SET name = ?, updated_at = NOW() WHERE price_group_id = ?")
            ->execute([$name, $price_group_id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated POS price group: $name");
        $message = t('Price group updated successfully.');
    } else {
        $pdo->prepare("INSERT INTO price_groups (name, is_default, status, created_by, created_at, updated_at) VALUES (?, 0, 'active', ?, NOW(), NOW())")
            ->execute([$name, $_SESSION['user_id']]);
        $price_group_id = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created POS price group: $name");
        $message = t('Price group created successfully.');
    }

    echo json_encode(['success' => true, 'message' => $message, 'price_group_id' => $price_group_id]);

} catch (PDOException $e) {
    error_log('save_price_group: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
