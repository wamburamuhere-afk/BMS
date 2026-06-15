<?php
// File: reps/balance_sheet.php
// IFRS / TFRS-for-SMEs Balance Sheet partial. Included by reports.php.
require_once __DIR__ . '/../../../../roots.php';
if (!canView('reports')) {
    http_response_code(403);
    die("Access Denied");
}

$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

// Consume the new IFRS-structured BS API internally.
$saved_get = $_GET;
$_GET = ['as_of_date' => $as_of_date];
if ($project_id !== null) $_GET['project_id'] = (string)$project_id;
ob_start();
require __DIR__ . '/../../../../api/account/get_balance_sheet.php';
$bs_raw = ob_get_clean();
$_GET = $saved_get;
$bs = json_decode($bs_raw, true);
$ok = $bs && !empty($bs['success']);
$err = $ok ? '' : ($bs['message'] ?? 'Failed to load report');
$d = $ok ? $bs['data'] : null;

// Projects dropdown
$_GET = [];
ob_start();
require_once __DIR__ . '/../../../../api/account/get_projects_for_filter.php';
$proj_raw = ob_get_clean();
$_GET = $saved_get;
$proj_resp = json_decode($proj_raw, true);
$projects_list = ($proj_resp && !empty($proj_resp['success'])) ? $proj_resp['projects'] : [];

$cur_label = date('d M Y', strtotime($as_of_date));
$cmp_label = $ok ? date('d M Y', strtotime($d['meta']['comparative_date'])) : '';
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
    <h3 style="margin-top: 10px; font-size: 13pt; text-transform: uppercase; letter-spacing: 2px;">Statement of Financial Position</h3>
    <p style="margin:0; font-size: 9pt;">As at <?= $cur_label ?></p>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
        <h5 class="mb-0 fw-bold text-success"><i class="bi bi-journal-text me-2"></i> Statement of Financial Position</h5>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
    <div class="card-body border-bottom bg-light d-print-none">
        <form method="GET" action="<?= getUrl('reports') ?>" class="row g-3 align-items-end">
            <input type="hidden" name="report" value="balance_sheet">
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
                <button type="submit" class="btn btn-success btn-sm text-white"><i class="bi bi-filter"></i> Generate Report</button>
            </div>
        </form>
    </div>

    <?php if (!$ok): ?>
        <div class="alert alert-danger m-3"><?= htmlspecialchars($err) ?></div>
    <?php else: ?>

    <?php if (!empty($d['meta']['project_filter_active'])): ?>
        <div class="alert alert-info border-0 mx-3 mt-3 py-2 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-info-circle me-2"></i>
            Project filter active. Cash, Inventory, Fixed Assets, Salaries, Equity are <strong>company-wide</strong> and not shown here.
        </div>
    <?php endif; ?>
    <?php if (isset($d['meta']['is_admin']) && $d['meta']['is_admin'] === false): ?>
        <div class="alert alert-secondary border-0 mx-3 mt-3 py-2 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-shield-lock me-2"></i>
            Showing your scoped view: <?= count($d['meta']['scoped_project_ids'] ?? []) ?> assigned project(s) + untagged company-wide rows.
        </div>
    <?php endif; ?>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0 bs-table">
                <thead class="bg-light text-uppercase small fw-bold text-muted">
                    <tr>
                        <th class="ps-4" style="width:50%">Description</th>
                        <th class="text-end" style="width:25%"><?= $cur_label ?></th>
                        <th class="text-end pe-4" style="width:25%"><?= $cmp_label ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- ===== ASSETS ===== -->
                    <tr class="bs-section-head"><td colspan="3" class="ps-3 fw-bold text-uppercase text-success">ASSETS</td></tr>

                    <!-- Current Assets -->
                    <tr class="bs-sub-head"><td colspan="3" class="ps-4 fw-semibold">Current Assets</td></tr>
                    <?php
                    $bs_render = function($lines) {
                        foreach ($lines as $l) {
                            $sub = !empty($l['is_subtotal']);
                            $brk = !empty($l['is_breakdown']);
                            $cmp = isset($l['comparative_amount']) && $l['comparative_amount'] !== null ? number_format($l['comparative_amount'], 2) : '—';
                            $cls = $sub ? 'fw-semibold border-top' : ($brk ? 'text-muted small' : '');
                            echo '<tr class="' . $cls . '">';
                            echo '<td class="ps-' . ($brk ? '5' : '4') . '">' . htmlspecialchars($l['name']) . '</td>';
                            echo '<td class="text-end font-monospace">' . number_format($l['amount'], 2) . '</td>';
                            echo '<td class="text-end pe-4 font-monospace text-muted">' . $cmp . '</td>';
                            echo '</tr>';
                        }
                    };
                    if (empty($d['sections']['current_assets']['lines'])) {
                        echo '<tr><td class="ps-5 text-muted small">No current asset balances</td><td colspan="2"></td></tr>';
                    } else {
                        $bs_render($d['sections']['current_assets']['lines']);
                    }
                    ?>
                    <tr class="bs-subtotal">
                        <td class="ps-4 fw-bold">Total Current Assets</td>
                        <td class="text-end font-monospace fw-bold border-top"><?= number_format($d['sections']['current_assets']['total'], 2) ?></td>
                        <td class="text-end pe-4 font-monospace fw-bold border-top text-muted"><?= number_format($d['sections']['current_assets']['comparative_total'], 2) ?></td>
                    </tr>

                    <!-- Non-Current Assets -->
                    <tr class="bs-sub-head"><td colspan="3" class="ps-4 fw-semibold pt-3">Non-Current Assets</td></tr>
                    <?php
                    if (empty($d['sections']['non_current_assets']['lines'])) {
                        echo '<tr><td class="ps-5 text-muted small">No non-current assets</td><td colspan="2"></td></tr>';
                    } else {
                        $bs_render($d['sections']['non_current_assets']['lines']);
                    }
                    ?>
                    <tr class="bs-subtotal">
                        <td class="ps-4 fw-bold">Total Non-Current Assets</td>
                        <td class="text-end font-monospace fw-bold border-top"><?= number_format($d['sections']['non_current_assets']['total'], 2) ?></td>
                        <td class="text-end pe-4 font-monospace fw-bold border-top text-muted"><?= number_format($d['sections']['non_current_assets']['comparative_total'], 2) ?></td>
                    </tr>

                    <tr class="bs-grand">
                        <td class="ps-4 fw-bold text-uppercase">TOTAL ASSETS</td>
                        <td class="text-end font-monospace fw-bold border-top border-top-2 text-success"><?= number_format($d['totals']['total_assets'], 2) ?></td>
                        <td class="text-end pe-4 font-monospace fw-bold border-top border-top-2 text-muted"><?= number_format($d['totals']['comparative']['total_assets'], 2) ?></td>
                    </tr>

                    <!-- ===== EQUITY & LIABILITIES ===== -->
                    <tr class="bs-section-head pt-2"><td colspan="3" class="ps-3 fw-bold text-uppercase text-success pt-4">EQUITY &amp; LIABILITIES</td></tr>

                    <!-- Equity -->
                    <tr class="bs-sub-head"><td colspan="3" class="ps-4 fw-semibold">Equity</td></tr>
                    <?php
                    if (empty($d['sections']['equity']['lines'])) {
                        echo '<tr><td class="ps-5 text-muted small">No equity balances</td><td colspan="2"></td></tr>';
                    } else {
                        $bs_render($d['sections']['equity']['lines']);
                    }
                    ?>
                    <tr class="bs-subtotal">
                        <td class="ps-4 fw-bold">Total Equity</td>
                        <td class="text-end font-monospace fw-bold border-top"><?= number_format($d['sections']['equity']['total'], 2) ?></td>
                        <td class="text-end pe-4 font-monospace fw-bold border-top text-muted"><?= number_format($d['sections']['equity']['comparative_total'], 2) ?></td>
                    </tr>

                    <!-- Non-Current Liabilities — shown only when present (hidden when BMS has none) -->
                    <?php if (!empty($d['sections']['non_current_liabilities']['lines'])): ?>
                    <tr class="bs-sub-head"><td colspan="3" class="ps-4 fw-semibold pt-3">Non-Current Liabilities</td></tr>
                    <?php $bs_render($d['sections']['non_current_liabilities']['lines']); ?>
                    <tr class="bs-subtotal">
                        <td class="ps-4 fw-bold">Total Non-Current Liabilities</td>
                        <td class="text-end font-monospace fw-bold border-top"><?= number_format($d['sections']['non_current_liabilities']['total'], 2) ?></td>
                        <td class="text-end pe-4 font-monospace fw-bold border-top text-muted"><?= number_format($d['sections']['non_current_liabilities']['comparative_total'], 2) ?></td>
                    </tr>
                    <?php endif; ?>

                    <!-- Current Liabilities -->
                    <tr class="bs-sub-head"><td colspan="3" class="ps-4 fw-semibold pt-3">Current Liabilities</td></tr>
                    <?php
                    if (empty($d['sections']['current_liabilities']['lines'])) {
                        echo '<tr><td class="ps-5 text-muted small">No current liability balances</td><td colspan="2"></td></tr>';
                    } else {
                        $bs_render($d['sections']['current_liabilities']['lines']);
                    }
                    ?>
                    <tr class="bs-subtotal">
                        <td class="ps-4 fw-bold">Total Current Liabilities</td>
                        <td class="text-end font-monospace fw-bold border-top"><?= number_format($d['sections']['current_liabilities']['total'], 2) ?></td>
                        <td class="text-end pe-4 font-monospace fw-bold border-top text-muted"><?= number_format($d['sections']['current_liabilities']['comparative_total'], 2) ?></td>
                    </tr>

                    <tr class="bs-grand">
                        <td class="ps-4 fw-bold text-uppercase">TOTAL EQUITY &amp; LIABILITIES</td>
                        <td class="text-end font-monospace fw-bold border-top border-top-2 text-success"><?= number_format($d['totals']['liab_plus_equity'], 2) ?></td>
                        <td class="text-end pe-4 font-monospace fw-bold border-top border-top-2 text-muted"><?= number_format($d['totals']['comparative']['total_liabilities'] + $d['totals']['comparative']['total_equity'], 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Statement of Changes in Equity -->
    <div class="card-body pt-4 border-top">
        <h6 class="text-uppercase text-success fw-bold mb-3"><i class="bi bi-arrow-left-right me-1"></i> Statement of Changes in Equity</h6>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                    <?php foreach ($d['sections']['changes_in_equity']['lines'] as $l):
                        $sub = !empty($l['is_subtotal']); ?>
                        <tr class="<?= $sub ? 'fw-bold border-top' : '' ?>">
                            <td class="ps-4 <?= $sub ? '' : 'text-muted' ?>"><?= htmlspecialchars($l['name']) ?></td>
                            <td class="text-end pe-4 font-monospace"><?= number_format($l['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Footer / Notes -->
    <div class="card-footer bg-white py-3">
        <?php if (!empty($d['totals']['balanced'])): ?>
            <div class="alert alert-success d-flex align-items-start mb-2 border-0">
                <i class="bi bi-check-circle-fill me-2 mt-1"></i>
                <div>
                    <strong>Balance Sheet balances.</strong> Total Assets = Total Equity &amp; Liabilities, derived from the general ledger.
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger d-flex align-items-center mb-2 border-0">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <div>Balance Sheet is out of balance by <?= number_format(abs($d['totals']['balance_difference']), 2) ?>. Check your data.</div>
            </div>
        <?php endif; ?>
        <div class="small text-muted">
            <strong>Notes:</strong>
            <ol class="mb-0 ps-3">
                <li>Accrual basis. Revenue is recognised when earned (invoice / IPC / POS) and costs when incurred; Trade Receivables and Trade Payables are outstanding balances.</li>
                <li>Property, Plant &amp; Equipment shown at cost less accumulated depreciation. Depreciation engine is in Phase 2 of the assets module — accumulated depreciation will populate after the first depreciation run.</li>
                <li>Bad debt provisioning not yet applied. Trade Receivables shown gross.</li>
                <li>Borrowings / Long-term loans are excluded from this report per company policy.</li>
                <li>Retained Earnings is GL-derived (accumulated profit to date), split into brought-forward (prior years) and current-year profit.</li>
            </ol>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
.bs-table .bs-section-head td { background-color: #eaf8ef; font-size: 0.95rem; }
.bs-table .bs-sub-head td { font-size: 0.9rem; }
.bs-table .bs-subtotal td { background-color: #f8f9fa; }
.bs-table .bs-grand td { background-color: #f0f7ff; font-size: 1rem; }
@media print {
    body { background: white !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table { border: 1px solid #000 !important; }
    .table th { background-color: #f8f9fa !important; }
}
</style>

<script>
$(document).ready(function() {
    if (typeof logReportAction === 'function') {
        logReportAction('Viewed Balance Sheet', 'as of <?= $as_of_date ?>'<?= $project_id !== null ? " + ', project ' + " . (int)$project_id : '' ?>);
    }
});
</script>
