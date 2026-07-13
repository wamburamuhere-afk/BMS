<?php
// File: reps/cash_flow.php
// Phase 3.4 — Cash Flow UI rewrite:
//   • Tabs for Direct / Indirect method toggle
//   • Three amount columns: Current | Comparative | Variance
//   • IFRS for SMEs §7.19A + §7.19B-C disclosure cards
// Included by app/bms/invoice/reports.php (gates 'reports') AND by the canonical
// Cash Flow route app/constant/reports/cash_flow.php (gates 'financial_reports');
// accept either so both entry points work.
require_once __DIR__ . '/../../../../roots.php';
if (!canView('reports') && !canView('financial_reports')) {
    http_response_code(403);
    die("Access Denied");
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-t');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;
$method = (isset($_GET['method']) && $_GET['method'] === 'indirect') ? 'indirect' : 'direct';

// Consume the Cash Flow API internally so all rules (scope, project filter,
// canonical helpers) stay in a single place.
$saved_get = $_GET;
$_GET = ['start_date' => $start_date, 'end_date' => $end_date, 'method' => $method];
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

// The two APIs included above each set a JSON Content-Type when headers aren't yet
// sent (roots.php buffers all output, so headers_sent() is false here). Left as-is,
// the whole page would be served as application/json and the browser would show the
// HTML as raw code. Reset to HTML now — header() replaces the field and the last call
// wins when the buffer flushes — so this partial renders as a page under any caller
// (the reports hub and the canonical /cash_flow route alike).
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// Helper: build a URL preserving the current filter params but swapping `method`.
if (!function_exists('cf_tab_url')) {
    function cf_tab_url(string $new_method, string $start_date, string $end_date, ?int $project_id): string {
        $params = [
            'report'     => 'cash_flow',
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'method'     => $new_method,
        ];
        if ($project_id !== null) $params['project_id'] = (string)$project_id;
        return getUrl('reports') . '?' . http_build_query($params);
    }
}

// Helper: signed-amount renderer for the table cells.
if (!function_exists('cf_fmt')) {
    function cf_fmt(float $v): string {
        return number_format($v, 2);
    }
    function cf_class(float $v): string {
        if ($v < 0) return 'text-danger';
        if ($v > 0) return 'text-success';
        return '';
    }
}
?>

<!-- Print-only Header — company logo + name come from the global print header
     (renderPrintHeader() in header.php, already output via includeHeader() in
     cash_flow_gl.php); do NOT repeat them here or they print twice. -->
<div class="d-none d-print-block text-center mb-4">
    <div class="mt-3">
        <h3 class="fw-bold text-primary text-uppercase">STATEMENT OF CASH FLOWS</h3>
        <h6 class="text-muted">
            <?= date('d M Y', strtotime($start_date)) ?> – <?= date('d M Y', strtotime($end_date)) ?>
            <span class="ms-2">(<?= $method === 'indirect' ? 'Indirect Method' : 'Direct Method' ?>)</span>
        </h6>
        <?php if ($cf_ok && !empty($cf_data['meta']['comparative_start'])): ?>
            <div class="small text-muted">
                With comparative period:
                <?= date('d M Y', strtotime($cf_data['meta']['comparative_start'])) ?>
                – <?= date('d M Y', strtotime($cf_data['meta']['comparative_end'])) ?>
            </div>
        <?php endif; ?>
        <div class="mt-2" style="border-top: 2px solid #0d6efd; width: 100px; margin: 0 auto;"></div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4 print-flow-card">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
        <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-cash-stack me-2"></i> Cash Flow Statement</h5>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
    <div class="card-body border-bottom bg-light d-print-none">
        <form method="GET" action="<?= getUrl('reports') ?>" class="row g-3 align-items-end">
            <input type="hidden" name="report" value="cash_flow">
            <input type="hidden" name="method" value="<?= htmlspecialchars($method) ?>">
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
        $discl   = $cf_data['disclosures'] ?? null;

        $cur_total_net = (float)$totals['net_change_in_cash'];
        $cmp_total_net = (float)($totals['comparative']['net_change_in_cash'] ?? 0);
        $var_total_net = $cur_total_net - $cmp_total_net;
    ?>

    <!-- Method tabs -->
    <ul class="nav nav-tabs px-3 pt-3 d-print-none" id="cf-method-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $method === 'direct' ? 'active fw-bold' : '' ?>"
               href="<?= htmlspecialchars(cf_tab_url('direct', $start_date, $end_date, $project_id)) ?>">
                <i class="bi bi-arrow-down-up me-1"></i> Direct Method
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $method === 'indirect' ? 'active fw-bold' : '' ?>"
               href="<?= htmlspecialchars(cf_tab_url('indirect', $start_date, $end_date, $project_id)) ?>">
                <i class="bi bi-shuffle me-1"></i> Indirect Method
            </a>
        </li>
    </ul>

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
                        <th width="40%" class="ps-4">Line</th>
                        <th width="20%" class="text-end">
                            Current<br>
                            <span class="text-secondary text-nowrap fw-normal" style="font-size:0.7rem;">
                                <?= htmlspecialchars($meta['current_start'] ?? $start_date) ?>
                                — <?= htmlspecialchars($meta['current_end'] ?? $end_date) ?>
                            </span>
                        </th>
                        <th width="20%" class="text-end">
                            Comparative<br>
                            <span class="text-secondary text-nowrap fw-normal" style="font-size:0.7rem;">
                                <?= htmlspecialchars($meta['comparative_start'] ?? '—') ?>
                                — <?= htmlspecialchars($meta['comparative_end'] ?? '—') ?>
                            </span>
                        </th>
                        <th width="20%" class="text-end pe-4">
                            Variance<br>
                            <span class="text-secondary text-nowrap fw-normal" style="font-size:0.7rem;">(Current − Comparative)</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <!-- OPENING CASH -->
                    <tr class="bg-light">
                        <td class="ps-4 fw-semibold">Opening Cash &amp; Bank Balance</td>
                        <td class="text-end fw-semibold"><?= cf_fmt((float)$meta['opening_cash']) ?></td>
                        <td class="text-end fw-semibold"><?= cf_fmt((float)($meta['comparative_opening_cash'] ?? 0)) ?></td>
                        <td class="text-end pe-4 fw-semibold text-muted"><?= cf_fmt((float)$meta['opening_cash'] - (float)($meta['comparative_opening_cash'] ?? 0)) ?></td>
                    </tr>

                    <?php
                    // Render a section block: header + lines + subtotal row.
                    $renderSection = function (string $title, string $colorClass, array $sec, string $emptyMsg) {
                        $cur = (float)$sec['total'];
                        $cmp = (float)($sec['comparative_total'] ?? 0);
                        $var = $cur - $cmp;
                    ?>
                        <tr class="<?= $colorClass ?> fw-bold">
                            <td colspan="4" class="ps-4"><?= htmlspecialchars($title) ?></td>
                        </tr>
                        <?php if (empty($sec['lines'])): ?>
                            <tr>
                                <td class="ps-5 text-muted small fst-italic" colspan="4"><?= htmlspecialchars($emptyMsg) ?></td>
                            </tr>
                        <?php else: foreach ($sec['lines'] as $l):
                            $line_cur = (float)$l['amount'];
                            $line_cmp = (float)($l['comparative_amount'] ?? 0);
                            $line_var = $line_cur - $line_cmp;
                        ?>
                            <tr>
                                <td class="ps-5"><?= htmlspecialchars($l['name']) ?></td>
                                <td class="text-end <?= cf_class($line_cur) ?>"><?= cf_fmt($line_cur) ?></td>
                                <td class="text-end <?= cf_class($line_cmp) ?>"><?= cf_fmt($line_cmp) ?></td>
                                <td class="text-end pe-4 <?= cf_class($line_var) ?>"><?= cf_fmt($line_var) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        <tr class="fw-bold bg-light">
                            <td class="ps-4">Net cash from <?= strtolower(str_replace(' ACTIVITIES', '', $title)) ?> activities</td>
                            <td class="text-end <?= cf_class($cur) ?>"><?= cf_fmt($cur) ?></td>
                            <td class="text-end <?= cf_class($cmp) ?>"><?= cf_fmt($cmp) ?></td>
                            <td class="text-end pe-4 <?= cf_class($var) ?>"><?= cf_fmt($var) ?></td>
                        </tr>
                    <?php };

                    $operatingEmpty = ($method === 'indirect')
                        ? 'No indirect-method operating data in this period'
                        : 'No operating cash activity in this period';

                    $renderSection('OPERATING ACTIVITIES', 'table-info', $sec['operating'], $operatingEmpty);
                    $renderSection('INVESTING ACTIVITIES', 'table-warning', $sec['investing'], 'No investing activity in this period');
                    $renderSection('FINANCING ACTIVITIES', 'table-secondary', $sec['financing'], 'No financing activity tracked (no borrowing / equity / dividend records in this system)');
                    ?>

                    <!-- NET CHANGE + CLOSING CASH -->
                    <tr class="fw-bold border-top-2">
                        <td class="ps-4">NET CHANGE IN CASH</td>
                        <td class="text-end <?= cf_class($cur_total_net) ?>"><?= cf_fmt($cur_total_net) ?></td>
                        <td class="text-end <?= cf_class($cmp_total_net) ?>"><?= cf_fmt($cmp_total_net) ?></td>
                        <td class="text-end pe-4 <?= cf_class($var_total_net) ?>"><?= cf_fmt($var_total_net) ?></td>
                    </tr>
                    <tr class="fw-bold bg-light fs-5">
                        <td class="ps-4">Closing Cash &amp; Bank Balance</td>
                        <td class="text-end"><?= cf_fmt((float)$meta['closing_cash']) ?></td>
                        <td class="text-end"><?= cf_fmt((float)($meta['comparative_closing_cash'] ?? 0)) ?></td>
                        <td class="text-end pe-4 text-muted"><?= cf_fmt((float)$meta['closing_cash'] - (float)($meta['comparative_closing_cash'] ?? 0)) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-footer bg-white py-3">
        <small class="text-muted">
            <i class="bi bi-info-circle me-1"></i>
            <?php if ($method === 'indirect'): ?>
                Indirect method: starts from Net Profit and adds back non-cash items + working-capital movements. Reconciles to the direct-method operating total once auto-posting is enabled.
            <?php else: ?>
                Direct method: shows actual cash inflows and outflows by operating activity. Closing cash is read from your bank/cash chart accounts; opening cash is back-calculated.
            <?php endif; ?>
        </small>
    </div>

    <?php
    // ═══════════════════════════════════════════════════════════════════════
    // IFRS for SMEs §7.19A + §7.19B-C — disclosure cards (always-visible)
    // ═══════════════════════════════════════════════════════════════════════
    if ($discl):
        $fin_cur = $discl['financing_liabilities_reconciliation']['current']   ?? null;
        $fin_cmp = $discl['financing_liabilities_reconciliation']['comparative'] ?? null;
        $sup_cur = $discl['supplier_finance_arrangements']['current']          ?? null;
        $sup_cmp = $discl['supplier_finance_arrangements']['comparative']      ?? null;
    ?>
    <div class="card-body border-top bg-light">
        <h6 class="fw-bold text-uppercase text-secondary mb-3">
            <i class="bi bi-journal-text me-1"></i> IFRS for SMEs — Required Disclosures
        </h6>

        <?php if ($fin_cur): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <div>
                    <strong>§7.19A — Reconciliation of Liabilities Arising from Financing Activities</strong>
                </div>
                <span class="badge bg-<?= !empty($fin_cur['applicable']) ? 'success' : 'secondary' ?>">
                    <?= !empty($fin_cur['applicable']) ? 'Applicable' : 'Not Applicable' ?>
                </span>
            </div>
            <div class="card-body py-2 small">
                <p class="text-muted mb-2"><?= htmlspecialchars($fin_cur['note']) ?></p>
                <table class="table table-sm mb-0 small">
                    <thead class="text-uppercase text-muted" style="font-size:0.7rem;">
                        <tr>
                            <th>Item</th>
                            <th class="text-end">Current</th>
                            <th class="text-end">Comparative</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Opening balance</td>
                            <td class="text-end"><?= cf_fmt((float)$fin_cur['opening_balance']) ?></td>
                            <td class="text-end"><?= cf_fmt((float)($fin_cmp['opening_balance'] ?? 0)) ?></td>
                        </tr>
                        <tr>
                            <td>Cash changes</td>
                            <td class="text-end"><?= cf_fmt((float)$fin_cur['cash_changes']) ?></td>
                            <td class="text-end"><?= cf_fmt((float)($fin_cmp['cash_changes'] ?? 0)) ?></td>
                        </tr>
                        <tr>
                            <td>Non-cash changes</td>
                            <td class="text-end"><?= cf_fmt((float)$fin_cur['non_cash_changes']) ?></td>
                            <td class="text-end"><?= cf_fmt((float)($fin_cmp['non_cash_changes'] ?? 0)) ?></td>
                        </tr>
                        <tr class="fw-bold bg-light">
                            <td>Closing balance</td>
                            <td class="text-end"><?= cf_fmt((float)$fin_cur['closing_balance']) ?></td>
                            <td class="text-end"><?= cf_fmt((float)($fin_cmp['closing_balance'] ?? 0)) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($sup_cur): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <div>
                    <strong>§7.19B-C — Supplier Finance Arrangements</strong>
                </div>
                <span class="badge bg-<?= !empty($sup_cur['applicable']) ? 'success' : 'secondary' ?>">
                    <?= !empty($sup_cur['applicable']) ? 'Applicable' : 'Proxy Disclosure' ?>
                </span>
            </div>
            <div class="card-body py-2 small">
                <p class="text-muted mb-2"><?= htmlspecialchars($sup_cur['note']) ?></p>
                <table class="table table-sm mb-0 small">
                    <thead class="text-uppercase text-muted" style="font-size:0.7rem;">
                        <tr>
                            <th>Metric</th>
                            <th class="text-end">Current</th>
                            <th class="text-end">Comparative</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Unpaid approved invoices (count)</td>
                            <td class="text-end"><?= (int)$sup_cur['invoice_count'] ?></td>
                            <td class="text-end"><?= (int)($sup_cmp['invoice_count'] ?? 0) ?></td>
                        </tr>
                        <tr>
                            <td>… of which have parseable payment terms</td>
                            <td class="text-end"><?= (int)$sup_cur['invoices_with_terms'] ?></td>
                            <td class="text-end"><?= (int)($sup_cmp['invoices_with_terms'] ?? 0) ?></td>
                        </tr>
                        <tr class="fw-bold bg-light">
                            <td>Total unpaid amount (TZS)</td>
                            <td class="text-end"><?= cf_fmt((float)$sup_cur['total_unpaid_amount']) ?></td>
                            <td class="text-end"><?= cf_fmt((float)($sup_cmp['total_unpaid_amount'] ?? 0)) ?></td>
                        </tr>
                        <tr>
                            <td>Earliest computed due date</td>
                            <td class="text-end"><?= $sup_cur['earliest_due_date'] ? htmlspecialchars($sup_cur['earliest_due_date']) : '—' ?></td>
                            <td class="text-end"><?= !empty($sup_cmp['earliest_due_date']) ? htmlspecialchars($sup_cmp['earliest_due_date']) : '—' ?></td>
                        </tr>
                        <tr>
                            <td>Latest computed due date</td>
                            <td class="text-end"><?= $sup_cur['latest_due_date'] ? htmlspecialchars($sup_cur['latest_due_date']) : '—' ?></td>
                            <td class="text-end"><?= !empty($sup_cmp['latest_due_date']) ? htmlspecialchars($sup_cmp['latest_due_date']) : '—' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<style>
@media print {
    /* Table wasn't starting on the first printed page — same shared-rule
       cause fixed across every list/report page: the global responsive.css
       rule `.card { page-break-inside: avoid }` applies to every .card on
       every printed page. This report's card can grow tall with many line
       items, so "never break inside it" pushed the whole card to page 2,
       leaving page 1 with just the header. Scoped override so only this
       page's card is affected — the shared rule and every other page stay
       untouched. */
    .print-flow-card {
        page-break-inside: auto !important;
        break-inside: auto !important;
    }
}
</style>

<script>
$(document).ready(function() {
    if (typeof logReportAction === 'function') {
        logReportAction('Viewed Cash Flow', 'method=<?= $method ?>, period <?= $start_date ?> to <?= $end_date ?>');
    }
});
</script>
