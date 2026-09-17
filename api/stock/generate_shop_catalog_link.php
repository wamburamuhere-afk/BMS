<?php
/**
 * API: generate (or regenerate) a warehouse's public shop-catalog link.
 * ----------------------------------------------------------------------------
 * Same security model as api/document/request_external_signature.php: a
 * fresh, cryptographically random token is generated, only its SHA-256
 * hash is stored (warehouses.public_catalog_token_hash), and the RAW token
 * is returned in THIS response only — never persisted anywhere in a
 * recoverable form, never logged. Regenerating overwrites the previous
 * hash, so the old link stops working immediately (this table holds one
 * hash per warehouse, not a history).
 *
 * POST: warehouse_id
 * Permission: canEdit('warehouses') + userCan('warehouse', $warehouse_id)
 * Simple POS only (this whole feature is).
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_nav.php';

header('Content-Type: application/json');

if (!isAuthenticated())      { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canEdit('warehouses'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if (!posSimpleModeEnabled()) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('This view is only available in Simple POS mode.')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

$warehouseId = (int)($_POST['warehouse_id'] ?? 0);
if ($warehouseId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('A valid warehouse is required.')]);
    exit;
}
if (!userCan('warehouse', $warehouseId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}

try {
    global $pdo;

    $check = $pdo->prepare("SELECT warehouse_id, warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
    $check->execute([$warehouseId]);
    $wh = $check->fetch(PDO::FETCH_ASSOC);
    if (!$wh) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('Warehouse not found.')]);
        exit;
    }

    // 24 random bytes = 192 bits of entropy, 48 hex characters — not
    // practically guessable, and short enough to paste into a chat message.
    $rawToken  = bin2hex(random_bytes(24));
    $tokenHash = hash('sha256', $rawToken);

    $pdo->prepare("
        UPDATE warehouses
           SET public_catalog_token_hash = ?, public_catalog_token_created_at = NOW()
         WHERE warehouse_id = ?
    ")->execute([$tokenHash, $warehouseId]);

    logActivity($pdo, $_SESSION['user_id'], "Generated public shop-catalog link for warehouse #$warehouseId ({$wh['warehouse_name']})");
    logAudit($pdo, $_SESSION['user_id'], 'warehouse_public_catalog_link_generated', [
        'entity_type' => 'warehouse',
        'entity_id'   => $warehouseId,
        // Never the raw token or its hash — an audit trail that could
        // itself leak the credential defeats the point of hashing it.
        'new_values'  => ['action' => 'generated'],
    ]);

    echo json_encode([
        'success' => true,
        'message' => t('Link generated. Copy it now — for your security, it cannot be shown again.'),
        'token'   => $rawToken,
        'url'     => buildUrl('shop-catalog') . '?token=' . urlencode($rawToken),
    ]);
} catch (Throwable $e) {
    error_log('generate_shop_catalog_link: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Server error.')]);
}
