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

    // Register (till) selection — Phase 8 (pos_upgrade_plan.md §7). Falls back to
    // register #1 ("Main Counter", the schema's seed row) when the terminal is
    // still on the pre-register UI, so this never breaks an un-migrated client.
    $register_id = isset($_POST['register_id']) ? (int)$_POST['register_id'] : 1;
    $reg = $pdo->prepare("SELECT register_id, register_name FROM pos_registers WHERE register_id = ? AND status = 'active'");
    $reg->execute([$register_id]);
    $register = $reg->fetch(PDO::FETCH_ASSOC);
    if (!$register) {
        echo json_encode(['success' => false, 'message' => t('Selected register is not available. Please choose an active register.')]);
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

    // Create new shift
    $stmt = $pdo->prepare("
        INSERT INTO cash_register_shifts
        (shift_code, user_id, register_id, start_time, starting_cash, status, created_at)
        VALUES (?, ?, ?, NOW(), ?, 'active', NOW())
    ");

    $stmt->execute([$shift_code, $user_id, $register_id, $opening_cash]);
    $shift_id = $pdo->lastInsertId();
    
    // Store shift ID in session
    $_SESSION['shift_id'] = $shift_id;
    
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
