<?php
// File: reps/balance_sheet.php
// Phase 5c — partial; normally included by app/bms/invoice/reports.php
// (which already gates 'reports'), but a direct hit on this URL must
// also be denied. roots.php and the permission helpers are idempotent.
require_once __DIR__ . '/../../../../roots.php';
if (!canView('reports')) {
    http_response_code(403);
    die("Access Denied");
}

$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

// ── Consume the Balance Sheet API internally ────────────────────────────
// Reuses every rule and the user-scope filter defined there, so this
// partial stays in sync with the AJAX endpoint and any future
// integration consumer.
$saved_get = $_GET;
$_GET = ['as_of_date' => $as_of_date];
if ($project_id !== null) $_GET['project_id'] = (string)$project_id;
ob_start();
require __DIR__ . '/../../../../api/account/get_balance_sheet.php';
$bs_raw = ob_get_clean();
$_GET = $saved_get;

$bs = json_decode($bs_raw, true);
$bs_ok = $bs && !empty($bs['success']);
$bs_data = $bs_ok ? $bs['data'] : null;
$bs_error = $bs_ok ? '' : ($bs['message'] ?? 'Failed to load report');

// Projects list for the filter dropdown.
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
    $c_email = getSetting('company_email', '');
    $c_web = getSetting('company_website', '');
    $c_tin = getSetting('company_tin', '');
    $c_vrn = getSetting('company_vrn', '');
    ?>
    <?php if(!empty($c_logo)): ?>
        <div class="mb-3">
            <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
        </div>
    <?php endif; ?>
    <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= safe_output($c_name) ?></h1>
    <p class="text-dark mb-1 small text-uppercase">
        <?php
        $web_email = [];
        if (!empty($c_web)) $web_email[] = "Web: " . safe_output($c_web);
        if (!empty($c_email)) $web_email[] = "Email: " . safe_output($c_email);
        if (!empty($web_email)) echo implode(" | ", $web_email);
        ?>
    </p>
    <p class="text-dark mb-1 small text-uppercase">
        <?php
        $tin_vrn = [];
        if (!empty($c_tin)) $tin_vrn[] = "TIN: " . safe_output($c_tin);
        if (!empty($c_vrn)) $tin_vrn[] = "VRN: " . safe_output($c_vrn);
        if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
        ?>
    </p>
    <div class="mt-3">
        <h3 class="fw-bold text-success text-uppercase" style="color: #198754 !important;">BALANCE SHEET</h3>
        <h6 class="text-muted">As of: <?= date('d M Y', strtotime($as_of_date)) ?></h6>
        <div class="mt-2" style="border-top: 2px solid #198754; width: 100px; margin: 0 auto;"></div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
        <h5 class="mb-0 fw-bold text-success"><i class="bi bi-journal-text me-2"></i> Balance Sheet</h5>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
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
                    <option value=""><?= ($bs_ok && empty($bs_data['meta']['is_admin'])) ? 'All My Projects' : 'All Projects (Consolidated)' ?></option>
                    <?php foreach ($projects_list as $p): ?>
                        <option value="<?= (int)$p['project_id'] ?>" <?= $project_id === (int)$p['project_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-grid">
                <button type="submit" class="btn btn-success btn-sm text-white">
                    <i class="bi bi-filter"></i> Generate Report
                </button>
            </div>
        </form>
    </div>

    <style>
    @media print {
        body { background: white !important; }
        .container, .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        .table { width: 100% !important; border: 1px solid #dee2e6 !important; }
        .table th { background-color: #f8f9fa !important; color: black !important; }
        .text-success { color: #198754 !important; }
        .text-danger { color: #dc3545 !important; }
        .text-primary { color: #0d6efd !important; }
        .card-footer { border: none !important; margin-top: 20px !important; }
        .alert { border: 1px solid #ddd !important; background: transparent !important; color: black !important; }
    }
    </style>

    <?php if (!$bs_ok): ?>
        <div class="alert alert-danger m-3"><?= htmlspecialchars($bs_error) ?></div>
    <?php else:
        $meta    = $bs_data['meta'];
        $sec     = $bs_data['sections'];
        $totals  = $bs_data['totals'];
    ?>

    <?php if (!empty($meta['project_filter_active'])): ?>
        <div class="alert alert-info border-0 mx-3 mt-3 py-2 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-info-circle me-2"></i>
            Project filter active. Cash, Inventory, Fixed Assets, Salaries Payable, and Equity are <strong>company-wide</strong> and not attributable to a single project — they're shown as 0 here.
        </div>
    <?php endif; ?>
    <?php if (isset($meta['is_admin']) && $meta['is_admin'] === false): ?>
        <div class="alert alert-secondary border-0 mx-3 mt-3 py-2 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-shield-lock me-2"></i>
            Showing your scoped view: <?= count($meta['scoped_project_ids'] ?? []) ?> assigned project(s) plus untagged company-wide rows.
        </div>
    <?php endif; ?>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-uppercase small fw-bold text-muted">
                    <tr>
                        <th width="70%" class="ps-4">Account Description</th>
                        <th width="30%" class="text-end pe-4">Balance (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- ASSETS -->
                    <tr class="table-info fw-bold"><td colspan="2" class="ps-4">ASSETS</td></tr>
                    <?php if (empty($sec['assets']['lines'])): ?>
                        <tr><td class="ps-5 text-muted small">No asset balances</td><td class="text-end pe-4">-</td></tr>
                    <?php else: foreach ($sec['assets']['lines'] as $l): ?>
                        <tr>
                            <td class="ps-5"><?= htmlspecialchars($l['name']) ?></td>
                            <td class="text-end pe-4"><?= number_format($l['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr class="fw-bold bg-light">
                        <td class="ps-4">TOTAL ASSETS</td>
                        <td class="text-end pe-4 text-primary"><?= number_format($sec['assets']['total'], 2) ?></td>
                    </tr>

                    <!-- LIABILITIES -->
                    <tr class="table-warning fw-bold"><td colspan="2" class="ps-4">LIABILITIES</td></tr>
                    <?php if (empty($sec['liabilities']['lines'])): ?>
                        <tr><td class="ps-5 text-muted small">No liability balances</td><td class="text-end pe-4">-</td></tr>
                    <?php else: foreach ($sec['liabilities']['lines'] as $l): ?>
                        <tr>
                            <td class="ps-5"><?= htmlspecialchars($l['name']) ?></td>
                            <td class="text-end pe-4"><?= number_format($l['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr class="fw-bold bg-light">
                        <td class="ps-4">TOTAL LIABILITIES</td>
                        <td class="text-end pe-4 text-danger"><?= number_format($sec['liabilities']['total'], 2) ?></td>
                    </tr>

                    <!-- EQUITY -->
                    <tr class="table-success fw-bold"><td colspan="2" class="ps-4">EQUITY</td></tr>
                    <?php foreach ($sec['equity']['lines'] as $l): ?>
                        <tr>
                            <td class="ps-5"><?= htmlspecialchars($l['name']) ?></td>
                            <td class="text-end pe-4"><?= number_format($l['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="fw-bold bg-light">
                        <td class="ps-4">TOTAL EQUITY</td>
                        <td class="text-end pe-4 text-success"><?= number_format($sec['equity']['total'], 2) ?></td>
                    </tr>

                    <tr class="fw-bold fs-5 border-top-2">
                        <td class="ps-4">TOTAL LIABILITIES &amp; EQUITY</td>
                        <td class="text-end pe-4"><?= number_format($totals['liab_plus_equity'], 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-footer bg-white py-3">
        <?php if (!empty($totals['balanced'])): ?>
            <div class="alert alert-success d-flex align-items-center mb-0 border-0">
                <i class="bi bi-check-circle-fill me-2"></i>
                <div>Balance Sheet is balanced. (Retained Earnings is computed as the balancing plug — set up your Opening Balance Equity and Share Capital accounts in <a href="<?= getUrl('chart_of_accounts') ?>">Chart of Accounts</a> to reduce reliance on it.)</div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger d-flex align-items-center mb-0 border-0">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <div>Balance Sheet is out of balance by <?= number_format(abs($totals['balance_difference']), 2) ?>. Check your account categorisation.</div>
            </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    if (typeof logReportAction === 'function') {
        logReportAction('Viewed Balance Sheet', 'as of <?= $as_of_date ?>'<?= $project_id !== null ? " + ', project ' + " . (int)$project_id : '' ?>);
    }
});
</script>
