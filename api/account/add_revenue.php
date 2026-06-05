<?php
/**
 * api/account/add_revenue.php
 *
 * Create a standalone revenue / other-income record as 'pending'. NO money moves
 * at create — the cash receipt (Dr bank / Cr income) + bank-register deposit happen
 * only at the Posted step (update_revenue_status.php), mirroring the expense flow.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';   // cashBankAccounts()
require_once __DIR__ . '/../../core/project_scope.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canCreate('revenue')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to create revenue']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

try {
    $revenue_date = $_POST['revenue_date'] ?? '';
    $category_id  = (int)($_POST['category_id'] ?? 0) ?: null;
    $income_acc   = (int)($_POST['income_account_id'] ?? 0);
    $bank_acc     = (int)($_POST['bank_account_id'] ?? 0);
    $amount       = round((float)($_POST['amount'] ?? 0), 2);
    $payer        = trim($_POST['payer_name'] ?? '');
    $reference    = trim($_POST['reference_number'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $project_id   = (isset($_POST['project_id']) && $_POST['project_id'] !== '') ? (int)$_POST['project_id'] : null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $revenue_date)) throw new Exception('A valid revenue date is required.');
    if ($income_acc <= 0) throw new Exception('Select the income account to credit.');
    if ($bank_acc <= 0)   throw new Exception('Select the account the money was received into.');
    if ($amount <= 0)     throw new Exception('Amount must be greater than zero.');

    // Received-into must be a valid cash/bank account.
    $cash = [];
    foreach (cashBankAccounts($pdo) as $a) $cash[(int)$a['account_id']] = $a;
    if (!isset($cash[$bank_acc])) throw new Exception('The received-into account is not a valid cash/bank account.');

    // Income account must be an active income GL account.
    $okInc = $pdo->prepare("SELECT 1 FROM accounts WHERE account_id = ? AND status = 'active' AND account_type = 'income'");
    $okInc->execute([$income_acc]);
    if (!$okInc->fetchColumn()) throw new Exception('The selected income account is not a valid income account.');

    if ($category_id !== null) {
        $okCat = $pdo->prepare("SELECT 1 FROM revenue_categories WHERE id = ? AND status = 'active'");
        $okCat->execute([$category_id]);
        if (!$okCat->fetchColumn()) $category_id = null;   // ignore a stale category silently
    }

    if ($project_id !== null && !userCan('project', $project_id)) {
        throw new Exception('The selected project is not in your assigned scope.');
    }

    // REV-YYYY-NNNN.
    $year = date('Y', strtotime($revenue_date));
    $seq  = (int)$pdo->query("SELECT COUNT(*) FROM revenues WHERE YEAR(revenue_date) = " . (int)$year)->fetchColumn() + 1;
    $revenue_number = 'REV-' . $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO revenues
            (revenue_number, revenue_date, category_id, income_account_id, bank_account_id, amount,
             payer_name, reference_number, description, project_id, status, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
    ");
    $stmt->execute([
        $revenue_number, $revenue_date, $category_id, $income_acc, $bank_acc, $amount,
        ($payer !== '' ? $payer : null), ($reference !== '' ? $reference : null),
        ($description !== '' ? $description : null), $project_id, $_SESSION['user_id'],
    ]);
    $id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Created revenue $revenue_number (amount " . number_format($amount, 2) . ")");

    echo json_encode(['success' => true, 'message' => "Revenue $revenue_number created.", 'id' => $id, 'revenue_number' => $revenue_number]);

} catch (Exception $e) {
    error_log('add_revenue error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
