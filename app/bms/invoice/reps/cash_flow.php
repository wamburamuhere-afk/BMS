<?php
// File: reps/cash_flow.php
// Phase 5c — partial; included by app/bms/invoice/reports.php which already
// gates 'reports'. Direct hits on this URL are denied too.
require_once __DIR__ . '/../../../../roots.php';
if (!canView('reports')) {
    http_response_code(403);
    die("Access Denied");
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-t');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

// Consume the Cash Flow API internally so all rules (scope, project filter,
// canonical helpers) stay in a single place.
$saved_get = $_GET;
$_GET = ['start_date' => $start_date, 'end_date' => $end_date];
if ($project_id !== null) $_GET['project_id'] = (string)$project_id;
ob_start();
require __DIR__ . '/../../../../api/account/get_cash_flow.php';
$cf_raw = ob_get_clean();
$_GET = $saved_get;

$cf = json_decode($cf_raw, true);
$cf_ok = $cf && !empty($cf['success']);
$cf_data = $cf_ok ? $cf['data'] : null;
$cf_error = $cf_ok ? '' : ($cf['message'] ?? 'Failed to load report');

$_GET = [];
ob_start();
require_once __DIR__ . '/../../../../api/account/get_projects_for_filter.php';
$proj_raw = ob_get_clean();
$_GET = $saved_get;
$proj_resp = json_decode($proj_raw, true);
$projects_list = ($proj_resp && !empty($proj_resp['success'])) ? $proj_resp['projects'] : [];
?>

<!-- Print-only Header -->
<div class="d-none d-print-block text-center mb-4">
    <?php
    $c_name = getSetting('company_name', 'BMS');
    $c_logo = getSetting('company_logo', '');
    ?>
    <?php if(!empty($c_logo)): ?>
        <div class="mb-3">
            <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
        </div>
    <?php endif; ?>
    <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= safe_output($c_name) ?></h1>
    <div class="mt-3">
        <h3 class="fw-bold text-primary text-uppercase">CASH FLOW STATEMENT</h3>
        <h6 class="text-muted"><?= date('d M Y', strtotime($start_date)) ?> – <?= date('d M Y', strtotime($end_date)) ?></h6>
        <div class="mt-2" style="border-top: 2px solid #0d6efd; width: 100px; margin: 0 auto;"></div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
        <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-cash-stack me-2"></i> Cash Flow Statement</h5>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
    <div class="card-body border-bottom bg-light d-print-none">
        <form method="GET" action="<?= getUrl('reports') ?>" class="row g-3 align-items-end">
            <input type="hidden" name="report" value="cash_flow">
            <div class="col-md-3">
                <label class="form-label small fw-bold">Period Start</label>
                <input type="date" class="form-control form-control-sm" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Period End</label>
                <input type="date" class="form-control form-control-sm" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Project</label>
                <select class="form-select form-select-sm" name="project_id">
                    <option value=""><?= ($cf_ok && empty($cf_data['meta']['is_admin'])) ? 'All My Projects' : 'All Projects (Consolidated)' ?></option>
                    <?php foreach ($projects_list as $p): ?>
                        <option value="<?= (int)$p['project_id'] ?>" <?= $project_id === (int)$p['project_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-grid">
                <button type="submit" class="btn btn-primary btn-sm text-white">
                    <i class="bi bi-filter"></i> Generate Report
                </button>
            </div>
        </form>
    </div>

    <?php if (!$cf_ok): ?>
        <div class="alert alert-danger m-3"><?= htmlspecialchars($cf_error) ?></div>
    <?php else:
        $meta    = $cf_data['meta'];
        $sec     = $cf_data['sections'];
        $totals  = $cf_data['totals'];
    ?>

    <?php if (!empty($meta['project_filter_active'])): ?>
        <div class="alert alert-info border-0 mx-3 mt-3 py-2 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-info-circle me-2"></i>
            Project filter active. Salaries, opening/closing cash, and asset purchases are <strong>company-wide</strong> and shown as 0 here.
        </div>
    <?php endif; ?>

    <?php if (isset($meta['is_admin']) && $meta['is_admin'] === false): ?>
        <div class="alert alert-secondary border-0 mx-3 mt-3 py-2 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-shield-lock me-2"></i>
            Showing your scoped view: <?= count($meta['scoped_project_ids'] ?? []) ?> assigned project(s) plus untagged company-wide activity.
        </div>
    <?php endif; ?>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-uppercase small fw-bold text-muted">
                    <tr>
                        <th width="70%" class="ps-4">Line</th>
                        <th width="30%" class="text-end pe-4">Amount (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- OPENING CASH -->
                    <tr class="bg-light">
                        <td class="ps-4 fw-semibold">Opening Cash &amp; Bank Balance</td>
                        <td class="text-end pe-4 fw-semibold"><?= number_format($meta['opening_cash'], 2) ?></td>
                    </tr>

                    <!-- OPERATING -->
                    <tr class="table-info fw-bold"><td colspan="2" class="ps-4">OPERATING ACTIVITIES</td></tr>
                    <?php if (empty($sec['operating']['lines'])): ?>
                        <tr><td class="ps-5 text-muted small">No operating cash activity in this period</td><td class="text-end pe-4">-</td></tr>
                    <?php else: foreach ($sec['operating']['lines'] as $l): ?>
                        <tr>
                            <td class="ps-5"><?= htmlspecialchars($l['name']) ?></td>
                            <td class="text-end pe-4 <?= $l['amount'] < 0 ? 'text-danger' : '' ?>"><?= number_format($l['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr class="fw-bold bg-light">
                        <td class="ps-4">Net cash from operating activities</td>
                        <td class="text-end pe-4 <?= $sec['operating']['total'] < 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($sec['operating']['total'], 2) ?></td>
                    </tr>

                    <!-- INVESTING -->
                    <tr class="table-warning fw-bold"><td colspan="2" class="ps-4">INVESTING ACTIVITIES</td></tr>
                    <?php if (empty($sec['investing']['lines'])): ?>
                        <tr><td class="ps-5 text-muted small">No investing activity in this period</td><td class="text-end pe-4">-</td></tr>
                    <?php else: foreach ($sec['investing']['lines'] as $l): ?>
                        <tr>
                            <td class="ps-5"><?= htmlspecialchars($l['name']) ?></td>
                            <td class="text-end pe-4 <?= $l['amount'] < 0 ? 'text-danger' : '' ?>"><?= number_format($l['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr class="fw-bold bg-light">
                        <td class="ps-4">Net cash from investing activities</td>
                        <td class="text-end pe-4 <?= $sec['investing']['total'] < 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($sec['investing']['total'], 2) ?></td>
                    </tr>

                    <!-- FINANCING -->
                    <tr class="table-secondary fw-bold"><td colspan="2" class="ps-4">FINANCING ACTIVITIES</td></tr>
                    <?php if (empty($sec['financing']['lines'])): ?>
                        <tr><td class="ps-5 text-muted small fst-italic">No financing activity tracked (no borrowing / equity / dividend records in this system)</td><td class="text-end pe-4">-</td></tr>
                    <?php else: foreach ($sec['financing']['lines'] as $l): ?>
                        <tr>
                            <td class="ps-5"><?= htmlspecialchars($l['name']) ?></td>
                            <td class="text-end pe-4 <?= $l['amount'] < 0 ? 'text-danger' : '' ?>"><?= number_format($l['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr class="fw-bold bg-light">
                        <td class="ps-4">Net cash from financing activities</td>
                        <td class="text-end pe-4"><?= number_format($sec['financing']['total'], 2) ?></td>
                    </tr>

                    <!-- NET CHANGE + CLOSING CASH -->
                    <tr class="fw-bold border-top-2">
                        <td class="ps-4">NET CHANGE IN CASH</td>
                        <td class="text-end pe-4 <?= $totals['net_change_in_cash'] < 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($totals['net_change_in_cash'], 2) ?></td>
                    </tr>
                    <tr class="fw-bold bg-light fs-5">
                        <td class="ps-4">Closing Cash &amp; Bank Balance</td>
                        <td class="text-end pe-4"><?= number_format($meta['closing_cash'], 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-footer bg-white py-3">
        <small class="text-muted">
            <i class="bi bi-info-circle me-1"></i>
            Closing cash is read from your bank/cash chart accounts. Opening cash is back-calculated (Closing − Net Change). Once historical bank balances are tracked, the integrity check will be exact.
        </small>
    </div>

    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    if (typeof logReportAction === 'function') {
        logReportAction('Viewed Cash Flow', 'period <?= $start_date ?> to <?= $end_date ?>');
    }
});
</script>
