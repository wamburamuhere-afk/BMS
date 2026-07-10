<?php
ob_start();
$page_title = 'CRM Campaigns';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('campaign_management');
includeHeader();

$can_create = canCreate('campaign_management');
$can_edit   = canEdit('campaign_management');
$can_delete = canDelete('campaign_management');

logActivity($pdo, $_SESSION['user_id'], 'View campaigns', 'User viewed the CRM Campaigns list');

$stmt = $pdo->query("
    SELECT mc.campaign_id, mc.campaign_name, mc.type, mc.target_audience,
           mc.start_date, mc.end_date, mc.budget, mc.spent, mc.status,
           COUNT(cl.lead_id)                                                     AS leads_count,
           COALESCE(SUM(cl.converted = 1), 0)                                   AS leads_converted,
           COALESCE(SUM(CASE WHEN ps.is_won = 1 THEN cl.lead_value ELSE 0 END), 0) AS won_value
    FROM marketing_campaigns mc
    LEFT JOIN crm_leads cl ON cl.campaign_id = mc.campaign_id AND cl.status != 'deleted'
    LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
    WHERE mc.is_deleted = 0
    GROUP BY mc.campaign_id
    ORDER BY mc.created_at DESC
");
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total  = count($campaigns);
$active = 0; $budget = 0.0; $spent = 0.0;
foreach ($campaigns as $c) {
    if ($c['status'] === 'Active') $active++;
    $budget += (float)$c['budget'];
    $spent  += (float)$c['spent'];
}

$campaign_types    = ['Email', 'SMS', 'Social Media', 'Direct Call', 'Other'];
$campaign_statuses = ['Planned', 'Active', 'Completed', 'Cancelled', 'Paused'];

$statusBadge = [
    'Planned'   => '#e9ecef;color:#495057',
    'Active'    => '#0d6efd;color:#fff',
    'Completed' => '#052c65;color:#fff',
    'Cancelled' => '#6c757d;color:#fff',
    'Paused'    => '#cfe2ff;color:#084298',
];
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-megaphone text-primary me-2"></i>CRM Campaigns</h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCampaignModal">
            <i class="bi bi-plus-circle me-1"></i> New Campaign
        </button>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= $total ?></div>
                <div class="small text-muted">Total Campaigns</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= $active ?></div>
                <div class="small text-muted">Active</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= number_format($budget, 0) ?></div>
                <div class="small text-muted">Total Budget (TZS)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= number_format($spent, 0) ?></div>
                <div class="small text-muted">Total Spent (TZS)</div>
            </div>
        </div>
    </div>

    <!-- Search -->
    <div class="row mb-2">
        <div class="col-md-4 ms-auto">
            <input type="text" id="campSearch" class="form-control" placeholder="Search campaigns...">
        </div>
    </div>

    <!-- Table -->
    <div id="tableView" class="card border-0 shadow-sm">
        <div class="card-body p-2">
            <table id="campaignsTable" class="table table-hover align-middle w-100">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Date Range</th>
                        <th class="text-end">Budget (TZS)</th>
                        <th class="text-end">Spent (TZS)</th>
                        <th class="text-end">Leads</th>
                        <th class="text-end">Converted</th>
                        <th class="text-end">Won Value (TZS)</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $c): ?>
                    <tr>
                        <td class="fw-semibold"><?= safe_output($c['campaign_name']) ?></td>
                        <td><?= safe_output($c['type']) ?></td>
                        <td><?= safe_output($c['start_date'], '—') ?> <?= $c['end_date'] ? '→ ' . $c['end_date'] : '' ?></td>
                        <td class="text-end"><?= number_format((float)$c['budget'], 0) ?></td>
                        <td class="text-end"><?= number_format((float)$c['spent'], 0) ?></td>
                        <td class="text-end"><?= (int)$c['leads_count'] ?></td>
                        <td class="text-end"><?= (int)$c['leads_converted'] ?></td>
                        <td class="text-end"><?= number_format((float)$c['won_value'], 0) ?></td>
                        <td><span class="badge" style="background:<?= $statusBadge[$c['status']] ?? '#6c757d;color:#fff' ?>"><?= safe_output($c['status']) ?></span></td>
                        <td class="text-end">
                            <div class="dropdown d-flex justify-content-end">
                                <button class="btn btn-sm btn-outline-primary dropdown-toggle px-2" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-gear-fill me-1"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                    <?php if ($can_edit): ?>
                                    <li><button class="dropdown-item py-2 rounded" onclick="editCampaign(<?= $c['campaign_id'] ?>)"><i class="bi bi-pencil text-primary me-2"></i>Edit</button></li>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button class="dropdown-item py-2 rounded text-danger" onclick="deleteCampaign(<?= $c['campaign_id'] ?>, <?= json_encode($c['campaign_name']) ?>)"><i class="bi bi-trash text-danger me-2"></i>Delete</button></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- Campaign Form Helper -->
<?php function campaign_form_fields(string $p, array $types, array $statuses): void { ?>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label">Campaign Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="campaign_name" id="<?= $p ?>_name" required maxlength="255">
        </div>
        <div class="col-md-6">
            <label class="form-label">Type</label>
            <select class="form-select select2-static" name="type" id="<?= $p ?>_type">
                <?php foreach ($types as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Status</label>
            <select class="form-select select2-static" name="status" id="<?= $p ?>_status">
                <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Start Date</label>
            <input type="date" class="form-control" name="start_date" id="<?= $p ?>_start_date">
        </div>
        <div class="col-md-6">
            <label class="form-label">End Date</label>
            <input type="date" class="form-control" name="end_date" id="<?= $p ?>_end_date">
        </div>
        <div class="col-md-6">
            <label class="form-label">Budget (TZS)</label>
            <input type="number" class="form-control" name="budget" id="<?= $p ?>_budget" min="0" step="0.01" value="0">
        </div>
        <div class="col-12">
            <label class="form-label">Target Audience</label>
            <textarea class="form-control" name="target_audience" id="<?= $p ?>_audience" rows="2"></textarea>
        </div>
    </div>
<?php } ?>

<!-- Add Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="addCampaignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i>New Campaign</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addCampaignForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="add-camp-msg" class="mb-2"></div>
                    <?php campaign_form_fields('add', $campaign_types, $campaign_statuses); ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Modal -->
<?php if ($can_edit): ?>
<div class="modal fade" id="editCampaignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i>Edit Campaign</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editCampaignForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="campaign_id" id="edit_camp_id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="edit-camp-msg" class="mb-2"></div>
                    <?php campaign_form_fields('edit', $campaign_types, $campaign_statuses); ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const SAVE_URL   = '<?= buildUrl('api/crm/save_campaign.php') ?>';
const DELETE_URL = '<?= buildUrl('api/crm/delete_campaign.php') ?>';
const CSRF       = '<?= csrf_token() ?>';

function safeOutput(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}

$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#campaignsTable')) {
        const tbl = $('#campaignsTable').DataTable({
            responsive: false, scrollX: true, pageLength: 25, order: [],
            dom: 'rtipB',
            buttons: [{ extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }],
            language: { emptyTable: 'No campaigns found.', zeroRecords: 'No matching campaigns.' },
            drawCallback: function () { renderCards(this.api().rows({ page: 'current' }).data().toArray()); }
        });
        $('#campSearch').on('keyup', function () { tbl.search(this.value).draw(); });
    }

    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView(); $(window).on('resize', applyView);

    // Select2 in modals
    $('#addCampaignModal, #editCampaignModal').on('shown.bs.modal', function () {
        const modal = $(this);
        modal.find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible'))
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: modal, width: '100%' });
        });
    });

    // Add form
    $('#addCampaignForm').on('submit', function (e) {
        e.preventDefault();
        submitForm(this, SAVE_URL, 'addCampaignModal', 'add-camp-msg');
    });

    // Edit form
    $('#editCampaignForm').on('submit', function (e) {
        e.preventDefault();
        submitForm(this, SAVE_URL, 'editCampaignModal', 'edit-camp-msg');
    });

    // Reset modals on close
    $('.modal').on('hidden.bs.modal', function () {
        $(this).find('form')[0]?.reset();
        $(this).find('[id$="-msg"]').html('');
    });
});

function submitForm(form, url, modalId, msgId) {
    const btn = $(form).find('[type=submit]'), orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
    $.ajax({ url, type: 'POST', data: new FormData(form), contentType: false, processData: false, dataType: 'json',
        success: res => {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById(modalId)).hide();
                Swal.fire({ icon:'success', title:'Saved!', text: res.message, timer:2000, showConfirmButton:false })
                    .then(() => location.reload());
            } else {
                $(`#${msgId}`).html(`<div class="alert alert-danger py-1 px-2 small">${res.message}</div>`);
            }
        },
        error: () => Swal.fire({ icon:'error', title:'Error', text:'Server error.' }),
        complete: () => btn.prop('disabled', false).html(orig)
    });
}

function editCampaign(id) {
    $.getJSON('<?= buildUrl('api/crm/get_campaigns.php') ?>', { campaign_id: id }, function (res) {
        if (!res.success || !res.data?.length) {
            Swal.fire({ icon:'error', title:'Error', text: 'Could not load campaign.' }); return;
        }
        const d = res.data[0];
        $('#edit_camp_id').val(d.campaign_id);
        $('#edit_name').val(d.campaign_name);
        $('#edit_type').val(d.type).trigger('change');
        $('#edit_status').val(d.status).trigger('change');
        $('#edit_start_date').val(d.start_date || '');
        $('#edit_end_date').val(d.end_date || '');
        $('#edit_budget').val(d.budget);
        $('#edit_audience').val(d.target_audience || '');
        new bootstrap.Modal(document.getElementById('editCampaignModal')).show();
    });
}

function deleteCampaign(id, name) {
    Swal.fire({ icon:'warning', title:'Delete campaign?', text:`"${name}" will be deleted.`,
        showCancelButton: true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(DELETE_URL, { campaign_id: id, _csrf: CSRF }, function (res) {
            if (res.success) {
                Swal.fire({ icon:'success', title:'Deleted!', text: res.message, timer:1800, showConfirmButton:false })
                    .then(() => location.reload());
            } else { Swal.fire({ icon:'error', title:'Error', text: res.message }); }
        }, 'json');
    });
}

function renderCards(rows) {
    if (!rows.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No campaigns found</div>'); return; }
    let html = '';
    rows.forEach(row => {
        const name = $('<div>').html(row[0]).text();
        const type = $('<div>').html(row[1]).text();
        const id   = /* extract from action col */ null;
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="fw-bold">${safeOutput(name)}</div>
                    <div class="small text-muted">${safeOutput(type)}</div>
                    <div class="d-flex justify-content-between mt-2">
                        <span>${row[8]}</span>
                        <span class="text-primary fw-semibold">${row[7]} TZS won</span>
                    </div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
