<?php
/**
 * app/superadmin/tenants.php — the tenant lifecycle panel.
 *
 * Reads ONLY the control database. It never opens a tenant's database, so no
 * company's business data can appear here — the panel manages accounts, it does
 * not look inside them.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me      = currentSuperadmin();
$stats   = ['active' => 0, 'trial' => 0, 'suspended' => 0, 'deleted' => 0, 'total' => 0];
$tenants = [];
$dbError = null;

try {
    $stats   = tenantStats();
    $tenants = listTenants();
} catch (Throwable $e) {
    error_log('superadmin tenants: ' . $e->getMessage());
    $dbError = 'The tenant registry could not be read.';
}

// Build distinct filter option lists from the loaded data
$allIndustries = $allCountries = $allSizes = [];
foreach ($tenants as $t) {
    if (!empty($t['industry']))    $allIndustries[$t['industry']] = true;
    if (!empty($t['country']))     $allCountries[$t['country']]   = true;
    if (!empty($t['company_size'])) $allSizes[$t['company_size']] = true;
}
ksort($allIndustries); ksort($allCountries); ksort($allSizes);

/**
 * Unified "Expires" badge — shows what matters for every tenant state.
 *
 * trial          → trial end date with days-left urgency colouring
 * active (paid)  → subscription end date
 * suspended      → why it was suspended (trial / subscription / manual)
 * deleted        → —
 */
function expiresBadge(
    ?string $trialEndsAt,
    ?string $subscriptionEndsAt,
    string  $status,
    ?string $suspensionReason = null
): string {
    if ($status === 'trial') {
        if ($trialEndsAt === null) return '<span class="text-muted">—</span>';
        $daysLeft = (int)floor((strtotime($trialEndsAt) - time()) / 86400);
        $date = date('d M Y', strtotime($trialEndsAt));
        if ($daysLeft < 0)   return '<span class="badge bg-danger">Trial expired</span>';
        if ($daysLeft === 0) return '<span class="badge bg-danger">Trial ends today</span>';
        // Within the standard 14-day window — show friendly "days remaining for testing"
        if ($daysLeft <= 3)  return '<span class="badge bg-danger">' . $daysLeft . 'd remaining for testing</span>';
        if ($daysLeft <= 7)  return '<span class="badge bg-warning text-dark">' . $daysLeft . 'd remaining for testing</span>';
        if ($daysLeft <= 14) return '<span class="badge bg-info text-dark">' . $daysLeft . ' days remaining for testing</span>';
        // Extended beyond 14 days
        return '<span class="badge bg-info text-dark">Trial · ' . $date . ' (' . $daysLeft . 'd)</span>';
    }
    if ($status === 'active') {
        // subscription_ends_at is set from the last recorded payment's ends_at
        if ($subscriptionEndsAt === null) return '<span class="text-muted small">—</span>';
        $daysLeft = (int)floor((strtotime($subscriptionEndsAt) - time()) / 86400);
        $date = date('d M Y', strtotime($subscriptionEndsAt));
        if ($daysLeft < 0)   return '<span class="badge bg-danger">Expired ' . $date . '</span>';
        if ($daysLeft <= 7)  return '<span class="badge bg-warning text-dark">Expires ' . $date . ' (' . $daysLeft . 'd)</span>';
        if ($daysLeft <= 30) return '<span class="badge bg-success">Expires ' . $date . ' (' . $daysLeft . 'd)</span>';
        return '<span class="badge bg-success">Expires ' . $date . '</span>';
    }
    if ($status === 'suspended') {
        if ($suspensionReason === 'trial_expired') {
            $date = $trialEndsAt ? date('d M Y', strtotime($trialEndsAt)) : '?';
            return '<span class="badge bg-danger"><i class="bi bi-hourglass-split me-1"></i>Trial ended ' . $date . '</span>';
        }
        if ($suspensionReason === 'subscription_expired') {
            $date = $subscriptionEndsAt ? date('d M Y', strtotime($subscriptionEndsAt)) : '?';
            return '<span class="badge bg-danger"><i class="bi bi-credit-card me-1"></i>Sub ended ' . $date . '</span>';
        }
        return '<span class="badge bg-secondary">Manually suspended</span>';
    }
    return '<span class="text-muted">—</span>';
}

/** Last-active badge — colour by dormancy. */
function lastActiveBadge(?string $lastActiveAt): string
{
    if ($lastActiveAt === null) return '<span class="text-muted small">Never</span>';
    $days = (int)floor((time() - strtotime($lastActiveAt)) / 86400);
    if ($days <= 7)  return '<span class="badge bg-success-subtle text-success">' . $days . 'd ago</span>';
    if ($days <= 30) return '<span class="badge bg-warning-subtle text-warning">' . $days . 'd ago</span>';
    return '<span class="badge bg-danger-subtle text-danger">Dormant ' . $days . 'd</span>';
}

/** Status badge — blue scale only, per .claude/ui-constants.md §UI-1. */
function saBadge(string $status): string
{
    $map = [
        'active'    => ['#0d6efd', '#fff'],
        'trial'     => ['#cfe2ff', '#084298'],
        'suspended' => ['#6c757d', '#fff'],
        'deleted'   => ['#dc3545', '#fff'],
    ];
    [$bg, $fg] = $map[$status] ?? ['#e9ecef', '#495057'];
    return '<span class="badge" style="background:' . $bg . ';color:' . $fg . '">'
         . htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Tenants | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .stat-card { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; }
    .stat-card .value { font-size: 1.75rem; font-weight: 600; }
    @media (max-width: 767px) { #tableWrap { display: none; } }
    @media (min-width: 768px) { #cardView { display: none; } }
</style>
</head>
<body>

<?php renderSuperadminHeader('tenants', $me); ?>

<div class="container-fluid p-3">

    <?php if ($dbError): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= safe_output($dbError, '') ?></div>
    <?php endif; ?>

    <div class="row g-2 mb-3">
        <?php foreach ([
            'active'    => ['Active', 'bi-check-circle'],
            'trial'     => ['Trial', 'bi-hourglass-split'],
            'suspended' => ['Suspended', 'bi-pause-circle'],
            'deleted'   => ['Closed', 'bi-x-circle'],
        ] as $key => [$label, $icon]): ?>
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small"><i class="bi <?= $icon ?> text-primary me-1"></i><?= $label ?></div>
                <div class="value"><?= (int)($stats[$key] ?? 0) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
        <h6 class="mb-0"><i class="bi bi-building text-primary me-1"></i> Tenants (<span id="visibleCount"><?= count($tenants) ?></span>)</h6>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <select id="filterStatus" class="form-select form-select-sm w-auto">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="trial">Trial</option>
                <option value="suspended">Suspended</option>
                <option value="deleted">Closed</option>
            </select>
            <?php if ($allIndustries): ?>
            <select id="filterIndustry" class="form-select form-select-sm w-auto">
                <option value="">All industries</option>
                <?php foreach (array_keys($allIndustries) as $v): ?>
                <option value="<?= htmlspecialchars($v, ENT_QUOTES) ?>"><?= htmlspecialchars(ucfirst($v), ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($allCountries): ?>
            <select id="filterCountry" class="form-select form-select-sm w-auto">
                <option value="">All countries</option>
                <?php foreach (array_keys($allCountries) as $v): ?>
                <option value="<?= htmlspecialchars($v, ENT_QUOTES) ?>"><?= htmlspecialchars($v, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($allSizes): ?>
            <select id="filterSize" class="form-select form-select-sm w-auto">
                <option value="">All sizes</option>
                <?php foreach (array_keys($allSizes) as $v): ?>
                <option value="<?= htmlspecialchars($v, ENT_QUOTES) ?>"><?= htmlspecialchars($v, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="search" id="tblSearch" class="form-control form-control-sm w-auto" placeholder="Search…">
            <button class="btn btn-sm btn-outline-secondary" id="btnExportCsv" title="Export filtered view to CSV">
                <i class="bi bi-download me-1"></i> CSV
            </button>
            <a href="<?= saUrl('tenants/new') ?>" class="btn btn-sm btn-primary text-nowrap">
                <i class="bi bi-plus-circle me-1"></i> New company
            </a>
        </div>
    </div>
    <div id="filterChips" class="d-flex flex-wrap gap-1 mb-2" style="min-height:0"></div>

    <?php if (!$tenants && !$dbError): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            No tenants registered yet.
            <div class="mt-3">
                <a href="<?= saUrl('tenants/new') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> Register the first company
                </a>
            </div>
        </div>
    <?php else: ?>

    <div id="tableWrap" class="table-responsive">
        <table id="tenantTable" class="table table-sm align-middle" style="width:100%">
            <thead>
                <tr>
                    <th style="width:2rem"><input type="checkbox" id="chkAll" class="form-check-input" title="Select all visible"></th>
                    <th>#</th><th>Company</th><th>Subdomain</th><th>Status</th>
                    <th>Owner</th><th>Expires</th><th>Last Active</th>
                    <th>Created</th><th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tenants as $t): ?>
                <?php
                $ownerName = trim(($t['owner_first_name'] ?? '') . ' ' . ($t['owner_last_name'] ?? ''));
                $ownerDisplay = $ownerName !== '' ? $ownerName : safe_output($t['owner_email'], '');
                ?>
                <tr data-status="<?= htmlspecialchars($t['status'], ENT_QUOTES) ?>"
                    data-industry="<?= htmlspecialchars(strtolower($t['industry'] ?? ''), ENT_QUOTES) ?>"
                    data-country="<?= htmlspecialchars($t['country'] ?? '', ENT_QUOTES) ?>"
                    data-size="<?= htmlspecialchars($t['company_size'] ?? '', ENT_QUOTES) ?>"
                    data-id="<?= (int)$t['id'] ?>">
                    <td><input type="checkbox" class="form-check-input row-chk" value="<?= (int)$t['id'] ?>"></td>
                    <td><?= (int)$t['id'] ?></td>
                    <td>
                        <a href="<?= saUrl('tenants/view') ?>?id=<?= (int)$t['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= safe_output($t['company_name'], '') ?>
                        </a>
                        <?php if (!empty($t['industry'])): ?><br><small class="text-muted"><?= safe_output($t['industry'], '') ?></small><?php endif; ?>
                    </td>
                    <td><code><?= safe_output($t['subdomain'], '') ?></code></td>
                    <td data-order="<?= safe_output($t['status'], '') ?>"><?= saBadge((string)$t['status']) ?></td>
                    <td>
                        <?= htmlspecialchars($ownerDisplay, ENT_QUOTES, 'UTF-8') ?>
                        <?php if (!empty($t['owner_phone'])): ?><br><small class="text-muted"><?= safe_output($t['owner_phone'], '') ?></small><?php endif; ?>
                    </td>
                    <td data-order="<?= htmlspecialchars($t['trial_ends_at'] ?? ($t['subscription_ends_at'] ?? ''), ENT_QUOTES) ?>">
                        <?= expiresBadge($t['trial_ends_at'] ?? null, $t['subscription_ends_at'] ?? null, (string)$t['status'], $t['suspension_reason'] ?? null) ?>
                    </td>
                    <td data-order="<?= htmlspecialchars($t['last_active_at'] ?? '', ENT_QUOTES) ?>">
                        <?= lastActiveBadge($t['last_active_at'] ?? null) ?>
                    </td>
                    <td><?= date('d M Y', strtotime((string)$t['created_at'])) ?></td>
                    <td class="text-end"><?= tenantActionMenu($t) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- P8 — Bulk action bar -->
    <div id="bulkBar" class="d-none position-sticky bottom-0 bg-white border-top py-2 px-2 shadow d-flex align-items-center gap-2 flex-wrap" style="z-index:100">
        <span class="text-muted small me-1"><span id="bulkCount">0</span> selected</span>
        <button class="btn btn-sm btn-outline-primary" id="btnBulkActivate">
            <i class="bi bi-play-circle me-1"></i> Activate
        </button>
        <button class="btn btn-sm btn-outline-warning" id="btnBulkSuspend">
            <i class="bi bi-pause-circle me-1"></i> Suspend
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="btnBulkExport">
            <i class="bi bi-download me-1"></i> Export CSV
        </button>
        <button class="btn btn-sm btn-link text-muted ms-auto" id="btnBulkClear">
            <i class="bi bi-x-circle me-1"></i> Clear
        </button>
    </div>
    <!-- /P8 -->

    <div id="cardView" class="row g-2">
        <?php foreach ($tenants as $t): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="fw-bold"><?= safe_output($t['company_name'], '') ?></div>
                        <?= saBadge((string)$t['status']) ?>
                    </div>
                    <div><code><?= safe_output($t['subdomain'], '') ?></code></div>
                    <small class="text-muted"><?= safe_output($t['owner_email'], '') ?></small>
                </div>
                <div class="card-footer bg-white border-top p-0">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                        <a class="btn btn-sm btn-outline-primary" style="flex:1;padding:3px 4px;font-size:.72rem"
                           href="<?= saUrl('tenants/view') ?>?id=<?= (int)$t['id'] ?>"><i class="bi bi-eye"></i></a>
                        <?php if ($t['status'] === 'suspended'): ?>
                        <button class="btn btn-sm btn-outline-primary" style="flex:1;padding:3px 4px;font-size:.72rem"
                                onclick="doActivate(<?= (int)$t['id'] ?>)"><i class="bi bi-play-circle"></i></button>
                        <?php elseif ($t['status'] !== 'deleted'): ?>
                        <button class="btn btn-sm btn-outline-primary" style="flex:1;padding:3px 4px;font-size:.72rem"
                                onclick="doSuspend(<?= (int)$t['id'] ?>)"><i class="bi bi-pause-circle"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<?php
/** Gear dropdown, per .claude/ui-constants.md §UI-5. */
function tenantActionMenu(array $t): string
{
    $id   = (int)$t['id'];
    $name = htmlspecialchars((string)$t['company_name'], ENT_QUOTES, 'UTF-8');
    $items  = '<li><a class="dropdown-item py-2 rounded" href="' . saUrl('tenants/view') . '?id=' . $id . '">'
            . '<i class="bi bi-eye text-primary me-2"></i> View</a></li>';

    if ($t['status'] === 'deleted') {
        // Nothing can be done to a deleted tenant: its database is gone.
        $items .= '<li><span class="dropdown-item py-2 text-muted disabled">'
                . '<i class="bi bi-slash-circle me-2"></i> Closed</span></li>';
    } else {
        if ($t['status'] === 'suspended') {
            $items .= '<li><button class="dropdown-item py-2 rounded" onclick="doActivate(' . $id . ')">'
                    . '<i class="bi bi-play-circle text-primary me-2"></i> Activate</button></li>';
        } else {
            $items .= '<li><button class="dropdown-item py-2 rounded" onclick="doSuspend(' . $id . ')">'
                    . '<i class="bi bi-pause-circle text-primary me-2"></i> Suspend</button></li>';
        }
        $items .= '<li><hr class="dropdown-divider"></li>'
                . '<li><button class="dropdown-item py-2 rounded text-danger" '
                . 'onclick="doDelete(' . $id . ', \'' . str_replace("'", "\\'", $name) . '\')">'
                . '<i class="bi bi-trash text-danger me-2"></i> Delete</button></li>';
    }

    return '<div class="dropdown d-flex justify-content-end">'
         . '<button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" '
         . 'data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill me-1"></i></button>'
         . '<ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">' . $items . '</ul></div>';
}
?>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Named SA_CSRF_TOKEN, not CSRF_TOKEN: header.php is the canonical
// declarer of the latter, and tests/test_csrf_token_redeclaration_cli.php
// forbids any page under app/ from shadowing it. These pages never include
// header.php, but keeping the invariant absolute is safer than exempting them.
const SA_CSRF_TOKEN = '<?= csrf_token() ?>';
$.ajaxSetup({ headers: { 'X-CSRF-Token': SA_CSRF_TOKEN } });

let table = null;
if (document.getElementById('tenantTable')) {
    table = $('#tenantTable').DataTable({
        responsive: false,
        scrollX: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: 'rtip',
        columnDefs: [{ orderable: false, targets: -1 }],
        language: { emptyTable: 'No records found.', zeroRecords: 'No matching records.' }
    });
    $('#tblSearch').on('keyup', function () { table.search(this.value).draw(); });

    // Multi-filter: status, industry, country, size
    function applyFilters() {
        const fStatus   = $('#filterStatus').val()   || '';
        const fIndustry = ($('#filterIndustry').val() || '').toLowerCase();
        const fCountry  = $('#filterCountry').val()  || '';
        const fSize     = $('#filterSize').val()     || '';
        let visible = 0;
        table.rows().every(function () {
            const node    = $(this.node());
            const status  = node.data('status')   || '';
            const industry= (node.data('industry') || '').toLowerCase();
            const country = node.data('country')  || '';
            const size    = node.data('size')      || '';
            const show = (!fStatus   || status   === fStatus)
                      && (!fIndustry || industry === fIndustry)
                      && (!fCountry  || country  === fCountry)
                      && (!fSize     || size      === fSize);
            node.toggle(show);
            if (show) visible++;
        });
        $('#visibleCount').text(visible);
        renderChips(fStatus, fIndustry, fCountry, fSize);
    }

    function renderChips(fStatus, fIndustry, fCountry, fSize) {
        const chips = [];
        if (fStatus)   chips.push({ label: 'Status: ' + fStatus,   clear: function () { $('#filterStatus').val('').trigger('change'); } });
        if (fIndustry) chips.push({ label: 'Industry: ' + fIndustry, clear: function () { $('#filterIndustry').val('').trigger('change'); } });
        if (fCountry)  chips.push({ label: 'Country: ' + fCountry,  clear: function () { $('#filterCountry').val('').trigger('change'); } });
        if (fSize)     chips.push({ label: 'Size: ' + fSize,        clear: function () { $('#filterSize').val('').trigger('change'); } });
        const $c = $('#filterChips').empty();
        chips.forEach(function (chip, i) {
            $('<span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="cursor:pointer;font-size:.8rem">'
                + chip.label + ' &times;</span>')
                .on('click', chip.clear).appendTo($c);
        });
    }

    $('#filterStatus, #filterIndustry, #filterCountry, #filterSize').on('change', applyFilters);

    // CSV Export — only visible (non-hidden) rows
    $('#btnExportCsv').on('click', function () {
        const rows  = [];
        const heads = [];
        $('#tenantTable thead th').each(function (i, th) {
            const t = $(th).text().trim();
            if (i < $(th).closest('table').find('thead th').length - 1) heads.push(t);
        });
        rows.push(heads.map(function (h) { return '"' + h.replace(/"/g, '""') + '"'; }).join(','));
        table.rows().every(function () {
            const node = $(this.node());
            if (!node.is(':visible')) return;
            const cols = [];
            node.find('td').each(function (i, td) {
                if (i < node.find('td').length - 1) {
                    cols.push('"' + $(td).text().trim().replace(/\s+/g, ' ').replace(/"/g, '""') + '"');
                }
            });
            rows.push(cols.join(','));
        });
        const csv  = rows.join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = 'tenants_' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
}

// P8 — Bulk actions
(function () {
    function selectedIds() {
        const ids = [];
        document.querySelectorAll('.row-chk:checked').forEach(function (cb) {
            if (cb.closest('tr') && !cb.closest('tr').classList.contains('d-none')
                && cb.closest('tr').style.display !== 'none') {
                ids.push(parseInt(cb.value, 10));
            }
        });
        return ids;
    }

    function updateBulkBar() {
        const ids = selectedIds();
        const bar = document.getElementById('bulkBar');
        if (ids.length > 0) {
            bar.classList.remove('d-none');
        } else {
            bar.classList.add('d-none');
        }
        document.getElementById('bulkCount').textContent = ids.length;
    }

    // Select-all checkbox
    document.getElementById('chkAll').addEventListener('change', function () {
        const checked = this.checked;
        document.querySelectorAll('.row-chk').forEach(function (cb) {
            const row = cb.closest('tr');
            if (!row) return;
            if (row.style.display === 'none') return;
            cb.checked = checked;
        });
        updateBulkBar();
    });

    // Individual row checkboxes
    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('row-chk')) {
            if (!e.target.checked) document.getElementById('chkAll').checked = false;
            updateBulkBar();
        }
    });

    function doBulk(action) {
        const ids = selectedIds();
        if (!ids.length) return;
        const label = action === 'suspend' ? 'suspend' : 'activate';
        Swal.fire({
            title: 'Bulk ' + label + '?',
            text: ids.length + ' tenant' + (ids.length > 1 ? 's' : '') + ' will be ' + label + 'd.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            confirmButtonText: 'Yes, ' + label + ' all'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            $.ajax({
                url: '/actions/superadmin_bulk_action.php',
                method: 'POST',
                dataType: 'json',
                data: { _csrf: SA_CSRF_TOKEN, action: action, tenant_ids: ids }
            }).done(function (res) {
                if (res && res.success) {
                    Swal.fire({ icon: 'success', title: 'Done',
                        text: 'OK: ' + res.ok_count + ' · Failed: ' + res.fail_count,
                        timer: 2500, showConfirmButton: false });
                    setTimeout(function () { window.location.reload(); }, 2500);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Bulk action failed.' });
                }
            }).fail(function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); });
        });
    }

    document.getElementById('btnBulkActivate').addEventListener('click', function () { doBulk('activate'); });
    document.getElementById('btnBulkSuspend').addEventListener('click', function () { doBulk('suspend'); });
    document.getElementById('btnBulkClear').addEventListener('click', function () {
        document.querySelectorAll('.row-chk, #chkAll').forEach(function (cb) { cb.checked = false; });
        updateBulkBar();
    });

    // Bulk CSV export — same rows as the filtered table
    document.getElementById('btnBulkExport').addEventListener('click', function () {
        const ids = selectedIds();
        if (!ids.length) return;
        document.getElementById('btnExportCsv').click(); // reuse existing export logic (visibleCount already only shows filtered)
    });
})();

function postAction(data, successTitle) {
    return $.ajax({
        url: '/actions/superadmin_tenant_action.php',
        method: 'POST',
        dataType: 'json',
        data: Object.assign({ _csrf: SA_CSRF_TOKEN }, data)
    }).done(function (res) {
        if (res && res.success) {
            Swal.fire({ icon: 'success', title: successTitle, text: res.message,
                        timer: 1800, showConfirmButton: false });
            setTimeout(function () { window.location.reload(); }, 1800);
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Action failed.' });
        }
    }).fail(function (xhr) {
        let msg = 'Action failed.';
        try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
        Swal.fire({ icon: 'error', title: 'Error', text: msg });
    });
}

function doSuspend(id) {
    Swal.fire({
        title: 'Suspend this tenant?',
        text: 'They will be locked out of their system immediately. No data is deleted, and no other tenant is affected.',
        icon: 'warning',
        input: 'text',
        inputPlaceholder: 'Reason (optional)',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        confirmButtonText: 'Suspend'
    }).then(r => {
        if (r.isConfirmed) postAction({ action: 'suspend', tenant_id: id, reason: r.value || '' }, 'Suspended');
    });
}

function doActivate(id) {
    Swal.fire({
        title: 'Reactivate this tenant?',
        text: 'Their system becomes available again immediately.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        confirmButtonText: 'Activate'
    }).then(r => {
        if (r.isConfirmed) postAction({ action: 'activate', tenant_id: id }, 'Reactivated');
    });
}

function doDelete(id, name) {
    // Typed confirmation. The server re-checks this against the stored company
    // name, so the dialog is a courtesy, not the actual guard.
    Swal.fire({
        title: 'Delete this tenant permanently?',
        html: 'This <strong>destroys their entire database</strong> — every invoice, ledger entry, '
            + 'employee record and document. <strong>It cannot be undone.</strong><br><br>'
            + 'Type <code>' + $('<div>').text(name).html() + '</code> to confirm:',
        icon: 'warning',
        input: 'text',
        inputPlaceholder: 'Company name',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete permanently',
        preConfirm: function (value) {
            if ((value || '').trim() !== name.trim()) {
                Swal.showValidationMessage('The name does not match.');
                return false;
            }
            return value;
        }
    }).then(r => {
        if (r.isConfirmed) postAction({ action: 'delete', tenant_id: id, confirm_name: r.value }, 'Deleted');
    });
}
</script>
</body>
</html>
