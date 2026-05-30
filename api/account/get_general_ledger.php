<?php
/**
 * General Ledger — Data API (Phase 2.1)
 *
 * Per-account detail report — the audit trail backing the Trial Balance.
 * Required input: account_id. Date range + project filter narrow the
 * window. Output is everything an auditor needs to reconstruct the
 * account's position within the window:
 *
 *   - Account metadata (code, name, statement, category, normal_side)
 *   - Opening balance at start_date (= accounts.opening_balance
 *     allocated by normal_side + cumulative net of all posted
 *     journal_entry_items BEFORE start_date, project-scope filtered)
 *   - Chronological list of every posted journal_entry_items row in
 *     window, each with running balance after that posting
 *   - Closing balance at end_date
 *   - Source column data: entity_type-entity_id when present, else
 *     "Manual" (Phase 0.1 added these columns; Phase 4 auto-posting
 *     will populate them. Today's 2 existing manual entries show as
 *     "Manual".)
 *
 * STRUCTURE / SEMANTICS
 *   The running balance follows the account's natural side. For
 *   asset / expense (debit-natural) accounts:
 *       running = prior_running + debit - credit
 *   For liability / equity / revenue (credit-natural):
 *       running = prior_running + credit - debit
 *   This yields a positive running balance whenever the account is in
 *   its natural-side position (the normal expectation).
 *
 * PROJECT FILTER + USER SCOPE
 *   Same model as IS/BS/CF/TB:
 *     - Admin sees everything
 *     - Non-admin: scopeFilterSqlNullable('project') — assigned projects
 *       OR untagged entries
 *     - Specific project_id requires userCan('project', id) → else 403
 *
 * Returns JSON shape:
 *   { success, data: {
 *       meta: { account_id, start_date, end_date, project_id,
 *               project_filter_active, is_admin, scoped_project_ids,
 *               line_count },
 *       account: { account_id, account_code, account_name, statement,
 *                  category, normal_side },
 *       opening_balance, closing_balance,
 *       window_debit_total, window_credit_total,
 *       lines: [
 *         { entry_id, entry_date, description, reference_number,
 *           entity_type, entity_id, source, type, amount,
 *           item_description, running_balance },
 *         ...
 *       ]
 *   } }
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

// Guarded: consumed as an internal report partial after headers are sent.
if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Guard: account_types classification columns (migration 2026_05_27) must exist
// on this server, else every at.category query throws. Return a clear message.
try { $fc_ready = $pdo->query("SHOW COLUMNS FROM account_types LIKE 'category'")->fetch() !== false; }
catch (Throwable $e) { $fc_ready = false; }
if (!$fc_ready) {
    echo json_encode(['success' => false, 'message' =>
        'Report unavailable: account-type classification not installed on this server. '
      . 'Run migration 2026_05_27_account_types_classification.php (see /migrations/status.php).']);
    exit;
}

// ── Parameters ───────────────────────────────────────────────────────────
$account_id  = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$start_date  = $_GET['start_date'] ?? date('Y-m-01');
$end_date    = $_GET['end_date']   ?? date('Y-m-t');
$project_id  = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

if ($account_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'account_id is required']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'start_date and end_date must be YYYY-MM-DD']);
    exit;
}
if ($start_date > $end_date) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'start_date must be <= end_date']);
    exit;
}

// ── Scope resolution ────────────────────────────────────────────────────
$is_admin = isAdmin();
$user_project_ids = [];
if (!$is_admin) {
    $user_project_ids = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied: this project is not in your assigned scope.',
    ]);
    exit;
}

try {
    global $pdo;

    // ── Account metadata + normal_side (drives running-balance direction)
    $accountStmt = $pdo->prepare("
        SELECT a.account_id, a.account_code, a.account_name, a.opening_balance,
               a.status,
               COALESCE(at.statement, 'BS')                AS statement,
               COALESCE(at.category, 'asset')              AS category,
               COALESCE(at.normal_side, 'debit')           AS normal_side
          FROM accounts a
     LEFT JOIN account_types at ON a.account_type_id = at.type_id
         WHERE a.account_id = ?
    ");
    $accountStmt->execute([$account_id]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Account $account_id not found."]);
        exit;
    }

    $normal_side    = $account['normal_side'];
    $opening_amt    = (float)$account['opening_balance'];

    // Scope clause (uses the canonical helper from core/project_scope.php).
    $buildScopeClause = function () use ($project_id): array {
        if ($project_id !== null) {
            return ['sql' => " AND je.project_id = ?", 'params' => [$project_id]];
        }
        return ['sql' => scopeFilterSqlNullable('project', 'je'), 'params' => []];
    };
    $scope = $buildScopeClause();

    // ── Opening balance at start_date ─────────────────────────────────────
    // = accounts.opening_balance allocated by normal_side
    //   + cumulative net of every posted journal_entry_items BEFORE start_date
    //     that matches the project scope.
    $sqlPre = "
        SELECT
            COALESCE(SUM(CASE WHEN jei.type = 'debit'  THEN jei.amount ELSE 0 END), 0) AS pre_debit,
            COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) AS pre_credit
          FROM journal_entry_items jei
    INNER JOIN journal_entries je ON jei.entry_id = je.entry_id
         WHERE jei.account_id   = ?
           AND je.status        = 'posted'
           AND je.entry_date    <  ?
           {$scope['sql']}
    ";
    $preStmt = $pdo->prepare($sqlPre);
    $preStmt->execute(array_merge([$account_id, $start_date], $scope['params']));
    $pre = $preStmt->fetch(PDO::FETCH_ASSOC);
    $pre_debit  = (float)$pre['pre_debit'];
    $pre_credit = (float)$pre['pre_credit'];

    // Opening balance — positive when account sits on its natural side.
    if ($normal_side === 'debit') {
        $opening_balance = $opening_amt + ($pre_debit - $pre_credit);
    } else {
        // credit-natural: opening_amt was allocated to Cr
        $opening_balance = $opening_amt + ($pre_credit - $pre_debit);
    }

    // ── Window detail rows ─────────────────────────────────────────────────
    $sqlLines = "
        SELECT
            jei.item_id,
            je.entry_id,
            je.entry_date,
            je.reference_number,
            je.description       AS entry_description,
            je.entity_type,
            je.entity_id,
            je.project_id,
            jei.type,
            jei.amount,
            jei.description      AS item_description
          FROM journal_entry_items jei
    INNER JOIN journal_entries je ON jei.entry_id = je.entry_id
         WHERE jei.account_id   = ?
           AND je.status        = 'posted'
           AND je.entry_date   BETWEEN ? AND ?
           {$scope['sql']}
      ORDER BY je.entry_date ASC,
               je.entry_id   ASC,
               jei.item_id   ASC
    ";
    $linesStmt = $pdo->prepare($sqlLines);
    $linesStmt->execute(array_merge([$account_id, $start_date, $end_date], $scope['params']));
    $rawLines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute running balance + format lines
    $running = $opening_balance;
    $window_debit_total  = 0.0;
    $window_credit_total = 0.0;
    $lines = [];

    foreach ($rawLines as $r) {
        $debit  = ($r['type'] === 'debit')  ? (float)$r['amount'] : 0.0;
        $credit = ($r['type'] === 'credit') ? (float)$r['amount'] : 0.0;

        if ($normal_side === 'debit') {
            $running += $debit - $credit;
        } else {
            $running += $credit - $debit;
        }

        // Source column: entity_type-entity_id when both present, else "Manual"
        if (!empty($r['entity_type']) && $r['entity_id'] !== null && $r['entity_id'] !== '') {
            $source = $r['entity_type'] . '-' . (int)$r['entity_id'];
        } else {
            $source = 'Manual';
        }

        $lines[] = [
            'item_id'           => (int)$r['item_id'],
            'entry_id'          => (int)$r['entry_id'],
            'entry_date'        => $r['entry_date'],
            'reference_number'  => $r['reference_number'],
            'description'       => $r['entry_description'],
            'item_description'  => $r['item_description'],
            'entity_type'       => $r['entity_type'],
            'entity_id'         => $r['entity_id'] !== null ? (int)$r['entity_id'] : null,
            'source'            => $source,
            'type'              => $r['type'],
            'debit'             => $debit,
            'credit'            => $credit,
            'running_balance'   => $running,
        ];
        $window_debit_total  += $debit;
        $window_credit_total += $credit;
    }

    $closing_balance = $running;

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'account_id'           => $account_id,
                'start_date'           => $start_date,
                'end_date'             => $end_date,
                'project_id'           => $project_id,
                'project_filter_active'=> $project_id !== null,
                'is_admin'             => $is_admin,
                'scoped_project_ids'   => $is_admin ? null : $user_project_ids,
                'line_count'           => count($lines),
            ],
            'account' => [
                'account_id'   => (int)$account['account_id'],
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'statement'    => $account['statement'],
                'category'     => $account['category'],
                'normal_side'  => $normal_side,
                'status'       => $account['status'],
            ],
            'opening_balance'       => $opening_balance,
            'closing_balance'       => $closing_balance,
            'window_debit_total'    => $window_debit_total,
            'window_credit_total'   => $window_credit_total,
            'lines'                 => $lines,
        ],
    ]);
} catch (Throwable $e) {
    error_log('General Ledger API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
