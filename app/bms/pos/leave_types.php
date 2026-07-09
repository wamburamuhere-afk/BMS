<?php
ob_start();

// File: app/bms/pos/leave_types.php
//
// Leave Types management — add / edit / delete, with each type's maximum days
// per year and its paid/unpaid classification.
//
// Deliberately NOT in the header navigation. It is reached only from the
// "Manage leave types & maximum days" link rendered beneath the Leave Type
// field on the leave form (app/bms/pos/leaves.php).

$page_title = 'Leave Types';
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output.
$can_view   = isAdmin() || canView('leave_types');
$can_create = isAdmin() || canCreate('leave_types');
$can_edit   = isAdmin() || canEdit('leave_types');
$can_delete = isAdmin() || canDelete('leave_types');

if (!$can_view) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View leave types', 'User viewed the leave types page');

// Usage count drives the delete behaviour: a type with leaves booked against it
// is deactivated rather than removed, so history keeps resolving.
$types = $pdo->query("
    SELECT lt.*,
           (SELECT COUNT(*) FROM leaves l WHERE l.leave_type_id = lt.type_id) AS usage_count
      FROM leave_types lt
     ORDER BY lt.status = 'inactive', lt.type_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$total_types  = count($types);
$active_types = count(array_filter($types, fn($t) => $t['status'] === 'active'));
$paid_types   = count(array_filter($types, fn($t) => (int)$t['is_paid'] === 1 && $t['status'] === 'active'));
$unpaid_types = $active_types - $paid_types;
?>

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-tags me-2 text-primary"></i>Leave Types</h4>
            <small class="text-muted">Define each leave type, its maximum days per year, and whether it is paid.</small>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= getUrl('leaves') ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Leaves
            </a>
            <?php if ($can_create): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTypeModal">
                <i class="bi bi-plus-circle me-1"></i> Add Leave Type
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                <div class="fs-4 fw-bold text-primary"><?= $total_types ?></div>
                <div class="small text-muted">Total Types</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                <div class="fs-4 fw-bold text-primary"><?= $active_types ?></div>
                <div class="small text-muted">Active</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                <div class="fs-4 fw-bold text-primary"><?= $paid_types ?></div>
                <div class="small text-muted">Paid</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                <div class="fs-4 fw-bold text-primary"><?= $unpaid_types ?></div>
                <div class="small text-muted">Unpaid</div>
            </div>
        </div>
    </div>

    <div id="tableView">
        <table id="leaveTypesTable" class="table table-hover align-middle w-100">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th>Leave Type</th>
                    <th class="text-center">Max Days / Year</th>
                    <th class="text-center">Max Consecutive</th>
                    <th class="text-center">Notice (days)</th>
                    <th class="text-center">Payment</th>
                    <th class="text-center">Document</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($types as $i => $t): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:<?= htmlspecialchars($t['color'] ?: '#0d6efd') ?>"></span>
                        <span class="fw-bold"><?= safe_output($t['type_name']) ?></span>
                        <?php if (!empty($t['description'])): ?>
                            <div class="small text-muted"><?= safe_output($t['description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int)$t['max_days_per_year'] ?></td>
                    <td class="text-center"><?= (int)$t['max_consecutive_days'] ?></td>
                    <td class="text-center"><?= (int)$t['min_days_before_apply'] ?></td>
                    <td class="text-center">
                        <?php if ((int)$t['is_paid'] === 1): ?>
                            <span class="badge" style="background:#0d6efd;color:#fff;">Paid</span>
                        <?php else: ?>
                            <span class="badge" style="background:#6c757d;color:#fff;">Unpaid</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$t['requires_document'] === 1): ?>
                            <i class="bi bi-paperclip text-primary" title="Supporting document required"></i>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($t['status'] === 'active'): ?>
                            <span class="badge" style="background:#0d6efd;color:#fff;">Active</span>
                        <?php else: ?>
                            <span class="badge" style="background:#6c757d;color:#fff;">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($can_edit): ?>
                        <button class="btn btn-sm btn-outline-primary" onclick="editType(<?= (int)$t['type_id'] ?>)" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($can_delete): ?>
                        <button class="btn btn-sm btn-outline-danger"
                                onclick="confirmDelete(<?= (int)$t['type_id'] ?>, <?= (int)$t['usage_count'] ?>, '<?= htmlspecialchars(addslashes($t['type_name']), ENT_QUOTES) ?>')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="cardView" class="row g-2 d-none"></div>
</div>

<?php
// The add and edit modals share one field set; only ids and the title differ.
$modalFields = function (string $p) {
    ob_start(); ?>
    <div class="row">
        <div class="col-md-8 mb-3">
            <label class="form-label">Leave Type Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="type_name" id="<?= $p ?>type_name" maxlength="50" required
                   placeholder="e.g. Compassionate Leave">
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Colour</label>
            <input type="color" class="form-control form-control-color w-100" name="color" id="<?= $p ?>color" value="#0d6efd">
        </div>

        <div class="col-12 mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" id="<?= $p ?>description" rows="2"
                      placeholder="Briefly describe when this leave type applies"></textarea>
        </div>

        <div class="col-md-4 mb-3">
            <label class="form-label">Maximum Days per Year <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="max_days_per_year" id="<?= $p ?>max_days_per_year"
                   min="1" max="366" required>
            <small class="text-muted">Total entitlement in a calendar year.</small>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Maximum Consecutive Days</label>
            <input type="number" class="form-control" name="max_consecutive_days" id="<?= $p ?>max_consecutive_days" min="1">
            <small class="text-muted">Longest unbroken block. Defaults to the yearly maximum.</small>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Notice Required (days)</label>
            <input type="number" class="form-control" name="min_days_before_apply" id="<?= $p ?>min_days_before_apply" min="0" value="0">
            <small class="text-muted">How far ahead the employee must apply.</small>
        </div>

        <div class="col-md-4 mb-3">
            <label class="form-label">Carry-over Days</label>
            <input type="number" class="form-control" name="carry_over_days" id="<?= $p ?>carry_over_days" min="0" value="0">
            <small class="text-muted">Unused days that roll into next year.</small>
        </div>

        <div class="col-md-8 mb-3">
            <label class="form-label d-block">Payment Treatment <span class="text-danger">*</span></label>
            <div class="border rounded p-2">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="is_paid" id="<?= $p ?>is_paid_yes" value="1" checked>
                    <label class="form-check-label" for="<?= $p ?>is_paid_yes">
                        <strong>Paid Leave</strong>
                        <div class="small text-muted">Salary is paid in full for the days taken, and the days count against the yearly entitlement above.</div>
                    </label>
                </div>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="radio" name="is_paid" id="<?= $p ?>is_paid_no" value="0">
                    <label class="form-check-label" for="<?= $p ?>is_paid_no">
                        <strong>Unpaid Leave</strong>
                        <div class="small text-muted">Salary is not paid for the days taken. The days are still recorded and limited by the maximum above.</div>
                    </label>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="requires_document" id="<?= $p ?>requires_document" value="1">
                <label class="form-check-label" for="<?= $p ?>requires_document">
                    Requires a supporting document
                    <div class="small text-muted">e.g. a medical certificate for Sick Leave.</div>
                </label>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="status" id="<?= $p ?>status">
                <option value="active">Active — available when applying for leave</option>
                <option value="inactive">Inactive — hidden from the leave form</option>
            </select>
        </div>
    </div>
    <?php return ob_get_clean();
};
?>

<?php if ($can_create): ?>
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> Add Leave Type</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addTypeForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="add-message" class="mb-2"></div>
                    <?= $modalFields('add_') ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_edit): ?>
<div class="modal fade" id="editTypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Edit Leave Type</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editTypeForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="type_id" id="edit_type_id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="edit-message" class="mb-2"></div>
                    <div id="edit_usage_note" class="alert alert-info py-2 small d-none"></div>
                    <?= $modalFields('edit_') ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const CAN_EDIT_TYPE   = <?= json_encode($can_edit) ?>;
const CAN_DELETE_TYPE = <?= json_encode($can_delete) ?>;

$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#leaveTypesTable')) {
        $('#leaveTypesTable').DataTable({
            responsive: false,
            scrollX: true,
            pageLength: 25,
            order: [[1, 'asc']],
            dom: 'rtipB',
            buttons: [
                { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }
            ],
            language: { emptyTable: 'No records found.', zeroRecords: 'No matching records.' },
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

    $('#addTypeForm').on('submit', function (e) {
        e.preventDefault();
        submitForm(this, '<?= buildUrl('api/add_leave_type.php') ?>', () => location.reload());
    });

    $('#editTypeForm').on('submit', function (e) {
        e.preventDefault();
        submitForm(this, '<?= buildUrl('api/update_leave_type.php') ?>', () => location.reload());
    });

    $('.modal').on('hidden.bs.modal', function () {
        const f = $(this).find('form')[0];
        if (f) f.reset();
        $(this).find('[id$="-message"]').html('');
        $('#edit_usage_note').addClass('d-none').text('');
    });
});

function submitForm(form, url, onSuccess) {
    const btn = $(form).find('[type="submit"]');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
    $.ajax({
        url: url,
        type: 'POST',
        data: new FormData(form),
        contentType: false,
        processData: false,
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: res.message, timer: 2200, showConfirmButton: false }).then(onSuccess);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
            }
        },
        error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
        complete: function () { btn.prop('disabled', false).html(orig); }
    });
}

function editType(id) {
    $.getJSON('<?= buildUrl('api/get_leave_type.php') ?>', { id: id }, function (res) {
        if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not load leave type.' });
            return;
        }
        const d = res.data;
        $('#edit_type_id').val(d.type_id);
        $('#edit_type_name').val(d.type_name);
        $('#edit_description').val(d.description || '');
        $('#edit_max_days_per_year').val(d.max_days_per_year);
        $('#edit_max_consecutive_days').val(d.max_consecutive_days);
        $('#edit_min_days_before_apply').val(d.min_days_before_apply);
        $('#edit_carry_over_days').val(d.carry_over_days);
        $('#edit_color').val(d.color || '#0d6efd');
        $('#edit_requires_document').prop('checked', String(d.requires_document) === '1');
        $(String(d.is_paid) === '1' ? '#edit_is_paid_yes' : '#edit_is_paid_no').prop('checked', true);
        $('#edit_status').val(d.status);

        const used = parseInt(d.usage_count, 10) || 0;
        if (used > 0) {
            $('#edit_usage_note')
                .text(`${used} leave record(s) already use this type. Changing the payment treatment will not alter those records — each leave keeps the paid/unpaid status it was approved under.`)
                .removeClass('d-none');
        }
        new bootstrap.Modal(document.getElementById('editTypeModal')).show();
    });
}

function confirmDelete(id, usageCount, name) {
    const used = parseInt(usageCount, 10) || 0;
    const opts = used > 0
        ? {
            title: 'Deactivate this leave type?',
            html: `<b>${name}</b> is used by <b>${used}</b> leave record(s), so it cannot be deleted.<br><br>` +
                  `It will be <b>deactivated</b>: it disappears from the leave form, and existing records keep working.`,
            confirmButtonText: 'Yes, deactivate'
          }
        : {
            title: 'Delete this leave type?',
            html: `<b>${name}</b> is not used by any leave record. This cannot be undone.`,
            confirmButtonText: 'Yes, delete'
          };

    Swal.fire({
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', ...opts
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/delete_leave_type.php') ?>', { type_id: id, _csrf: '<?= csrf_token() ?>' }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Done', text: res.message, timer: 2600, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}

function renderCards(rows) {
    if (!rows.length) {
        $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No records found.</div>');
        return;
    }
    let html = '';
    rows.forEach(row => {
        // row[] indices follow the <thead> order.
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="fw-bold">${row[1]}</div>
                    <div class="small text-muted mt-1">
                        Max ${row[2]} days/year &middot; up to ${row[3]} consecutive &middot; ${row[4]} day(s) notice
                    </div>
                    <div class="mt-2">${row[5]} ${row[7]}</div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
