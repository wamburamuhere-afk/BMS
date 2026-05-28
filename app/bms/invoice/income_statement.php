<?php
// app/bms/invoice/income_statement.php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

autoEnforcePermission('income_statement');

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
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
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Period Start</label>
                    <input type="date" class="form-control rounded-3 border-light shadow-sm" id="start_date" name="start_date" value="<?= $start_date ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Period End</label>
                    <input type="date" class="form-control rounded-3 border-light shadow-sm" id="end_date" name="end_date" value="<?= $end_date ?>">
                </div>
                <div class="col-md-4">
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

                        <!-- Net Profit -->
                        <tr class="pl-net-profit"><td class="ps-4 fw-bold text-uppercase py-3" style="font-size: 1.0rem; letter-spacing: 1px;">NET PROFIT / (LOSS) <small class="text-muted ms-2 fw-normal" id="netMarginLabel"></small></td><td class="text-end font-monospace fw-semibold py-3" style="font-size: 1.0rem;" id="netIncomePrev">0.00</td><td class="text-end pe-4 fw-bold font-monospace py-3" style="font-size: 1.15rem; border-top: 3px double #0d6efd;" id="netIncomeFinal">0.00</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    loadReport();
    
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        loadReport();
    });
});

function loadReport() {
    const start = $('#start_date').val();
    const end = $('#end_date').val();
    
    if (typeof logReportAction === 'function') {
        logReportAction('Viewed P&L Statement', 'Analysis for ' + start + ' to ' + end);
    }

    $('body').css('cursor', 'wait');
    
    $.ajax({
        url: '<?= buildUrl('api/account/get_income_statement.php') ?>',
        type: 'GET',
        data: { start_date: start, end_date: end },
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
    renderLines($('#revenueBody'),  sections.revenue ? sections.revenue.lines  : []);
    renderLines($('#cogsBody'),     sections.cogs    ? sections.cogs.lines     : []);
    renderLines($('#expensesBody'), sections.expense ? sections.expense.lines  : []);

    // ── Server-computed totals (we no longer recompute on the client) ──
    const t  = data.totals;
    const tp = t.previous || {};
    $('#revenueSubtotal').text(formatMoney(t.total_revenue));
    $('#revenuePrevSubtotal').text(formatMoney(tp.total_revenue || 0));
    $('#cogsSubtotal').text(formatMoney(t.total_cogs));
    $('#cogsPrevSubtotal').text(formatMoney(tp.total_cogs || 0));
    $('#expensesSubtotal').text(formatMoney(t.total_expenses));
    $('#expensesPrevSubtotal').text(formatMoney(tp.total_expenses || 0));
    $('#grossProfit').text(formatMoney(t.gross_profit));
    $('#grossProfitPrev').text(formatMoney(tp.gross_profit || 0));
    $('#netIncomeFinal').text(formatMoney(t.net_profit));
    $('#netIncomePrev').text(formatMoney(tp.net_profit || 0));

    // Margin labels next to Gross Profit / Net Profit headers
    $('#grossMarginLabel').text(t.gross_margin_pct ? `(${t.gross_margin_pct}% of revenue)` : '');
    $('#netMarginLabel').text(t.net_margin_pct ? `(${t.net_margin_pct}% of revenue)` : '');

    // Summary card values
    $('#totalRevenue, #printTotalRev').text(formatMoney(t.total_revenue));
    $('#totalCOGS, #printTotalCogs').text(formatMoney(t.total_cogs));
    $('#totalExpenses, #printTotalExp').text(formatMoney(t.total_expenses));
    $('#netIncome, #printNetIncome').text(formatMoney(t.net_profit));

    // Period label in the card header
    const meta = data.meta || {};
    if (meta.current_start && meta.current_end) {
        $('#periodLabel').text(`${meta.current_start} → ${meta.current_end}`);
    }

    // ── Posting / classification warnings ──────────────────────────────
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
}

function formatMoney(n) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
}

function exportExcel() {
    const start = $('#start_date').val();
    const end = $('#end_date').val();
    window.location.href = `<?= buildUrl('api/account/export_income_statement.php') ?>?start_date=${start}&end_date=${end}`;
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