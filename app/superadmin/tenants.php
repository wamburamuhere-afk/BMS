<?php
/**
 * app/superadmin/tenants.php — the tenant lifecycle panel.
 *
 * Reads ONLY the control database. It never opens a tenant's database, so no
 * company's business data can appear here — the panel manages accounts, it does
 * not look inside them.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
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
    .page-header { position: sticky; top: 0; z-index: 1020; background: #fff; border-bottom: 1px solid #e9ecef; }
    .stat-card { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; }
    .stat-card .value { font-size: 1.75rem; font-weight: 600; }
    @media (max-width: 767px) { #tableWrap { display: none; } }
    @media (min-width: 768px) { #cardView { display: none; } }
</style>
</head>
<body>

<div class="page-header px-3 py-2 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock-fill text-primary fs-4"></i>
        <div>
            <div class="fw-bold">Platform Administration</div>
            <small class="text-muted">Signed in as <?= safe_output($me['name'] ?? '', '') ?></small>
        </div>
    </div>
    <a href="logout.php" class="btn btn-sm btn-secondary"><i class="bi bi-box-arrow-right me-1"></i> Sign out</a>
</div>

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

    <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="mb-0"><i class="bi bi-building text-primary me-1"></i> Tenants (<?= (int)($stats['total'] ?? 0) ?>)</h6>
        <input type="search" id="tblSearch" class="form-control form-control-sm w-auto" placeholder="Search…">
    </div>

    <?php if (!$tenants && !$dbError): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            No tenants registered yet.
        </div>
    <?php else: ?>

    <div id="tableWrap" class="table-responsive">
        <table id="tenantTable" class="table table-sm align-middle" style="width:100%">
            <thead>
                <tr>
                    <th>#</th><th>Company</th><th>Subdomain</th><th>Status</th>
                    <th>Owner</th><th>Created</th><th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tenants as $t): ?>
                <tr>
                    <td><?= (int)$t['id'] ?></td>
                    <td><?= safe_output($t['company_name'], '') ?></td>
                    <td><code><?= safe_output($t['subdomain'], '') ?></code></td>
                    <td data-order="<?= safe_output($t['status'], '') ?>"><?= saBadge((string)$t['status']) ?></td>
                    <td><?= safe_output($t['owner_email'], '') ?></td>
                    <td><?= safe_output($t['created_at'], '') ?></td>
                    <td class="text-end"><?= tenantActionMenu($t) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

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
                           href="tenant_view.php?id=<?= (int)$t['id'] ?>"><i class="bi bi-eye"></i></a>
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
    $items  = '<li><a class="dropdown-item py-2 rounded" href="tenant_view.php?id=' . $id . '">'
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
}

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
