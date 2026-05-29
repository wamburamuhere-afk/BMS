<?php
/**
 * api/account/save_journal_mappings.php
 * --------------------------------------
 * Phase 4.2 — bulk-save endpoint for the Journal Mappings admin page.
 *
 * Accepts POST with a `mappings` array — one entry per row currently in
 * journal_mappings. Each entry: { id, debit_account_id, credit_account_id,
 * is_active, notes }. Updates all rows in a single transaction.
 *
 * Validation enforced:
 *   1. Caller is authenticated AND has canEdit('chart_of_accounts') (admin-
 *      grade permission already in the BMS permission system).
 *   2. Method = POST.
 *   3. Every posted `id` must already exist in journal_mappings (we don't
 *      create event rows from the UI — those are seeded by migrations).
 *   4. is_active = 1 requires BOTH debit_account_id AND credit_account_id
 *      to be non-null. (Posting with a NULL account would later violate
 *      postLedgerEntry()'s "valid account" check anyway, so we refuse it
 *      here at config time with a clear message.)
 *   5. Both account FKs (when provided) must reference real rows in the
 *      accounts table.
 *   6. debit_account_id != credit_account_id (debit-to-itself would balance
 *      but post nothing meaningful; almost always a config mistake).
 *
 * Returns JSON: { success: bool, updated: int, errors: [string] | message }.
 */

require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized access');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    if (!canEdit('chart_of_accounts')) {
        throw new Exception('You do not have permission to edit journal mappings');
    }

    $mappings = $_POST['mappings'] ?? null;
    if (!is_array($mappings) || count($mappings) === 0) {
        throw new Exception('No mappings supplied');
    }

    // ── Per-row validation ──────────────────────────────────────────────
    //
    // We collect ALL validation errors before writing anything so the UI
    // can show every issue in one go (rather than fix-one-retry-discover-next).
    $valid_account_ids = array_map('intval', $pdo
        ->query("SELECT account_id FROM accounts WHERE status = 'active'")
        ->fetchAll(PDO::FETCH_COLUMN));
    $valid_account_set = array_flip($valid_account_ids);

    $existing_ids = array_map('intval', $pdo
        ->query("SELECT id FROM journal_mappings")
        ->fetchAll(PDO::FETCH_COLUMN));
    $existing_set = array_flip($existing_ids);

    // Pre-load event_type label for nicer error messages
    $rows = $pdo->query("SELECT id, event_type FROM journal_mappings")->fetchAll(PDO::FETCH_KEY_PAIR);

    $errors = [];
    $clean  = [];

    foreach ($mappings as $idx => $m) {
        $id  = isset($m['id']) ? (int)$m['id'] : 0;
        $dr  = isset($m['debit_account_id']) && $m['debit_account_id']  !== ''
                  ? (int)$m['debit_account_id']  : null;
        $cr  = isset($m['credit_account_id']) && $m['credit_account_id'] !== ''
                  ? (int)$m['credit_account_id'] : null;
        $act = !empty($m['is_active']) ? 1 : 0;
        $notes = isset($m['notes']) ? trim((string)$m['notes']) : '';

        $label = $rows[$id] ?? "row #$idx";

        if (!isset($existing_set[$id])) {
            $errors[] = "$label: mapping id=$id does not exist (event rows are added via migrations only)";
            continue;
        }
        if ($dr !== null && !isset($valid_account_set[$dr])) {
            $errors[] = "$label: debit_account_id=$dr is not a valid active account";
        }
        if ($cr !== null && !isset($valid_account_set[$cr])) {
            $errors[] = "$label: credit_account_id=$cr is not a valid active account";
        }
        if ($dr !== null && $cr !== null && $dr === $cr) {
            $errors[] = "$label: debit and credit cannot be the same account";
        }
        if ($act === 1 && ($dr === null || $cr === null)) {
            $errors[] = "$label: cannot activate without both debit and credit accounts set";
        }

        $clean[] = [
            'id'                => $id,
            'debit_account_id'  => $dr,
            'credit_account_id' => $cr,
            'is_active'         => $act,
            'notes'             => $notes,
        ];
    }

    if (count($errors) > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Validation failed',
            'errors'  => $errors,
        ]);
    } else {
        // ── Bulk update inside a single transaction ─────────────────────
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE journal_mappings
               SET debit_account_id  = :dr,
                   credit_account_id = :cr,
                   is_active         = :act,
                   notes             = :notes
             WHERE id = :id
        ");

        $updated = 0;
        foreach ($clean as $row) {
            $stmt->execute([
                ':dr'    => $row['debit_account_id'],
                ':cr'    => $row['credit_account_id'],
                ':act'   => $row['is_active'],
                ':notes' => $row['notes'] !== '' ? $row['notes'] : null,
                ':id'    => $row['id'],
            ]);
            if ($stmt->rowCount() > 0) $updated++;
        }

        $pdo->commit();

        if (function_exists('logActivity')) {
            logActivity(
                $pdo,
                $_SESSION['user_id'] ?? null,
                'update_journal_mappings',
                "Updated $updated journal mapping(s)"
            );
        }

        echo json_encode([
            'success' => true,
            'updated' => $updated,
            'message' => "$updated mapping(s) saved.",
        ]);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
