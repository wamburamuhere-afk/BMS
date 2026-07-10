<?php
ob_start();

// File: app/bms/pos/inactive_employees.php
//
// Deliberately NOT in the header navigation. Reached only from the
// "Inactive Employees" link on app/bms/pos/employees.php.

$page_title = 'Inactive Employees';
require_once __DIR__ . '/../../../roots.php';

// Reuse the same authorization boundary as the Inactivate/Reactivate actions.
$can_reactivate = isAdmin() || canDelete('employees');
$can_view       = isAdmin() || canView('employees');

if (!$can_view) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

includeHeader();

$stmt = $pdo->query("
    SELECT
        e.employee_id, e.employee_number, e.first_name, e.last_name,
        e.employment_status, e.inactivation_reason, e.updated_at,
        d.department_name, des.designation_name,
        CONCAT(u.first_name, ' ', u.last_name) AS updated_by_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN designations des ON e.designation_id = des.designation_id
    LEFT JOIN users u ON e.updated_by = u.user_id
    WHERE e.status != 'active'
    ORDER BY e.updated_at DESC
");
$inactiveEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$reasonLabels = [
    'terminated' => 'Terminated',
    'resigned'   => 'Resigned',
];
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="bi bi-person-x-fill text-primary"></i> Inactive Employees</h2>
                    <p class="text-muted mb-0">Deactivated staff — no data lost, reactivate anytime</p>
                </div>
                <a href="<?= getUrl('employees') ?>" class="btn btn-outline-primary px-4 shadow-sm" style="font-weight: 600;">
                    <i class="bi bi-arrow-left me-1"></i> Back to Employees
                </a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary"><?= count($inactiveEmployees) ?></div>
                <div class="small text-muted">Total Inactive</div>
            </div>
        </div>
    </div>

    <div id="tableView">
        <table id="inactiveTable" class="table table-hover align-middle w-100">
            <thead class="table-light">
                <tr>
                    <th>Employee #</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Designation</th>
                    <th>Reason</th>
                    <th>Note</th>
                    <th>Inactivated By</th>
                    <th>Inactivated On</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inactiveEmployees as $emp): ?>
                <tr>
                    <td><?= safe_output($emp['employee_number']) ?></td>
                    <td><?= safe_output(trim($emp['first_name'] . ' ' . $emp['last_name'])) ?></td>
                    <td><?= safe_output($emp['department_name'], '—') ?></td>
                    <td><?= safe_output($emp['designation_name'], '—') ?></td>
                    <td><span class="badge" style="background:#6c757d;color:#fff;"><?= safe_output($reasonLabels[$emp['employment_status']] ?? $emp['employment_status'], '—') ?></span></td>
                    <td><?= safe_output($emp['inactivation_reason'], '—') ?></td>
                    <td><?= safe_output($emp['updated_by_name'], '—') ?></td>
                    <td><?= $emp['updated_at'] ? date('d M Y, H:i', strtotime($emp['updated_at'])) : '—' ?></td>
                    <td class="text-end">
                        <div class="dropdown d-flex justify-content-end">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear-fill me-1"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                <li>
                                    <a class="dropdown-item py-2 rounded" href="<?= getUrl('employee_details') ?>?id=<?= (int)$emp['employee_id'] ?>">
                                        <i class="bi bi-person-badge-fill text-primary me-2"></i> View Full Profile
                                    </a>
                                </li>
                                <?php if ($can_reactivate): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button class="dropdown-item py-2 rounded text-primary" onclick="confirmReactivate(<?= (int)$emp['employee_id'] ?>, '<?= addslashes(trim($emp['first_name'] . ' ' . $emp['last_name'])) ?>')">
                                        <i class="bi bi-arrow-counterclockwise me-2"></i> Reactivate
                                    </button>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="cardView" class="row g-2 d-none"></div>
</div>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#inactiveTable')) {
        $('#inactiveTable').DataTable({
            responsive: false,
            scrollX: true,
            pageLength: 25,
            order: [[7, 'desc']],
            dom: 'rtipB',
            buttons: [
                { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }
            ],
            language: { emptyTable: 'No inactive employees.', zeroRecords: 'No matching records.' },
            drawCallback: function () {
                renderCards(this.api().rows({ page: 'current' }).data().toArray());
            }
        });
    }

    function applyView() {
        if (window.innerWidth < 768) {
            $('#tableView').addClass('d-none');
            $('#cardView').removeClass('d-none');
        } else {
            $('#tableView').removeClass('d-none');
            $('#cardView').addClass('d-none');
        }
    }
    applyView();
    $(window).on('resize', applyView);
});

function renderCards(rows) {
    if (!rows.length) {
        $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No inactive employees</div>');
        return;
    }
    let html = '';
    rows.forEach(row => {
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="fw-bold">${row[1]}</div>
                    <small class="text-muted">${row[2]} — ${row[3]}</small><br>
                    <small class="text-muted">${row[4]} on ${row[7]}</small>
                </div>
                <div class="card-footer bg-white border-top p-0">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                        ${row[8]}
                    </div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}

function confirmReactivate(employeeId, employeeName) {
    var safeName = $('<div>').text(employeeName).html();
    Swal.fire({
        title: 'Reactivate Employee?',
        html: '<p class="text-start mb-0">This restores <strong>' + safeName + '</strong> to active — they will reappear in attendance, leave, payroll, and reporting pickers immediately.</p>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, reactivate'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/reactivate_employee',
                method: 'POST',
                data: { employee_id: employeeId },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: 'Employee has been reactivated.',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire({ icon: 'error', title: 'Reactivate Failed', text: response.message });
                    }
                },
                error: function () {
                    Swal.fire({ icon: 'error', title: 'Server Error', text: 'Error reactivating employee. Please try again.' });
                }
            });
        }
    });
}
</script>

<?php includeFooter(); ?>
