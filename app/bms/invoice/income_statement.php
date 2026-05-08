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
        <?php 
        $c_name = getSetting('company_name', 'BMS');
        $c_logo = getSetting('company_logo', '');
        
        ?>
        <?php if(!empty($c_logo)): ?>
            <div class="mb-3 text-center">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;" class="text-center"><?= safe_output($c_name) ?></h1>
        
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

    <!-- Detailed breakdown -->
    <div class="card border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="mb-0 fw-bold">Statement of Profit or Loss</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="reportTable">
                    <thead class="bg-light">
                        <tr class="text-muted small text-uppercase">
                            <th class="ps-4">Account Description</th>
                            <th class="text-end pe-4">Current Amount</th>
                        </tr>
                    </thead>
                    <tbody id="reportContent">
                        <!-- Revenue -->
                        <tr class="bg-light"><td class="ps-4 fw-bold">OPERATING REVENUE</td><td></td></tr>
                        <tbody id="revenueBody"></tbody>
                        <tr class="fw-bold table-primary"><td class="ps-4">Total Operating Revenue</td><td class="text-end pe-4" id="revenueSubtotal">0.00</td></tr>

                        <!-- COGS -->
                        <tr class="bg-light"><td class="ps-4 fw-bold">COST OF GOODS SOLD</td><td></td></tr>
                        <tbody id="cogsBody"></tbody>
                        <tr class="fw-bold table-warning"><td class="ps-4">Total Cost of Sales</td><td class="text-end pe-4" id="cogsSubtotal">0.00</td></tr>

                        <!-- Gross Profit -->
                        <tr class="bg-dark text-white fw-bold"><td class="ps-4">GROSS PROFIT</td><td class="text-end pe-4" id="grossProfit">0.00</td></tr>

                        <!-- Expenses -->
                        <tr class="bg-light"><td class="ps-4 fw-bold">OPERATING EXPENSES</td><td></td></tr>
                        <tbody id="expensesBody"></tbody>
                        <tr class="fw-bold table-danger"><td class="ps-4">Total Operating Expenses</td><td class="text-end pe-4" id="expensesSubtotal">0.00</td></tr>

                        <!-- Net Income -->
                        <tr class="table-success fw-bold fs-5"><td class="ps-4">NET INCOME / (LOSS)</td><td class="text-end pe-4" id="netIncomeFinal">0.00</td></tr>
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
    const revBody = $('#revenueBody');
    const cogsBody = $('#cogsBody');
    const expBody = $('#expensesBody');
    
    revBody.empty();
    cogsBody.empty();
    expBody.empty();
    
    let totalRev = 0, totalCogs = 0, totalExp = 0;
    
    if(data.revenue_accounts) {
        data.revenue_accounts.forEach(acc => {
            const val = parseFloat(acc.current_period);
            totalRev += val;
            revBody.append(`<tr><td class="ps-5">${acc.account_name} <small class="text-muted ms-2">${acc.account_code || ''}</small></td><td class="text-end pe-4">${formatMoney(val)}</td></tr>`);
        });
    }
    
    if(data.expense_accounts) {
        data.expense_accounts.forEach(acc => {
            const val = parseFloat(acc.current_period);
            if (acc.account_type === 'cost_of_sales' || acc.account_name.toLowerCase().includes('cost of goods')) {
                totalCogs += val;
                cogsBody.append(`<tr><td class="ps-5">${acc.account_name} <small class="text-muted ms-2">${acc.account_code || ''}</small></td><td class="text-end pe-4">${formatMoney(val)}</td></tr>`);
            } else {
                totalExp += val;
                expBody.append(`<tr><td class="ps-5">${acc.account_name} <small class="text-muted ms-2">${acc.account_code || ''}</small></td><td class="text-end pe-4">${formatMoney(val)}</td></tr>`);
            }
        });
    }

    const gross = totalRev - totalCogs;
    const net = gross - totalExp;

    $('#revenueSubtotal, #totalRevenue, #printTotalRev').text(formatMoney(totalRev));
    $('#cogsSubtotal, #totalCOGS, #printTotalCogs').text(formatMoney(totalCogs));
    $('#grossProfit').text(formatMoney(gross));
    $('#expensesSubtotal, #totalExpenses, #printTotalExp').text(formatMoney(totalExp));
    $('#netIncomeFinal, #netIncome, #printNetIncome').text(formatMoney(net));
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
</style>

<?php includeFooter(); ob_end_flush(); ?>