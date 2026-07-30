<?php
/**
 * api/account/import_bank_statement.php
 * ----------------------------------------
 * Bank Reconciliation Phase 5 — Statement Import.
 *
 * Writes real, independent STATEMENT-side rows into bank_transactions
 * (imported_from set) from a CSV bank statement, so the matching worksheet
 * has genuine external data to compare against the BOOK-side rows BMS
 * already auto-writes for every posted cash event (core/bank_register.php).
 *
 * CSV only — matches the existing project-wide import convention
 * (api/import_customers.php etc. are all fgetcsv-based; no XLSX library is
 * vendored). Template columns (see downloadTemplate() in
 * app/constant/accounts/bank_reconciliation.php):
 *   transaction_date, value_date, description, reference, transaction_type,
 *   amount, balance_after, category, counterparty_name, counterparty_account
 *
 * import_action:
 *   add_new — insert rows that don't already exist; skip duplicates.
 *   update  — insert new rows; update non-matching-state fields on existing ones.
 *   replace — delete this account's UNMATCHED statement-side rows within the
 *             file's date range, then insert fresh. Never touches book-side
 *             rows or anything already matched/manual/reconciled.
 *
 * auto_match — after import, calls suggestBankRecMatches() to pre-pair new
 * statement rows against existing unmatched book-side rows (see
 * core/bank_recon_matching.php). This only ever sets matched_transaction_id
 * as a SUGGESTION; it never flips matching_status, so nothing here can
 * affect an open reconciliation's cleared/uncleared totals by itself — the
 * user still confirms each suggested pair from the worksheet.
 *
 * try/catch, no hard exit() on a validation failure — matches every other
 * bank-recon endpoint in this directory (create_reconciliation.php,
 * unreconcile.php, toggle_reconciliation_match.php,
 * create_entry_from_statement_line.php).
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/bank_recon_matching.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new Exception('Method not allowed'); }
    csrf_check();
    if (!canEdit('bank_reconciliation')) { http_response_code(403); throw new Exception('Access Denied: you do not have permission to import bank statements'); }

    $bankAccountId = (int)($_POST['bank_account_id'] ?? 0);
    $importAction  = $_POST['import_action'] ?? 'add_new';
    $autoMatch     = isset($_POST['auto_match']) && $_POST['auto_match'] !== 'false' && $_POST['auto_match'] !== '0';

    if ($bankAccountId <= 0) throw new Exception('Bank account is required');
    if (!in_array($importAction, ['add_new', 'update', 'replace'], true)) throw new Exception('Invalid import action');

    // The "bank account" must actually be a valid accounts row (BMS has no
    // separate bank_accounts table — see core/bank_register.php).
    $acctChk = $pdo->prepare("SELECT account_id FROM accounts WHERE account_id = ? AND status != 'deleted'");
    $acctChk->execute([$bankAccountId]);
    if (!$acctChk->fetchColumn()) throw new Exception('Bank account not found');

    // ---- File upload validation (security.md §19) ----
    if (!isset($_FILES['statement_file']) || $_FILES['statement_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Please select a statement file to upload');
    }

    $ext = strtolower(pathinfo($_FILES['statement_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') throw new Exception('Only CSV files are supported. Export your statement as CSV and try again.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($_FILES['statement_file']['tmp_name']);
    $allowedMime = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
    if (!in_array($realMime, $allowedMime, true)) throw new Exception('File content does not look like a CSV file');

    $maxSize = 10 * 1024 * 1024; // 10MB, matches the UI's stated limit
    if ($_FILES['statement_file']['size'] > $maxSize) throw new Exception('File exceeds the 10MB size limit');

    $handle = fopen($_FILES['statement_file']['tmp_name'], 'r');
    if (!$handle) throw new Exception('Unable to read the uploaded file');

    try {
        $headers = fgetcsv($handle);
        if (!$headers) throw new Exception('Empty CSV file');
        $headers[0] = preg_replace('/^[\xEF\xBB\xBF\xFF\xFE]+/', '', $headers[0]);
        $headers = array_map('trim', $headers);

        $requiredHeaders = ['transaction_date', 'transaction_type', 'amount', 'description'];
        $missing = array_diff($requiredHeaders, $headers);
        if (!empty($missing)) throw new Exception('Missing required column(s): ' . implode(', ', $missing));

        $idx = array_flip($headers);
        $col = function (array $row, string $name, string $default = '') use ($idx) {
            return isset($idx[$name]) && isset($row[$idx[$name]]) ? trim((string)$row[$idx[$name]]) : $default;
        };

        $rows = [];
        $rowNum = 1; // header was row 1
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) continue; // blank line
            $rows[] = ['n' => $rowNum, 'row' => $row];
        }
    } finally {
        fclose($handle);
    }

    $results = ['total_rows' => count($rows), 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    $userId  = (int)($_SESSION['user_id'] ?? 0);

    // Pre-pass: the file's date span, needed to scope "replace" BEFORE any
    // insert happens (deleting after insert would let a fresh row dedupe
    // against — and then lose — the very row it was meant to replace).
    $minDate = null; $maxDate = null;
    foreach ($rows as $entry) {
        $d = $col($entry['row'], 'transaction_date');
        $t = strtotime($d);
        if ($t === false) continue;
        $d = date('Y-m-d', $t);
        if ($minDate === null || $d < $minDate) $minDate = $d;
        if ($maxDate === null || $d > $maxDate) $maxDate = $d;
    }

    if ($importAction === 'replace' && $minDate !== null) {
        // Only unmatched imported (statement-side) rows are eligible — book-side
        // rows and anything already matched/manual/reconciled are never touched.
        $stale = $pdo->prepare("
            SELECT transaction_id, matched_transaction_id FROM bank_transactions
             WHERE bank_account_id = ? AND imported_from IS NOT NULL
               AND transaction_date BETWEEN ? AND ?
               AND COALESCE(matching_status,'unmatched') = 'unmatched'
        ");
        $stale->execute([$bankAccountId, $minDate, $maxDate]);
        $staleRows = $stale->fetchAll(PDO::FETCH_ASSOC);
        if ($staleRows) {
            $clearPartner = $pdo->prepare("UPDATE bank_transactions SET matched_transaction_id = NULL, updated_at = NOW() WHERE transaction_id = ?");
            $delStmt = $pdo->prepare("DELETE FROM bank_transactions WHERE transaction_id = ?");
            foreach ($staleRows as $sr) {
                if (!empty($sr['matched_transaction_id'])) {
                    $clearPartner->execute([(int)$sr['matched_transaction_id']]);
                }
                $delStmt->execute([(int)$sr['transaction_id']]);
            }
        }
    }

    // Scoped to imported_from IS NOT NULL only — this is a "have we already
    // imported this exact statement line before" check (repeat-import safety),
    // never a match against a book-side row. Pairing a statement line to its
    // book-side counterpart is a separate concern (suggestBankRecMatches()).
    $findDup = $pdo->prepare("
        SELECT transaction_id FROM bank_transactions
         WHERE bank_account_id = ? AND transaction_date = ? AND transaction_type = ?
           AND ABS(amount - ?) < 0.01
           AND imported_from IS NOT NULL
           AND ( (reference_number IS NOT NULL AND reference_number = ?) OR (matching_reference = ?) )
         LIMIT 1
    ");
    $insert = $pdo->prepare("
        INSERT INTO bank_transactions
            (bank_account_id, account_id, transaction_date, value_date, description,
             reference_number, transaction_type, amount, balance_after, category,
             counterparty_name, counterparty_account, matching_reference,
             matching_status, status, imported_from, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unmatched', 'cleared', 'csv_import', ?, NOW(), NOW())
    ");
    $update = $pdo->prepare("
        UPDATE bank_transactions
           SET description = ?, balance_after = ?, category = ?, counterparty_name = ?, counterparty_account = ?, updated_at = NOW()
         WHERE transaction_id = ?
    ");

    foreach ($rows as $entry) {
        $n = $entry['n']; $row = $entry['row'];

        $date = $col($row, 'transaction_date');
        $ts   = strtotime($date);
        if ($date === '' || $ts === false) {
            $results['errors'][] = "Row $n: invalid or missing transaction_date";
            $results['skipped']++;
            continue;
        }
        $date = date('Y-m-d', $ts);

        $type = strtolower($col($row, 'transaction_type'));
        if (!in_array($type, ['deposit', 'withdrawal'], true)) {
            $results['errors'][] = "Row $n: transaction_type must be 'deposit' or 'withdrawal'";
            $results['skipped']++;
            continue;
        }

        $amount = (float)str_replace(',', '', $col($row, 'amount'));
        if ($amount <= 0) {
            $results['errors'][] = "Row $n: amount must be a positive number";
            $results['skipped']++;
            continue;
        }

        $description = $col($row, 'description');
        if ($description === '') {
            $results['errors'][] = "Row $n: description is required";
            $results['skipped']++;
            continue;
        }

        $valueDate = $col($row, 'value_date');
        $valueDate = ($valueDate !== '' && strtotime($valueDate) !== false) ? date('Y-m-d', strtotime($valueDate)) : $date;
        $reference = $col($row, 'reference') ?: null;
        $balanceAfter = $col($row, 'balance_after');
        $balanceAfter = ($balanceAfter !== '') ? (float)str_replace(',', '', $balanceAfter) : null;
        $category = $col($row, 'category') ?: null;
        $counterpartyName = $col($row, 'counterparty_name') ?: null;
        $counterpartyAccount = $col($row, 'counterparty_account') ?: null;

        // Synthetic dedupe key used only when the bank's own CSV has no reference.
        $syntheticRef = 'CSV-' . md5($bankAccountId . '|' . $date . '|' . $type . '|' . number_format($amount, 2, '.', '') . '|' . $description);

        $findDup->execute([$bankAccountId, $date, $type, $amount, $reference, $syntheticRef]);
        $dupId = $findDup->fetchColumn();

        if ($dupId) {
            if ($importAction === 'update') {
                $update->execute([$description, $balanceAfter, $category, $counterpartyName, $counterpartyAccount, (int)$dupId]);
                $results['updated']++;
            } else {
                $results['skipped']++;
            }
            continue;
        }

        $insert->execute([
            $bankAccountId, $bankAccountId, $date, $valueDate, $description,
            $reference, $type, $amount, $balanceAfter, $category,
            $counterpartyName, $counterpartyAccount, $reference ?: $syntheticRef,
            $userId ?: null,
        ]);
        $results['imported']++;
    }

    $suggested = [];
    if ($autoMatch && $results['imported'] > 0) {
        $suggested = suggestBankRecMatches($pdo, $bankAccountId);
    }

    $logMsg = "Imported {$results['imported']}, updated {$results['updated']}, skipped {$results['skipped']} of {$results['total_rows']} row(s)"
        . ($suggested ? '; ' . count($suggested) . ' suggested match(es) found' : '');
    logActivity($pdo, $userId, 'Bank statement import', $logMsg . " (account #$bankAccountId)");

    echo json_encode([
        'success' => true,
        'message' => $logMsg,
        'results' => $results,
        'suggested_matches' => count($suggested),
    ]);

} catch (Throwable $e) {
    error_log('import_bank_statement error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
