<?php
ob_start();

$page_title = 'CRM Leads';
require_once 'header.php';

// Permission check
$can_view   = canView('crm_leads');
$can_create = canCreate('crm_leads');
$can_edit   = canEdit('crm_leads');
$can_delete = canDelete('crm_leads');
$can_bulk   = canEdit('crm_bulk');
$can_import = canCreate('crm_import');

if (!$can_view) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

logActivity($pdo, $_SESSION['user_id'], 'View leads', 'User viewed the CRM leads management list');

// Lead detail page ships in a later phase — only show the View action once it exists
$lead_view_ready = file_exists(CRM_DIR . '/crm_lead_view.php');

$lead_sources = [
    'website'        => 'Website',
    'referral'       => 'Referral',
    'walk_in'        => 'Walk-in',
    'phone_call'     => 'Phone Call',
    'social_media'   => 'Social Media',
    'exhibition'     => 'Exhibition',
    'cold_call'      => 'Cold Call',
    'email_campaign' => 'Email Campaign',
    'other'          => 'Other',
];

// Dropdown data
$stages = $pdo->query("SELECT stage_id, stage_name, color, is_won, is_lost FROM crm_pipeline_stages WHERE status = 'active' ORDER BY stage_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$users  = $pdo->query("SELECT user_id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username) AS name FROM users WHERE is_active = 1 ORDER BY first_name, username")->fetchAll(PDO::FETCH_ASSOC);
$labels = $pdo->query("SELECT label_id, label_name, color FROM crm_labels WHERE status = 'active' ORDER BY label_name")->fetchAll(PDO::FETCH_ASSOC);

// Filters (same param names as api/crm/export_leads.php so Export carries them through)
$f_stage    = intval($_GET['stage_id'] ?? 0);
$f_source   = isset($_GET['lead_source']) && isset($lead_sources[$_GET['lead_source']]) ? $_GET['lead_source'] : '';
$f_assigned = intval($_GET['assigned_to'] ?? 0);
$f_from     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$f_to       = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'] ?? '') ? $_GET['date_to'] : '';

$where  = ["cl.status != 'deleted'"];
$params = [];
if ($f_stage)    { $where[] = "cl.pipeline_stage_id = ?"; $params[] = $f_stage; }
if ($f_source)   { $where[] = "cl.lead_source = ?";       $params[] = $f_source; }
if ($f_assigned) { $where[] = "cl.assigned_to = ?";       $params[] = $f_assigned; }
if ($f_from)     { $where[] = "DATE(cl.created_at) >= ?"; $params[] = $f_from; }
if ($f_to)       { $where[] = "DATE(cl.created_at) <= ?"; $params[] = $f_to; }

$where_sql = implode(' AND ', $where) . scopeFilterSqlNullable('project', 'cl');

// Stats (same filtered set as the table)
$statStmt = $pdo->prepare("
    SELECT COUNT(*)                                                                          AS total,
           COALESCE(SUM(cl.status = 'active'), 0)                                           AS active,
           COALESCE(SUM(cl.converted = 1), 0)                                               AS converted,
           COALESCE(SUM(CASE WHEN cl.converted = 0 AND COALESCE(ps.is_lost, 0) = 0
                             THEN cl.lead_value ELSE 0 END), 0)                              AS pipeline_value
    FROM crm_leads cl
    LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
    WHERE $where_sql
");
$statStmt->execute($params);
$stats = $statStmt->fetch(PDO::FETCH_ASSOC);

// Lead rows
$stmt = $pdo->prepare("
    SELECT cl.lead_id, cl.lead_code, cl.first_name, cl.last_name, cl.company_name,
           cl.lead_source, cl.lead_value, cl.probability, cl.expected_close_date,
           cl.converted, cl.status,
           ps.stage_name, ps.color AS stage_color,
           COALESCE(NULLIF(TRIM(CONCAT_WS(' ', ua.first_name, ua.last_name)), ''), ua.username) AS assigned_name
    FROM crm_leads cl
    LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
    LEFT JOIN users ua ON cl.assigned_to = ua.user_id
    WHERE $where_sql
    ORDER BY cl.created_at DESC
");
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$export_qs = http_build_query(array_filter([
    'stage_id'    => $f_stage ?: null,
    'lead_source' => $f_source ?: null,
    'assigned_to' => $f_assigned ?: null,
    'date_from'   => $f_from ?: null,
    'date_to'     => $f_to ?: null,
]));
?>

<div class="container-fluid mt-4">
    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-person-plus me-2 text-primary"></i>CRM Leads</h4>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-primary btn-sm" href="<?= buildUrl('api/crm/export_leads.php') ?><?= $export_qs ? '?' . $export_qs : '' ?>">
                <i class="bi bi-download me-1"></i> Export
            </a>
            <?php if ($can_import): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= getUrl('crm/import_leads') ?>">
                <i class="bi bi-file-earmark-arrow-up me-1"></i> Import CSV
            </a>
            <?php endif; ?>
            <?php if ($can_create): ?>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                <i class="bi bi-plus-circle me-1"></i> Add Lead
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary"><?= number_format($stats['total']) ?></div>
                <div class="small text-muted">Total Leads</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary"><?= number_format($stats['active']) ?></div>
                <div class="small text-muted">Active</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary"><?= number_format($stats['converted']) ?></div>
                <div class="small text-muted">Converted</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary"><?= number_format($stats['pipeline_value']) ?> <small class="fs-6">TZS</small></div>
                <div class="small text-muted">Pipeline Value</div>
            </div>
        </div>
    </div>

    <!-- Filter bar -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="get" action="<?= getUrl('crm/leads') ?>" class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Stage</label>
                    <select class="form-select form-select-sm select2-filter" name="stage_id">
                        <option value="">All Stages</option>
                        <?php foreach ($stages as $s): ?>
                        <option value="<?= $s['stage_id'] ?>" <?= $f_stage == $s['stage_id'] ? 'selected' : '' ?>><?= safe_output($s['stage_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Source</label>
                    <select class="form-select form-select-sm select2-filter" name="lead_source">
                        <option value="">All Sources</option>
                        <?php foreach ($lead_sources as $key => $lbl): ?>
                        <option value="<?= $key ?>" <?= $f_source === $key ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Assigned To</label>
                    <select class="form-select form-select-sm select2-filter" name="assigned_to">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['user_id'] ?>" <?= $f_assigned == $u['user_id'] ? 'selected' : '' ?>><?= safe_output($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" class="form-control form-control-sm" name="date_from" value="<?= safe_output($f_from, '') ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" class="form-control form-control-sm" name="date_to" value="<?= safe_output($f_to, '') ?>">
                </div>
                <div class="col-6 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="<?= getUrl('crm/leads') ?>" class="btn btn-sm btn-secondary flex-fill">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Search (DataTable dom has no built-in box) -->
    <div class="row mb-2">
        <div class="col-md-4 ms-auto">
            <input type="text" id="leadSearch" class="form-control" placeholder="Search leads...">
        </div>
    </div>

    <!-- Bulk action bar (hidden until selections made) -->
    <?php if ($can_bulk): ?>
    <div id="bulkBar" class="card border-0 shadow-sm mb-2 d-none" style="background:#e7f0ff;border:1px solid #b6ccfe !important">
        <div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">
            <span class="fw-semibold text-primary"><span id="bulkCount">0</span> selected</span>
            <select class="form-select form-select-sm" id="bulkAction" style="max-width:180px">
                <option value="">Choose action…</option>
                <option value="stage">Move to Stage</option>
                <option value="assign">Assign To</option>
                <option value="label">Add Label</option>
                <option value="delete">Delete Selected</option>
            </select>
            <select class="form-select form-select-sm d-none" id="bulkStage" style="max-width:180px">
                <option value="">Select stage…</option>
                <?php foreach ($stages as $s): ?><option value="<?= $s['stage_id'] ?>"><?= safe_output($s['stage_name']) ?></option><?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm d-none" id="bulkUser" style="max-width:180px">
                <option value="">Select user…</option>
                <?php foreach ($users as $u): ?><option value="<?= $u['user_id'] ?>"><?= safe_output($u['name']) ?></option><?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm d-none" id="bulkLabel" style="max-width:180px">
                <option value="">Select label…</option>
                <?php foreach ($labels as $l): ?><option value="<?= $l['label_id'] ?>"><?= safe_output($l['label_name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" onclick="runBulk()"><i class="bi bi-check2 me-1"></i>Apply</button>
            <button class="btn btn-secondary btn-sm" onclick="clearSelection()">Cancel</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Desktop table -->
    <div id="tableView" class="card border-0 shadow-sm">
        <div class="card-body p-2">
            <table id="leadsTable" class="table table-hover align-middle w-100">
                <thead class="table-light">
                    <tr>
                        <?php if ($can_bulk): ?><th style="width:36px"><input type="checkbox" id="selectAll" class="form-check-input" title="Select all"></th><?php endif; ?>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Source</th>
                        <th>Stage</th>
                        <th class="text-end">Value (TZS)</th>
                        <th>Assigned To</th>
                        <th>Expected Close</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $row): ?>
                    <tr data-lead-id="<?= $row['lead_id'] ?>">
                        <?php if ($can_bulk): ?>
                        <td><input type="checkbox" class="form-check-input lead-check" value="<?= $row['lead_id'] ?>"></td>
                        <?php endif; ?>
                        <td><span class="fw-semibold text-primary" data-lead-id="<?= $row['lead_id'] ?>"><?= safe_output($row['lead_code']) ?></span></td>
                        <td><?= safe_output(trim($row['first_name'] . ' ' . ($row['last_name'] ?? ''))) ?></td>
                        <td><?= safe_output($row['company_name'], '—') ?></td>
                        <td><?= $lead_sources[$row['lead_source']] ?? ucfirst($row['lead_source']) ?></td>
                        <td><span class="badge" style="background:<?= safe_output($row['stage_color'], '#6c757d') ?>;color:#fff;"><?= safe_output($row['stage_name'], '—') ?></span></td>
                        <td class="text-end"><?= number_format($row['lead_value'], 0) ?></td>
                        <td><?= safe_output($row['assigned_name'], '—') ?></td>
                        <td><?= safe_output($row['expected_close_date'], '—') ?></td>
                        <td>
                            <?php if ((int)$row['converted'] === 1): ?>
                            <span class="badge" style="background:#052c65;color:#fff;">Converted</span>
                            <?php elseif ($row['status'] === 'active'): ?>
                            <span class="badge" style="background:#0d6efd;color:#fff;">Active</span>
                            <?php else: ?>
                            <span class="badge" style="background:#6c757d;color:#fff;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="dropdown d-flex justify-content-end">
                                <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-gear-fill me-1"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                    <?php if ($lead_view_ready): ?>
                                    <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('crm/lead_view') ?>?id=<?= $row['lead_id'] ?>"><i class="bi bi-eye text-primary me-2"></i> View</a></li>
                                    <?php endif; ?>
                                    <?php if ($can_edit): ?>
                                    <li><button class="dropdown-item py-2 rounded" onclick="editRow(<?= $row['lead_id'] ?>)"><i class="bi bi-pencil text-primary me-2"></i> Edit</button></li>
                                    <?php endif; ?>
                                    <?php if ($can_delete && (int)$row['converted'] !== 1): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button class="dropdown-item py-2 rounded text-danger" onclick="confirmDelete(<?= $row['lead_id'] ?>)"><i class="bi bi-trash text-danger me-2"></i> Delete</button></li>
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

    <!-- Mobile card view -->
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<?php
// Shared modal form fields — rendered twice (add / edit) with an ID prefix
function crm_lead_form_fields($prefix, $stages, $users, $labels, $lead_sources) {
?>
    <!-- Section: Contact Details -->
    <p class="fw-semibold text-primary mb-2 mt-1" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e9ecef;padding-bottom:4px;">
        <i class="bi bi-person me-1"></i>Contact Details
    </p>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label">First Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="first_name" id="<?= $prefix ?>_first_name" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Last Name</label>
            <input type="text" class="form-control" name="last_name" id="<?= $prefix ?>_last_name">
        </div>
        <div class="col-md-4">
            <label class="form-label">Company</label>
            <input type="text" class="form-control" name="company_name" id="<?= $prefix ?>_company_name">
        </div>
        <div class="col-md-4">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" id="<?= $prefix ?>_email">
        </div>
        <div class="col-md-4">
            <label class="form-label">Phone</label>
            <input type="text" class="form-control" name="phone" id="<?= $prefix ?>_phone">
        </div>
        <div class="col-md-4">
            <label class="form-label">Mobile</label>
            <input type="text" class="form-control" name="mobile" id="<?= $prefix ?>_mobile">
        </div>
        <div class="col-md-4">
            <label class="form-label">City</label>
            <input type="text" class="form-control" name="city" id="<?= $prefix ?>_city">
        </div>
        <div class="col-md-4">
            <label class="form-label">Country</label>
            <input type="text" class="form-control" name="country" id="<?= $prefix ?>_country" value="Tanzania">
        </div>
        <div class="col-md-4">
            <label class="form-label">Website</label>
            <input type="text" class="form-control" name="website" id="<?= $prefix ?>_website" placeholder="https://">
        </div>
        <div class="col-12">
            <label class="form-label">Address</label>
            <input type="text" class="form-control" name="address" id="<?= $prefix ?>_address">
        </div>
    </div>

    <!-- Section: Pipeline Details -->
    <p class="fw-semibold text-primary mb-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e9ecef;padding-bottom:4px;">
        <i class="bi bi-funnel me-1"></i>Pipeline Details
    </p>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label">Source</label>
            <select class="form-select select2-static" name="lead_source" id="<?= $prefix ?>_lead_source">
                <?php foreach ($lead_sources as $key => $lbl): ?>
                <option value="<?= $key ?>" <?= $key === 'other' ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Pipeline Stage</label>
            <select class="form-select select2-static" name="pipeline_stage_id" id="<?= $prefix ?>_pipeline_stage_id">
                <?php foreach ($stages as $i => $s): ?>
                <option value="<?= $s['stage_id'] ?>" <?= $i === 0 ? 'selected' : '' ?>><?= safe_output($s['stage_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Assigned To</label>
            <select class="form-select select2-static" name="assigned_to" id="<?= $prefix ?>_assigned_to">
                <option value="">-- Select --</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= $u['user_id'] ?>"><?= safe_output($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Lead Value (TZS)</label>
            <input type="number" class="form-control" name="lead_value" id="<?= $prefix ?>_lead_value" min="0" step="0.01" value="0">
        </div>
        <div class="col-md-4">
            <label class="form-label">Probability (%)</label>
            <input type="number" class="form-control" name="probability" id="<?= $prefix ?>_probability" min="0" max="100" value="20">
        </div>
        <div class="col-md-4">
            <label class="form-label">Expected Close Date</label>
            <input type="date" class="form-control" name="expected_close_date" id="<?= $prefix ?>_expected_close_date">
        </div>
        <?php if ($labels): ?>
        <div class="col-12">
            <label class="form-label">Labels</label>
            <select class="form-select select2-static" name="labels[]" id="<?= $prefix ?>_labels" multiple>
                <?php foreach ($labels as $l): ?>
                <option value="<?= $l['label_id'] ?>"><?= safe_output($l['label_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <!-- Section: Notes -->
    <p class="fw-semibold text-primary mb-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e9ecef;padding-bottom:4px;">
        <i class="bi bi-sticky me-1"></i>Additional Information
    </p>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label">Product / Service Interest</label>
            <textarea class="form-control" name="product_interest" id="<?= $prefix ?>_product_interest" rows="2" placeholder="Which products or services is this lead interested in?"></textarea>
        </div>
        <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" id="<?= $prefix ?>_notes" rows="2" placeholder="Any additional notes about this lead..."></textarea>
        </div>
    </div>
<?php } ?>

<!-- Shared CSS: allow Select2 dropdowns to escape modal-content boundary -->
<style>
#addLeadModal .modal-content,
#editLeadModal .modal-content { overflow: visible; }
#addLeadModal .modal-header,
#editLeadModal .modal-header,
#addLeadModal .modal-footer,
#editLeadModal .modal-footer { position: relative; z-index: 1; }
#addLeadModal .modal-body,
#editLeadModal .modal-body {
    overflow-y: auto;
    overflow-x: hidden;
    max-height: calc(100vh - 200px);
}
</style>

<!-- Add Lead Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="addLeadModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> Add Lead</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addLeadForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <?php crm_lead_form_fields('add', $stages, $users, $labels, $lead_sources); ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save Lead</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Lead Modal -->
<?php if ($can_edit): ?>
<div class="modal fade" id="editLeadModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Edit Lead</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editLeadForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="lead_id" id="edit_lead_id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <?php crm_lead_form_fields('edit', $stages, $users, $labels, $lead_sources); ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Update Lead</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const CAN_EDIT       = <?= json_encode((bool)$can_edit) ?>;
const CAN_DELETE     = <?= json_encode((bool)$can_delete) ?>;
const CAN_BULK       = <?= json_encode((bool)$can_bulk) ?>;

function safeOutput(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}
const LEAD_VIEW_READY = <?= json_encode((bool)$lead_view_ready) ?>;
const LEAD_VIEW_URL  = '<?= getUrl('crm/lead_view') ?>';
const CSRF = '<?= csrf_token() ?>';
const BULK_URL = '<?= buildUrl('api/crm/bulk_update_leads.php') ?>';

$(document).ready(function () {
    // DataTable init
    if (!$.fn.DataTable.isDataTable('#leadsTable')) {
        const table = $('#leadsTable').DataTable({
            responsive: false,
            scrollX: true,
            pageLength: 25,
            order: [],
            dom: 'rtipB',
            buttons: [
                { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }
            ],
            language: { emptyTable: 'No records found.', zeroRecords: 'No matching records.' },
            drawCallback: function () {
                renderCards(this.api().rows({ page: 'current' }).data().toArray());
            }
        });

        $('#leadSearch').on('keyup', function () {
            table.search(this.value).draw();
        });
    }

    // View toggle: card on mobile, table on desktop
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

    // Filter bar Select2 (outside modals)
    $('.select2-filter').each(function () {
        if (!$(this).hasClass('select2-hidden-accessible')) {
            $(this).select2({ theme: 'bootstrap-5', width: '100%', allowClear: false });
        }
    });

    // Add form submit
    $('#addLeadForm').on('submit', function (e) {
        e.preventDefault();
        submitLeadForm(this, '<?= buildUrl('api/crm/add_lead.php') ?>', 'addLeadModal');
    });

    // Edit form submit
    $('#editLeadForm').on('submit', function (e) {
        e.preventDefault();
        submitLeadForm(this, '<?= buildUrl('api/crm/edit_lead.php') ?>', 'editLeadModal');
    });

    // Clear modals on close
    $('.modal').on('hidden.bs.modal', function () {
        const form = $(this).find('form')[0];
        if (form) form.reset();
        $(this).find('select.select2-static').val(null).trigger('change');
    });

    // Init Select2 in modals
    $('#addLeadModal, #editLeadModal').on('shown.bs.modal', function () {
        const modal = $(this);
        modal.find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({
                    theme: 'bootstrap-5',
                    dropdownParent: modal,
                    placeholder: 'Select...',
                    allowClear: true,
                    width: '100%'
                });
            }
        });
    });
});

// AJAX submit — disables button with spinner, restores on complete
function submitLeadForm(form, url, modalId) {
    const btn  = $(form).find('[type="submit"]');
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
                bootstrap.Modal.getInstance(document.getElementById(modalId)).hide();
                Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 2000, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
            }
        },
        error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' }); },
        complete: function () { btn.prop('disabled', false).html(orig); }
    });
}

// Load lead into the edit modal
function editRow(id) {
    $.getJSON('<?= buildUrl('api/crm/get_lead.php') ?>', { id: id }, function (res) {
        if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not load lead.' });
            return;
        }
        const d = res.data;
        $('#edit_lead_id').val(d.lead_id);
        $('#edit_first_name').val(d.first_name);
        $('#edit_last_name').val(d.last_name);
        $('#edit_company_name').val(d.company_name);
        $('#edit_email').val(d.email);
        $('#edit_phone').val(d.phone);
        $('#edit_mobile').val(d.mobile);
        $('#edit_lead_source').val(d.lead_source).trigger('change');
        $('#edit_pipeline_stage_id').val(d.pipeline_stage_id).trigger('change');
        $('#edit_assigned_to').val(d.assigned_to || '').trigger('change');
        $('#edit_lead_value').val(d.lead_value);
        $('#edit_probability').val(d.probability);
        $('#edit_expected_close_date').val(d.expected_close_date);
        $('#edit_city').val(d.city);
        $('#edit_country').val(d.country);
        $('#edit_address').val(d.address);
        $('#edit_website').val(d.website);
        $('#edit_product_interest').val(d.product_interest);
        $('#edit_notes').val(d.notes);
        if (d.labels && $('#edit_labels').length) {
            $('#edit_labels').val(d.labels.map(l => String(l.label_id))).trigger('change');
        }
        new bootstrap.Modal(document.getElementById('editLeadModal')).show();
    });
}

// Delete confirmation
function confirmDelete(id) {
    Swal.fire({
        title: 'Delete this lead?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/crm/delete_lead.php') ?>', { lead_id: id, _csrf: CSRF }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}

// ── Bulk selection logic ────────────────────────────────────────────────────
if (CAN_BULK) {
    $(document).on('change', '#selectAll', function () {
        $('.lead-check').prop('checked', this.checked);
        updateBulkBar();
    });
    $(document).on('change', '.lead-check', updateBulkBar);

    $('#bulkAction').on('change', function () {
        const val = $(this).val();
        $('#bulkStage,#bulkUser,#bulkLabel').addClass('d-none');
        if (val === 'stage')  $('#bulkStage').removeClass('d-none');
        if (val === 'assign') $('#bulkUser').removeClass('d-none');
        if (val === 'label')  $('#bulkLabel').removeClass('d-none');
    });
}

function updateBulkBar() {
    if (!CAN_BULK) return;
    const checked = $('.lead-check:checked');
    if (checked.length > 0) {
        $('#bulkCount').text(checked.length);
        $('#bulkBar').removeClass('d-none');
    } else {
        $('#bulkBar').addClass('d-none');
    }
}

function clearSelection() {
    $('.lead-check,#selectAll').prop('checked', false);
    $('#bulkBar').addClass('d-none');
    $('#bulkAction').val('');
    $('#bulkStage,#bulkUser,#bulkLabel').addClass('d-none');
}

function runBulk() {
    const action = $('#bulkAction').val();
    const ids = $('.lead-check:checked').map((i, el) => el.value).get();
    if (!action) { Swal.fire({ icon:'warning', text:'Please choose an action.' }); return; }
    if (!ids.length) { Swal.fire({ icon:'warning', text:'No leads selected.' }); return; }

    let value = '';
    if (action === 'stage')  value = $('#bulkStage').val();
    if (action === 'assign') value = $('#bulkUser').val();
    if (action === 'label')  value = $('#bulkLabel').val();
    if (['stage','assign','label'].includes(action) && !value) {
        Swal.fire({ icon:'warning', text:'Please make a selection for this action.' }); return;
    }

    const confirmMsg = action === 'delete'
        ? `Delete ${ids.length} lead(s)? This cannot be undone.`
        : `Apply to ${ids.length} lead(s)?`;

    Swal.fire({ icon:'question', title:'Confirm', text: confirmMsg,
        showCancelButton: true, confirmButtonColor: action === 'delete' ? '#dc3545' : '#0d6efd',
        confirmButtonText: action === 'delete' ? 'Yes, Delete' : 'Apply'
    }).then(r => {
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('action', action);
        fd.append('value', value);
        ids.forEach(id => fd.append('lead_ids[]', id));
        $.ajax({ url: BULK_URL, type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
            success: res => {
                if (res.success) {
                    Swal.fire({ icon:'success', title:'Done!', text: res.message, timer:2000, showConfirmButton:false })
                        .then(() => location.reload());
                } else { Swal.fire({ icon:'error', title:'Error', text: res.message }); }
            },
            error: () => Swal.fire({ icon:'error', title:'Error', text:'Server error.' })
        });
    });
}

// Mobile card renderer — cells: 0=Code 1=Name 2=Company 4=Stage badge 5=Value 8=Status badge
function renderCards(rows) {
    if (!rows.length) {
        $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No records found</div>');
        return;
    }
    let html = '';
    rows.forEach(row => {
        const id      = $(row[0]).data('lead-id');
        const code    = $('<div>').html(row[0]).text();
        const name    = $('<div>').html(row[1]).text();
        const company = $('<div>').html(row[2]).text();

        let buttons = '';
        if (LEAD_VIEW_READY) buttons += `<a class="btn btn-sm btn-outline-primary" href="${LEAD_VIEW_URL}?id=${id}" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-eye"></i></a>`;
        if (CAN_EDIT)        buttons += `<button class="btn btn-sm btn-outline-primary" onclick="editRow(${id})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-pencil"></i></button>`;
        if (CAN_DELETE)      buttons += `<button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(${id})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-trash"></i></button>`;

        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold">${safeOutput(name)}</div>
                            <small class="text-muted">${safeOutput(company, '')} · ${safeOutput(code)}</small>
                        </div>
                        <div>${row[8]}</div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div>${row[4]}</div>
                        <div class="fw-semibold">${row[5]} <small class="text-muted">TZS</small></div>
                    </div>
                </div>
                ${buttons ? `<div class="card-footer bg-white border-top p-0"><div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">${buttons}</div></div>` : ''}
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php require_once 'footer.php'; ?>
