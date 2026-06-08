<?php
// File: pos_dashboard.php — POS Dashboard (WorkDo-style analytics)
// scope-audit: skip — reads via api/pos/get_dashboard.php which applies project scope
ob_start();

require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('pos');

$page_title = 'POS Dashboard';
require_once 'header.php';

// View-page activity log (keeps security-coverage baseline intact).
logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed POS Dashboard');

$currency = getSetting('currency', 'TZS');
?>
<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0 text-primary"><i class="bi bi-speedometer2 me-2"></i>POS Dashboard</h4>
        <div class="d-flex gap-2">
            <a href="<?= getUrl('pos/sales-history') ?>" class="btn btn-outline-primary"><i class="bi bi-receipt me-1"></i> Sales History</a>
            <a href="<?= getUrl('pos') ?>" class="btn btn-primary"><i class="bi bi-bag-plus me-1"></i> Open POS</a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-5 fw-bold text-primary" id="stat-today-net">0.00</div>
                <div class="small text-muted">Today — Net Sales</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-5 fw-bold text-primary" id="stat-today-count">0</div>
                <div class="small text-muted">Today — Sales</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-5 fw-bold text-primary" id="stat-today-aov">0.00</div>
                <div class="small text-muted">Avg Sale (AOV)</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-5 fw-bold text-primary" id="stat-today-items">0</div>
                <div class="small text-muted">Items Sold Today</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-5 fw-bold text-primary" id="stat-month-net">0.00</div>
                <div class="small text-muted">This Month — Net</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-5 fw-bold text-primary" id="stat-low-stock">0</div>
                <div class="small text-muted">Low-Stock Items</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Trend chart -->
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 fw-bold text-primary"><i class="bi bi-graph-up me-1"></i> Sales Trend (last 14 days)</div>
                <div class="card-body"><canvas id="trendChart" height="110"></canvas></div>
            </div>
        </div>
        <!-- Top products -->
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 fw-bold text-primary"><i class="bi bi-trophy me-1"></i> Top Products (this month)</div>
                <div class="card-body p-2"><div id="topProducts" class="small">Loading…</div></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <!-- Low stock -->
        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 fw-bold text-primary"><i class="bi bi-exclamation-triangle me-1"></i> Low Stock</div>
                <div class="card-body p-2"><div id="lowStock" class="small">Loading…</div></div>
            </div>
        </div>
        <!-- Recent sales -->
        <div class="col-12 col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 fw-bold text-primary"><i class="bi bi-clock-history me-1"></i> Recent Sales</div>
                <div class="card-body p-2"><div id="recentSales" class="small">Loading…</div></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const DASH_URL    = '<?= buildUrl('api/pos/get_dashboard.php') ?>';
const RECEIPT_URL = '<?= buildUrl('api/pos/print_receipt.php') ?>';
const CURRENCY    = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
const money = n => (parseFloat(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
let trendChart;

function statusBadge(s) {
    const map = {
        completed: ['#0d6efd', '#fff'], paid: ['#052c65', '#fff'], partial: ['#cfe2ff', '#084298'],
        pending: ['#e9ecef', '#495057'], partially_refunded: ['#bfdbfe', '#1e3a8a'],
        refunded: ['#6c757d', '#fff'], voided: ['#dc3545', '#fff'], cancelled: ['#6c757d', '#fff']
    };
    const [bg, fg] = map[s] || ['#e9ecef', '#495057'];
    return `<span class="badge" style="background:${bg};color:${fg};padding:3px 8px;border-radius:20px;font-size:.68rem;">${safeOutput(s)}</span>`;
}

$(document).ready(loadDashboard);

function loadDashboard() {
    $.getJSON(DASH_URL, function (res) {
        if (!res.success) { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Load failed.' }); return; }
        const d = res.data;

        $('#stat-today-net').text(money(d.today.net));
        $('#stat-today-count').text(d.today.count);
        $('#stat-today-aov').text(money(d.today.aov));
        $('#stat-today-items').text((d.today.items_sold || 0).toLocaleString());
        $('#stat-month-net').text(money(d.month.net));
        $('#stat-low-stock').text(d.low_stock_count);

        // Trend chart
        const ctx = document.getElementById('trendChart').getContext('2d');
        if (trendChart) trendChart.destroy();
        trendChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: d.trend.map(t => t.label),
                datasets: [{ label: 'Net Sales (' + CURRENCY + ')', data: d.trend.map(t => t.net), backgroundColor: '#0d6efd', borderRadius: 4 }]
            },
            options: {
                responsive: true, maintainAspectRatio: true,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => CURRENCY + ' ' + money(c.parsed.y) } } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => v >= 1e6 ? (v/1e6)+'M' : (v >= 1e3 ? (v/1e3)+'k' : v) } } }
            }
        });

        // Top products
        if (!d.top_products.length) {
            $('#topProducts').html('<div class="text-muted text-center py-3">No sales this month</div>');
        } else {
            let html = '<table class="table table-sm mb-0"><tbody>';
            d.top_products.forEach((p, i) => {
                html += `<tr><td class="text-muted">${i + 1}.</td><td>${safeOutput(p.name)}</td>
                         <td class="text-end fw-bold">${(p.qty).toLocaleString()}</td>
                         <td class="text-end text-primary">${money(p.revenue)}</td></tr>`;
            });
            $('#topProducts').html(html + '</tbody></table>');
        }

        // Low stock
        if (!d.low_stock.length) {
            $('#lowStock').html('<div class="text-success text-center py-3"><i class="bi bi-check-circle me-1"></i>All stock above minimum</div>');
        } else {
            let html = '<table class="table table-sm mb-0"><thead><tr><th>Product</th><th class="text-end">In stock</th><th class="text-end">Min</th></tr></thead><tbody>';
            d.low_stock.forEach(s => {
                html += `<tr><td>${safeOutput(s.name)}</td>
                         <td class="text-end fw-bold ${s.current <= 0 ? 'text-danger' : 'text-primary'}">${(s.current).toLocaleString()}</td>
                         <td class="text-end text-muted">${(s.min).toLocaleString()}</td></tr>`;
            });
            $('#lowStock').html(html + '</tbody></table>');
        }

        // Recent sales
        if (!d.recent.length) {
            $('#recentSales').html('<div class="text-muted text-center py-3">No sales yet</div>');
        } else {
            let html = '<table class="table table-sm table-hover mb-0"><thead><tr><th>Receipt</th><th>Customer</th><th class="text-end">Total</th><th>Status</th></tr></thead><tbody>';
            d.recent.forEach(r => {
                const ret = r.is_return_sale ? '<i class="bi bi-arrow-return-left text-muted me-1"></i>' : '';
                html += `<tr style="cursor:pointer" onclick="window.open('${RECEIPT_URL}?id=${r.sale_id}','_blank')">
                         <td>${ret}${safeOutput(r.receipt_number)}</td>
                         <td>${safeOutput(r.party)}</td>
                         <td class="text-end fw-bold">${money(r.grand_total)}</td>
                         <td>${statusBadge(r.sale_status)}</td></tr>`;
            });
            $('#recentSales').html(html + '</tbody></table>');
        }
    }).fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error loading dashboard.' }));
}
</script>

<?php require_once 'footer.php'; ?>
