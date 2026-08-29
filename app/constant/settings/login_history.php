<?php
/**
 * app/constant/settings/login_history.php
 * User Login History — who logged in, from where, on what device.
 * Strictly admin-only by design (privacy-sensitive audit trail of every
 * user's login IPs/devices/locations) — not delegable via Roles &
 * Permissions no matter what is granted (the 'login_history' permission row
 * is hidden from that UI entirely). Reached only via Settings > Admin.
 * Data fed by user_sessions table enriched with GeoIP + UA parsing.
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

$countries = $pdo->query("
    SELECT DISTINCT country FROM user_sessions
    WHERE country IS NOT NULL AND country != '' AND country != 'Local'
    ORDER BY country ASC
")->fetchAll(PDO::FETCH_COLUMN);

// Summary stats — always "today", unaffected by the table's own filters (same
// as the reference implementation this page matches: these are a snapshot of
// right-now, not a recap of whatever the admin happens to be filtering for).
$stats = $pdo->query("
    SELECT
        SUM(CASE WHEN logout_at IS NULL THEN 1 ELSE 0 END)                                      AS signed_in_now,
        SUM(CASE WHEN DATE(login_at) = CURDATE() THEN 1 ELSE 0 END)                              AS signins_today,
        COUNT(DISTINCT CASE WHEN DATE(login_at) = CURDATE() THEN user_id END)                    AS people_today,
        SUM(CASE WHEN logout_type = 'timeout' AND DATE(logout_at) = CURDATE() THEN 1 ELSE 0 END) AS expired_today,
        SUM(CASE WHEN precise_captured_at IS NOT NULL AND DATE(login_at) = CURDATE() THEN 1 ELSE 0 END) AS precise_today
    FROM user_sessions
")->fetch(PDO::FETCH_ASSOC);

// So the page's own "End Session" action can warn an admin they are about to
// sign themselves out, rather than let it happen as a surprise.
$current_session_row_id = (int) ($_SESSION['session_row_id'] ?? 0);
?>

<div class="container-fluid py-4">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold text-primary"><i class="bi bi-clock-history me-2"></i>User Login History</h4>
            <p class="text-muted mb-0 small">Track who accessed the system, from where, and on what device</p>
        </div>
    </div>

    <!-- Stats cards — always "today", independent of the table's own filters -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-lg">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-success" id="stat-signed-in-now"><?= intval($stats['signed_in_now']) ?></div>
                <div class="small text-muted">Signed In Now</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary" id="stat-signins-today"><?= intval($stats['signins_today']) ?></div>
                <div class="small text-muted">Sign-ins Today</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-info" id="stat-people-today"><?= intval($stats['people_today']) ?></div>
                <div class="small text-muted">People Today</div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-lg">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-warning" id="stat-expired-today"><?= intval($stats['expired_today']) ?></div>
                <div class="small text-muted">Expired Today</div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-lg">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-secondary" id="stat-precise-today"><?= intval($stats['precise_today']) ?></div>
                <div class="small text-muted">Precise Today</div>
            </div>
        </div>
    </div>

    <!-- Methodology — how "Ended"/duration/location are actually computed, so an
         admin never misreads what these numbers mean. -->
    <div class="alert alert-light border small mb-4" style="border-radius:10px;">
        <div class="mb-1"><i class="bi bi-info-circle me-1"></i>A session ends when the person signs out, or after <strong>30 minutes</strong> without activity (matching the POS terminal timeout). Nothing runs at the exact moment a session goes idle, so an <strong>Expired</strong> row shows when the person was <strong>last seen</strong> — not a logout time, which was never observed. Duration is measured to that same last-seen moment, and ticks live for anyone signed in right now.</div>
        <div class="mb-0"><i class="bi bi-geo-alt me-1"></i><strong>Approximate</strong> location is inferred from the IP address — city-level at best, and on mobile networks it can report the carrier's city rather than the person's. <strong>Precise</strong> means the browser reported its actual position and the person's device permission prompt was accepted — the only source accurate to street level, shown with its accuracy radius.</div>
    </div>

    <!-- Filters -->
    <div class="card border shadow-sm mb-4" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-3">
            <div class="row g-2 mb-3">
                <div class="col-12">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Period</label>
                    <div class="btn-group flex-wrap" role="group" id="periodChips">
                        <button type="button" class="btn btn-sm btn-outline-primary period-chip active" data-period="all">All Time</button>
                        <button type="button" class="btn btn-sm btn-outline-primary period-chip" data-period="today">Today</button>
                        <button type="button" class="btn btn-sm btn-outline-primary period-chip" data-period="week">This Week</button>
                        <button type="button" class="btn btn-sm btn-outline-primary period-chip" data-period="month">This Month</button>
                        <button type="button" class="btn btn-sm btn-outline-primary period-chip" data-period="year">This Year</button>
                        <button type="button" class="btn btn-sm btn-outline-primary period-chip" data-period="custom">Custom Range</button>
                    </div>
                </div>
            </div>
            <div class="row g-3 align-items-end" id="customRangeRow" style="display:none;">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                    <input type="date" id="f-from" class="form-control" value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                    <input type="date" id="f-to" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">User</label>
                    <select id="f-user" class="form-select" style="width:100%">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['user_id'] ?>"><?= safe_output($u['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Ended</label>
                    <select id="f-ended" class="form-select" style="width:100%">
                        <option value="">Any</option>
                        <option value="active">Still signed in</option>
                        <option value="manual">Signed out</option>
                        <option value="timeout">Expired</option>
                        <option value="superseded">Signed in again</option>
                        <option value="revoked">Revoked</option>
                        <option value="admin_ended">Ended by admin</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Device</label>
                    <select id="f-device" class="form-select" style="width:100%">
                        <option value="">Any device</option>
                        <option value="Desktop">Desktop</option>
                        <option value="Mobile">Mobile</option>
                        <option value="Tablet">Tablet</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Country</label>
                    <select id="f-country" class="form-select" style="width:100%">
                        <option value="">Anywhere</option>
                        <?php foreach ($countries as $c): ?>
                            <option value="<?= safe_output($c) ?>"><?= safe_output($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Location</label>
                    <select id="f-location-source" class="form-select" style="width:100%">
                        <option value="">Any source</option>
                        <option value="precise">Precise (device)</option>
                        <option value="approximate">Approximate (IP)</option>
                        <option value="none">No location</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Search</label>
                    <input type="text" id="f-search" class="form-control" placeholder="IP, city, browser…">
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-12 d-flex gap-2">
                    <button id="btn-filter" class="btn btn-primary fw-bold"><i class="bi bi-filter me-1"></i>Apply Filters</button>
                    <button id="btn-reset" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button>
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
                                <th>Ended</th>
                                <th>Duration</th>
                                <th class="pe-3 text-end">Actions</th>
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
const CURRENT_SESSION_ROW_ID = <?= (int) $current_session_row_id ?>;

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

// Location-source badge: Precise (device, consent-based) vs Approximate (IP)
// vs no location captured at all — see the methodology note above the table.
function locationBadge(r) {
    if (r.location_source === 'precise') {
        const acc = r.precise_accuracy_m ? ` (±${Math.round(r.precise_accuracy_m)}m)` : '';
        return `<span class="badge bg-success-subtle text-success border border-success-subtle" title="Device-reported position, consented"><i class="bi bi-geo-alt-fill me-1"></i>Precise${acc}</span>`;
    }
    if (r.location_source === 'approximate') {
        return `<span class="badge bg-light text-muted border" title="Inferred from IP address"><i class="bi bi-geo-alt me-1"></i>Approx (IP)</span>`;
    }
    return `<span class="badge bg-light text-muted border">No location</span>`;
}

// "Ended" cell: real lifecycle state + a human detail line, matching what the
// methodology note above the table promises. See logout_type in
// core/session_tracker.php for what produces each value.
function endedBadge(r) {
    const at = (iso) => iso ? new Date(iso.replace(' ', 'T')).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '';
    if (!r.logout_at) {
        return { badge: '<span class="badge bg-success">Active</span>', detail: 'so far', isActive: true };
    }
    const by = r.revoked_by_name ? ` by ${safeOutput(r.revoked_by_name)}` : '';
    switch (r.logout_type) {
        case 'timeout':     return { badge: '<span class="badge bg-warning text-dark">Expired</span>',        detail: 'last seen ' + at(r.logout_at) };
        case 'superseded':  return { badge: '<span class="badge bg-info text-dark">Signed in again</span>',   detail: 'at ' + at(r.logout_at) };
        case 'revoked':     return { badge: '<span class="badge bg-danger">Revoked</span>',                   detail: 'at ' + at(r.logout_at) + by };
        case 'admin_ended': return { badge: '<span class="badge bg-dark">Ended by admin</span>',              detail: 'at ' + at(r.logout_at) + by };
        default:            return { badge: '<span class="badge bg-secondary-subtle text-secondary border">Signed out</span>', detail: 'at ' + at(r.logout_at) };
    }
}

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
        ${tz ? `<div class="text-muted" style="font-size:.75rem"><i class="bi bi-clock me-1"></i>${safeOutput(tz)}</div>` : ''}
        <div class="mt-1">${locationBadge(r)}</div>`;
}

// End Session — admin force-ends a live row. Shared by the table and card
// action buttons.
function endSession(id, isSelf) {
    const doIt = () => {
        $.post('<?= buildUrl('api/revoke_session.php') ?>', { id: id, reason: 'revoked' }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Session ended', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => { if (isSelf) { window.location.href = '<?= getUrl('login') ?>'; } else { $('#loginTable').DataTable().ajax.reload(null, false); } });
            } else {
                Swal.fire({ icon: 'error', title: 'Could not end session', text: res.message });
            }
        }, 'json').fail(function () {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' });
        });
    };
    Swal.fire({
        title: isSelf ? 'End your own session?' : 'End this session?',
        text: isSelf ? 'This is the session you are using right now — you will be signed out immediately.' : 'The user will be signed out the next time they take any action.',
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, end it'
    }).then(r => { if (r.isConfirmed) doIt(); });
}

// Render cards grid from a DataTables rows data array
function renderCards(rows) {
    if (!rows.length) {
        $('#loginCards').html('<div class="col-12 text-center py-5 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No login records found</div>');
        return;
    }
    let html = '';
    rows.forEach(r => {
        const ended      = endedBadge(r);
        const isActive   = ended.isActive;
        const isSelf     = CURRENT_SESSION_ROW_ID > 0 && Number(r.id) === CURRENT_SESSION_ROW_ID;
        const roleBadge  = r.role_name
            ? `<span class="badge bg-${roleColor(r.role_name)}">${safeOutput(r.role_name)}</span>`
            : '';
        const loginTime  = r.login_at ? new Date(r.login_at).toLocaleString() : '—';
        const actionBtn  = isActive
            ? `<button class="btn btn-sm btn-outline-danger w-100" onclick="endSession(${r.id}, ${isSelf})"><i class="bi bi-x-octagon me-1"></i>End Session${isSelf ? ' (yours)' : ''}</button>`
            : '';

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
                        <strong>${loginTime}</strong> ${ended.badge}
                    </div>
                    <div class="mb-1 text-muted"><i class="bi bi-hdd-network me-1"></i><code style="font-size:.78rem">${safeOutput(r.ip_address) || '—'}</code></div>
                    <div class="mb-1">${renderLocDevice(r)}</div>
                    <div class="text-muted small mb-1"><i class="bi bi-wifi me-1"></i>${safeOutput(ispLine(r))}</div>
                </div>
                <div class="card-footer bg-white border-top py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small"><i class="bi bi-stopwatch me-1"></i>${safeOutput(ended.detail)}</span>
                        <span class="fw-semibold small durvalue ${isActive ? 'text-success' : 'text-secondary'}" data-login-at="${safeOutput(r.login_at)}" data-active="${isActive}">${isActive ? formatDur(0) : formatDur(r.duration_seconds)}</span>
                    </div>
                    ${actionBtn}
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

let currentPeriod = 'all';

$(document).ready(function () {

    // Select2 on user/country filters
    if ($.fn.select2) {
        $('#f-user').select2({ theme: 'bootstrap-5', placeholder: 'All Users', allowClear: true, width: '100%' });
        $('#f-country').select2({ theme: 'bootstrap-5', placeholder: 'Anywhere', allowClear: true, width: '100%' });
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
                d.user_id          = $('#f-user').val()   || '';
                d.period           = currentPeriod;
                d.date_from        = currentPeriod === 'custom' ? ($('#f-from').val() || '') : '';
                d.date_to          = currentPeriod === 'custom' ? ($('#f-to').val()   || '') : '';
                d.ended            = $('#f-ended').val()   || '';
                d.device           = $('#f-device').val()  || '';
                d.country          = $('#f-country').val() || '';
                d.location_source  = $('#f-location-source').val() || '';
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
                render: function (data) {
                    const dt = data ? new Date(data).toLocaleString() : '—';
                    return `<div class="small fw-semibold">${dt}</div>`;
                }
            },
            {   // Ended
                data: null, orderable: true,
                render: function (data, type, row) {
                    const e = endedBadge(row);
                    return `<div>${e.badge}</div><div class="text-muted" style="font-size:.72rem">${safeOutput(e.detail)}</div>`;
                }
            },
            {   // Duration — ticks live for active rows (see the setInterval below)
                data: 'duration_seconds', orderable: true, className: 'pe-3 small',
                render: function (data, type, row) {
                    if (type !== 'display') return data || 0;
                    const isActive = !row.logout_at;
                    return `<span class="durvalue ${isActive ? 'text-success fw-semibold' : 'text-muted'}" data-login-at="${safeOutput(row.login_at)}" data-active="${isActive}">${isActive ? '—' : formatDur(data)}</span>`;
                }
            },
            {   // Actions
                data: null, orderable: false, className: 'pe-3 text-end',
                render: function (data, type, row) {
                    if (row.logout_at) return '';
                    const isSelf = CURRENT_SESSION_ROW_ID > 0 && Number(row.id) === CURRENT_SESSION_ROW_ID;
                    return `<button class="btn btn-sm btn-outline-danger" onclick="endSession(${row.id}, ${isSelf})" title="End this session"><i class="bi bi-x-octagon"></i></button>`;
                }
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

    $('#btn-reset').on('click', function () {
        $('#f-user').val('').trigger('change');
        $('#f-country').val('').trigger('change');
        $('#f-ended, #f-device, #f-location-source').val('');
        $('#f-search').val('');
        $('#f-from').val('<?= date('Y-m-d', strtotime('-30 days')) ?>');
        $('#f-to').val('<?= date('Y-m-d') ?>');
        setPeriod('all');
        applyFilters();
    });

    // ── Period chips ────────────────────────────────────────────────────
    function setPeriod(p) {
        currentPeriod = p;
        $('.period-chip').removeClass('active');
        $(`.period-chip[data-period="${p}"]`).addClass('active');
        $('#customRangeRow').toggle(p === 'custom');
    }
    $('.period-chip').on('click', function () {
        setPeriod($(this).data('period'));
        applyFilters();
    });
    $('#f-from, #f-to').on('change', function () { if (currentPeriod === 'custom') applyFilters(); });

    // ── Live-ticking duration for Active rows — recomputed from the row's own
    //    login_at, not incremented locally, so it self-corrects after any pause
    //    (tab backgrounded, laptop sleep) instead of drifting.
    setInterval(function () {
        $('.durvalue[data-active="true"]').each(function () {
            const loginAt = $(this).data('login-at');
            if (!loginAt) return;
            const loginMs = new Date(String(loginAt).replace(' ', 'T')).getTime();
            if (isNaN(loginMs)) return;
            const secs = Math.max(0, Math.floor((Date.now() - loginMs) / 1000));
            $(this).text(formatDur(secs));
        });
    }, 1000);

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
