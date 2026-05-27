<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

// Use existing permission mapping
autoEnforcePermission('ledger_report');

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date   = $_GET['end_date']   ?? date('Y-12-31');
$account_id = $_GET['account_id'] ?? '';

try {
    // Fetch accounts for filter
    $acc_stmt = $pdo->query("SELECT account_id, account_name, account_code FROM accounts WHERE status='active' ORDER BY account_code ASC");
    $all_accounts = $acc_stmt->fetchAll(PDO::FETCH_ASSOC);

    $where = "WHERE je.entry_date BETWEEN ? AND ? AND je.status = 'posted'";
    $params = [$start_date, $end_date];

    if ($account_id) {
        $where .= " AND jei.account_id = ?";
        $params[] = $account_id;
    }

    // Main Query: Journal entries with account details
    $sql = "SELECT je.entry_date, je.reference_number as entry_number, jei.account_id, a.account_code, a.account_name,
                   jei.type, jei.amount, jei.description, je.entry_id
            FROM journal_entries je
            JOIN journal_entry_items jei ON je.entry_id = jei.entry_id
            JOIN accounts a ON jei.account_id = a.account_id
            $where
            ORDER BY je.entry_date ASC, je.entry_id ASC, jei.item_id ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Opening Balance logic if account selected
    $opening_balance = 0;
    if ($account_id) {
        $ob_sql = "SELECT 
                    SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE -jei.amount END) as balance
                   FROM journal_entry_items jei
                   JOIN journal_entries je ON jei.entry_id = je.entry_id
                   WHERE jei.account_id = ? AND je.entry_date < ? AND je.status = 'posted'";
        $ob_stmt = $pdo->prepare($ob_sql);
        $ob_stmt->execute([$account_id, $start_date]);
        $opening_balance = floatval($ob_stmt->fetchColumn() ?: 0);
        
        // Add opening balance from account table if applicable (depending on system design)
        $acc_info_stmt = $pdo->prepare("SELECT opening_balance FROM accounts WHERE account_id = ?");
        $acc_info_stmt->execute([$account_id]);
        $opening_balance += floatval($acc_info_stmt->fetchColumn() ?: 0);
    }

    $total_debit = 0;
    $total_credit = 0;
    foreach($entries as $e) {
        if($e['type'] == 'debit') $total_debit += floatval($e['amount']);
        else $total_credit += floatval($e['amount']);
    }
    
    $net_change = $total_debit - $total_credit;
    $closing_balance = $opening_balance + $net_change;

} catch (Exception $e) { 
    $error = $e->getMessage(); 
    $entries = []; 
    $all_accounts = [];
    $total_debit = $total_credit = $opening_balance = $closing_balance = 0; 
}
?>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">GENERAL LEDGER REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Comprehensive record of all journal transactions across registered accounts.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div class="row g-2">
            <?php if($account_id): ?>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Opening Balance</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($opening_balance) ?></h4>
                </div>
            </div>
            <?php endif; ?>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Debits</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_debit) ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Credits</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_credit) ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;"><?= $account_id ? 'Closing Balance' : 'Net Movement' ?></p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($account_id ? $closing_balance : $net_change) ?></h4>
                </div>
            </div>
        </div>
    </div>
    <!-- Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-journal-richtext me-2"></i>General Ledger</h2>
            <p class="text-muted mb-0">Detailed historical record of financial movements</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer-fill me-2"></i> Print Ledger
            </button>
            <button class="btn btn-dark shadow-sm px-4 fw-bold ms-2" onclick="exportCSV()">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> Export CSV
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-radius: 15px; background: #fdfdfd;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Fiscal Start</label>
                    <input type="date" name="start_date" class="form-control rounded-pill border-light shadow-sm" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Fiscal End</label>
                    <input type="date" name="end_date" class="form-control rounded-pill border-light shadow-sm" value="<?= $end_date ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Filter by Account</label>
                    <select name="account_id" class="form-select rounded-pill border-light shadow-sm select2">
                        <option value="">All Accounts (General View)</option>
                        <?php foreach($all_accounts as $acc): ?>
                            <option value="<?= $acc['account_id'] ?>" <?= $account_id == $acc['account_id'] ? 'selected' : '' ?>>
                                [<?= htmlspecialchars((string)($acc['account_code'] ?? '')) ?>] <?= htmlspecialchars((string)($acc['account_name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm rounded-pill">
                        <i class="bi bi-filter me-1"></i> Apply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4">
        <?php if($account_id): ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Opening Balance</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($opening_balance) ?></h4>
                    <span class="small text-muted fw-bold">Brought Forward</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-md-<?= $account_id ? '3' : '4' ?>">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Debits</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_debit) ?></h4>
                    
                </div>
            </div>
        </div>
        <div class="col-md-<?= $account_id ? '3' : '4' ?>">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Credits</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_credit) ?></h4>
                    
                </div>
            </div>
        </div>
        <div class="col-md-<?= $account_id ? '3' : '4' ?>">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1"><?= $account_id ? 'Closing Balance' : 'Net Movement' ?></p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($account_id ? $closing_balance : $net_change) ?></h4>
                  
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Ledger Table -->
    <div class="card border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            
            <div class="d-print-none">
                <input type="text" id="ledgerSearch" class="form-control form-control-sm px-3 shadow-sm border-light" placeholder="Search entries..." style="width: 250px; border-radius: 20px;">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="ledgerTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 text-muted small text-uppercase">Date</th>
                            <th class="text-muted small text-uppercase">Reference</th>
                            <?php if(!$account_id): ?>
                                <th class="text-muted small text-uppercase">Account</th>
                            <?php endif; ?>
                            <th class="text-muted small text-uppercase pe-4">Description</th>
                            <th class="text-end text-muted small text-uppercase">Debit</th>
                            <th class="text-end text-muted small text-uppercase">Credit</th>
                            <?php if($account_id): ?>
                                <th class="text-end pe-4 text-muted small text-uppercase">Balance</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($account_id): ?>
                            <tr class="table-light italic">
                                <td colspan="<?= $account_id ? '4' : '5' ?>" class="ps-4">Opening Balance Brought Forward</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end pe-4 fw-bold"><?= format_currency($opening_balance) ?></td>
                            </tr>
                        <?php endif; ?>

                        <?php if(empty($entries)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted italic">No ledger records found for this selection.</td></tr>
                        <?php else: 
                            $running_balance = $opening_balance;
                            foreach($entries as $e): 
                                if($e['type'] == 'debit') $running_balance += floatval($e['amount']);
                                else $running_balance -= floatval($e['amount']);
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= date('d M Y', strtotime($e['entry_date'])) ?></div>
                                </td>
                                <td class="font-monospace small text-muted"><?= htmlspecialchars((string)($e['entry_number'] ?? '#-')) ?></td>
                                <?php if(!$account_id): ?>
                                    <td>
                                        <div class="fw-semibold text-primary"><?= htmlspecialchars((string)($e['account_name'] ?? '')) ?></div>
                                        <div class="x-small text-muted">ID: <?= htmlspecialchars((string)($e['account_code'] ?? '')) ?></div>
                                    </td>
                                <?php endif; ?>
                                <td class="pe-4">
                                    <div class="small text-dark"><?= htmlspecialchars((string)($e['description'] ?? '-')) ?></div>
                                </td>
                                <td class="text-end">
                                    <span class="<?= $e['type']=='debit' ? 'fw-bold text-dark' : 'text-muted opacity-50' ?>">
                                        <?= $e['type']=='debit' ? format_currency($e['amount']) : '-' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <span class="<?= $e['type']=='credit' ? 'fw-bold text-danger' : 'text-muted opacity-50' ?>">
                                        <?= $e['type']=='credit' ? format_currency($e['amount']) : '-' ?>
                                    </span>
                                </td>
                                <?php if($account_id): ?>
                                    <td class="text-end pe-4 fw-bold <?= $running_balance < 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= format_currency($running_balance) ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot class="table-light border-top">
                        <tr class="fw-bold">
                            <td colspan="<?= $account_id ? '4' : '4' ?>" class="ps-4 py-3 text-uppercase small text-muted">Totals for selected period</td>
                            <td class="text-end py-3"><?= format_currency($total_debit) ?></td>
                            <td class="text-end py-3 text-danger"><?= format_currency($total_credit) ?></td>
                            <?php if($account_id): ?>
                                <td class="text-end pe-4 py-3 h5 mb-0 <?= $closing_balance < 0 ? 'text-danger' : 'text-success' ?>"><?= format_currency($closing_balance) ?></td>
                            <?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#ledgerSearch').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $("#ledgerTable tbody tr").filter(function() {
            if($(this).hasClass('italic')) return true;
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    if(typeof logReportAction==='function') {
        logReportAction('Viewed Ledger Report', 'Generated ledger records for period <?= $start_date ?> to <?= $end_date ?>');
    }
});

function exportCSV() {
    alert('Generating Ledger CSV Export...');
}
</script>

<style>
    .card { border-radius: 15px; }
    .table thead th { border-top: none; }
    .italic { font-style: italic; }
    .x-small { font-size: 0.72rem; }
    .truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; }
    @media print {
        .d-print-none, .btn, #ledgerSearch, .row.g-3.mb-4 { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; }
        .table td { border: 1px solid #dee2e6 !important; }
        .container-fluid { padding: 0 !important; }
        .badge { color: #000 !important; border: 1px solid #ddd !important; background: transparent !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
