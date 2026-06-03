<?php
/**
 * app/constant/reports/consolidated_expenses.php
 *
 * Consolidated Expenses / Cash-Disbursement view — every money-out event
 * (expense, supplier payment, received-invoice payment, sub-contractor payment,
 * payroll, voucher, petty cash) read from the single `transactions` ledger,
 * each line carrying its source ("Paid From") account.
 *
 * Filters: date range, type, source account. Totals by type + by source.
 * UI follows .claude/ui-constants.md (blue scheme). Print + Excel.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';
includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('financial_reports');
}

// ── Filters ──────────────────────────────────────────────────────────────────
$from   = (!empty($_GET['from'])) ? $_GET['from'] : date('Y-m-01');
$to     = (!empty($_GET['to']))   ? $_GET['to']   : date('Y-m-d');
$f_type = $_GET['type'] ?? '';
$f_src  = isset($_GET['source']) && $_GET['source'] !== '' ? (int)$_GET['source'] : 0;

$OUTFLOW_TYPES = [
    'expense'                  => 'Expense',
    'supplier_payment'         => 'Supplier Payment',
    'received_invoice_payment' => 'Received-Invoice Payment',
    'sc_payment'               => 'Sub-Contractor Payment',
    'payroll'                  => 'Payroll',
    'voucher'                  => 'Payment Voucher',
    'petty_cash'               => 'Petty Cash',
];

$typeKeys = array_keys($OUTFLOW_TYPES);
$where  = ["t.transaction_type IN (" . implode(',', array_fill(0, count($typeKeys), '?')) . ")",
           "t.transaction_date BETWEEN ? AND ?"];
$params = array_merge($typeKeys, [$from, $to]);
if ($f_type !== '' && isset($OUTFLOW_TYPES[$f_type])) { $where[] = "t.transaction_type = ?"; $params[] = $f_type; }
if ($f_src) { $where[] = "src.account_id = ?"; $params[] = $f_src; }
$where_sql = implode(' AND ', $where);

// Source account = the credit line in books_transactions.
$sql = "
    SELECT t.transaction_id, t.transaction_date, t.transaction_type, t.amount,
           t.reference_number, t.description,
           src.account_id AS source_account_id, acc.account_name AS source_account_name
      FROM transactions t
      LEFT JOIN books_transactions src ON src.transaction_id = t.transaction_id AND src.type = 'credit'
      LEFT JOIN accounts acc ON acc.account_id = src.account_id
     WHERE {$where_sql}
  ORDER BY t.transaction_date DESC, t.transaction_id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals.
$grand = 0.0; $byType = []; $bySource = [];
foreach ($rows as $r) {
    $amt = (float)$r['amount'];
    $grand += $amt;
    $byType[$r['transaction_type']] = ($byType[$r['transaction_type']] ?? 0) + $amt;
    $sName = $r['source_account_name'] ?: '— (no source)';
    $bySource[$sName] = ($bySource[$sName] ?? 0) + $amt;
}
function ce_money($v) { return number_format((float)$v, 0); }

$cash_accounts = cashBankAccounts($pdo);

if (function_exists('logActivity')) {
    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed Consolidated Expenses', "from $from to $to");
}
?>

<div class="container-fluid py-4">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Consolidated Expenses</li>
        </ol>
    </nav>

    <!-- Print-only heading (logo/name come from the shared print header) -->
    <div class="d-none d-print-block text-center mb-4">
        <h3 style="margin:0; font-size:13pt; color:#000; text-transform:uppercase; letter-spacing:1px;">
            Consolidated Expenses — <?= date('d.m.Y', strtotime($from)) ?> to <?= date('d.m.Y', strtotime($to)) ?>
        </h3>
    </div>

    <div class="row mb-3 align-items-center d-print-none">
        <div class="col-md-7">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-cash-stack me-2"></i> Consolidated Expenses</h2>
            <p class="text-muted small mb-0">Every money-out event in one place, with the account it was paid from.</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3 d-print-none" style="background:#e7f0ff; border:1px solid #b6ccfe !important;">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get" action="<?= getUrl('reports/consolidated_expenses') ?>">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">From</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= safe_output($from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">To</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= safe_output($to) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        <?php foreach ($OUTFLOW_TYPES as $k => $label): ?>
                        <option value="<?= $k ?>" <?= $f_type === $k ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Source Account</label>
                    <select name="source" class="form-select form-select-sm">
                        <option value="">All sources</option>
                        <?php foreach ($cash_accounts as $acc): ?>
                        <option value="<?= (int)$acc['account_id'] ?>" <?= $f_src === (int)$acc['account_id'] ? 'selected' : '' ?>>
                            <?= safe_output($acc['account_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Totals cards -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary"><?= ce_money($grand) ?></div>
                <div class="small text-muted">Total Out (TZS)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary"><?= count($rows) ?></div>
                <div class="small text-muted">Transactions</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary"><?= count($byType) ?></div>
                <div class="small text-muted">Expense Types</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary"><?= count($bySource) ?></div>
                <div class="small text-muted">Source Accounts</div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main ledger -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold"><i class="bi bi-list-ul me-1 text-primary"></i> Disbursements</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="ceTable" class="table table-hover align-middle mb-0 w-100">
                            <thead class="bg-light text-uppercase small fw-bold text-muted">
                                <tr>
                                    <th class="ps-3">Date</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Reference</th>
                                    <th>Paid From</th>
                                    <th class="text-end pe-3">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No disbursements in this period.</td></tr>
                                <?php else: foreach ($rows as $r): ?>
                                <tr>
                                    <td class="ps-3"><?= safe_output($r['transaction_date']) ?></td>
                                    <td><span class="badge bg-primary-subtle text-primary-emphasis border"><?= safe_output($OUTFLOW_TYPES[$r['transaction_type']] ?? $r['transaction_type']) ?></span></td>
                                    <td class="small"><?= safe_output($r['description'] ?: '—') ?></td>
                                    <td class="small"><?= safe_output($r['reference_number'] ?: '—') ?></td>
                                    <td class="small"><?= safe_output($r['source_account_name'] ?: '—') ?></td>
                                    <td class="text-end pe-3 fw-semibold"><?= ce_money($r['amount']) ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-primary fw-bold">
                                    <td colspan="5" class="text-end">TOTAL</td>
                                    <td class="text-end pe-3"><?= ce_money($grand) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Breakdowns -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-bold"><i class="bi bi-pie-chart me-1 text-primary"></i> By Type</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <?php arsort($byType); foreach ($byType as $k => $v): ?>
                            <tr><td class="ps-3"><?= safe_output($OUTFLOW_TYPES[$k] ?? $k) ?></td><td class="text-end pe-3 fw-semibold"><?= ce_money($v) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold"><i class="bi bi-bank me-1 text-primary"></i> By Source Account</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <?php arsort($bySource); foreach ($bySource as $k => $v): ?>
                            <tr><td class="ps-3 small"><?= safe_output($k) ?></td><td class="text-end pe-3 fw-semibold"><?= ce_money($v) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {
    if (!$.fn.DataTable.isDataTable('#ceTable') && <?= $rows ? 'true' : 'false' ?>) {
        $('#ceTable').DataTable({
            responsive: false, scrollX: true, pageLength: 25, order: [],
            dom: 'rtipB',
            buttons: [{ extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }],
            language: { emptyTable: 'No disbursements.', zeroRecords: 'No matching disbursements.' }
        });
    }
});
</script>

<?php includeFooter(); ?>
