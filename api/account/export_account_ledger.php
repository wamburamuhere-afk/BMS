<?php
/**
 * api/account/export_account_ledger.php
 * -------------------------------------
 * CSV export of one account's general ledger for a period — opening balance,
 * each posted line (with its contra account and a running balance), and period
 * totals. Reads the UNIFIED ledger source (items + header-only) so nothing is
 * dropped. GET ?account_id&date_from&date_to.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/account_balance.php';

if (!isAuthenticated())            { http_response_code(401); die('Unauthorized'); }
if (!canView('chart_of_accounts')) { http_response_code(403); die('Permission denied'); }

$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$date_from  = $_GET['date_from'] ?? date('Y-01-01');
$date_to    = $_GET['date_to']   ?? date('Y-12-31');
if ($account_id <= 0) { http_response_code(400); die('Invalid account id'); }

$acc = $pdo->prepare("SELECT a.account_code, a.account_name, a.opening_balance, at.type_name
                        FROM accounts a LEFT JOIN account_types at ON a.account_type_id = at.type_id
                       WHERE a.account_id = ?");
$acc->execute([$account_id]);
$account = $acc->fetch(PDO::FETCH_ASSOC);
if (!$account) { http_response_code(404); die('Account not found'); }

$is_debit_primary = in_array(strtolower($account['type_name'] ?? ''), ['asset', 'expense']);

// Unified ledger query (items + header-only), same as the page.
$ls = $pdo->prepare("
    SELECT mv.entry_id, mv.entry_date, mv.reference_number, mv.main_desc, mv.type, mv.amount
      FROM (
        SELECT je.entry_id, je.entry_date, je.reference_number, je.description AS main_desc, jei.type, jei.amount
          FROM journal_entry_items jei JOIN journal_entries je ON jei.entry_id = je.entry_id
         WHERE jei.account_id = :a1 AND je.status='posted' AND je.entry_date BETWEEN :f1 AND :t1
        UNION ALL
        SELECT je.entry_id, je.entry_date, je.reference_number, je.description, 'debit', je.amount
          FROM journal_entries je WHERE je.debit_account_id = :a2 AND je.status='posted'
           AND je.entry_date BETWEEN :f2 AND :t2 AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id=je.entry_id)
        UNION ALL
        SELECT je.entry_id, je.entry_date, je.reference_number, je.description, 'credit', je.amount
          FROM journal_entries je WHERE je.credit_account_id = :a3 AND je.status='posted'
           AND je.entry_date BETWEEN :f3 AND :t3 AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id=je.entry_id)
      ) mv ORDER BY mv.entry_date ASC, mv.entry_id ASC
");
$ls->execute([':a1'=>$account_id, ':f1'=>$date_from, ':t1'=>$date_to,
              ':a2'=>$account_id, ':f2'=>$date_from, ':t2'=>$date_to,
              ':a3'=>$account_id, ':f3'=>$date_from, ':t3'=>$date_to]);
$rows = $ls->fetchAll(PDO::FETCH_ASSOC);

// Contra per entry (opposite item legs, else other header account).
$contra = [];
if ($rows) {
    $ids = array_values(array_unique(array_map(fn($r) => (int)$r['entry_id'], $rows)));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ci = $pdo->prepare("SELECT ji.entry_id, a.account_code, a.account_name FROM journal_entry_items ji
                          JOIN accounts a ON ji.account_id=a.account_id
                         WHERE ji.entry_id IN ($ph) AND ji.account_id <> ?");
    $ci->execute(array_merge($ids, [$account_id]));
    $by = [];
    foreach ($ci->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(int)$r['entry_id']][] = $r['account_code'].' '.$r['account_name'];
    $ch = $pdo->prepare("SELECT je.entry_id, je.debit_account_id, je.credit_account_id,
                                da.account_code dc, da.account_name dn, ca.account_code cc, ca.account_name cn
                           FROM journal_entries je
                           LEFT JOIN accounts da ON je.debit_account_id=da.account_id
                           LEFT JOIN accounts ca ON je.credit_account_id=ca.account_id
                          WHERE je.entry_id IN ($ph) AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id=je.entry_id)");
    $ch->execute($ids);
    $hdr = [];
    foreach ($ch->fetchAll(PDO::FETCH_ASSOC) as $r) $hdr[(int)$r['entry_id']] = $r;
    foreach ($ids as $eid) {
        if (!empty($by[$eid])) $contra[$eid] = count($by[$eid]) === 1 ? $by[$eid][0] : ('Split — '.count($by[$eid]).' accounts');
        elseif (isset($hdr[$eid])) {
            $h = $hdr[$eid];
            $contra[$eid] = ((int)$h['debit_account_id'] === $account_id)
                ? trim(($h['cc']??'').' '.($h['cn']??'')) : trim(($h['dc']??'').' '.($h['dn']??''));
        }
    }
}

// Opening balance for the period (balance before date_from), unified source.
$bb = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN mv.type='debit' THEN mv.amount ELSE -mv.amount END),0)
      FROM (
        SELECT jei.type, jei.amount FROM journal_entry_items jei JOIN journal_entries je ON jei.entry_id=je.entry_id
         WHERE jei.account_id=:a1 AND je.status='posted' AND je.entry_date < :f1
        UNION ALL SELECT 'debit', je.amount FROM journal_entries je WHERE je.debit_account_id=:a2 AND je.status='posted' AND je.entry_date < :f2 AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id=je.entry_id)
        UNION ALL SELECT 'credit', je.amount FROM journal_entries je WHERE je.credit_account_id=:a3 AND je.status='posted' AND je.entry_date < :f3 AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id=je.entry_id)
      ) mv");
$bb->execute([':a1'=>$account_id, ':f1'=>$date_from, ':a2'=>$account_id, ':f2'=>$date_from, ':a3'=>$account_id, ':f3'=>$date_from]);
$net_before = (float)$bb->fetchColumn();
if (!$is_debit_primary) $net_before = -$net_before;
$run = (float)$account['opening_balance'] + $net_before;

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="ledger_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $account['account_code']) . '_' . $date_from . '_to_' . $date_to . '.csv"');
$out = fopen('php://output', 'w');

fputcsv($out, ['General Ledger', $account['account_code'] . ' - ' . $account['account_name']]);
fputcsv($out, ['Period', $date_from . ' to ' . $date_to]);
fputcsv($out, []);
fputcsv($out, ['Date', 'Reference', 'Description', 'Contra Account', 'Debit', 'Credit', 'Balance']);
fputcsv($out, [date('Y-m-d', strtotime($date_from)), 'OPENING', 'Balance Brought Forward', '', '', '', number_format($run, 2, '.', '')]);

$td = 0.0; $tc = 0.0;
foreach ($rows as $r) {
    $debit  = $r['type'] === 'debit'  ? (float)$r['amount'] : 0.0;
    $credit = $r['type'] === 'credit' ? (float)$r['amount'] : 0.0;
    $run += $is_debit_primary ? ($debit - $credit) : ($credit - $debit);
    $td += $debit; $tc += $credit;
    fputcsv($out, [
        date('Y-m-d', strtotime($r['entry_date'])),
        $r['reference_number'] ?: ('#' . $r['entry_id']),
        $r['main_desc'],
        $contra[(int)$r['entry_id']] ?? '',
        $debit  > 0 ? number_format($debit, 2, '.', '')  : '',
        $credit > 0 ? number_format($credit, 2, '.', '') : '',
        number_format($run, 2, '.', ''),
    ]);
}

fputcsv($out, []);
fputcsv($out, ['', '', 'PERIOD TOTALS', '', number_format($td, 2, '.', ''), number_format($tc, 2, '.', ''), number_format($run, 2, '.', '')]);
fclose($out);
exit;
