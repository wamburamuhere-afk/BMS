<?php
/**
 * app/constant/settings/login_history.php
 * User Login History — who logged in, from where, on what device.
 * Admin-only. Data fed by user_sessions table enriched with GeoIP + UA parsing.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/session_tracker.php';

autoEnforcePermission('login_history');

if (!isAdmin()) {
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

    <!-- Table / Card -->
    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between py-3">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-list-columns-reverse me-2"></i>Login Records</h6>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-primary" id="total-badge">—</span>
                <!-- View toggle — desktop only -->
                <div class="btn-group d-none d-md-flex" id="viewToggle" role="group" aria-label="View mode">
                    <button class="btn btn-sm btn-outline-secondary active" id="btnTableView" title="Table view">
                        <i class="bi bi-table"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" id="btnCardView" title="Card view">
                        <i class="bi bi-grid-3x2-gap"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <!-- Table view -->
            <div id="loginTableWrap">
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
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <!-- Card view (mobile default, optional on desktop) -->
            <div id="loginCards" class="row g-2 p-3 d-none"></div>
        </div>
    </div>

</div>

<script>
function safeOutput(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

function deviceIcon(type) {
    if (!type) return '';
    const icons = { Mobile: 'bi-phone', Tablet: 'bi-tablet', Desktop: 'bi-display' };
    return `<i class="bi ${icons[type] || 'bi-laptop'} me-1"></i>`;
}

function roleColor(role) {
    if (!role) return 'secondary';
    const r = role.toLowerCase();
    if (r.includes('admin'))      return 'danger';
    if (r.includes('manager'))    return 'warning';
    if (r.includes('accountant')) return 'info';
    if (r.includes('hr'))         return 'success';
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

function tzFormat(tz) {
    if (!tz || tz === 'Local') return '';
    const parts = tz.split('/');
    const city = (parts[1] || parts[0]).replace(/_/g, ' ');
    const area = parts.length > 1 ? parts[0] : '';
    return city + (area ? ` (${area})` : '');
}

function locLine1(r) { return r.city || '—'; }
function locLine2(r) { return [r.region, r.country].filter(Boolean).join(', '); }
function ispLine(r)  { return [r.isp, r.org].filter(Boolean).join(' / ') || '—'; }

// Render the Location & Device cell HTML (shared by table column renderer and card)
function renderLocDevice(r) {
    const l1  = safeOutput(locLine1(r));
    const l2  = safeOutput(locLine2(r));
    const dev = safeOutput(r.device || '—');
    const tz  = tzFormat(r.timezone);
    return `
        <div class="fw-semibold small">${l1}</div>
        ${l2 ? `<div class="text-muted small">${l2}</div>` : ''}
        <div class="text-muted small">${deviceIcon(r.device_type)}${dev}</div>
        ${tz ? `<div class="text-muted" style="font-size:.75rem"><i class="bi bi-clock me-1"></i>${safeOutput(tz)}</div>` : ''}`;
}

// Render cards grid from a DataTables rows data array
function renderCards(rows) {
    if (!rows.length) {
        $('#loginCards').html('<div class="col-12 text-center py-5 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No login records found</div>');
        return;
    }
    let html = '';
    rows.forEach(r => {
        const isActive   = !r.logout_at;
        const activeBadge = isActive
            ? '<span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Active</span>'
            : '';
        const roleBadge  = r.role_name
            ? `<span class="badge bg-${roleColor(r.role_name)}">${safeOutput(r.role_name)}</span>`
            : '';
        const loginTime  = r.login_at ? new Date(r.login_at).toLocaleString() : '—';

        html += `
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius:10px;overflow:hidden;">
                <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between"
                     style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);">
                    <div>
                        <div class="fw-bold text-dark small">${safeOutput(r.username)}</div>
                        <div class="text-muted" style="font-size:.72rem">${safeOutput(r.email)}</div>
                    </div>
                    ${roleBadge}
                </div>
                <div class="card-body p-3" style="font-size:.82rem;">
                    <div class="mb-2">
                        <span class="text-muted me-1"><i class="bi bi-calendar-event me-1"></i></span>
                        <strong>${loginTime}</strong>${activeBadge}
                    </div>
                    <div class="mb-1 text-muted"><i class="bi bi-hdd-network me-1"></i><code style="font-size:.78rem">${safeOutput(r.ip_address) || '—'}</code></div>
                    <div class="mb-1">${renderLocDevice(r)}</div>
                    <div class="text-muted small mb-1"><i class="bi bi-wifi me-1"></i>${safeOutput(ispLine(r))}</div>
                </div>
                <div class="card-footer bg-white border-top py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="text-muted small"><i class="bi bi-stopwatch me-1"></i>Duration</span>
                    <span class="fw-semibold small ${isActive ? 'text-success' : 'text-secondary'}">${isActive ? 'Ongoing' : formatDur(r.duration_seconds)}</span>
                </div>
            </div>
        </div>`;
    });
    $('#loginCards').html(html);
}

// ── View mode ──────────────────────────────────────────────────────────────
let viewMode = 'table';

function applyView() {
    if (window.innerWidth < 768) {
        // Mobile: always cards, no toggle shown
        $('#loginTableWrap').addClass('d-none');
        $('#loginCards').removeClass('d-none');
        $('#viewToggle').addClass('d-none');
    } else {
        $('#viewToggle').removeClass('d-none');
        if (viewMode === 'card') {
            $('#loginTableWrap').addClass('d-none');
            $('#loginCards').removeClass('d-none');
            $('#btnCardView').addClass('active');
            $('#btnTableView').removeClass('active');
        } else {
            $('#loginTableWrap').removeClass('d-none');
            $('#loginCards').addClass('d-none');
            $('#btnTableView').addClass('active');
            $('#btnCardView').removeClass('active');
        }
    }
}

$(document).ready(function () {

    // Select2 on user filter
    if ($.fn.select2) {
        $('#f-user').select2({ theme: 'bootstrap-5', placeholder: 'All Users', allowClear: true, width: '100%' });
    }

    // ── DataTables — server-side ─────────────────────────────────────────
    const lhTable = $('#loginTable').DataTable({
        serverSide:  true,
        processing:  true,
        ordering:    true,
        autoWidth:   false,
        order:       [[6, 'desc']],   // Login Time, newest first
        pageLength:  25,
        lengthChange: false,
        dom: 'rt<"d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2 px-3 pb-3"ip>',
        ajax: {
            url:  '<?= buildUrl('api/get_login_history.php') ?>',
            type: 'GET',
            data: function (d) {
                d.user_id   = $('#f-user').val()   || '';
                d.date_from = $('#f-from').val()   || '';
                d.date_to   = $('#f-to').val()     || '';
            },
            error: function (xhr) {
                console.error('Login History DataTables error:', xhr.status, xhr.statusText);
            }
        },
        columns: [
            {   // # — row counter
                data: null, orderable: false, className: 'ps-3 text-muted small',
                render: function (data, type, row, meta) {
                    return meta.row + meta.settings._iDisplayStart + 1;
                }
            },
            {   // User
                data: null, orderable: true,
                render: function (data, type, row) {
                    return `<div class="fw-semibold">${safeOutput(row.username)}</div>`
                         + `<div class="text-muted small">${safeOutput(row.email)}</div>`;
                }
            },
            {   // IP Address
                data: 'ip_address', orderable: false,
                render: function (data) {
                    return `<code class="small">${safeOutput(data) || '—'}</code>`;
                }
            },
            {   // Location & Device
                data: null, orderable: false,
                render: function (data, type, row) { return renderLocDevice(row); }
            },
            {   // ISP / Org
                data: null, orderable: false,
                render: function (data, type, row) {
                    return `<div class="small">${safeOutput(ispLine(row))}</div>`;
                }
            },
            {   // Role
                data: 'role_name', orderable: false,
                render: function (data) {
                    return data
                        ? `<span class="badge bg-${roleColor(data)}">${safeOutput(data)}</span>`
                        : '—';
                }
            },
            {   // Login Time
                data: 'login_at', orderable: true,
                render: function (data, type, row) {
                    const active = !row.logout_at
                        ? '<span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Active</span>'
                        : '';
                    const dt = data ? new Date(data).toLocaleString() : '—';
                    return `<div class="small fw-semibold">${dt}</div>${active}`;
                }
            },
            {   // Duration
                data: 'duration_seconds', orderable: false, className: 'pe-3 text-muted small',
                render: function (data) { return formatDur(data); }
            }
        ],
        language: {
            processing:  'Loading…',
            emptyTable:  'No login records found.',
            zeroRecords: 'No matching records.',
            info:        'Showing _START_–_END_ of _TOTAL_',
            infoEmpty:   'Showing 0 records',
            infoFiltered: '(filtered from _MAX_ total)'
        },
        drawCallback: function () {
            const api = this.api();
            $('#total-badge').text(api.page.info().recordsTotal.toLocaleString());
            const rows = api.rows({ page: 'current' }).data().toArray();
            renderCards(rows);
            applyView();
        }
    });

    // ── Filters ─────────────────────────────────────────────────────────
    function applyFilters() {
        lhTable.search($('#f-search').val().trim()).draw();
    }

    $('#btn-filter').on('click', applyFilters);
    $('#f-search').on('keydown', function (e) { if (e.key === 'Enter') applyFilters(); });

    // ── View toggle ──────────────────────────────────────────────────────
    $('#btnTableView').on('click', function () { viewMode = 'table'; applyView(); });
    $('#btnCardView').on('click',  function () { viewMode = 'card';  applyView(); });

    $(window).on('resize', applyView);

    // Initial view based on screen width
    if (window.innerWidth < 768) viewMode = 'card';
    applyView();
});
</script>

<?php require_once __DIR__ . '/../../../footer.php'; ?>
