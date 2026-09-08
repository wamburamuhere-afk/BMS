<?php
// scope-audit: skip — price groups are a small global lookup table (no project/warehouse scope), same as tax_rates/pos_registers
/**
 * API: Activate/Deactivate a POS Price Group
 * A price group is never hard-deleted (sales history and product overrides
 * reference it) — deactivating just removes it from the POS terminal's
 * price-group selector. The default "Retail" group can never be deactivated
 * — it's the implicit fallback every product already has.
 * POST: price_group_id, status ('active'|'inactive')
 * Permission: canEdit('pos_config_settings').
 * Entitlement: Phase 14 (pos_upgrade_plan.md §8) — gated behind 'pos_advanced'.
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
$status = $_POST['status'] ?? '';

if ($price_group_id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}

$stmt = $pdo->prepare("SELECT name, is_default FROM price_groups WHERE price_group_id = ?");
$stmt->execute([$price_group_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => t('Price group not found.')]);
    exit;
}

if ($status === 'inactive' && (int)$row['is_default'] === 1) {
    echo json_encode(['success' => false, 'message' => t('The default price group cannot be deactivated.')]);
    exit;
}

$pdo->prepare("UPDATE price_groups SET status = ?, updated_at = NOW() WHERE price_group_id = ?")
    ->execute([$status, $price_group_id]);

$verb = $status === 'active' ? 'Activated' : 'Deactivated';
logActivity($pdo, $_SESSION['user_id'], "$verb POS price group: {$row['name']}");

echo json_encode(['success' => true, 'message' => $status === 'active' ? t('Price group activated successfully.') : t('Price group deactivated successfully.')]);
