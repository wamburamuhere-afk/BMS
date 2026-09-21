<?php
/**
 * API: POS Shift History
 * ----------------------------------------------------------------------------
 * Returns a paginated list of closed shifts belonging to the current user,
 * newest first. The current active shift (if any) is excluded — the app
 * shows it via the open_shift response already.
 *
 * GET: page (default 1), per_page (default 20, max 50)
 * Permission: canView('pos')
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php'; mobileBearerAuth();
require_once __DIR__ . '/../../core/permissions.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

global $pdo;

$page     = max(1, (int)($_GET['page']     ?? 1));
$perPage  = min(50, max(5, (int)($_GET['per_page'] ?? 20)));
$offset   = ($page - 1) * $perPage;
$userId   = (int)($_SESSION['user_id'] ?? 0);

try {
    $count = $pdo->prepare("SELECT COUNT(*) FROM cash_register_shifts WHERE user_id = ? AND status = 'closed'");
    $count->execute([$userId]);
    $total = (int)$count->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT shift_id, shift_code, start_time, end_time,
               starting_cash, ending_cash, total_sales,
               total_cash_sales, total_card_sales, total_mobile_sales,
               total_credit_sales, total_refunds,
               cash_difference, notes
        FROM cash_register_shifts
        WHERE user_id = ? AND status = 'closed'
        ORDER BY shift_id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute([$userId]);
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast numbers to float
    $numFields = ['starting_cash','ending_cash','total_sales','total_cash_sales',
                  'total_card_sales','total_mobile_sales','total_credit_sales',
                  'total_refunds','cash_difference'];
    foreach ($shifts as &$s) {
        foreach ($numFields as $f) {
            $s[$f] = (float)($s[$f] ?? 0);
        }
        $s['shift_id'] = (int)$s['shift_id'];
    }
    unset($s);

    echo json_encode([
        'success'    => true,
        'data'       => $shifts,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'last_page'  => (int)ceil($total / $perPage),
    ]);
} catch (Throwable $e) {
    error_log('get_shift_history: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
