<?php
/**
 * API: Open/Start Shift
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/pos_denominations.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit();
}

if (!canCreate('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access Denied: you do not have permission to open POS shifts')]);
    exit();
}

csrf_check();

try {
    global $pdo;

    $user_id = $_SESSION['user_id'];
    $opening_cash = isset($_POST['opening_cash']) ? floatval($_POST['opening_cash']) : 0;

    // Phase 20 (pos_upgrade_plan.md §8) — optional cash denomination
    // breakdown. Never required (backward compatible) — the single
    // opening_cash total above stays authoritative either way.
    $denominations = [];
    if (!empty($_POST['denominations'])) {
        $decoded = json_decode($_POST['denominations'], true);
        if (is_array($decoded)) {
            $denomCheck = validateDenominationBreakdown($decoded, $opening_cash);
            if (!$denomCheck['valid']) {
                echo json_encode(['success' => false, 'message' => $denomCheck['error']]);
                exit();
            }
            $denominations = $decoded;
        }
    }

    // Register (till) selection — Phase 8 (pos_upgrade_plan.md §7).
    // If no register_id is posted (or the posted one is not found / not active),
    // fall back to the first active register in the tenant's DB. If no registers
    // exist at all (common for fresh Simple POS tenants that never configured
    // tills), auto-create a "Main Register" so the cashier can start selling
    // immediately without needing to visit settings first.
    $register_id = isset($_POST['register_id']) ? (int)$_POST['register_id'] : 0;
    $register = null;
    if ($register_id > 0) {
        $reg = $pdo->prepare("SELECT register_id, register_name, warehouse_id FROM pos_registers WHERE register_id = ? AND status = 'active'");
        $reg->execute([$register_id]);
        $register = $reg->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$register) {
        // Try the first available active register
        $any = $pdo->query("SELECT register_id, register_name, warehouse_id FROM pos_registers WHERE status = 'active' ORDER BY register_id ASC LIMIT 1");
        $register = $any->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$register) {
        // No registers exist — auto-create a default one so Simple POS works out of the box
        $pdo->exec("INSERT INTO pos_registers (register_name, register_code, status, opening_cash, created_at)
                    VALUES ('Main Register', 'REG-001', 'active', 0, NOW())");
        $newId = (int)$pdo->lastInsertId();
        $register = ['register_id' => $newId, 'register_name' => 'Main Register', 'warehouse_id' => null];
    }
    $register_id = (int)$register['register_id'];

    // Shop scope (2026-09-16): a register tied to a shop can only be staffed
    // by a cashier actually granted that shop via Settings > Admin > Project &
    // Warehouse Access — get_registers.php already hides it from the Start
    // Shift dropdown, this is the server-side backstop against a hand-crafted
    // request. A register with no warehouse_id (legacy/shared till) stays open
    // to anyone with POS create permission, exactly as before this column
    // existed.
    $register_warehouse_id = $register['warehouse_id'] !== null ? (int)$register['warehouse_id'] : null;
    if ($register_warehouse_id !== null && !userCan('warehouse', $register_warehouse_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => wLabel('Access denied: this register\'s warehouse is not in your assigned scope.', 'Access denied: this register\'s shop is not in your assigned scope.', true)]);
        exit();
    }

    // Check if user already has an active shift
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $existing_shift = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing_shift) {
        echo json_encode([
            'success' => false,
            'message' => t('You already have an active shift. Please close it first.')
        ]);
        exit();
    }

    // A register can only be staffed by one active shift at a time.
    $regBusy = $pdo->prepare("SELECT COUNT(*) FROM cash_register_shifts WHERE register_id = ? AND status = 'active'");
    $regBusy->execute([$register_id]);
    if ($regBusy->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => sprintf(t('Register "%s" is already in an active shift with another cashier.'), $register['register_name'])]);
        exit();
    }

    // Generate shift code
    $shift_code = 'SHIFT-' . date('Ymd-His') . '-' . $user_id;

    // Create new shift — warehouse_id is stamped from the register's own shop
    // assignment now (not re-derived later via a join), same denormalisation
    // pattern pos_sales already uses for register_id/register_name.
    $stmt = $pdo->prepare("
        INSERT INTO cash_register_shifts
        (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status, created_at)
        VALUES (?, ?, ?, ?, NOW(), ?, 'active', NOW())
    ");

    $stmt->execute([$shift_code, $user_id, $register_id, $register_warehouse_id, $opening_cash]);
    $shift_id = $pdo->lastInsertId();

    // Store shift ID in session
    $_SESSION['shift_id'] = $shift_id;

    // Phase 20 — persist the validated breakdown, if one was submitted.
    if (!empty($denominations)) {
        saveDenominationBreakdown($pdo, (int)$shift_id, 'open', $denominations);
    }
    
    // Note: We DO NOT record an 'Opening cash' transaction in the transactions table
    // because the pos.php calculation already adds shift_active['starting_cash'].
    // Recording it as a 'cash_in' transaction causes the balance to double.
    
    require_once __DIR__ . '/../../helpers.php';
    $username = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $user_id, 'Open POS Shift', "$username opened a new POS shift on register \"{$register['register_name']}\" (Starting Cash: " . number_format($opening_cash, 2) . ")");

    echo json_encode([
        'success' => true,
        'message' => t('Shift started successfully'),
        'shift_id' => $shift_id,
        'shift_code' => $shift_code,
        'starting_cash' => $opening_cash,
        'register_id' => $register_id,
        'register_name' => $register['register_name']
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
