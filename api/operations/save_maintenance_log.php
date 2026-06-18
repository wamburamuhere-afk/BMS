<?php
// api/operations/save_maintenance_log.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/expense_posting.php';  // postAccrualEntry / reverseAccrualEntry / accrualEntryId
require_once __DIR__ . '/../../core/ledger_post.php';      // postLedgerEntry

global $pdo;

if (!isAuthenticated()) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

$log_id = $_POST['log_id'] ?? null;

if (!empty($log_id) ? !canEdit('maintenance') : !canCreate('maintenance')) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access Denied: you do not have permission to " . (!empty($log_id) ? 'edit' : 'create') . " maintenance logs"]);
    exit;
}

$asset_id           = $_POST['asset_id']           ?? null;
$maintenance_date   = $_POST['maintenance_date']   ?? null;
$maintenance_type   = $_POST['maintenance_type']   ?? 'routine';
$description        = $_POST['description']        ?? '';
$cost               = (float)($_POST['cost']       ?? 0);
$performed_by       = $_POST['performed_by']       ?? '';
$status             = $_POST['status']             ?? 'pending';
$completion_date    = $_POST['completion_date']    ?? null;
$notes              = $_POST['notes']              ?? '';
$expense_account_id = !empty($_POST['expense_account_id']) ? (int)$_POST['expense_account_id'] : null;

if (!$asset_id || !$maintenance_date || !$description) {
    echo json_encode(["success" => false, "message" => "Please fill required fields"]);
    exit;
}

// Require an expense account when completing a log that has a cost.
if ($status === 'completed' && $cost > 0 && !$expense_account_id) {
    echo json_encode(["success" => false, "message" => "Please select an Expense Account to post this maintenance cost to the GL."]);
    exit;
}

try {
    $userId = (int)($_SESSION['user_id'] ?? 0);

    // Read old state on edit path so we can detect transitions.
    $oldRow     = [];
    $oldEntryId = null;
    if ($log_id) {
        $old = $pdo->prepare("SELECT status, gl_journal_entry_id, expense_account_id, cost FROM maintenance_logs WHERE log_id = ?");
        $old->execute([$log_id]);
        $oldRow     = $old->fetch(PDO::FETCH_ASSOC) ?: [];
        $oldEntryId = $oldRow['gl_journal_entry_id'] ?? null;
    }

    // Step 1 — persist the record so we always have a real log_id before GL.
    if ($log_id) {
        $stmt = $pdo->prepare("UPDATE maintenance_logs SET
            asset_id = ?, maintenance_date = ?, maintenance_type = ?, description = ?,
            cost = ?, performed_by = ?, status = ?, completion_date = ?, notes = ?,
            expense_account_id = ?
            WHERE log_id = ?");
        $stmt->execute([
            $asset_id, $maintenance_date, $maintenance_type, $description,
            $cost, $performed_by, $status, $completion_date, $notes,
            $expense_account_id,
            $log_id,
        ]);
        $msg = "Log updated";
    } else {
        $stmt = $pdo->prepare("INSERT INTO maintenance_logs
            (asset_id, maintenance_date, maintenance_type, description, cost, performed_by,
             status, completion_date, notes, expense_account_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $asset_id, $maintenance_date, $maintenance_type, $description,
            $cost, $performed_by, $status, $completion_date, $notes,
            $expense_account_id,
        ]);
        $log_id = (int)$pdo->lastInsertId();
        $msg = "Log saved";

        // Auto-set asset to maintenance status if not completed.
        if ($status !== 'completed') {
            $pdo->prepare("UPDATE assets SET status = 'maintenance' WHERE asset_id = ?")->execute([$asset_id]);
        }
    }

    // Step 2 — GL (now we always have a real log_id).
    $glDate     = $completion_date ?: $maintenance_date ?: date('Y-m-d');
    $newEntryId = $oldEntryId;

    if ($status === 'completed' && $cost > 0 && $expense_account_id) {
        $existing = accrualEntryId($pdo, 'maintenance_log', (int)$log_id);

        // On edit: if cost or account changed, reverse old entry and re-post.
        $needRepost = $existing && !empty($oldRow) && (
            abs($cost - (float)($oldRow['cost'] ?? $cost)) > 0.001 ||
            (int)$expense_account_id !== (int)($oldRow['expense_account_id'] ?? 0)
        );
        if ($needRepost) {
            reverseAccrualEntry($pdo, 'maintenance_log', (int)$log_id, $userId);
            $existing = null;
        }

        if (!$existing) {
            $ref    = 'ML-' . $log_id;
            $result = postAccrualEntry(
                $pdo, 'maintenance_log', 'Maintenance', (int)$log_id,
                $expense_account_id, $cost,
                $glDate, null, $userId, $ref,
                "Asset #{$asset_id} — {$maintenance_type}: " . substr($description, 0, 80)
            );
            if (!empty($result['entry_id'])) {
                $newEntryId = (int)$result['entry_id'];
            }
        }
    } elseif ($status === 'cancelled') {
        // Reverse any posted accrual for this log.
        reverseAccrualEntry($pdo, 'maintenance_log', (int)$log_id, $userId);
        $newEntryId = null;
    }

    // Step 3 — stamp GL entry id back onto the row.
    $pdo->prepare("UPDATE maintenance_logs SET gl_journal_entry_id = ? WHERE log_id = ?")
        ->execute([$newEntryId, $log_id]);

    logActivity(
        $pdo, $userId,
        $msg === "Log updated" ? "Updated Maintenance Log" : "Created Maintenance Log",
        "Asset ID: $asset_id, type: $maintenance_type, cost: $cost, status: $status"
    );

    echo json_encode(["success" => true, "message" => $msg]);
} catch (Exception $e) {
    error_log("save_maintenance_log: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
