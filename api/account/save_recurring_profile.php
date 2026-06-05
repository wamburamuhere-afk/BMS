<?php
/**
 * api/account/save_recurring_profile.php
 * Create a recurring profile (v1: expense template + schedule). The template is
 * stored as JSON; the engine (core/recurring.php) reads it when the profile is due.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';   // cashBankAccounts()
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/recurring.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canCreate('expenses')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    $name        = trim($_POST['name'] ?? '');
    $doc_type    = 'expense';   // v1
    $frequency   = $_POST['frequency'] ?? 'monthly';
    $interval    = max(1, (int)($_POST['interval_count'] ?? 1));
    $start_date  = $_POST['start_date'] ?? date('Y-m-d');
    $end_date    = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $occurrences = ($_POST['occurrences_left'] ?? '') !== '' ? max(1, (int)$_POST['occurrences_left']) : null;
    $project_id  = (isset($_POST['project_id']) && $_POST['project_id'] !== '') ? (int)$_POST['project_id'] : null;

    $amount      = round((float)($_POST['amount'] ?? 0), 2);
    $exp_acc     = (int)($_POST['expense_account_id'] ?? 0) ?: null;
    $bank_acc    = (int)($_POST['bank_account_id'] ?? 0) ?: null;
    $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
    $type_id     = (int)($_POST['type_id'] ?? 0) ?: null;
    $description = trim($_POST['description'] ?? '');
    $method      = $_POST['payment_method'] ?? null;

    if ($name === '') throw new Exception('A profile name is required.');
    if (!in_array($frequency, ['weekly','monthly','quarterly','yearly'], true)) throw new Exception('Invalid frequency.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) throw new Exception('A valid start date is required.');
    if ($end_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) throw new Exception('Invalid end date.');
    if ($amount <= 0) throw new Exception('Template amount must be greater than zero.');
    if ($exp_acc === null) throw new Exception('Select the expense account.');

    if ($project_id !== null && !userCan('project', $project_id)) throw new Exception('The selected project is not in your scope.');

    $template = json_encode([
        'amount'             => $amount,
        'expense_account_id' => $exp_acc,
        'bank_account_id'    => $bank_acc,
        'category_id'        => $category_id,
        'type_id'            => $type_id,
        'description'        => $description !== '' ? $description : $name,
        'payment_method'     => $method,
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO recurring_profiles
            (name, doc_type, template_json, frequency, interval_count, start_date, next_run_date, end_date, occurrences_left, status, project_id, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$name, $doc_type, $template, $frequency, $interval, $start_date, $start_date, $end_date, $occurrences, $project_id, $_SESSION['user_id']]);
    $id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Created recurring profile", "$name ($frequency, every $interval) — ID $id");
    echo json_encode(['success' => true, 'message' => "Recurring profile \"$name\" created.", 'id' => $id]);

} catch (Exception $e) {
    error_log('save_recurring_profile error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
