<?php
// File: reps/trial_balance.php
// Phase 1.2 — Trial Balance UI partial. Included by reports.php.
//
// Per Corporate Finance Institute the Trial Balance is NOT a formal
// financial statement — it's an internal working document used to verify
// the canonical ledger is internally consistent (Sum Dr = Sum Cr) before
// formal BS / IS are produced. The page header explicitly says so.
//
// Drill-down to General Ledger is intentionally absent in Phase 1.2;
// it will be wired in Phase 2 once GL exists. Shipping a deliberately
// broken link would be worse than no link.

require_once __DIR__ . '/../../../../roots.php';
if (!canView('reports')) {
    http_response_code(403);
    die("Access Denied");
}

$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

// Consume the Trial Balance API internally — same pattern as BS partial.
$saved_get = $_GET;
$_GET = ['as_of_date' => $as_of_date];
if ($project_id !== null) $_GET['project_id'] = (string)$project_id;
ob_start();
require __DIR__ . '/../../../../api/account/get_trial_balance.php';
$tb_raw = ob_get_clean();
$_GET = $saved_get;
$tb  = json_decode($tb_raw, true);
$ok  = $tb && !empty($tb['success']);
$err = $ok ? '' : ($tb['message'] ?? 'Failed to load report');
$d   = $ok ? $tb['data'] : null;

// Projects dropdown via the shared scoped endpoint.
$_GET = [];
ob_start();
require_once __DIR__ . '/../../../../api/account/get_projects_for_filter.php';
$proj_raw = ob_get_clean();
$_GET = $saved_get;
$proj_resp = json_decode($proj_raw, true);
$projects_list = ($proj_resp && !empty($proj_resp['success'])) ? $proj_resp['projects'] : [];

$cur_label = date('d M Y', strtotime($as_of_date));

// Helper: format with negative-paren style? Use plain accountancy formatting.
function tb_fmt(float $n): string {
    return number_format($n, 2);
}

// Pre-group accounts by (statement, category) so the rendered table can
// insert section headers + subtotals between groups.
$grouped = [];     // [statement][category][] = account row
if ($ok) {
    foreach ($d['accounts'] as $a) {
        $s = $a['statement'] ?? '?';
        $c = $a['category']  ?? '?';
        $grouped[$s][$c][] = $a;
    }
}

// Canonical display order for the groups.
$bs_order = [
    'asset'     => 'Assets',
    'liability' => 'Liabilities',
    'equity'    => 'Equity',
];
$is_order = [
    'revenue'   => 'Revenue',
    'cogs'      => 'Cost of Goods Sold',
    'expense'   => 'Expenses',
];

$catLabel = function (string $statement, string $category) use ($bs_order, $is_order): string {
    if ($statement === 'BS' && isset($bs_order[$category])) return $bs_order[$category];
    if ($statement === 'IS' && isset($is_order[$category])) return $is_order[$category];
    return ucfirst($category);
};
?>

<!-- Print-only Header -->
<div class="d-none d-print-block text-center mb-4">
    <?php
    $c_name = getSetting('company_name', 'BMS');
    $c_logo = getSetting('company_logo', '');
    $c_tin  = getSetting('company_tin', '');
    $c_vrn  = getSetting('company_vrn', '');
    ?>
    <?php if(!empty($c_logo)): ?>
        <div class="mb-2"><img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 70px;"></div>
    <?php endif; ?>
    <h2 style="margin:0; font-size: 18pt;"><?= safe_output($c_name) ?></h2>
    <?php if ($c_tin || $c_vrn): ?>
        <p style="margin:2px 0; font-size: 9pt;">
            <?= $c_tin ? 'TIN: ' . safe_output($c_tin) : '' ?>
            <?= $c_tin && $c_vrn ? '&nbsp;|&nbsp;' : '' ?>
            <?= $c_vrn ? 'VRN: ' . safe_output($c_vrn) : '' ?>
        </p>
    <?php endif; ?>
    <h3 style="margin-top: 10px; font-size: 13pt; text-transform: uppercase; letter-spacing: 2px;">Trial Balance</h3>
    <p style="margin:0; font-size: 9pt; font-style: italic;">Internal Working Document (not a formal financial statement)</p>
    <p style="margin:0; font-size: 9pt;">As at <?= $cur_label ?></p>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
        <div>
            <h5 class="mb-0 fw-bold text-secondary"><i class="bi bi-calculator me-2"></i> Trial Balance</h5>
            <small class="text-muted fst-italic">Internal Working Document — verifies the ledger is internally consistent before BS/IS are produced.</small>
        </div>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>

    <div class="card-body border-bottom bg-light d-print-none">
        <form method="GET" action="<?= getUrl('reports') ?>" class="row g-3 align-items-end">
            <input type="hidden" name="report" value="trial_balance">
            <div class="col-md-5">
                <label class="form-label small fw-bold">As of Date</label>
                <input type="date" class="form-control form-control-sm" name="as_of_date" value="<?= htmlspecialchars($as_of_date) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Project</label>
                <select class="form-select form-select-sm" name="project_id">
                    <option value=""><?= ($ok && empty($d['meta']['is_admin'])) ? 'All My Projects' : 'All Projects (Consolidated)' ?></option>
                    <?php foreach ($projects_list as $p): ?>
                        <option value="<?= (int)$p['project_id'] ?>" <?= $project_id === (int)$p['project_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-grid">
                <button type="submit" class="btn btn-secondary btn-sm text-white"><i class="bi bi-filter"></i> Generate Report</button>
            </div>
        </form>
    </div>

    <?php if (!$ok): ?>
        <div class="alert alert-danger m-3"><?= htmlspecialchars($err) ?></div>
    <?php else: ?>

    <?php if (!empty($d['meta']['project_filter_active'])): ?>
        <div class="alert alert-info border-0 mx-3 mt-3 py-2 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-info-circle me-2"></i>
            Project filter active. Only journal entries tagged to this project are included.
        </div>
    <?php endif; ?>
    <?php if (isset($d['meta']['is_admin']) && $d['meta']['is_admin'] === false): ?>
        <div class="alert alert-secondary border-0 mx-3 mt-3 py-2 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-shield-lock me-2"></i>
            Showing your scoped view: <?= count($d['meta']['scoped_project_ids'] ?? []) ?> assigned project(s) + untagged company-wide entries.
        </div>
    <?php endif; ?>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0 tb-table">
                <thead class="bg-light text-uppercase small fw-bold text-muted">
                    <tr>
                        <th class="ps-4" style="width:12%">Account #</th>
                        <th style="width:35%">Account Name</th>
                        <th style="width:10%">Statement</th>
                        <th style="width:15%">Type</th>
                        <th class="text-end" style="width:14%">Debit (TZS)</th>
                        <th class="text-end pe-4" style="width:14%">Credit (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($d['accounts'])): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted fst-italic">
                            No accounts have ledger activity or opening balances yet. Trial Balance will populate as journal entries are posted.
                        </td></tr>
                    <?php else:
                        // Render in canonical order: BS-first, then IS.
                        $sections = [
                            ['statement' => 'BS', 'label' => 'BALANCE SHEET ACCOUNTS', 'order' => $bs_order],
                            ['statement' => 'IS', 'label' => 'INCOME STATEMENT ACCOUNTS', 'order' => $is_order],
                        ];
                        foreach ($sections as $sect):
                            $stmt = $sect['statement'];
                            if (empty($grouped[$stmt])) continue;
                    ?>
                        <tr class="tb-section-head">
                            <td colspan="6" class="ps-3 fw-bold text-uppercase text-secondary"><?= $sect['label'] ?></td>
                        </tr>
                        <?php foreach ($sect['order'] as $cat => $cat_label):
                            if (empty($grouped[$stmt][$cat])) continue;
                            $rows = $grouped[$stmt][$cat];
                            $sub_dr = 0.0; $sub_cr = 0.0;
                        ?>
                            <?php foreach ($rows as $a):
                                $dr = (float)($a['current']['total_debit']  ?? 0);
                                $cr = (float)($a['current']['total_credit'] ?? 0);
                                $sub_dr += $dr; $sub_cr += $cr;
                            ?>
                                <tr>
                                    <td class="ps-4 font-monospace small"><?= htmlspecialchars($a['account_code'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($a['account_name'] ?? '') ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($a['statement'] ?? '') ?></td>
                                    <td class="small"><?= htmlspecialchars($cat_label) ?></td>
                                    <td class="text-end font-monospace"><?= tb_fmt($dr) ?></td>
                                    <td class="text-end pe-4 font-monospace"><?= tb_fmt($cr) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="tb-subtotal">
                                <td colspan="4" class="ps-4 text-end fst-italic small text-muted">Subtotal — <?= htmlspecialchars($cat_label) ?></td>
                                <td class="text-end font-monospace border-top"><?= tb_fmt($sub_dr) ?></td>
                                <td class="text-end pe-4 font-monospace border-top"><?= tb_fmt($sub_cr) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <!-- GRAND TOTAL -->
                    <tr class="tb-grand">
                        <td colspan="4" class="ps-3 fw-bold text-uppercase">Grand Total</td>
                        <td class="text-end fw-bold font-monospace border-top border-top-2"><?= tb_fmt($d['totals']['total_debit']) ?></td>
                        <td class="text-end pe-4 fw-bold font-monospace border-top border-top-2"><?= tb_fmt($d['totals']['total_credit']) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Balance status + Notes -->
    <div class="card-footer bg-white py-3">
        <?php if (!empty($d['totals']['balanced'])): ?>
            <div class="alert alert-success d-flex align-items-center mb-2 border-0">
                <i class="bi bi-check-circle-fill me-2"></i>
                <div><strong>BALANCED</strong> &nbsp;—&nbsp; Total Debits = Total Credits. Ledger integrity confirmed.</div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning d-flex align-items-start mb-2 border-0">
                <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
                <div>
                    <strong>OUT OF BALANCE</strong> &nbsp;—&nbsp; difference: <?= tb_fmt(abs((float)$d['totals']['balance_difference'])) ?> TZS
                    <?php if ((float)$d['totals']['balance_difference'] > 0): ?>
                        (Debits exceed Credits)
                    <?php else: ?>
                        (Credits exceed Debits)
                    <?php endif; ?>
                    <div class="small text-muted mt-1">
                        Common causes: opening balances entered without matching counterparts, manual journal entries not yet posted, or accounts mis-classified.
                        Review draft entries and account classifications, then re-generate.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="small text-muted">
            <strong>Notes:</strong>
            <ol class="mb-0 ps-3">
                <li>The Trial Balance is an <strong>internal working document</strong>, not a formal financial statement. Its only job is to confirm the ledger is internally consistent (Sum Dr = Sum Cr) before producing the Balance Sheet and Income Statement.</li>
                <li>Each account's totals include <code>accounts.opening_balance</code> (allocated by its natural side) plus every <code>posted</code> journal entry on or before the as-of date.</li>
                <li>Empty accounts (no opening balance and no posted activity) are omitted from the listing.</li>
                <li>Account drill-down to General Ledger will be available once Phase 2 ships.</li>
            </ol>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
.tb-table .tb-section-head td { background-color: #f1f3f5; font-size: 0.95rem; }
.tb-table .tb-subtotal td     { background-color: #fafbfc; }
.tb-table .tb-grand td        { background-color: #e9ecef; font-size: 1rem; }
@media print {
    body { background: white !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table { border: 1px solid #000 !important; font-size: 10pt; }
    .table th { background-color: #f8f9fa !important; }
}
</style>

<script>
$(document).ready(function() {
    if (typeof logReportAction === 'function') {
        logReportAction('Viewed Trial Balance', 'as of <?= $as_of_date ?>'<?= $project_id !== null ? " + ', project ' + " . (int)$project_id : '' ?>);
    }
});
</script>
