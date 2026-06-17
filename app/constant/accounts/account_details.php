<?php
// app/constant/accounts/account_details.php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/account_balance.php';   // unified ledger source + ledger-true balances
require_once __DIR__ . '/../../../core/gl_accounts.php';       // apAccountId()

// Include the header and authentication
autoEnforcePermission('chart_of_accounts');

includeHeader();

// Get Account ID
if (!isset($_GET['account_id']) || empty($_GET['account_id'])) {
    header('Location: ' . getUrl('chart-of-accounts'));
    exit;
}

$account_id = $_GET['account_id'];
$date_from = $_GET['date_from'] ?? date('Y-01-01'); // Default to start of year
$date_to = $_GET['date_to'] ?? date('Y-12-31');     // Default to end of year

// Fetch Account Info
$stmt = $pdo->prepare("
    SELECT a.*, at.type_name, at.display_name as type_display, st.name AS category_name
    FROM accounts a
    LEFT JOIN account_types at ON a.account_type_id = at.type_id
    LEFT JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
    WHERE a.account_id = ?
");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Account not found. <a href='" . getUrl('chart-of-accounts') . "'>Return to chart of accounts</a></div></div>";
    includeFooter();
    exit;
}

// ── AP Sub-Ledger: is this the Trade Creditors control account? ──────────
// If yes, build a per-vendor summary from supplier_invoices (the authoritative
// AP source — same as vendor_statement uses) so the drill-down shows one row
// per supplier / sub-contractor instead of raw mixed journal lines.
$apAccountId   = apAccountId($pdo);
$is_ap_control = ($apAccountId && (int)$account_id === (int)$apAccountId);
$apSubLedger   = [];

if ($is_ap_control) {
    // Invoice totals per vendor (billed + legacy full-payment)
    $slQ1 = $pdo->prepare("
        SELECT si.supplier_id,
               si.invoice_type                                              AS vendor_type,
               CASE WHEN si.invoice_type = 'sub_contractor'
                    THEN sc.supplier_name ELSE s.supplier_name END          AS party_name,
               COUNT(DISTINCT CASE WHEN si.status IN ('approved','partial')
                                   THEN si.id END)                          AS open_count,
               COUNT(DISTINCT si.id)                                        AS total_count,
               SUM(si.amount)                                               AS total_billed,
               SUM(CASE WHEN si.status = 'paid'
                             AND si.payment_date IS NOT NULL
                             AND si.id NOT IN (
                                 SELECT DISTINCT invoice_id
                                   FROM supplier_invoice_payments)
                        THEN si.amount ELSE 0 END)                          AS legacy_paid
          FROM supplier_invoices si
          LEFT JOIN suppliers s
                 ON s.supplier_id = si.supplier_id AND si.invoice_type = 'supplier'
          LEFT JOIN sub_contractors sc
                 ON sc.supplier_id = si.supplier_id AND si.invoice_type = 'sub_contractor'
         WHERE si.status IN ('approved','partial','paid')
         GROUP BY si.supplier_id, si.invoice_type, party_name
    ");
    $slQ1->execute();
    $slVendors = $slQ1->fetchAll(PDO::FETCH_ASSOC);

    // Payment totals from supplier_invoice_payments per vendor
    $slQ2 = $pdo->query("
        SELECT si.supplier_id, si.invoice_type, SUM(sip.amount) AS sip_paid
          FROM supplier_invoice_payments sip
          JOIN supplier_invoices si ON sip.invoice_id = si.id
         GROUP BY si.supplier_id, si.invoice_type
    ");
    $sipByVendor = [];
    foreach ($slQ2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sipByVendor[$r['invoice_type'] . '::' . $r['supplier_id']] = (float)$r['sip_paid'];
    }

    foreach ($slVendors as &$v) {
        $key          = $v['vendor_type'] . '::' . $v['supplier_id'];
        $v['total_paid'] = (float)($sipByVendor[$key] ?? 0) + (float)$v['legacy_paid'];
        $v['balance']    = round((float)$v['total_billed'] - $v['total_paid'], 2);
    }
    unset($v);
    usort($slVendors, fn($a, $b) => $b['balance'] <=> $a['balance']);
    $apSubLedger = $slVendors;
}

// ── Parent (where this account sits) ─────────────────────────────────────
$parent = null;
if (!empty($account['parent_account_id'])) {
    $pst = $pdo->prepare("SELECT account_id, account_code, account_name FROM accounts WHERE account_id = ?");
    $pst->execute([$account['parent_account_id']]);
    $parent = $pst->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Direct sub-accounts (how this account is distributed) ────────────────
$childStmt = $pdo->prepare("
    SELECT a.account_id, a.account_code, a.account_name, a.description, a.current_balance, a.status, a.level,
           (SELECT COUNT(*) FROM accounts c WHERE c.parent_account_id = a.account_id) AS grandchildren
      FROM accounts a
     WHERE a.parent_account_id = ? AND a.account_id <> ?
     ORDER BY a.account_code, a.account_name
");
$childStmt->execute([$account_id, $account_id]);
$children = $childStmt->fetchAll(PDO::FETCH_ASSOC);

// Ledger-true balances (opening + posted movements) — the SAME source the Chart
// of Accounts and Bank Accounts now use, so this page's composition figures can
// never disagree with the rest of the system. $ownLedger = each account's own
// balance; $rollupLedger = each account's balance including its whole subtree.
$ownLedger    = ledgerBalanceMap($pdo);
$rollupLedger = ledgerRollupMap($pdo);
$subtreeSum   = fn (int $id): float => (float)($rollupLedger[$id] ?? 0.0);

$own_balance  = (float)($ownLedger[(int)$account_id] ?? 0.0);
$rollup_total = $subtreeSum((int)$account_id);   // own + all descendants (ledger-true)

// Each child's rolled-up contribution + its share of the group balance.
$children_total = 0.0;
foreach ($children as &$c) {
    $c['subtree_total'] = $subtreeSum((int)$c['account_id']);
    $children_total += $c['subtree_total'];
}
unset($c);
$denom = abs($rollup_total) > 0.0001 ? abs($rollup_total) : ($children_total != 0.0 ? abs($children_total) : 1.0);
foreach ($children as &$c) {
    $c['pct'] = round(abs($c['subtree_total']) / $denom * 100, 1);
}
unset($c);

// Distinct colour per child segment (blue-family per ui-constants.md).
$palette = ['#0d6efd', '#3d8bfd', '#6ea8fe', '#0a58ca', '#084298', '#9ec5fe', '#1e3a8a', '#52b2bf', '#0dcaf0', '#6610f2'];

// ── Balance health: stored current_balance vs the ledger-true balance ────────
// If they drift, the page surfaces it with a one-click Reconcile (admins/editors).
$bh_stored     = (float)$account['current_balance'];
$bh_ledger     = accountLedgerBalance($pdo, (int)$account_id);
$bh_in_sync    = abs($bh_stored - $bh_ledger) < 0.01;
$bh_difference = round($bh_stored - $bh_ledger, 2);

// Fetch Transaction History (Ledger) from the UNIFIED ledger source: itemised
// lines where an entry has them, PLUS the journal_entries header debit/credit for
// the rare posted entry that has no item lines — so this ledger never silently
// drops a transaction (the same unified view that drives the ledger-true balance).
$ledger_stmt = $pdo->prepare("
    SELECT mv.entry_id, mv.entry_date, mv.reference_number, mv.main_desc,
           mv.item_desc, mv.type, mv.amount, mv.status,
           mv.created_at AS posted_at, u.username AS posted_by
      FROM (
            -- 1) Itemised lines
            SELECT je.entry_id, je.entry_date, je.reference_number,
                   je.description AS main_desc, jei.description AS item_desc,
                   jei.type, jei.amount, je.status, je.created_at, je.created_by
              FROM journal_entry_items jei
              JOIN journal_entries je ON jei.entry_id = je.entry_id
             WHERE jei.account_id = :acc1 AND je.status = 'posted'
               AND je.entry_date BETWEEN :from1 AND :to1
            UNION ALL
            -- 2) Header debit leg — only posted entries with no item lines
            SELECT je.entry_id, je.entry_date, je.reference_number,
                   je.description AS main_desc, je.description AS item_desc,
                   'debit' AS type, je.amount, je.status, je.created_at, je.created_by
              FROM journal_entries je
             WHERE je.debit_account_id = :acc2 AND je.status = 'posted'
               AND je.entry_date BETWEEN :from2 AND :to2
               AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id = je.entry_id)
            UNION ALL
            -- 3) Header credit leg — only posted entries with no item lines
            SELECT je.entry_id, je.entry_date, je.reference_number,
                   je.description AS main_desc, je.description AS item_desc,
                   'credit' AS type, je.amount, je.status, je.created_at, je.created_by
              FROM journal_entries je
             WHERE je.credit_account_id = :acc3 AND je.status = 'posted'
               AND je.entry_date BETWEEN :from3 AND :to3
               AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id = je.entry_id)
      ) mv
      LEFT JOIN users u ON mv.created_by = u.user_id
     ORDER BY mv.entry_date ASC, mv.entry_id ASC
");
$ledger_stmt->execute([
    ':acc1' => $account_id, ':from1' => $date_from, ':to1' => $date_to,
    ':acc2' => $account_id, ':from2' => $date_from, ':to2' => $date_to,
    ':acc3' => $account_id, ':from3' => $date_from, ':to3' => $date_to,
]);
$transactions = $ledger_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Contra account per line ("the other side") ───────────────────────────────
// Professional ledgers show WHERE each amount went/came from. For each entry on
// this account, the contra is the opposite leg(s): the other accounts on the same
// journal entry (items), or the other header account (header-only entries). When
// an entry has several opposite legs we show "Split" + the count.
$contra = [];
if ($transactions) {
    $entryIds = array_values(array_unique(array_map(fn($t) => (int)$t['entry_id'], $transactions)));
    $place = implode(',', array_fill(0, count($entryIds), '?'));

    // Items-side opposite legs (account != this account), grouped per entry.
    $ci = $pdo->prepare("
        SELECT ji.entry_id, ji.type, a.account_code, a.account_name, a.account_id
          FROM journal_entry_items ji
          JOIN accounts a ON ji.account_id = a.account_id
         WHERE ji.entry_id IN ($place) AND ji.account_id <> ?
    ");
    $ci->execute(array_merge($entryIds, [$account_id]));
    $byEntry = [];
    foreach ($ci->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byEntry[(int)$r['entry_id']][] = $r;
    }

    // Header-only entries: the contra is the other header account.
    $ch = $pdo->prepare("
        SELECT je.entry_id, je.debit_account_id, je.credit_account_id,
               da.account_code AS d_code, da.account_name AS d_name,
               ca.account_code AS c_code, ca.account_name AS c_name
          FROM journal_entries je
          LEFT JOIN accounts da ON je.debit_account_id  = da.account_id
          LEFT JOIN accounts ca ON je.credit_account_id = ca.account_id
         WHERE je.entry_id IN ($place)
           AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id = je.entry_id)
    ");
    $ch->execute($entryIds);
    $headerContra = [];
    foreach ($ch->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $headerContra[(int)$r['entry_id']] = $r;
    }

    foreach ($entryIds as $eid) {
        if (!empty($byEntry[$eid])) {
            $legs = $byEntry[$eid];
            if (count($legs) === 1) {
                $contra[$eid] = ['label' => $legs[0]['account_code'] . ' ' . $legs[0]['account_name'], 'id' => (int)$legs[0]['account_id'], 'split' => false];
            } else {
                $contra[$eid] = ['label' => 'Split — ' . count($legs) . ' accounts', 'id' => null, 'split' => true];
            }
        } elseif (isset($headerContra[$eid])) {
            // For this account's line, the contra is whichever header account isn't us.
            $h = $headerContra[$eid];
            if ((int)$h['debit_account_id'] === (int)$account_id) {
                $contra[$eid] = ['label' => trim(($h['c_code'] ?? '') . ' ' . ($h['c_name'] ?? '')), 'id' => (int)($h['credit_account_id'] ?? 0), 'split' => false];
            } else {
                $contra[$eid] = ['label' => trim(($h['d_code'] ?? '') . ' ' . ($h['d_name'] ?? '')), 'id' => (int)($h['debit_account_id'] ?? 0), 'split' => false];
            }
        }
    }
}

// Calculate Running Balance
$running_balance = $account['opening_balance'];
// "Balance before" the period — net debit movement prior to date_from, from the
// SAME unified source (items + header-only) so the opening figure is complete.
$bal_before_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN mv.type = 'debit' THEN mv.amount ELSE -mv.amount END), 0) AS net_change
      FROM (
            SELECT jei.type, jei.amount
              FROM journal_entry_items jei
              JOIN journal_entries je ON jei.entry_id = je.entry_id
             WHERE jei.account_id = :acc1 AND je.status = 'posted' AND je.entry_date < :from1
            UNION ALL
            SELECT 'debit' AS type, je.amount
              FROM journal_entries je
             WHERE je.debit_account_id = :acc2 AND je.status = 'posted' AND je.entry_date < :from2
               AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id = je.entry_id)
            UNION ALL
            SELECT 'credit' AS type, je.amount
              FROM journal_entries je
             WHERE je.credit_account_id = :acc3 AND je.status = 'posted' AND je.entry_date < :from3
               AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id = je.entry_id)
      ) mv
");
$bal_before_stmt->execute([
    ':acc1' => $account_id, ':from1' => $date_from,
    ':acc2' => $account_id, ':from2' => $date_from,
    ':acc3' => $account_id, ':from3' => $date_from,
]);
$net_change_before = $bal_before_stmt->fetchColumn() ?: 0;

$is_debit_primary = in_array(strtolower($account['type_name']), ['asset', 'expense']);
if (!$is_debit_primary) {
    $net_change_before = -$net_change_before;
}

$opening_period_balance = $account['opening_balance'] + $net_change_before;
$current_run_bal = $opening_period_balance;

// ── Period reconciliation totals (Opening + Dr − Cr = Closing) ────────────────
$period_total_debit  = 0.0;
$period_total_credit = 0.0;
foreach ($transactions as $t) {
    if ($t['type'] === 'debit')  $period_total_debit  += (float)$t['amount'];
    else                          $period_total_credit += (float)$t['amount'];
}
$period_net_movement = $is_debit_primary
    ? ($period_total_debit - $period_total_credit)
    : ($period_total_credit - $period_total_debit);
$closing_period_balance = $opening_period_balance + $period_net_movement;
$period_entry_count = count($transactions);

?>

<div class="container-fluid py-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4">
       
        <h3 style="color: #333 !important; font-weight: 700; text-transform: uppercase; margin: 5px 0; font-size: 18pt; letter-spacing: 1px;">GENERAL LEDGER REPORT</h3>
        <h5 class="text-dark fw-bold"><?= htmlspecialchars($account['account_name']) ?> (<?= htmlspecialchars($account['account_code']) ?>)</h5>
        <div style="border-bottom: 4px solid #0d6efd; margin-top: 15px; margin-bottom: 25px; width: 150px; margin-left: auto; margin-right: auto;"></div>
    </div>
    <!-- Header -->
    <div class="row mb-4 align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= getUrl('chart-of-accounts') ?>">Chart of Accounts</a></li>
                    <?php if ($parent): ?>
                    <li class="breadcrumb-item"><a href="<?= getUrl('accounts/account_details') ?>?account_id=<?= (int)$parent['account_id'] ?>"><?= htmlspecialchars($parent['account_code'] . ' ' . $parent['account_name']) ?></a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($account['account_name']) ?></li>
                </ol>
            </nav>
            <h2 class="fw-bold mb-0">
                <span class="text-muted small fw-normal"><?= htmlspecialchars($account['account_code']) ?></span> -
                <?= htmlspecialchars($account['account_name']) ?>
                <?php if (count($children) > 0): ?>
                    <span class="badge bg-primary bg-opacity-10 text-primary align-middle ms-2" style="font-size:.6em;"><i class="bi bi-diagram-3"></i> Parent · <?= count($children) ?> sub-accounts</span>
                <?php else: ?>
                    <span class="badge bg-secondary bg-opacity-10 text-secondary align-middle ms-2" style="font-size:.6em;">Postable account</span>
                <?php endif; ?>
            </h2>
            <div class="d-flex flex-wrap align-items-baseline gap-3 mt-1">
                <span class="text-muted small">
                    <?= htmlspecialchars($account['type_display'] ?: ($account['type_name'] ?? '')) ?>
                    <?php if (!empty($account['category_name'])): ?> · <?= htmlspecialchars($account['category_name']) ?><?php endif; ?>
                </span>
                <span class="badge rounded-pill bg-<?= ($account['status'] ?? 'active') === 'active' ? 'primary' : 'secondary' ?>"><?= ucfirst($account['status'] ?? 'active') ?></span>
                <?php if ($bh_in_sync): ?>
                    <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle" title="Stored balance matches the posted ledger"><i class="bi bi-check-circle-fill me-1"></i>Reconciled</span>
                <?php else: ?>
                    <span class="badge rounded-pill bg-warning-subtle text-warning border border-warning-subtle" title="Stored balance differs from the posted ledger by <?= number_format(abs($bh_difference), 2) ?>"><i class="bi bi-exclamation-triangle-fill me-1"></i>Drift: <?= number_format($bh_difference, 2) ?></span>
                <?php endif; ?>
                <?php if (count($children) > 0): ?>
                <span class="ms-auto text-end">
                    <span class="text-muted small text-uppercase d-block" style="font-size:.7rem;">Group balance (incl. sub-accounts)</span>
                    <span class="h4 fw-bold mb-0 <?= $rollup_total < 0 ? 'text-danger' : 'text-primary' ?>"><?= format_currency($rollup_total) ?></span>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-auto">
            <div class="d-flex gap-2">
                <?php if (!$bh_in_sync && canEdit('chart_of_accounts')): ?>
                <button onclick="reconcileAccount()" class="btn btn-warning border shadow-sm" title="Re-sync the stored balance to the posted ledger">
                    <i class="bi bi-arrow-repeat me-1"></i> Reconcile
                </button>
                <?php endif; ?>
                <a href="<?= buildUrl('api/account/export_account_ledger.php') ?>?account_id=<?= (int)$account_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>" class="btn btn-light border shadow-sm" onclick="logReportAction('Exported Account Ledger', 'CSV export for account #<?= $account_id ?>')">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                </a>
                <button onclick="printLedger()" class="btn btn-light border shadow-sm">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
                <?php if (canEdit('chart_of_accounts')): ?>
                <a href="<?= getUrl('chart-of-accounts') ?>?edit=<?= $account_id ?>" class="btn btn-primary" onclick="logReportAction('Initiated Account Edit', 'User clicked edit from account details for account #<?= $account_id ?>')">
                    <i class="bi bi-pencil"></i> Edit Account
                </a>
                <?php endif; ?>
                <a href="<?= getUrl('chart-of-accounts') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>
    </div>

    <!-- Info Cards Removed as requested -->
    <?php /* 
    <div class="row g-3 mb-4 stat-cards-row">
        ...
    </div>
    */ ?>

    <div class="row">
        <!-- Sidebar Filters -->
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Period Filter</h6>
                </div>
                <div class="card-body">
                    <form method="GET" action="">
                        <input type="hidden" name="account_id" value="<?= $account_id ?>">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">From Date</label>
                            <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">To Date</label>
                            <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Update View</button>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Account Description</h6>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-0">
                        <?= nl2br(htmlspecialchars($account['description'] ?: 'No description provided for this account.')) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Ledger Table -->
        <div class="col-lg-9">

            <?php if (count($children) > 0): ?>
            <!-- Account Composition: how this parent's balance is made up of its sub-accounts -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-pie-chart-fill text-primary me-1"></i> Account Composition</h6>
                    <?php if (canCreate('chart_of_accounts')): ?>
                    <a href="<?= getUrl('chart-of-accounts') ?>?add_child=<?= $account_id ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-circle me-1"></i> Add sub-account</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <!-- Group vs own -->
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <div class="p-3 rounded" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                                <div class="text-muted small text-uppercase" style="font-size:.7rem;">Group balance (incl. sub-accounts)</div>
                                <div class="h3 fw-bold mb-0 <?= $rollup_total < 0 ? 'text-danger' : 'text-primary' ?>"><?= format_currency($rollup_total) ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 rounded border h-100">
                                <div class="text-muted small text-uppercase" style="font-size:.7rem;">This account's own balance</div>
                                <div class="h5 fw-semibold mb-0 mt-1"><?= format_currency($own_balance) ?></div>
                                <div class="text-muted" style="font-size:.72rem;">Direct postings to this account only</div>
                            </div>
                        </div>
                    </div>

                    <!-- 100% stacked contribution bar -->
                    <?php if (abs($rollup_total) > 0.0001): ?>
                    <div class="d-flex rounded overflow-hidden mb-1 shadow-sm" style="height:20px;">
                        <?php foreach ($children as $i => $c): if (($c['pct'] ?? 0) <= 0) continue; ?>
                        <div style="width:<?= $c['pct'] ?>%;background:<?= $palette[$i % count($palette)] ?>;" title="<?= htmlspecialchars($c['account_name']) ?>: <?= $c['pct'] ?>%"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-muted mb-3" style="font-size:.72rem;">Each segment is a sub-account's share of the group balance.</div>
                    <?php else: ?>
                    <div class="alert alert-light border small mb-3"><i class="bi bi-info-circle me-1"></i> No balances yet — each sub-account's contribution will appear here once transactions are posted.</div>
                    <?php endif; ?>

                    <!-- Breakdown table -->
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr class="text-muted text-uppercase" style="font-size:.7rem;">
                                    <th style="width:26px;"></th>
                                    <th>Sub-Account</th>
                                    <th>Description</th>
                                    <th class="text-end">Balance</th>
                                    <th style="width:170px;">Share of group</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($children as $i => $c): ?>
                                <tr>
                                    <td><span class="d-inline-block rounded-circle" style="width:12px;height:12px;background:<?= $palette[$i % count($palette)] ?>;"></span></td>
                                    <td>
                                        <a href="<?= getUrl('accounts/account_details') ?>?account_id=<?= (int)$c['account_id'] ?>" class="text-decoration-none fw-semibold text-reset">
                                            <span class="text-muted small"><?= htmlspecialchars($c['account_code']) ?></span>
                                            <?= htmlspecialchars($c['account_name']) ?>
                                        </a>
                                        <?php if ($c['grandchildren'] > 0): ?>
                                            <a href="<?= getUrl('accounts/account_details') ?>?account_id=<?= (int)$c['account_id'] ?>" class="badge bg-light text-primary border text-decoration-none ms-1" title="Drill into its own sub-accounts"><i class="bi bi-diagram-3"></i> <?= (int)$c['grandchildren'] ?></a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?= $c['description'] ? htmlspecialchars($c['description']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-end fw-semibold <?= $c['subtree_total'] < 0 ? 'text-danger' : '' ?>"><?= format_currency($c['subtree_total']) ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height:6px;">
                                                <div class="progress-bar" role="progressbar" style="width:<?= max(0, min(100, $c['pct'])) ?>%;background:<?= $palette[$i % count($palette)] ?>;"></div>
                                            </div>
                                            <span class="text-muted small" style="width:42px;text-align:right;"><?= $c['pct'] ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="border-top">
                                    <td></td>
                                    <td class="fw-bold">Group total</td>
                                    <td class="text-muted small">incl. this account's own <?= format_currency($own_balance) ?></td>
                                    <td class="text-end fw-bold"><?= format_currency($rollup_total) ?></td>
                                    <td class="text-end fw-bold">100%</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Period reconciliation summary: Opening + Dr − Cr = Closing -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small text-uppercase" style="font-size:.68rem;">Opening Balance</div>
                            <div class="h5 fw-bold mb-0 mt-1"><?= number_format($opening_period_balance, 2) ?></div>
                            <div class="text-muted" style="font-size:.68rem;"><?= date('M d, Y', strtotime($date_from)) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small text-uppercase" style="font-size:.68rem;">Total Debits</div>
                            <div class="h5 fw-bold mb-0 mt-1 text-danger"><?= number_format($period_total_debit, 2) ?></div>
                            <div class="text-muted" style="font-size:.68rem;"><?= $period_entry_count ?> entr<?= $period_entry_count === 1 ? 'y' : 'ies' ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small text-uppercase" style="font-size:.68rem;">Total Credits</div>
                            <div class="h5 fw-bold mb-0 mt-1 text-success"><?= number_format($period_total_credit, 2) ?></div>
                            <div class="text-muted" style="font-size:.68rem;">Net <?= ($period_net_movement < 0 ? '−' : '') . number_format(abs($period_net_movement), 2) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                        <div class="card-body py-3">
                            <div class="text-muted small text-uppercase" style="font-size:.68rem;">Closing Balance</div>
                            <div class="h5 fw-bold mb-0 mt-1 <?= $closing_period_balance < 0 ? 'text-danger' : 'text-primary' ?>"><?= number_format($closing_period_balance, 2) ?></div>
                            <div class="text-muted" style="font-size:.68rem;"><?= date('M d, Y', strtotime($date_to)) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($is_ap_control && count($apSubLedger) > 0): ?>
            <!-- ── AP Sub-Ledger: one card per vendor ───────────────────────── -->
            <div class="card border-0 shadow-sm mb-4" style="border-top:3px solid #0d6efd!important;">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-people-fill text-primary me-2"></i>Payable by Vendor — Sub-Ledger</h6>
                    <span class="badge bg-primary rounded-pill"><?= count($apSubLedger) ?> vendor<?= count($apSubLedger) !== 1 ? 's' : '' ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">#</th>
                                    <th>Vendor</th>
                                    <th>Type</th>
                                    <th class="text-center">Open Invoices</th>
                                    <th class="text-end">Total Billed</th>
                                    <th class="text-end">Total Paid</th>
                                    <th class="text-end pe-2">Balance</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $currency = get_setting('currency', 'TZS'); $slSn = 1; ?>
                            <?php foreach ($apSubLedger as $v): ?>
                                <?php
                                    $vType  = $v['vendor_type'] === 'sub_contractor' ? 'Sub-contractor' : 'Supplier';
                                    $vClass = $v['vendor_type'] === 'sub_contractor' ? 'bg-purple text-white' : 'bg-primary text-white';
                                    $stmtUrl = getUrl('vendor_statement') . '?vendor_id=' . (int)$v['supplier_id'] . '&vendor_type=' . urlencode($v['vendor_type']);
                                ?>
                                <tr>
                                    <td class="ps-4 text-muted small"><?= $slSn++ ?></td>
                                    <td class="fw-semibold"><?= safe_output($v['party_name'] ?: '—') ?></td>
                                    <td><span class="badge <?= $v['vendor_type'] === 'sub_contractor' ? 'bg-secondary' : 'bg-primary' ?>"><?= $vType ?></span></td>
                                    <td class="text-center">
                                        <?php if ((int)$v['open_count'] > 0): ?>
                                            <span class="badge bg-warning text-dark"><?= (int)$v['open_count'] ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= $currency . ' ' . number_format((float)$v['total_billed'], 2) ?></td>
                                    <td class="text-end text-success"><?= $currency . ' ' . number_format((float)$v['total_paid'], 2) ?></td>
                                    <td class="text-end pe-2 fw-bold <?= $v['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= $currency . ' ' . number_format($v['balance'], 2) ?>
                                    </td>
                                    <td class="pe-3">
                                        <a href="<?= $stmtUrl ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-file-earmark-text me-1"></i> View Account
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="4" class="ps-4">Totals</td>
                                    <td class="text-end"><?= $currency . ' ' . number_format(array_sum(array_column($apSubLedger, 'total_billed')), 2) ?></td>
                                    <td class="text-end text-success"><?= $currency . ' ' . number_format(array_sum(array_column($apSubLedger, 'total_paid')), 2) ?></td>
                                    <td class="text-end pe-2 text-danger"><?= $currency . ' ' . number_format(array_sum(array_column($apSubLedger, 'balance')), 2) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2 d-print-none">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text text-primary me-1"></i> <?= $is_ap_control ? 'Full GL Ledger' : (count($children) > 0 ? "This account's own ledger" : 'Ledger') ?></h6>
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Filter by side">
                            <input type="radio" class="btn-check" name="ledgerSide" id="side_all" value="" checked onchange="applyLedgerSide(this.value)">
                            <label class="btn btn-outline-secondary" for="side_all">All</label>
                            <input type="radio" class="btn-check" name="ledgerSide" id="side_debit" value="debit" onchange="applyLedgerSide(this.value)">
                            <label class="btn btn-outline-danger" for="side_debit">Debits</label>
                            <input type="radio" class="btn-check" name="ledgerSide" id="side_credit" value="credit" onchange="applyLedgerSide(this.value)">
                            <label class="btn btn-outline-success" for="side_credit">Credits</label>
                        </div>
                        <input type="search" id="ledgerSearch" class="form-control form-control-sm" style="max-width:200px;" placeholder="Search reference / description…">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="ledgerTable">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width: 20px;"></th> <!-- Control Column -->
                                    <th style="width: 70px;">S/NO</th>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Description</th>
                                    <th>Contra Account</th>
                                    <th class="text-end">Debit</th>
                                    <th class="text-end">Credit</th>
                                    <th class="text-end pe-4">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Opening Balance Row -->
                                <tr class="opening-row table-info bg-opacity-10">
                                    <td></td> <!-- Control cell -->
                                    <td class="text-center text-muted small fw-bold">-</td>
                                    <td>
                                        <small class="text-muted"><?= date('M d, Y', strtotime($date_from)) ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary">OPENING</span></td>
                                    <td class="fw-bold">Balance Brought Forward</td>
                                    <td class="text-muted">-</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end fw-bold pe-4"><?= number_format($opening_period_balance, 2) ?></td>
                                </tr>

                                    <?php $sn = 1; ?>
                                <?php if (count($transactions) > 0): ?>
                                    <?php foreach ($transactions as $tx): 
                                        $debit = $tx['type'] === 'debit' ? $tx['amount'] : 0;
                                        $credit = $tx['type'] === 'credit' ? $tx['amount'] : 0;
                                        
                                        // Update dynamic balance based on account type
                                        if ($is_debit_primary) {
                                            $current_run_bal += ($debit - $credit);
                                        } else {
                                            $current_run_bal += ($credit - $debit);
                                        }
                                    ?>
                                    <tr data-side="<?= htmlspecialchars($tx['type']) ?>">
                                        <td></td> <!-- Control cell -->
                                        <td class="text-center text-muted small fw-bold"><?= $sn++ ?></td>
                                        <td><?= date('M d, Y', strtotime($tx['entry_date'])) ?></td>
                                        <td>
                                            <a href="<?= getUrl('transaction/view') ?>?id=<?= $tx['entry_id'] ?>" class="text-decoration-none">
                                                <code><?= htmlspecialchars($tx['reference_number'] ?: '#' . $tx['entry_id']) ?></code>
                                            </a>
                                            <?php if (!empty($tx['posted_by']) || !empty($tx['posted_at'])): ?>
                                            <div class="text-muted" style="font-size:.66rem;" title="Audit trail">
                                                <i class="bi bi-person-check"></i> <?= htmlspecialchars($tx['posted_by'] ?: 'system') ?><?php if (!empty($tx['posted_at'])): ?> · <?= date('M d, Y H:i', strtotime($tx['posted_at'])) ?><?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($tx['main_desc']) ?></div>
                                            <?php if ($tx['item_desc'] && $tx['item_desc'] !== $tx['main_desc']): ?>
                                                <small class="text-muted"><?= htmlspecialchars($tx['item_desc']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php $cx = $contra[(int)$tx['entry_id']] ?? null; ?>
                                            <?php if ($cx && $cx['split']): ?>
                                                <span class="badge bg-light text-secondary border" title="This entry hits several accounts"><i class="bi bi-diagram-2"></i> <?= htmlspecialchars($cx['label']) ?></span>
                                            <?php elseif ($cx && !empty($cx['id'])): ?>
                                                <a href="<?= getUrl('accounts/account_details') ?>?account_id=<?= (int)$cx['id'] ?>" class="text-decoration-none small"><?= htmlspecialchars($cx['label']) ?></a>
                                            <?php elseif ($cx): ?>
                                                <span class="small text-muted"><?= htmlspecialchars($cx['label']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-danger"><?= $debit > 0 ? number_format($debit, 2) : '-' ?></td>
                                        <td class="text-end text-success"><?= $credit > 0 ? number_format($credit, 2) : '-' ?></td>
                                        <td class="text-end fw-bold pe-4"><?= number_format($current_run_bal, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- 9 explicit cells (no colspan) so DataTables' per-row column
                                         count stays consistent with the 9-column header. -->
                                    <tr class="empty-row">
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                        <td class="text-center py-4 text-muted">No transactions found for this period.</td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <!-- 9 explicit cells (NO colspan): DataTables maps one footer
                                     cell per column and miscounts colspan'd footers (tn/18). -->
                                <tr>
                                    <td></td>
                                    <td></td>
                                    <td class="ps-4 text-nowrap">Period Totals &amp; Ending Balance</td>
                                    <td class="text-muted small"><?= date('M d, Y', strtotime($date_to)) ?></td>
                                    <td></td>
                                    <td></td>
                                    <td class="text-end text-danger"><?= number_format($period_total_debit, 2) ?></td>
                                    <td class="text-end text-success"><?= number_format($period_total_credit, 2) ?></td>
                                    <td class="text-end pe-4 h5 mb-0 fw-bold text-primary"><?= number_format($current_run_bal, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    .card-header:first-child { border-radius: 12px 12px 0 0; }
    .table thead th { font-size: 0.75rem; text-uppercase: uppercase; letter-spacing: 0.5px; }
    
    @page { margin: 10mm 8mm 16mm 8mm; }

    /* Custom Green Stat Card Theme */
    .custom-stat-card {
        background-color: #d1e7dd !important;
        border-color: #badbcc !important;
        border-radius: 12px;
        transition: transform 0.2s;
    }
    .custom-stat-card:hover { transform: translateY(-3px); }
    .custom-stat-card div, 
    .custom-stat-card h4, 
    .custom-stat-card h5 {
        color: #0f5132 !important;
        font-weight: 600;
    }
    
    .custom-badge {
        background-color: #0f5132 !important;
        color: #d1e7dd !important;
        padding: 4px 12px;
        border-radius: 6px;
        font-weight: 500;
        display: inline-block;
        font-size: 0.85rem;
    }

    @media print {
        .col-lg-3, .btn, .breadcrumb, header, footer, .navbar, .sidebar { display: none !important; }
        .col-lg-9 { width: 100% !important; }
        .container-fluid { padding: 0 !important; }
        .card { box-shadow: none !important; border: 1px solid #eee !important; }
        body { background: white !important; font-size: 10pt; }
        
        /* Force stat cards into one row on print */
        .stat-cards-row {
            display: flex !important;
            flex-wrap: nowrap !important;
            gap: 10px !important;
        }
        .stat-cards-row > div {
            flex: 1 1 0 !important;
            width: 25% !important;
            max-width: 25% !important;
        }
        .custom-stat-card {
            background-color: #d1e7dd !important;
            -webkit-print-color-adjust: exact;
            border: 1px solid #badbcc !important;
            padding: 10px !important;
        }
        .custom-stat-card .card-body { padding: 5px !important; }
        .custom-stat-card h4, .custom-stat-card h5 { font-size: 1.2rem !important; }
        
        .table { width: 100% !important; }
        .table thead th { background-color: #f8f9fa !important; -webkit-print-color-adjust: exact; }
    }
</style>

<script>
    $(document).ready(function() {
        // Log page view
        logReportAction('Viewed Account Ledger', 'User viewed ledger for account: <?= htmlspecialchars($account['account_name']) ?> (ID: <?= $account_id ?>)');
        
        // Initialize DataTable
        const table = $('#ledgerTable').DataTable({
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search ledger...",
                lengthMenu: "Show _MENU_",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
            },
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            columnDefs: [
                { className: 'dtr-control', orderable: false, targets: 0 },
                { 
                    className: 'text-center fw-bold text-dark', 
                    targets: 1,
                    data: null,
                    orderable: false,
                    responsivePriority: 1,
                    render: (data, type, row, meta) => {
                        // Return incremental number for ANY row in the table body
                        return meta.row + meta.settings._iDisplayStart + 1;
                    }
                },
                { responsivePriority: 1, targets: 8 }, // Balance
                { responsivePriority: 2, targets: 2 }, // Date
                { responsivePriority: 3, targets: 3 }, // Reference
                { responsivePriority: 9, targets: 5 }, // Contra Account
                { responsivePriority: 10, targets: 4 }, // Description
                { responsivePriority: 10, targets: 6 }, // Debit
                { responsivePriority: 10, targets: 7 }  // Credit
            ],
            order: [], // Keep original chronological order from SQL
            pageLength: 50,
            dom: 'rtip',
            drawCallback: function() {
                this.api().responsive.recalc();
            }
        });
        window.ledgerTable = table;

        // Side filter (Debits / Credits / All) — uses each row's data-side, and
        // always keeps the OPENING row (no data-side) visible for context.
        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
            if (settings.nTable.id !== 'ledgerTable') return true;
            const want = window._ledgerSide || '';
            if (!want) return true;
            const row = settings.aoData[dataIndex].nTr;
            const side = row.getAttribute('data-side');
            if (side === null) return true;            // opening row → always show
            return side === want;
        });

        // Free-text search box → DataTables global search.
        $('#ledgerSearch').on('input', function () { table.search(this.value).draw(); });

        // Forced adjustment for visibility
        setTimeout(() => {
            if (table) table.columns.adjust().responsive.recalc();
        }, 300);
    });

    function applyLedgerSide(side) {
        window._ledgerSide = side || '';
        if (window.ledgerTable) window.ledgerTable.draw();
    }

    function printLedger() {
        logReportAction('Printed Account Ledger', 'User printed ledger for account: <?= htmlspecialchars($account['account_name']) ?>');
        window.print();
    }

    // Re-sync this account's stored balance to the posted ledger (drift fix).
    function reconcileAccount() {
        Swal.fire({
            title: 'Reconcile balance?',
            text: 'This re-syncs the stored balance to the posted ledger. Postings are unaffected.',
            icon: 'question', showCancelButton: true,
            confirmButtonText: 'Yes, reconcile', confirmButtonColor: '#0d6efd'
        }).then(r => {
            if (!r.isConfirmed) return;
            $.ajax({
                url: '<?= buildUrl('api/account/reconcile_account.php') ?>',
                type: 'POST', dataType: 'json',
                data: { account_id: <?= (int)$account_id ?>, _csrf: CSRF_TOKEN },
                success: function (res) {
                    if (res.success) {
                        logReportAction('Reconciled Account', 'Account #<?= $account_id ?> balance reconciled to the ledger');
                        Swal.fire({ icon: 'success', title: 'Reconciled', text: res.message, timer: 1800, showConfirmButton: false })
                            .then(() => location.reload());
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not reconcile.' });
                    }
                },
                error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); }
            });
        });
    }
</script>

<?php
includeFooter();
ob_end_flush();
?>
