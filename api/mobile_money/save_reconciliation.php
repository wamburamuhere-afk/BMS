<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/code_generator.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('mm_reconciliation')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

csrf_check();

$tillId    = intval($_POST['till_id'] ?? 0);
$reconDate = trim($_POST['recon_date'] ?? '');

if (!$tillId || !$reconDate) {
    echo json_encode(['success' => false, 'message' => 'Till and date are required.']); exit;
}

// Validate till
$till = $pdo->prepare("SELECT * FROM mm_tills WHERE till_id=? AND status='active'");
$till->execute([$tillId]);
$till = $till->fetch(PDO::FETCH_ASSOC);
if (!$till) { echo json_encode(['success' => false, 'message' => 'Invalid or inactive till.']); exit; }

// Check for duplicate recon on same till+date
$existing = $pdo->prepare("SELECT recon_id FROM mm_reconciliations WHERE till_id=? AND recon_date=? LIMIT 1");
$existing->execute([$tillId, $reconDate]);
if ($existing->fetch()) {
    echo json_encode(['success' => false, 'message' => 'A reconciliation already exists for this till and date.']); exit;
}

// Opening balances: last closed shift before or on recon_date, or latest snapshot
$lastShift = $pdo->prepare("
    SELECT closing_cash, closing_float
    FROM mm_shifts
    WHERE till_id=? AND status IN ('closed','forced_close') AND DATE(closed_at) <= ?
    ORDER BY closed_at DESC LIMIT 1
");
$lastShift->execute([$tillId, $reconDate]);
$lastShift = $lastShift->fetch(PDO::FETCH_ASSOC);

if ($lastShift) {
    $openCash  = (float)$lastShift['closing_cash'];
    $openFloat = (float)$lastShift['closing_float'];
} else {
    // Fall back to float snapshot
    $snap = $pdo->prepare("SELECT cash_balance, float_balance FROM mm_float_snapshots WHERE till_id=? AND snapshot_at <= ? ORDER BY snapshot_at DESC LIMIT 1");
    $snap->execute([$tillId, $reconDate . ' 23:59:59']);
    $snap = $snap->fetch(PDO::FETCH_ASSOC);
    $openCash  = $snap ? (float)$snap['cash_balance'] : 0;
    $openFloat = $snap ? (float)$snap['float_balance'] : 0;
}

// Computed: opening + sum of effects from posted transactions on the day
$effects = $pdo->prepare("
    SELECT COALESCE(SUM(cash_effect),0) AS tot_cash, COALESCE(SUM(float_effect),0) AS tot_float
    FROM mm_transactions
    WHERE till_id=? AND txn_date=? AND status='posted'
");
$effects->execute([$tillId, $reconDate]);
$effects = $effects->fetch(PDO::FETCH_ASSOC);

// Add float movements for the day
$fmEffects = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN movement_type='float_topup' THEN amount ELSE -amount END),0) AS float_adj
    FROM mm_float_movements
    WHERE till_id=? AND movement_date=? AND status='posted'
      AND movement_type IN ('float_topup','float_withdrawal')
");
$fmEffects->execute([$tillId, $reconDate]);
$fmEffects = $fmEffects->fetch(PDO::FETCH_ASSOC);

$computedCash  = $openCash  + (float)$effects['tot_cash'];
$computedFloat = $openFloat + (float)$effects['tot_float'] + (float)$fmEffects['float_adj'];

try {
    $reconCode = nextCode($pdo, 'MM-REC');

    $pdo->prepare("
        INSERT INTO mm_reconciliations
            (recon_code, till_id, recon_date, opening_cash, opening_float, computed_cash, computed_float, status, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,'open',?,NOW())
    ")->execute([$reconCode, $tillId, $reconDate, $openCash, $openFloat, $computedCash, $computedFloat, $_SESSION['user_id']]);
    $reconId = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Started MM reconciliation $reconCode for till $tillId on $reconDate");
    echo json_encode(['success' => true, 'message' => "Reconciliation $reconCode started.", 'recon_id' => $reconId]);

} catch (Exception $e) {
    error_log("save_reconciliation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
