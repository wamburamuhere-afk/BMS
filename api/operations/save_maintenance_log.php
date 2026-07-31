<?php
// api/operations/save_maintenance_log.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/expense_posting.php';  // postAccrualEntry / reverseAccrualEntry / accrualEntryId
require_once __DIR__ . '/../../core/ledger_post.php';      // postLedgerEntry
require_once __DIR__ . '/../../core/payment_source.php';   // postOutflow / reverseOutflow / accruedExpensesAccountId
require_once __DIR__ . '/../../core/bank_register.php';    // recordBankTransaction / reverseBankTransaction

global $pdo;

// reverseMaintenanceLedger() lives in core/expense_posting.php (Maintenance
// Log wrappers) so delete_maintenance_log.php can share the exact same
// reversal — required above.

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

$asset_id            = $_POST['asset_id']            ?? null;
$maintenance_date    = $_POST['maintenance_date']    ?? null;
$maintenance_type    = $_POST['maintenance_type']    ?? 'routine';
$description         = $_POST['description']         ?? '';
$cost                = (float)($_POST['cost']        ?? 0);
$performed_by        = $_POST['performed_by']        ?? '';
$status               = $_POST['status']              ?? 'pending';
$completion_date      = $_POST['completion_date']     ?? null;
$next_due_date        = trim($_POST['next_due_date']  ?? '');
$notes                = $_POST['notes']               ?? '';
$expense_account_id   = !empty($_POST['expense_account_id'])   ? (int)$_POST['expense_account_id']   : null;
$paid_from_account_id = !empty($_POST['paid_from_account_id']) ? (int)$_POST['paid_from_account_id'] : null;

// All validation lives inside the try block (thrown as Exception) rather than
// early echo+exit — this file's own INSERT/UPDATE/GL logic already uses
// try/catch(Exception) below, and a hard exit() would terminate the whole
// PHP process if this file is ever include()'d more than once in one run
// (e.g. a CLI test harness exercising several transitions back to back);
// throwing keeps every path safely inside the one catch.
try {
    if (!$asset_id || !$maintenance_date || !$description) {
        throw new Exception('Please fill required fields');
    }
    if ($next_due_date !== '' && !DateTime::createFromFormat('Y-m-d', $next_due_date)) {
        throw new Exception('Next due date must be a valid date or blank');
    }
    // Require an expense account when completing (or paying) a log that has a cost.
    if (($status === 'completed' || $status === 'paid') && $cost > 0 && !$expense_account_id) {
        throw new Exception('Please select an Expense Account to post this maintenance cost to the GL.');
    }
    // Paying requires a Paid-From account — the money must leave a real cash/bank account.
    if ($status === 'paid' && $cost > 0 && !$paid_from_account_id) {
        throw new Exception('Please select the account this maintenance was Paid From.');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);

    // Read old state on edit path so we can detect transitions.
    $oldRow     = [];
    $oldEntryId = null;
    if ($log_id) {
        $old = $pdo->prepare("SELECT * FROM maintenance_logs WHERE log_id = ?");
        $old->execute([$log_id]);
        $oldRow     = $old->fetch(PDO::FETCH_ASSOC) ?: [];
        $oldEntryId = $oldRow['gl_journal_entry_id'] ?? null;

        if (($oldRow['status'] ?? '') === 'deleted') {
            throw new Exception('This maintenance log has been deleted and cannot be edited.');
        }
    }

    // A log already marked paid is settled. Editing non-financial fields
    // (description, notes, performed_by) on it is fine — but changing what
    // was actually paid (cost, expense account, or Paid-From account) must
    // not silently re-post; the user has to cancel (reverses both legs) and
    // re-enter instead. Re-submitting the SAME already-paid values (e.g. the
    // form just resubmitting unchanged) is a harmless no-op, not an error.
    if ($status === 'paid' && !empty($oldRow['transaction_id'])) {
        $moneyFieldsChanged =
            abs($cost - (float)($oldRow['cost'] ?? 0)) > 0.001 ||
            (int)$expense_account_id !== (int)($oldRow['expense_account_id'] ?? 0) ||
            (int)$paid_from_account_id !== (int)($oldRow['paid_from_account_id'] ?? 0);
        if ($moneyFieldsChanged) {
            throw new Exception('This maintenance log is already marked paid. Cancel it first if you need to correct the amount or account.');
        }
        // No money-affecting change — fall through to Step 1, but Step 2 must
        // skip re-posting (handled below: the 'paid' branch is only reachable
        // when transaction_id is NOT already set, via the accrual/settle
        // guards inside it — see the accrualEntryId()/txnId checks).
    }

    // Step 1 — persist the record so we always have a real log_id before GL.
    // (paid_from_account_id/payment_date/paid_amount/transaction_id are
    // written in Step 2, once we know whether the settlement actually posted.)
    if ($log_id) {
        $stmt = $pdo->prepare("UPDATE maintenance_logs SET
            asset_id = ?, maintenance_date = ?, maintenance_type = ?, description = ?,
            cost = ?, performed_by = ?, status = ?, completion_date = ?, next_due_date = ?, notes = ?,
            expense_account_id = ?
            WHERE log_id = ?");
        $stmt->execute([
            $asset_id, $maintenance_date, $maintenance_type, $description,
            $cost, $performed_by, $status, $completion_date, ($next_due_date !== '' ? $next_due_date : null), $notes,
            $expense_account_id,
            $log_id,
        ]);
        $msg = "Log updated";
    } else {
        $stmt = $pdo->prepare("INSERT INTO maintenance_logs
            (asset_id, maintenance_date, maintenance_type, description, cost, performed_by,
             status, completion_date, next_due_date, notes, expense_account_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $asset_id, $maintenance_date, $maintenance_type, $description,
            $cost, $performed_by, $status, $completion_date, ($next_due_date !== '' ? $next_due_date : null), $notes,
            $expense_account_id,
        ]);
        $log_id = (int)$pdo->lastInsertId();
        $msg = "Log saved";

        // Auto-set asset to maintenance status if not completed/paid.
        if (!in_array($status, ['completed', 'paid'], true)) {
            $pdo->prepare("UPDATE assets SET status = 'maintenance' WHERE asset_id = ?")->execute([$asset_id]);
        }
    }

    // Step 2 — GL.
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
    } elseif ($status === 'paid' && $cost > 0 && empty($oldRow['transaction_id'])) {
        // The empty($oldRow['transaction_id']) guard above is what makes a
        // harmless re-save of an already-paid log (e.g. fixing the
        // description) a no-op here instead of a duplicate settlement —
        // the validation block earlier already confirmed nothing money-
        // affecting changed before letting execution reach this far.
        $ref   = 'ML-' . $log_id;
        $label = "Maintenance #$log_id (Asset #$asset_id) — {$maintenance_type}: " . substr($description, 0, 80);

        // Ensure the accrual exists first — a log can go straight from
        // pending/in_progress to paid without ever passing through
        // 'completed' explicitly (the form allows picking Paid directly).
        if (!$expense_account_id) {
            throw new Exception('Cannot mark paid: an Expense Account is required.');
        }
        if (!accrualEntryId($pdo, 'maintenance_log', (int)$log_id)) {
            $accrResult = postAccrualEntry(
                $pdo, 'maintenance_log', 'Maintenance', (int)$log_id,
                $expense_account_id, $cost, $glDate, null, $userId, $ref,
                "Asset #{$asset_id} — {$maintenance_type}: " . substr($description, 0, 80)
            );
            if (!empty($accrResult['entry_id'])) $newEntryId = (int)$accrResult['entry_id'];
        }

        // Settle against the accrual if one exists (Dr Accrued / Cr Bank);
        // otherwise book straight to the expense account (accrual posting
        // was skipped, e.g. accounts not configured).
        $settleDebit = $expense_account_id;
        if (accrualEntryId($pdo, 'maintenance_log', (int)$log_id) !== null && !accrualVoided($pdo, 'maintenance_log', (int)$log_id)) {
            $accruedAcc = accruedExpensesAccountId($pdo);
            if ($accruedAcc) $settleDebit = (int)$accruedAcc;
        }

        $payDate = $completion_date ?: date('Y-m-d');
        $txnId = postOutflow($pdo, 'maintenance', $paid_from_account_id, $settleDebit, $cost, $payDate, $ref, $label, null);
        if (!$txnId) {
            throw new Exception('Ledger posting failed — check the Paid-From and Expense accounts are active.');
        }
        recordBankTransaction($pdo, $paid_from_account_id, $cost, 'withdrawal', $payDate, $ref, $label, $userId);

        $pdo->prepare("UPDATE maintenance_logs
                          SET paid_from_account_id = ?, payment_date = ?, paid_amount = ?, transaction_id = ?
                        WHERE log_id = ?")
            ->execute([$paid_from_account_id, $payDate, $cost, $txnId, $log_id]);

    } elseif ($status === 'cancelled') {
        // Reverse whatever this log has posted (settlement, then accrual).
        reverseMaintenanceLedger($pdo, array_merge($oldRow, ['log_id' => $log_id]), $userId);
        $newEntryId = null;
        $pdo->prepare("UPDATE maintenance_logs
                          SET paid_from_account_id = NULL, payment_date = NULL, paid_amount = NULL, transaction_id = NULL
                        WHERE log_id = ?")->execute([$log_id]);
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
