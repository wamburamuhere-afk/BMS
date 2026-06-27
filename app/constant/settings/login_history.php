<?php
/**
 * app/constant/settings/login_history.php
 * User Login History — who logged in, from where, on what device.
 * Admin-only. Data fed by user_sessions table enriched with GeoIP + UA parsing.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/session_tracker.php';

if (!isAuthenticated() || !isAdmin()) {
    header('Location: ' . getUrl('unauthorized'));
    exit;
}

$page_title = 'Login History';
includeHeader();

$users = $pdo->query("SELECT user_id, username FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
$stats = $pdo->query("
    SELECT
        COUNT(*)                                                AS total_logins,
        COUNT(DISTINCT user_id)                                AS unique_users,
        SUM(CASE WHEN DATE(login_at) = CURDATE() THEN 1 END)  AS today_logins,
        SUM(CASE WHEN logout_at IS NULL THEN 1 END)            AS active_now
    FROM user_sessions
")->fetch(PDO::FETCH_ASSOC);
?>

<div class="container-fluid py-4">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold text-primary"><i class="bi bi-clock-history me-2"></i>User Login History</h4>
            <p class="text-muted mb-0 small">Track who accessed the system, from where, and on what device</p>
        </div>
    </div>

    <!-- Stats cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary" id="stat-total"><?= number_format($stats['total_logins']) ?></div>
                <div class="small text-muted">Total Logins</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-success" id="stat-today"><?= intval($stats['today_logins']) ?></div>
                <div class="small text-muted">Today</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-info" id="stat-users"><?= intval($stats['unique_users']) ?></div>
                <div class="small text-muted">Unique Users</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-warning" id="stat-active"><?= intval($stats['active_now']) ?></div>
                <div class="small text-muted">Active Now</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border shadow-sm mb-4" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">User</label>
                    <select id="f-user" class="form-select" style="width:100%">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['user_id'] ?>"><?= safe_output($u['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                    <input type="date" id="f-from" class="form-control" value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                    <input type="date" id="f-to" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Search</label>
                    <input type="text" id="f-search" class="form-control" placeholder="IP, city, browser, ISP…">
                </div>
                <div class="col-md-2">
                    <button id="btn-filter" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter me-1"></i>Filter</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between py-3">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-list-columns-reverse me-2"></i>Login Records</h6>
            <span class="badge bg-primary" id="total-badge">—</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="loginTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">#</th>
                            <th>User</th>
                            <th>IP Address</th>
                            <th>Location &amp; Device</th>
                            <th>ISP / Org</th>
                            <th>Role</th>
                            <th>Login Time</th>
                            <th class="pe-3">Duration</th>
                        </tr>
                    </thead>
                    <tbody id="loginTbody">
                        <tr><td colspan="8" class="text-center py-4 text-muted">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white border-top py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div id="dt-info" class="small text-muted"></div>
            <div id="dt-pager" class="d-flex gap-1"></div>
        </div>
    </div>

</div>

<script>
function safeOutput(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

(function () {
    'use strict';

    const API = '<?= buildUrl('api/get_login_history.php') ?>';
    let draw = 0, currentPage = 0, perPage = 25, totalFiltered = 0;

    function deviceIcon(type) {
        if (!type) return '';
        const icons = { Mobile: 'bi-phone', Tablet: 'bi-tablet', Desktop: 'bi-display' };
        return `<i class="bi ${icons[type] || 'bi-laptop'} me-1"></i>`;
    }

    function roleColor(role) {
        if (!role) return 'secondary';
        const r = role.toLowerCase();
        if (r.includes('admin'))       return 'danger';
        if (r.includes('manager'))     return 'warning';
        if (r.includes('accountant'))  return 'info';
        if (r.includes('hr'))          return 'success';
        return 'primary';
    }

    function formatDur(secs) {
        if (!secs) return '—';
        secs = parseInt(secs);
        const h = Math.floor(secs / 3600);
        const m = Math.floor((secs % 3600) / 60);
        const s = secs % 60;
        if (h > 0) return `${h}h ${String(m).padStart(2,'0')}m`;
        if (m > 0) return `${m}m ${String(s).padStart(2,'0')}s`;
        return `${s}s`;
    }

    function load(page) {
        page = page || 0;
        const userId   = $('#f-user').val() || '';
        const dateFrom = $('#f-from').val() || '';
        const dateTo   = $('#f-to').val()   || '';
        const search   = $('#f-search').val().trim();

        draw++;
        const localDraw = draw;

        $.ajax({
            url: API,
            data: {
                draw:      localDraw,
                start:     page * perPage,
                length:    perPage,
                user_id:   userId,
                date_from: dateFrom,
                date_to:   dateTo,
                search:    { value: search }
            },
            dataType: 'json',
            success: function (res) {
                if (res.draw < localDraw) return; // stale response
                currentPage   = page;
                totalFiltered = res.recordsFiltered || 0;

                $('#total-badge').text(totalFiltered.toLocaleString());
                $('#dt-info').text(
                    totalFiltered === 0 ? 'No records found'
                    : `Showing ${page * perPage + 1}–${Math.min((page + 1) * perPage, totalFiltered)} of ${totalFiltered.toLocaleString()}`
                );

                renderRows(res.data || [], page * perPage);
                renderPager();
            },
            error: function () {
                $('#loginTbody').html('<tr><td colspan="8" class="text-center text-danger py-4">Failed to load data</td></tr>');
            }
        });
    }

    function renderRows(rows, offset) {
        if (!rows.length) {
            $('#loginTbody').html('<tr><td colspan="8" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No login records found</td></tr>');
            return;
        }

        let html = '';
        rows.forEach((r, i) => {
            // Location: City line + Region/Country line
            const city    = r.city    || '';
            const region  = r.region  || '';
            const country = r.country || '';
            const locLine1 = city || '—';
            const locLine2 = [region, country].filter(Boolean).join(', ');

            // Timezone: "Africa/Dar_es_Salaam" → "Dar es Salaam (Africa)"
            let tzDisplay = '';
            if (r.timezone && r.timezone !== 'Local') {
                const parts = r.timezone.split('/');
                const tzCity = (parts[1] || parts[0]).replace(/_/g, ' ');
                const tzArea = parts.length > 1 ? parts[0] : '';
                tzDisplay = tzCity + (tzArea ? ` (${tzArea})` : '');
            }

            const dev  = r.device   || '—';
            const isp  = [r.isp, r.org].filter(Boolean).join(' / ') || '—';
            const tzHtml = tzDisplay
                ? `<div class="text-muted" style="font-size:.75rem"><i class="bi bi-clock me-1"></i>${safeOutput(tzDisplay)}</div>`
                : '';
            const statusBadge = !r.logout_at
                ? '<span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Active</span>'
                : '';

            html += `
            <tr>
                <td class="ps-3 text-muted small">${offset + i + 1}</td>
                <td>
                    <div class="fw-semibold">${safeOutput(r.username)}</div>
                    <div class="text-muted small">${safeOutput(r.email)}</div>
                </td>
                <td>
                    <code class="small">${safeOutput(r.ip_address) || '—'}</code>
                </td>
                <td>
                    <div class="fw-semibold small">${safeOutput(locLine1)}</div>
                    ${locLine2 ? `<div class="text-muted small">${safeOutput(locLine2)}</div>` : ''}
                    <div class="text-muted small">${deviceIcon(r.device_type)}${safeOutput(dev)}</div>
                    ${tzHtml}
                </td>
                <td>
                    <div class="small">${safeOutput(isp)}</div>
                </td>
                <td>
                    ${r.role_name ? `<span class="badge bg-${roleColor(r.role_name)}">${safeOutput(r.role_name)}</span>` : '—'}
                </td>
                <td>
                    <div class="small fw-semibold">${r.login_at ? new Date(r.login_at).toLocaleString() : '—'}</div>
                    ${statusBadge}
                </td>
                <td class="pe-3 text-muted small">${formatDur(r.duration_seconds)}</td>
            </tr>`;
        });
        $('#loginTbody').html(html);
    }

    function renderPager() {
        const totalPages = Math.ceil(totalFiltered / perPage);
        if (totalPages <= 1) { $('#dt-pager').html(''); return; }

        let html = '';
        // Prev
        html += `<button class="btn btn-sm btn-outline-secondary" onclick="goPage(${currentPage - 1})" ${currentPage === 0 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i></button>`;
        // Page numbers (show at most 5 around current)
        const start = Math.max(0, currentPage - 2);
        const end   = Math.min(totalPages - 1, currentPage + 2);
        if (start > 0) html += `<button class="btn btn-sm btn-outline-secondary" onclick="goPage(0)">1</button>${start > 1 ? '<span class="btn btn-sm disabled">…</span>' : ''}`;
        for (let p = start; p <= end; p++) {
            html += `<button class="btn btn-sm ${p === currentPage ? 'btn-primary' : 'btn-outline-secondary'}" onclick="goPage(${p})">${p + 1}</button>`;
        }
        if (end < totalPages - 1) html += `${end < totalPages - 2 ? '<span class="btn btn-sm disabled">…</span>' : ''}<button class="btn btn-sm btn-outline-secondary" onclick="goPage(${totalPages - 1})">${totalPages}</button>`;
        // Next
        html += `<button class="btn btn-sm btn-outline-secondary" onclick="goPage(${currentPage + 1})" ${currentPage >= totalPages - 1 ? 'disabled' : ''}><i class="bi bi-chevron-right"></i></button>`;

        $('#dt-pager').html(html);
    }

    window.goPage = function (p) {
        const totalPages = Math.ceil(totalFiltered / perPage);
        if (p < 0 || p >= totalPages) return;
        load(p);
    };

    // Init Select2 on user filter
    $(document).ready(function () {
        if ($.fn.select2) {
            $('#f-user').select2({ theme: 'bootstrap-5', placeholder: 'All Users', allowClear: true, width: '100%' });
        }

        $('#btn-filter').on('click', function () { load(0); });
        $('#f-search').on('keydown', function (e) { if (e.key === 'Enter') load(0); });

        load(0);
    });
}());
</script>

<?php require_once __DIR__ . '/../../../footer.php'; ?>
