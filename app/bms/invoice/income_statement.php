<?php
// app/bms/invoice/income_statement.php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/permissions.php';
includeHeader();

autoEnforcePermission('income_statement');

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Used to choose the dropdown's default-option label (admins see "All Projects",
// non-admins see "All My Projects" because their consolidated view is scoped).
$is_admin_user = isAdmin();
?>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">PROFIT & LOSS STATEMENT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Official document summarizing operational performance and net profitability.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div class="row g-2">
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Revenue</p>
                    <h4 style="color: #0d6efd; font-weight: 800; margin: 0; font-size: 14pt;" id="printTotalRev">0.00</h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Cost of Sales</p>
                    <h4 style="color: #e67e22; font-weight: 800; margin: 0; font-size: 14pt;" id="printTotalCogs">0.00</h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Expenses</p>
                    <h4 style="color: #e74c3c; font-weight: 800; margin: 0; font-size: 14pt;" id="printTotalExp">0.00</h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Net Income</p>
                    <h4 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 14pt;" id="printNetIncome">0.00</h4>
                </div>
            </div>
        </div>
    </div>
    <!-- Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Income Statement</h2>
            <p class="text-muted mb-0">Profit & Loss analysis for the current fiscal period</p>
        </div>
        <div class="col-md-6 text-end">
            <div class="btn-group shadow-sm">
                <button class="btn btn-outline-primary fw-bold" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
                <button class="btn btn-primary fw-bold" onclick="exportExcel()">
                    <i class="bi bi-file-earmark-excel me-1"></i> Export
                </button>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-radius: 12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Period Start</label>
                    <input type="date" class="form-control rounded-3 border-light shadow-sm" id="start_date" name="start_date" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Period End</label>
                    <input type="date" class="form-control rounded-3 border-light shadow-sm" id="end_date" name="end_date" value="<?= $end_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Project</label>
                    <select class="form-select rounded-3 border-light shadow-sm" id="project_id" name="project_id">
                        <option value=""><?= $is_admin_user ? 'All Projects (Consolidated)' : 'All My Projects' ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-dark w-100 py-2 fw-bold shadow-sm rounded-3">
                        <i class="bi bi-filter me-1"></i> Update Analysis
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4" id="summaryCards">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 15px; background-color: #d1e7dd !important;">
                <div class="card-body p-4">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Revenue</p>
                    <h3 class="fw-bold mb-0 text-dark" id="totalRevenue">0.00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 15px; background-color: #d1e7dd !important;">
                <div class="card-body p-4">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Cost of Sales</p>
                    <h3 class="fw-bold mb-0 text-dark" id="totalCOGS">0.00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 15px; background-color: #d1e7dd !important;">
                <div class="card-body p-4">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Expenses</p>
                    <h3 class="fw-bold mb-0 text-dark" id="totalExpenses">0.00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 15px; background-color: #d1e7dd !important;">
                <div class="card-body p-4">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Net Income</p>
                    <h3 class="fw-bold mb-0 text-dark" id="netIncome">0.00</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Posting warning banner (drives accountant to investigate draft entries) -->
    <div id="postingWarning" class="alert alert-warning border-0 py-2 px-3 mb-3 d-print-none d-none" style="font-size: 0.85rem;">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <span id="postingWarningText"></span>
    </div>
    <div id="classificationWarning" class="alert alert-warning border-0 py-2 px-3 mb-3 d-print-none d-none" style="font-size: 0.85rem;">
        <i class="bi bi-info-circle-fill me-2"></i>
        <span id="classificationWarningText"></span>
    </div>
    <div id="unpaidPayrollWarning" class="alert alert-warning border-0 py-2 px-3 mb-3 d-print-none d-none" style="font-size: 0.85rem;">
        <i class="bi bi-cash-stack me-2"></i>
        <span id="unpaidPayrollText"></span>
    </div>
    <div id="projectFilterNotice" class="alert alert-info border-0 py-2 px-3 mb-3 d-print-none d-none" style="font-size: 0.85rem;">
        <i class="bi bi-info-circle me-2"></i>
        Project filter is active. Manual journal entries (no project tag) and company-wide salaries are <strong>excluded</strong> from this view.
    </div>
    <div id="scopedAccessNotice" class="alert alert-secondary border-0 py-2 px-3 mb-3 d-print-none d-none" style="font-size: 0.85rem;">
        <i class="bi bi-shield-lock me-2"></i>
        <span id="scopedAccessText"></span>
    </div>

    <!-- Detailed breakdown — structured P&L with server-side totals -->
    <div class="card border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">Statement of Profit or Loss</h5>
            <small class="text-muted" id="periodLabel"></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0 pl-table" id="reportTable">
                    <thead class="bg-light">
                        <tr class="text-muted text-uppercase" style="font-size: 0.78rem;">
                            <th class="ps-4 py-2">Account Description</th>
                            <th class="text-end py-2">Previous Period</th>
                            <th class="text-end pe-4 py-2">Current Period</th>
                        </tr>
                    </thead>
                    <tbody id="reportContent">
                        <!-- Revenue -->
                        <tr class="pl-section-header"><td colspan="3" class="ps-3 py-2 bg-light fw-bold text-uppercase" style="letter-spacing: 1px; font-size: 0.8rem; color: #495057;">REVENUE</td></tr>
                        <tbody id="revenueBody"></tbody>
                        <tr class="pl-subtotal"><td class="ps-4 py-2" style="font-size: 0.9rem;">Total Revenue</td><td class="text-end font-monospace py-2" style="font-size: 0.9rem; border-top: 1.5px solid #dee2e6;" id="revenuePrevSubtotal">0.00</td><td class="text-end pe-4 fw-bold font-monospace py-2" style="font-size: 0.95rem; border-top: 1.5px solid #dee2e6;" id="revenueSubtotal">0.00</td></tr>

                        <!-- (Informational) Sales Returns processed this period — hidden when zero -->
                        <tr class="pl-info-row d-none" id="salesReturnsRow">
                            <td class="ps-5 py-1 fst-italic text-muted" style="font-size: 0.82rem;">
                                <i class="bi bi-info-circle me-1"></i>
                                Sales returns processed (for reference; already netted within Revenue)
                            </td>
                            <td class="text-end font-monospace text-muted py-1" style="font-size: 0.8rem;" id="salesReturnsPrev">0.00</td>
                            <td class="text-end pe-4 font-monospace text-muted py-1" style="font-size: 0.82rem;" id="salesReturnsCurrent">0.00</td>
                        </tr>

                        <!-- COGS -->
                        <tr class="pl-section-header"><td colspan="3" class="ps-3 py-2 bg-light fw-bold text-uppercase" style="letter-spacing: 1px; font-size: 0.8rem; color: #495057;">LESS: COST OF GOODS SOLD</td></tr>
                        <tbody id="cogsBody"></tbody>
                        <tr class="pl-subtotal"><td class="ps-4 py-2" style="font-size: 0.9rem;">Total Cost of Goods Sold</td><td class="text-end font-monospace py-2" style="font-size: 0.9rem; border-top: 1.5px solid #dee2e6;" id="cogsPrevSubtotal">0.00</td><td class="text-end pe-4 fw-bold font-monospace py-2" style="font-size: 0.95rem; border-top: 1.5px solid #dee2e6;" id="cogsSubtotal">0.00</td></tr>

                        <!-- Gross Profit -->
                        <tr class="pl-gross-profit"><td class="ps-4 fw-bold text-uppercase py-2" style="font-size: 0.92rem; letter-spacing: 0.5px;">GROSS PROFIT <small class="text-muted ms-2 fw-normal" id="grossMarginLabel"></small></td><td class="text-end font-monospace fw-semibold py-2" style="font-size: 0.95rem;" id="grossProfitPrev">0.00</td><td class="text-end pe-4 fw-bold font-monospace py-2" style="font-size: 1.0rem; border-top: 2px solid #0d6efd; border-bottom: 1px solid #dee2e6;" id="grossProfit">0.00</td></tr>

                        <!-- Operating Expenses -->
                        <tr class="pl-section-header"><td colspan="3" class="ps-3 py-2 bg-light fw-bold text-uppercase" style="letter-spacing: 1px; font-size: 0.8rem; color: #495057;">LESS: OPERATING EXPENSES</td></tr>
                        <tbody id="expensesBody"></tbody>
                        <tr class="pl-subtotal"><td class="ps-4 py-2" style="font-size: 0.9rem;">Total Operating Expenses</td><td class="text-end font-monospace py-2" style="font-size: 0.9rem; border-top: 1.5px solid #dee2e6;" id="expensesPrevSubtotal">0.00</td><td class="text-end pe-4 fw-bold font-monospace py-2" style="font-size: 0.95rem; border-top: 1.5px solid #dee2e6;" id="expensesSubtotal">0.00</td></tr>

                        <!-- Operating Profit (EBIT) -->
                        <tr class="pl-operating-profit"><td class="ps-4 fw-bold text-uppercase py-2" style="font-size: 0.92rem; letter-spacing: 0.5px;">OPERATING PROFIT (EBIT) <small class="text-muted ms-2 fw-normal" id="operatingMarginLabel"></small></td><td class="text-end font-monospace fw-semibold py-2" style="font-size: 0.95rem;" id="operatingProfitPrev">0.00</td><td class="text-end pe-4 fw-bold font-monospace py-2" style="font-size: 1.0rem; border-top: 2px solid #0d6efd; border-bottom: 1px solid #dee2e6;" id="operatingProfit">0.00</td></tr>

                        <!-- Other Income (non-operating) -->
                        <tr class="pl-section-header" id="otherIncomeSection"><td colspan="3" class="ps-3 py-2 bg-light fw-bold text-uppercase" style="letter-spacing: 1px; font-size: 0.8rem; color: #495057;">OTHER INCOME</td></tr>
                        <tbody id="otherIncomeBody"></tbody>
                        <tr class="pl-subtotal" id="otherIncomeSubtotalRow"><td class="ps-4 py-2" style="font-size: 0.9rem;">Total Other Income</td><td class="text-end font-monospace py-2" style="font-size: 0.9rem; border-top: 1.5px solid #dee2e6;" id="otherIncomePrevSubtotal">0.00</td><td class="text-end pe-4 fw-bold font-monospace py-2" style="font-size: 0.95rem; border-top: 1.5px solid #dee2e6;" id="otherIncomeSubtotal">0.00</td></tr>

                        <!-- Finance Costs (required on face per IAS 1) -->
                        <tr class="pl-section-header"><td colspan="3" class="ps-3 py-2 bg-light fw-bold text-uppercase" style="letter-spacing: 1px; font-size: 0.8rem; color: #495057;">FINANCE COSTS <small class="text-muted fw-normal ms-1" style="font-size:0.72rem;">— classify accounts via Settings → Account Types</small></td></tr>
                        <tbody id="financeBody"></tbody>
                        <tr class="pl-subtotal" id="financeSubtotalRow"><td class="ps-4 py-2" style="font-size: 0.9rem;">Total Finance Costs</td><td class="text-end font-monospace py-2" style="font-size: 0.9rem; border-top: 1.5px solid #dee2e6;" id="financePrevSubtotal">0.00</td><td class="text-end pe-4 fw-bold font-monospace py-2" style="font-size: 0.95rem; border-top: 1.5px solid #dee2e6;" id="financeSubtotal">0.00</td></tr>

                        <!-- Income Tax (provision) -->
                        <tr class="pl-info-row"><td class="ps-4 py-2 text-muted" style="font-size: 0.88rem;">Less: Income Tax (provision) <small class="text-muted fst-italic ms-1">— post monthly via Finance → Journal Entries</small></td><td class="text-end font-monospace text-muted py-2" style="font-size: 0.88rem;" id="incomeTaxPrev">0.00</td><td class="text-end pe-4 font-monospace py-2" style="font-size: 0.9rem;" id="incomeTax">0.00</td></tr>

                        <!-- Profit Before Tax -->
                        <tr class="pl-info-row"><td class="ps-4 fw-semibold text-uppercase py-2" style="font-size: 0.9rem; letter-spacing: 0.5px;">PROFIT BEFORE TAX</td><td class="text-end font-monospace fw-semibold py-2" style="font-size: 0.9rem;" id="profitBeforeTaxPrev">0.00</td><td class="text-end pe-4 fw-semibold font-monospace py-2" style="font-size: 0.95rem; border-top: 1px solid #dee2e6;" id="profitBeforeTax">0.00</td></tr>

                        <!-- Net Profit For Period -->
                        <tr class="pl-net-profit"><td class="ps-4 fw-bold text-uppercase py-3" style="font-size: 1.0rem; letter-spacing: 1px;">NET PROFIT FOR PERIOD <small class="text-muted ms-2 fw-normal" id="netMarginLabel"></small></td><td class="text-end font-monospace fw-semibold py-3" style="font-size: 1.0rem;" id="netIncomePrev">0.00</td><td class="text-end pe-4 fw-bold font-monospace py-3" style="font-size: 1.15rem; border-top: 3px double #0d6efd;" id="netIncomeFinal">0.00</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    loadProjectsThenReport();

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        loadReport();
    });
});

function loadProjectsThenReport() {
    $.getJSON('<?= buildUrl('api/account/get_projects_for_filter.php') ?>', function(resp) {
        if (resp && resp.success && Array.isArray(resp.projects)) {
            const $sel = $('#project_id');
            resp.projects.forEach(p => {
                $sel.append('<option value="' + p.project_id + '">' + p.project_name + '</option>');
            });
        }
    }).always(function() {
        loadReport();
    });
}

function loadReport() {
    const start = $('#start_date').val();
    const end = $('#end_date').val();
    const projectId = $('#project_id').val() || '';

    if (typeof logReportAction === 'function') {
        logReportAction('Viewed P&L Statement', 'Analysis for ' + start + ' to ' + end + (projectId ? ' • project ' + projectId : ''));
    }

    $('body').css('cursor', 'wait');

    $.ajax({
        url: '<?= buildUrl('api/account/get_income_statement.php') ?>',
        type: 'GET',
        data: { start_date: start, end_date: end, project_id: projectId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderReport(response.data);
            } else {
                Swal.fire('Error', response.message || 'Failed to load report', 'error');
            }
            $('body').css('cursor', 'default');
        },
        error: function() {
            Swal.fire('Error', 'Failed to reach API', 'error');
            $('body').css('cursor', 'default');
        }
    });
}

function renderReport(data) {
    // ── Render section lines (3 columns: Account, Previous, Current) ──
    const renderLines = ($body, lines) => {
        $body.empty();
        if (!lines || !lines.length) {
            $body.append(`<tr><td colspan="3" class="ps-5 text-muted fst-italic py-2" style="font-size: 0.85rem;">— No activity in this period —</td></tr>`);
            return;
        }
        lines.forEach(line => {
            const code = line.account_code ? `<small class="text-muted me-2 font-monospace">${line.account_code}</small>` : '';
            $body.append(
                `<tr>
                    <td class="ps-5 py-1" style="font-size: 0.88rem;">${code}${line.account_name}</td>
                    <td class="text-end font-monospace text-muted py-1" style="font-size: 0.85rem;">${formatMoney(line.previous)}</td>
                    <td class="text-end pe-4 font-monospace py-1" style="font-size: 0.88rem;">${formatMoney(line.current)}</td>
                </tr>`
            );
        });
    };

    const sections = data.sections || {};
    renderLines($('#revenueBody'),      sections.revenue      ? sections.revenue.lines      : []);
    renderLines($('#cogsBody'),         sections.cogs         ? sections.cogs.lines         : []);
    renderLines($('#expensesBody'),     sections.expense      ? sections.expense.lines      : []);
    renderLines($('#otherIncomeBody'),  sections.other_income ? sections.other_income.lines : []);
    renderLines($('#financeBody'),      sections.finance_costs ? sections.finance_costs.lines : []);

    // ── Server-computed totals (we no longer recompute on the client) ──
    const t  = data.totals;
    const tp = t.previous || {};

    // Revenue
    $('#revenueSubtotal').text(formatMoney(t.total_revenue));
    $('#revenuePrevSubtotal').text(formatMoney(tp.total_revenue || 0));

    // Sales Returns (informational row — only shown when non-zero)
    const sr      = +(t.sales_returns || 0);
    const sr_prev = +(tp.sales_returns || 0);
    if (Math.abs(sr) > 0.001 || Math.abs(sr_prev) > 0.001) {
        $('#salesReturnsCurrent').text(formatMoney(sr));
        $('#salesReturnsPrev').text(formatMoney(sr_prev));
        $('#salesReturnsRow').removeClass('d-none');
    } else {
        $('#salesReturnsRow').addClass('d-none');
    }

    // COGS
    $('#cogsSubtotal').text(formatMoney(t.total_cogs));
    $('#cogsPrevSubtotal').text(formatMoney(tp.total_cogs || 0));

    // Gross Profit
    $('#grossProfit').text(formatMoney(t.gross_profit));
    $('#grossProfitPrev').text(formatMoney(tp.gross_profit || 0));

    // Operating Expenses
    $('#expensesSubtotal').text(formatMoney(t.total_expenses));
    $('#expensesPrevSubtotal').text(formatMoney(tp.total_expenses || 0));

    // Operating Profit (EBIT)
    $('#operatingProfit').text(formatMoney(t.operating_profit || 0));
    $('#operatingProfitPrev').text(formatMoney(tp.operating_profit || 0));

    // Other Income — show/hide section when zero
    const oi     = +(t.other_income || 0);
    const oi_prv = +(tp.other_income || 0);
    $('#otherIncomeSubtotal').text(formatMoney(oi));
    $('#otherIncomePrevSubtotal').text(formatMoney(oi_prv));
    if (Math.abs(oi) < 0.001 && Math.abs(oi_prv) < 0.001) {
        $('#otherIncomeSection, #otherIncomeSubtotalRow').addClass('d-none');
    } else {
        $('#otherIncomeSection, #otherIncomeSubtotalRow').removeClass('d-none');
    }

    // Finance Costs — always shown (required on face per IAS 1) but collapsed when zero
    const fc     = +(t.finance_costs || 0);
    const fc_prv = +(tp.finance_costs || 0);
    $('#financeSubtotal').text(formatMoney(fc));
    $('#financePrevSubtotal').text(formatMoney(fc_prv));
    if (Math.abs(fc) < 0.001 && Math.abs(fc_prv) < 0.001) {
        $('#financeSubtotalRow').addClass('d-none');
    } else {
        $('#financeSubtotalRow').removeClass('d-none');
    }

    // Income Tax (placeholder until accountant posts it)
    $('#incomeTax').text(formatMoney(t.income_tax || 0));
    $('#incomeTaxPrev').text(formatMoney(tp.income_tax || 0));

    // Profit Before Tax
    $('#profitBeforeTax').text(formatMoney(t.profit_before_tax || 0));
    $('#profitBeforeTaxPrev').text(formatMoney(tp.profit_before_tax || 0));

    // Net Profit For Period (bottom line)
    $('#netIncomeFinal').text(formatMoney(t.net_profit));
    $('#netIncomePrev').text(formatMoney(tp.net_profit || 0));

    // Margin labels next to Gross Profit / EBIT / Net Profit headers
    $('#grossMarginLabel').text(t.gross_margin_pct ? `(${t.gross_margin_pct}% of revenue)` : '');
    $('#operatingMarginLabel').text(t.operating_margin_pct ? `(${t.operating_margin_pct}% of revenue)` : '');
    $('#netMarginLabel').text(t.net_margin_pct ? `(${t.net_margin_pct}% of revenue)` : '');

    // Summary card values
    $('#totalRevenue, #printTotalRev').text(formatMoney(t.total_revenue));
    $('#totalCOGS, #printTotalCogs').text(formatMoney(t.total_cogs));
    $('#totalExpenses, #printTotalExp').text(formatMoney(t.total_expenses));
    $('#netIncome, #printNetIncome').text(formatMoney(t.net_profit));

    // Period label in the card header — includes project name when filter is active
    const meta = data.meta || {};
    let periodText = '';
    if (meta.current_start && meta.current_end) {
        periodText = `${meta.current_start} → ${meta.current_end}`;
    }
    if (meta.project_filter_active) {
        const projectName = $('#project_id option:selected').text();
        periodText = `Project: ${projectName} • ${periodText}`;
    }
    $('#periodLabel').text(periodText);

    // ── Posting / classification / payroll / project-filter banners ──────
    const draft = (meta.draft_count|0);
    if (draft > 0) {
        $('#postingWarningText').text(`${draft} draft journal entr${draft === 1 ? 'y' : 'ies'} exist in this period and are excluded. Post them in Finance → Journal Entries to include them.`);
        $('#postingWarning').removeClass('d-none');
    } else {
        $('#postingWarning').addClass('d-none');
    }

    const unc = (meta.unclassified_count|0);
    if (unc > 0) {
        $('#classificationWarningText').text(`${unc} account type(s) are not yet classified. Their activity may be missing from the P&L — classify them via Settings → Account Types.`);
        $('#classificationWarning').removeClass('d-none');
    } else {
        $('#classificationWarning').addClass('d-none');
    }

    const unpaidPayroll = (meta.unpaid_payroll_count|0);
    if (unpaidPayroll > 0) {
        $('#unpaidPayrollText').text(`${unpaidPayroll} payroll run${unpaidPayroll === 1 ? '' : 's'} ${unpaidPayroll === 1 ? 'is' : 'are'} not yet marked Paid in this period — staff compensation may be under-reported.`);
        $('#unpaidPayrollWarning').removeClass('d-none');
    } else {
        $('#unpaidPayrollWarning').addClass('d-none');
    }

    if (meta.project_filter_active) {
        $('#projectFilterNotice').removeClass('d-none');
    } else {
        $('#projectFilterNotice').addClass('d-none');
    }

    // Non-admin: show scope caption so they can see exactly what they're viewing.
    if (meta.is_admin === false) {
        const ids = Array.isArray(meta.scoped_project_ids) ? meta.scoped_project_ids : [];
        const label = ids.length === 0
            ? 'You have no projects assigned. Only company-wide untagged activity is included.'
            : `Viewing your scoped data: ${ids.length} assigned project${ids.length === 1 ? '' : 's'} + company-wide untagged activity. Manual journal entries are excluded.`;
        $('#scopedAccessText').text(label);
        $('#scopedAccessNotice').removeClass('d-none');
    } else {
        $('#scopedAccessNotice').addClass('d-none');
    }
}

function formatMoney(n) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
}

function exportExcel() {
    const start = $('#start_date').val();
    const end = $('#end_date').val();
    const projectId = $('#project_id').val() || '';
    window.location.href = `<?= buildUrl('api/account/export_income_statement.php') ?>?start_date=${start}&end_date=${end}&project_id=${projectId}`;
}
</script>

<style>
    .card { border-radius: 12px; }
    .table thead th { border-top: none; }
    .table tbody td { border-bottom: 1px solid #f0f0f0; }
    @media print {
        .d-print-none, #summaryCards { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; }
        .table td { border: 1px solid #dee2e6 !important; }
        .container-fluid { padding: 0 !important; }
        footer, .sidebar, .navbar { display: none !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>