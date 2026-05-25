<?php
// File: reps/daily_sales.php
// scope-audit: skip — read-only sales report; UNION ALL across invoices+pos_sales; scope filter to be added in Phase G-2
// Short URL param aliases: g=group_by, n=limit, p=page, s=start_date, m=month_filter, q=quarter_filter, y=year_filter

// Phase 5c — partial; normally included by app/bms/invoice/reports.php
// (which already gates 'reports'), but a direct hit on this URL must
// also be denied. roots.php and the permission helpers are idempotent.
require_once __DIR__ . '/../../../../roots.php';
if (!canView('reports')) {
    http_response_code(403);
    die("Access Denied");
}

$group_by    = $_GET['g'] ?? $_GET['group_by'] ?? 'daily';
$limit       = $_GET['n'] ?? $_GET['limit']    ?? '10';
$page        = (int)($_GET['p'] ?? $_GET['page'] ?? 1);
if ($page < 1) $page = 1;

// Initialize dates
$start_date = $_GET['s'] ?? $_GET['start_date'] ?? '';
$end_date   = '';

// Specific Filtering Logic for specialized periods
if ($group_by === 'daily') {
    $start_date = $_GET['start_date'] ?? date('Y-m-d');
    $end_date = $start_date;
} elseif ($group_by === 'weekly') {
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week'));
    $end_date = date('Y-m-d', strtotime($start_date . ' + 6 days'));
} elseif ($group_by === 'monthly') {
    $month_input = $_GET['m'] ?? $_GET['month_filter'] ?? date('Y-m');
    $start_date = $month_input . "-01";
    $end_date = date('Y-m-t', strtotime($start_date));
} elseif ($group_by === 'quarterly') {
    $year    = $_GET['y'] ?? $_GET['year_filter']    ?? date('Y');
    $quarter = $_GET['q'] ?? $_GET['quarter_filter'] ?? ceil(date('n') / 3);
    $q_starts = [1 => '01-01', 2 => '04-01', 3 => '07-01', 4 => '10-01'];
    $q_ends   = [1 => '03-31', 2 => '06-30', 3 => '09-30', 4 => '12-31'];
    $start_date = "$year-" . $q_starts[$quarter];
    $end_date   = "$year-" . $q_ends[$quarter];
} elseif ($group_by === 'yearly') {
    $year = $_GET['y'] ?? $_GET['year_filter'] ?? date('Y');
    $start_date = "$year-01-01";
    $end_date   = "$year-12-31";
} else {
    $start_date = $start_date ?: date('Y-01-01');
    $end_date = $end_date ?: date('Y-m-d');
}

try {
    global $pdo;
    
    // 1. Get Totals and Count
    $sql_total = "
        SELECT 
            COUNT(*) as total_count, 
            SUM(discount_amount) as total_discount, 
            SUM(tax_amount) as total_tax, 
            SUM(grand_total) as total_revenue
        FROM (
            SELECT 1 as sale_count, discount_amount, tax_amount, grand_total, sale_date as report_date FROM pos_sales WHERE DATE(sale_date) BETWEEN ? AND ? AND sale_status = 'completed'
            UNION ALL
            SELECT 1 as sale_count, discount_amount, tax_amount, grand_total, invoice_date as report_date FROM invoices WHERE invoice_date BETWEEN ? AND ? AND status != 'cancelled'
        ) AS combined_totals
    ";
    $stmt_total = $pdo->prepare($sql_total);
    $stmt_total->execute([$start_date, $end_date, $start_date, $end_date]);
    $summary = $stmt_total->fetch(PDO::FETCH_ASSOC);
    
    $final_total_qty = $summary['total_count'] ?? 0;
    $final_total_disc = $summary['total_discount'] ?? 0;
    $final_total_tax = $summary['total_tax'] ?? 0;
    $final_total_grand = $summary['total_revenue'] ?? 0;

    // Pagination constants
    $total_pages = 1;
    $offset = 0;
    if ($limit !== 'all') {
        $limit_val = (int)$limit;
        $total_pages = ceil($final_total_qty / $limit_val);
        if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
        $offset = ($page - 1) * $limit_val;
    }

    // 2. Get Transactional Data for Table
    $limit_sql = ($limit === 'all') ? "" : " LIMIT " . (int)$limit_val . " OFFSET " . (int)$offset;
    $sql_data = "
        SELECT * FROM (
            SELECT receipt_number as reference, sale_date as report_date, 1 as sale_count, discount_amount, tax_amount, grand_total as total_amount, 'POS' as source
            FROM pos_sales WHERE DATE(sale_date) BETWEEN ? AND ? AND sale_status = 'completed'
            UNION ALL
            SELECT invoice_number as reference, invoice_date as report_date, 1 as sale_count, discount_amount, tax_amount, grand_total as total_amount, 'Invoice' as source
            FROM invoices WHERE invoice_date BETWEEN ? AND ? AND status != 'cancelled'
        ) AS combined_data
        ORDER BY report_date DESC
        $limit_sql
    ";
    $stmt_data = $pdo->prepare($sql_data);
    $stmt_data->execute([$start_date, $end_date, $start_date, $end_date]);
    $results = $stmt_data->fetchAll(PDO::FETCH_ASSOC);
    
    $report_data = [];
    foreach ($results as $row) {
        $timestamp = strtotime($row['report_date']);
        $report_data[] = [
            'label' => $row['reference'] . ' <small class="text-muted d-block">' . date('d M Y, H:i', $timestamp) . '</small>',
            'sale_count' => 1,
            'total_discount' => $row['discount_amount'] ?? 0,
            'total_tax' => $row['tax_amount'] ?? 0,
            'grand_total' => $row['total_amount'],
            'source' => $row['source']
        ];
    }
} catch (Exception $e) { $error = $e->getMessage(); }

// Check if this is an AJAX request to return only the dynamic parts
if (isset($_GET['ajax'])) {
    if (ob_get_level()) ob_clean();
    ?>
    <div id="ajax-response">
        <!-- Part 1: Cards Update -->
        <div id="ajax-cards">
            <div class="col-md-4"><div class="card border-0 shadow-sm stat-mini-card h-100"><div class="card-body py-4"><h6 class="text-uppercase small fw-bold mb-3 opacity-75">Total Period Revenue</h6><h2 class="fw-bold mb-0"><?= format_currency($final_total_grand) ?></h2></div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm stat-mini-card h-100"><div class="card-body py-4"><h6 class="text-uppercase small fw-bold mb-3 opacity-75">Total Transactions</h6><h2 class="fw-bold mb-0"><?= number_format($final_total_qty) ?></h2></div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm stat-mini-card h-100"><div class="card-body py-4"><h6 class="text-uppercase small fw-bold mb-3 opacity-75">Avg. Value per Sale</h6><h2 class="fw-bold mb-0"><?= $final_total_qty > 0 ? format_currency($final_total_grand / $final_total_qty) : 'TZS 0.00' ?></h2></div></div></div>
        </div>

        <!-- Part 2: Table Rows -->
        <div id="ajax-table-rows">
            <?php if (!empty($report_data)): ?>
                <?php $sno = ($limit === 'all') ? 1 : (($page - 1) * $limit_val + 1); foreach ($report_data as $row): ?>
                    <tr>
                        <td class="ps-4 col-sno"><?= $sno++ ?></td>
                        <td class="fw-bold col-ref">
                            <span class="text-primary"><?= $row['label'] ?></span>
                            <span class="badge <?= $row['source'] === 'POS' ? 'bg-info' : 'bg-success' ?> ms-2 small" style="font-size: 0.65rem; font-weight: normal;"><?= $row['source'] ?></span>
                        </td>
                        <td class="text-center col-count"><span class="badge bg-light text-dark border px-3"><?= number_format($row['sale_count']) ?></span></td>
                        <td class="text-end text-muted small col-disc"><?= format_currency($row['total_discount']) ?></td>
                        <td class="text-end text-muted small col-tax"><?= format_currency($row['total_tax']) ?></td>
                        <td class="text-end pe-4 fw-bold text-primary col-revenue"><?= format_currency($row['grand_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="table-light fw-bold" style="border-top: 2px solid #dee2e6;">
                    <td class="ps-4 col-sno" colspan="2">PERIOD TOTAL</td>
                    <td class="text-center col-count"><?= number_format($final_total_qty) ?></td>
                    <td class="text-end col-disc"><?= format_currency($final_total_disc) ?></td>
                    <td class="text-end col-tax"><?= format_currency($final_total_tax) ?></td>
                    <td class="text-end pe-4 fs-5 text-primary col-revenue"><?= format_currency($final_total_grand) ?></td>
                </tr>
            <?php else: ?>
                <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox display-4 d-block mb-3 opacity-25"></i>No sales records found.</td></tr>
            <?php endif; ?>
        </div>

        <!-- Part 3: Pagination Update -->
        <div id="ajax-pagination">
            <div class="d-flex justify-content-between align-items-center">
                <div class="small text-muted">
                    Showing <?= $offset + 1 ?> to <?= min($offset + (int)($limit === 'all' ? $final_total_qty : $limit), $final_total_qty) ?> of <?= number_format($final_total_qty) ?> transactions
                </div>
                <?php if ($limit !== 'all' && $total_pages > 1): ?>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="javascript:void(0)" onclick="updateReport(null, <?= $page - 1 ?>)"><i class="bi bi-chevron-left"></i></a>
                            </li>
                            <?php 
                            $start_p = max(1, $page - 2);
                            $end_p = min($total_pages, $page + 2);
                            for ($p = $start_p; $p <= $end_p; $p++): 
                            ?>
                                <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="javascript:void(0)" onclick="updateReport(null, <?= $p ?>)"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="javascript:void(0)" onclick="updateReport(null, <?= $page + 1 ?>)"><i class="bi bi-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php exit;
}
?>

<style>
    .report-filter-pill { border-radius: 50px; padding: 6px 20px; font-weight: 600; font-size: 0.85rem; transition: all 0.3s; border: 1px solid #dee2e6; color: #6c757d; background: #fff; text-decoration: none; display: inline-block; }
    .report-filter-pill:hover { background: #f8f9fa; color: #0d6efd; }
    .report-filter-pill.active { background: #0d6efd; color: #fff; border-color: #0d6efd; box-shadow: 0 4px 10px rgba(13, 110, 253, 0.2); }
    .stat-mini-card { border-radius: 15px; border-left: 5px solid #198754; background-color: #d1e7dd !important; color: #0a3622; }
    
    /* Column Width Stabilization */
    .col-sno { width: 60px !important; min-width: 60px !important; }
    .col-ref { width: auto !important; min-width: 250px !important; }
    .col-count { width: 100px !important; min-width: 100px !important; }
    .col-disc { width: 140px !important; min-width: 140px !important; }
    .col-tax { width: 140px !important; min-width: 140px !important; }
    .col-revenue { width: 160px !important; min-width: 160px !important; }
    
    table.table-stabilized { table-layout: fixed !important; width: 100% !important; min-width: 900px !important; }
    
    .pagination .page-link { border-radius: 8px; margin: 0 2px; border: none; background: #f8f9fa; color: #444; }
    .pagination .page-item.active .page-link { background: #0d6efd; color: #fff; }
    @media print {
        @page { size: auto; margin: 10mm; }
        body { background: #white !important; }
        nav, footer, .navbar, .breadcrumb, .no-print, .d-print-none, .pagination-area { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
        .stat-mini-card { border: 1px solid #dee2e6 !important; background-color: #f8f9fa !important; color: #000 !important; }
        .table { width: 100% !important; border-collapse: collapse !important; }
        .table th, .table td { border: 1px solid #dee2e6 !important; padding: 8px !important; }
    }
</style>

<!-- Print Header -->
<div class="d-none d-print-block text-center mb-4 text-dark">
    <?php 
    $c_name = getSetting('company_name', 'BMS');
    $c_logo = getSetting('company_logo', '');
    ?>
    <?php if(!empty($c_logo)): ?>
        <div class="mb-3"><img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 100px; width: auto;"></div>
    <?php endif; ?>
    <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= safe_output($c_name) ?></h1>
    <h2 style="font-weight: 800; text-transform: uppercase; margin-top: 10px;"><?= strtoupper($group_by) ?> SALES REPORT</h2>
    <hr style="border: 2px solid #0d6efd; width: 50%; margin: 15px auto;">
</div>

<div id="dynamic-report-container">
    <!-- Summary Cards Row -->
    <div class="row g-4 mb-4" id="stat-cards-area">
        <div class="col-md-4"><div class="card border-0 shadow-sm stat-mini-card h-100"><div class="card-body py-4"><h6 class="text-uppercase small fw-bold mb-3 opacity-75">Total Period Revenue</h6><h2 class="fw-bold mb-0"><?= format_currency($final_total_grand) ?></h2></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm stat-mini-card h-100"><div class="card-body py-4"><h6 class="text-uppercase small fw-bold mb-3 opacity-75">Total Transactions</h6><h2 class="fw-bold mb-0"><?= number_format($final_total_qty) ?></h2></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm stat-mini-card h-100"><div class="card-body py-4"><h6 class="text-uppercase small fw-bold mb-3 opacity-75">Avg. Value per Sale</h6><h2 class="fw-bold mb-0"><?= $final_total_qty > 0 ? format_currency($final_total_grand / $final_total_qty) : 'TZS 0.00' ?></h2></div></div></div>
    </div>

    <div class="card shadow-sm border-0 mb-4" id="main-report-card">
        <div class="card-header bg-white py-4 d-print-none">
            <div class="row g-3 align-items-center">
                <!-- Left Side: Period Buttons and Actions -->
                <div class="col-lg-6">
                    <!-- Period Filter Row -->
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php 
                        $group_options = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];
                        foreach ($group_options as $key => $label): 
                        ?>
                            <a href="javascript:void(0)" class="report-filter-pill <?= $group_by == $key ? 'active' : '' ?>" onclick="updateReport('<?= $key ?>')"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Sub-Actions Row (Print and Limit) -->
                    <div class="d-flex align-items-center gap-3">
                        <button class="btn btn-outline-secondary btn-sm rounded-pill px-4 fw-bold" onclick="window.print()">
                            <i class="bi bi-printer me-2"></i>PRINT REPORT
                        </button>
                        
                        <div class="d-flex align-items-center bg-white border px-3 py-1 rounded-pill shadow-sm" style="font-size: 0.85rem;">
                            <span class="small text-muted me-2 font-monospace text-uppercase">Show:</span>
                            <select class="form-select form-select-sm border-0 p-0 shadow-none fw-bold" id="report_limit" style="width: auto; min-width: 60px; background: none; cursor: pointer;" onchange="updateReport()">
                                <option value="10" <?= $limit == '10' ? 'selected' : '' ?>>10</option>
                                <option value="25" <?= $limit == '25' ? 'selected' : '' ?>>25</option>
                                <option value="50" <?= $limit == '50' ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $limit == '100' ? 'selected' : '' ?>>100</option>
                                <option value="all" <?= $limit == 'all' ? 'selected' : '' ?>>All</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Right Side: Specific Date Filters -->
                <div class="col-lg-6">
                    <form id="filterForm" class="row g-2 justify-content-lg-end" onsubmit="event.preventDefault(); updateReport();">
                        <?php if ($group_by === 'daily'): ?>
                            <div class="col-auto"><label class="small text-muted d-block fw-bold text-uppercase mb-1">Date</label><input type="date" class="form-control form-control-sm border-0 bg-light" id="f_start_date" value="<?= $start_date ?>" style="min-width: 140px;"></div>
                        <?php elseif ($group_by === 'weekly'): ?>
                            <div class="col-auto"><label class="small text-muted d-block fw-bold text-uppercase mb-1">Week Starting</label><input type="date" class="form-control form-control-sm border-0 bg-light" id="f_start_date" value="<?= $start_date ?>"></div>
                        <?php elseif ($group_by === 'monthly'): ?>
                            <div class="col-auto"><label class="small text-muted d-block fw-bold text-uppercase mb-1">Month</label><input type="month" class="form-control form-control-sm border-0 bg-light" id="f_month_filter" value="<?= $_GET['m'] ?? $_GET['month_filter'] ?? date('Y-m') ?>" style="min-width: 140px;"></div>
                        <?php elseif ($group_by === 'quarterly'): ?>
                            <div class="col-auto"><label class="small text-muted d-block fw-bold text-uppercase mb-1">Quarter</label><select class="form-select form-select-sm border-0 bg-light" id="f_quarter_filter"><option value="1" <?= ($quarter ?? ceil(date('n')/3)) == 1 ? 'selected' : '' ?>>Q1</option><option value="2" <?= ($quarter ?? 0) == 2 ? 'selected' : '' ?>>Q2</option><option value="3" <?= ($quarter ?? 0) == 3 ? 'selected' : '' ?>>Q3</option><option value="4" <?= ($quarter ?? 0) == 4 ? 'selected' : '' ?>>Q4</option></select></div>
                            <div class="col-auto"><label class="small text-muted d-block fw-bold text-uppercase mb-1">Year</label><input type="number" class="form-control form-control-sm border-0 bg-light" id="f_year_filter" value="<?= $year ?? date('Y') ?>" min="2000" style="width:80px;"></div>
                        <?php elseif ($group_by === 'yearly'): ?>
                            <div class="col-auto"><label class="small text-muted d-block fw-bold text-uppercase mb-1">Year</label><input type="number" class="form-control form-control-sm border-0 bg-light" id="f_year_filter" value="<?= $year ?? date('Y') ?>" min="2000" style="width:100px;"></div>
                        <?php endif; ?>
                        <div class="col-auto align-self-end"><button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm fw-bold">REFRESH</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-dark w-100 table-stabilized">
                <thead class="bg-light text-uppercase small fw-bold">
                    <tr>
                        <th class="ps-4 col-sno">S/NO</th>
                        <th class="col-ref">Transaction Ref</th>
                        <th class="text-center col-count">Sale Count</th>
                        <th class="text-end col-disc">Discount</th>
                        <th class="text-end col-tax">Tax</th>
                        <th class="text-end pe-4 col-revenue">Revenue</th>
                    </tr>
                </thead>
                <tbody id="table-body-area">
                    <?php if (!empty($report_data)): ?>
                        <?php $sno = ($limit === 'all') ? 1 : (($page - 1) * $limit_val + 1); foreach ($report_data as $row): ?>
                            <tr>
                                <td class="ps-4 col-sno"><?= $sno++ ?></td>
                                <td class="fw-bold col-ref">
                                    <span class="text-primary"><?= $row['label'] ?></span>
                                    <span class="badge <?= $row['source'] === 'POS' ? 'bg-info' : 'bg-success' ?> ms-2 small" style="font-size: 0.65rem; font-weight: normal;"><?= $row['source'] ?></span>
                                </td>
                                <td class="text-center col-count"><span class="badge bg-light text-dark border px-3"><?= number_format($row['sale_count']) ?></span></td>
                                <td class="text-end text-muted small col-disc"><?= format_currency($row['total_discount']) ?></td>
                                <td class="text-end text-muted small col-tax"><?= format_currency($row['total_tax']) ?></td>
                                <td class="text-end pe-4 fw-bold text-primary col-revenue"><?= format_currency($row['grand_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-light fw-bold" style="border-top: 2px solid #dee2e6;">
                            <td class="ps-4 col-sno" colspan="2">PERIOD TOTAL</td>
                            <td class="text-center col-count"><?= number_format($final_total_qty) ?></td>
                            <td class="text-end col-disc"><?= format_currency($final_total_disc) ?></td>
                            <td class="text-end col-tax"><?= format_currency($final_total_tax) ?></td>
                            <td class="text-end pe-4 fs-5 text-primary col-revenue"><?= format_currency($final_total_grand) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox display-4 d-block mb-3 opacity-25"></i>No sales records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card-footer bg-white py-3 pagination-area d-print-none" id="pagination-footer">
            <div class="d-flex justify-content-between align-items-center">
                <div class="small text-muted">
                    Showing <?= $offset + 1 ?> to <?= min($offset + (int)($limit === 'all' ? $final_total_qty : $limit), $final_total_qty) ?> of <?= number_format($final_total_qty) ?> transactions
                </div>
                <?php if ($limit !== 'all' && $total_pages > 1): ?>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="javascript:void(0)" onclick="updateReport(null, <?= $page - 1 ?>)"><i class="bi bi-chevron-left"></i></a>
                            </li>
                            <?php 
                            $start_p = max(1, $page - 2);
                            $end_p = min($total_pages, $page + 2);
                            for ($p = $start_p; $p <= $end_p; $p++): 
                            ?>
                                <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="javascript:void(0)" onclick="updateReport(null, <?= $p ?>)"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="javascript:void(0)" onclick="updateReport(null, <?= $page + 1 ?>)"><i class="bi bi-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
var currentPeriod = '<?= $group_by ?>';

function buildReportUrl(period, pageNum, ajaxMode) {
    // Short param aliases: g=group_by, n=limit, p=page, s=start_date, m=month_filter, q=quarter_filter, y=year_filter
    const base = window.location.href.split('?')[0];
    const n    = $('#report_limit').val();
    const s    = $('#f_start_date').val()    || '';
    const m    = $('#f_month_filter').val()   || '';
    const q    = $('#f_quarter_filter').val() || '';
    const y    = $('#f_year_filter').val()    || '';

    let url = base + '?report=daily_sales&g=' + period + '&n=' + n + '&p=' + pageNum;
    if (s) url += '&s=' + encodeURIComponent(s);
    if (m) url += '&m=' + encodeURIComponent(m);
    if (q) url += '&q=' + encodeURIComponent(q);
    if (y) url += '&y=' + encodeURIComponent(y);
    if (ajaxMode) url += '&ajax=1';
    return url;
}

function updateReport(period, pageNum) {
    if (period) {
        currentPeriod = period;
        pageNum = 1;
        // Period change needs a full reload (different filter form)
        window.location.href = buildReportUrl(currentPeriod, 1, false);
        return;
    }
    pageNum = pageNum || 1;

    $('#main-report-card').css('opacity', '0.6');

    const ajaxUrl  = buildReportUrl(currentPeriod, pageNum, true);
    const cleanUrl = buildReportUrl(currentPeriod, pageNum, false);

    $.get(ajaxUrl, function(data) {
        const resp = $('<div>').html(data);

        const cardsHtml      = resp.find('#ajax-cards').html();
        const tableHtml      = resp.find('#ajax-table-rows').html();
        const paginationHtml = resp.find('#ajax-pagination').html();

        if (cardsHtml)      $('#stat-cards-area').html(cardsHtml);
        if (tableHtml)      $('#table-body-area').html(tableHtml);
        if (paginationHtml) $('#pagination-footer').html(paginationHtml);

        $('#main-report-card').css('opacity', '1');

        setTimeout(function() { $(window).trigger('resize'); }, 50);

        // Push clean, short URL to browser history
        window.history.replaceState({path: cleanUrl}, '', cleanUrl);

        $('html, body').animate({
            scrollTop: $('#main-report-card').offset().top - 100
        }, 200);
    });
}
</script>
