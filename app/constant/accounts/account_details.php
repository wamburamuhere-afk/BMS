<?php
// app/constant/accounts/account_details.php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

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
    SELECT a.*, at.type_name, at.display_name as type_display, ac.category_name
    FROM accounts a
    LEFT JOIN account_types at ON a.account_type_id = at.type_id
    LEFT JOIN account_categories ac ON a.category_id = ac.category_id
    WHERE a.account_id = ?
");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Account not found. <a href='" . getUrl('chart-of-accounts') . "'>Return to chart of accounts</a></div></div>";
    includeFooter();
    exit;
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

// One recursive subtree sum, reused for the parent and each child, so a child
// that itself has sub-accounts contributes its WHOLE branch to the parent.
$subtreeSum = function (int $id) use ($pdo): float {
    $st = $pdo->prepare("
        WITH RECURSIVE subtree AS (
            SELECT account_id, current_balance FROM accounts WHERE account_id = ?
            UNION ALL
            SELECT a.account_id, a.current_balance
              FROM accounts a JOIN subtree s ON a.parent_account_id = s.account_id
             WHERE a.account_id <> a.parent_account_id
        )
        SELECT COALESCE(SUM(current_balance), 0) FROM subtree
    ");
    $st->execute([$id]);
    return (float)$st->fetchColumn();
};

$own_balance  = (float)$account['current_balance'];
$rollup_total = $subtreeSum((int)$account_id);   // own + all descendants

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

// Fetch Transaction History (Ledger)
$ledger_stmt = $pdo->prepare("
    SELECT 
        je.entry_id,
        je.entry_date,
        je.reference_number,
        je.description as main_desc,
        jei.description as item_desc,
        jei.type,
        jei.amount,
        je.status
    FROM journal_entry_items jei
    JOIN journal_entries je ON jei.entry_id = je.entry_id
    WHERE jei.account_id = ?
    AND je.entry_date BETWEEN ? AND ?
    AND je.status = 'posted'
    ORDER BY je.entry_date ASC, je.entry_id ASC
");
$ledger_stmt->execute([$account_id, $date_from, $date_to]);
$transactions = $ledger_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Running Balance
$running_balance = $account['opening_balance'];
// To calculate running balance correctly for the period, we need the "balance before"
$bal_before_stmt = $pdo->prepare("
    SELECT SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE -jei.amount END) as net_change
    FROM journal_entry_items jei
    JOIN journal_entries je ON jei.entry_id = je.entry_id
    WHERE jei.account_id = ?
    AND je.entry_date < ?
    AND je.status = 'posted'
");
$bal_before_stmt->execute([$account_id, $date_from]);
$net_change_before = $bal_before_stmt->fetchColumn() ?: 0;

$is_debit_primary = in_array(strtolower($account['type_name']), ['asset', 'expense']);
if (!$is_debit_primary) {
    $net_change_before = -$net_change_before;
}

$opening_period_balance = $account['opening_balance'] + $net_change_before;
$current_run_bal = $opening_period_balance;

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
                <button onclick="printLedger()" class="btn btn-light border shadow-sm px-4">
                    <i class="bi bi-printer text-primary me-1"></i> Print Ledger
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

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text text-primary me-1"></i> <?= count($children) > 0 ? "This account's own ledger" : 'Ledger' ?></h6>
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
                                    <tr>
                                        <td></td> <!-- Control cell -->
                                        <td class="text-center text-muted small fw-bold"><?= $sn++ ?></td>
                                        <td><?= date('M d, Y', strtotime($tx['entry_date'])) ?></td>
                                        <td>
                                            <a href="<?= getUrl('transaction/view') ?>?id=<?= $tx['entry_id'] ?>" class="text-decoration-none">
                                                <code><?= htmlspecialchars($tx['reference_number'] ?: '#' . $tx['entry_id']) ?></code>
                                            </a>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($tx['main_desc']) ?></div>
                                            <?php if ($tx['item_desc'] && $tx['item_desc'] !== $tx['main_desc']): ?>
                                                <small class="text-muted"><?= htmlspecialchars($tx['item_desc']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-danger"><?= $debit > 0 ? number_format($debit, 2) : '-' ?></td>
                                        <td class="text-end text-success"><?= $credit > 0 ? number_format($credit, 2) : '-' ?></td>
                                        <td class="text-end fw-bold pe-4"><?= number_format($current_run_bal, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr class="empty-row">
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                        <td class="text-center py-4 text-muted">No transactions found for this period.</td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td></td>
                                    <td></td>
                                    <td class="ps-4">Period Ending Balance (<?= date('M d, Y', strtotime($date_to)) ?>)</td>
                                    <td></td>
                                    <td></td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
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
                { responsivePriority: 1, targets: 7 }, // Balance
                { responsivePriority: 2, targets: 2 }, // Date
                { responsivePriority: 3, targets: 3 }, // Reference
                { responsivePriority: 10, targets: 4 }, // Description
                { responsivePriority: 10, targets: 5 }, // Debit
                { responsivePriority: 10, targets: 6 }  // Credit
            ],
            order: [], // Keep original chronological order from SQL
            pageLength: 50,
            dom: 'rtip',
            drawCallback: function() {
                this.api().responsive.recalc();
            }
        });

        // Forced adjustment for visibility
        setTimeout(() => { 
            if (table) table.columns.adjust().responsive.recalc();
        }, 300);
    });

    function printLedger() {
        logReportAction('Printed Account Ledger', 'User printed ledger for account: <?= htmlspecialchars($account['account_name']) ?>');
        window.print();
    }
</script>

<?php
includeFooter();
ob_end_flush();
?>
