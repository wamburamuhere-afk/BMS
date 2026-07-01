<?php
ob_start();
$page_title = 'Lead Details';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('crm_leads');
includeHeader();

$can_edit     = canEdit('crm_leads');
$can_delete   = canDelete('crm_leads');
$can_convert  = canCreate('crm_convert');
$can_act_add  = canCreate('crm_activities');
$can_act_edit = canEdit('crm_activities');
$can_act_del  = canDelete('crm_activities');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Invalid lead ID.</div></div>";
    includeFooter(); exit;
}

assertScopeForRecordHtml('crm_leads', 'lead_id', $id);

$stmt = $pdo->prepare("
    SELECT cl.*,
           ps.stage_name, ps.color AS stage_color, ps.is_won, ps.is_lost,
           COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)),''), u.username) AS assigned_user_name,
           COALESCE(NULLIF(TRIM(CONCAT_WS(' ', cb.first_name, cb.last_name)),''), cb.username) AS created_by_name,
           c.customer_name, c.customer_code,
           q.order_number AS quote_code
    FROM crm_leads cl
    LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
    LEFT JOIN users u  ON cl.assigned_to  = u.user_id
    LEFT JOIN users cb ON cl.created_by   = cb.user_id
    LEFT JOIN customers c  ON cl.customer_id  = c.customer_id
    LEFT JOIN quotations q ON cl.quotation_id = q.sales_order_id
    WHERE cl.lead_id = ? AND cl.status != 'deleted'
");
$stmt->execute([$id]);
$lead = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lead) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Lead not found.</div></div>";
    includeFooter(); exit;
}

$stages = $pdo->query("SELECT stage_id, stage_name, color, stage_order FROM crm_pipeline_stages WHERE status='active' ORDER BY stage_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$users  = $pdo->query("SELECT user_id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)),''), username) AS name FROM users WHERE is_active=1 ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

// Labels for this lead
$lead_labels = $pdo->prepare("SELECT l.label_id, l.label_name, l.color FROM crm_labels l JOIN crm_lead_labels ll ON l.label_id = ll.label_id WHERE ll.lead_id = ? AND l.status = 'active'");
$lead_labels->execute([$id]);
$lead_labels = $lead_labels->fetchAll(PDO::FETCH_ASSOC);

// All available labels (for management)
$all_labels = $pdo->query("SELECT label_id, label_name, color FROM crm_labels WHERE status = 'active' ORDER BY label_name")->fetchAll(PDO::FETCH_ASSOC);

// Stage history
$history = $pdo->prepare("
    SELECT h.changed_at, ps_from.stage_name AS from_stage, ps_to.stage_name AS to_stage,
           COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)),''), u.username) AS changed_by_name
    FROM crm_lead_stage_history h
    LEFT JOIN crm_pipeline_stages ps_from ON h.from_stage_id = ps_from.stage_id
    LEFT JOIN crm_pipeline_stages ps_to   ON h.to_stage_id   = ps_to.stage_id
    LEFT JOIN users u ON h.changed_by = u.user_id
    WHERE h.lead_id = ?
    ORDER BY h.changed_at DESC
    LIMIT 10
");
$history->execute([$id]);
$stage_history = $history->fetchAll(PDO::FETCH_ASSOC);

// Days in current stage
$days_in_stage = null;
if (!empty($lead['stage_entered'])) {
    $days_in_stage = (int)floor((time() - strtotime($lead['stage_entered'])) / 86400);
}

// Stage progress index (for progress bar)
$stage_index = 0; $stage_total = count($stages);
foreach ($stages as $i => $s) {
    if ($s['stage_id'] == $lead['pipeline_stage_id']) { $stage_index = $i + 1; break; }
}
$stage_progress_pct = $stage_total > 0 ? round($stage_index / $stage_total * 100) : 0;

$can_label = canEdit('crm_leads');

$lead_sources = [
    'website'=>'Website','referral'=>'Referral','walk_in'=>'Walk-in',
    'phone_call'=>'Phone Call','social_media'=>'Social Media','exhibition'=>'Exhibition',
    'cold_call'=>'Cold Call','email_campaign'=>'Email Campaign','other'=>'Other',
];

$is_overdue_close = !empty($lead['expected_close_date']) && $lead['expected_close_date'] < date('Y-m-d')
                    && !in_array($lead['status'], ['deleted']) && !$lead['converted']
                    && !$lead['is_won'] && !$lead['is_lost'];

$source_label = $lead_sources[$lead['lead_source']] ?? ucfirst(str_replace('_',' ',$lead['lead_source'] ?? ''));
?>

<style>
.lv-section      { background:#fff; border-radius:10px; box-shadow:0 1px 6px rgba(0,0,0,.07); padding:20px 22px; margin-bottom:18px; }
.lv-label        { font-size:.72rem; color:#6c757d; text-transform:uppercase; letter-spacing:.04em; margin-bottom:2px; }
.lv-value        { font-size:.93rem; font-weight:600; color:#212529; }
.lv-title        { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#0d6efd; border-bottom:1px solid #e9ecef; padding-bottom:6px; margin-bottom:14px; }
.activity-item   { border-left:3px solid #dee2e6; padding:10px 14px; margin-bottom:10px; border-radius:0 8px 8px 0; background:#fafafa; transition:border-color .15s; }
.activity-item:hover { border-left-color:#0d6efd; background:#f1f5ff; }
.activity-icon   { width:30px; height:30px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; }
.prob-bar        { height:6px; border-radius:6px; background:#e9ecef; overflow:hidden; margin-top:4px; }
.prob-fill       { height:6px; border-radius:6px; }
</style>

<div class="container-fluid mt-3 mb-5 px-3 px-md-4">

    <!-- Breadcrumb + actions -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= getUrl('crm/leads') ?>">Leads</a></li>
                <li class="breadcrumb-item active"><?= safe_output($lead['lead_code']) ?></li>
            </ol>
        </nav>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($can_edit && !$lead['converted']): ?>
            <button class="btn btn-outline-primary btn-sm" onclick="openEditModal()">
                <i class="bi bi-pencil me-1"></i> Edit
            </button>
            <?php endif; ?>
            <?php if ($can_convert && $lead['is_won'] && !$lead['converted']): ?>
            <button class="btn btn-primary btn-sm" onclick="convertLead()">
                <i class="bi bi-arrow-right-circle me-1"></i> Convert Lead
            </button>
            <?php endif; ?>
            <?php if ($can_delete && !$lead['converted']): ?>
            <button class="btn btn-outline-danger btn-sm" onclick="deleteLead()">
                <i class="bi bi-trash me-1"></i> Delete
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">

        <!-- ── LEFT COLUMN ── -->
        <div class="col-lg-5">

            <!-- Header card -->
            <div class="lv-section" style="border-top:4px solid <?= htmlspecialchars($lead['stage_color'] ?? '#0d6efd') ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <div class="text-muted small"><?= safe_output($lead['lead_code']) ?></div>
                        <h4 class="fw-bold mb-1"><?= safe_output(trim($lead['first_name'].' '.($lead['last_name']??''))) ?></h4>
                        <?php if ($lead['company_name']): ?>
                        <div class="text-muted"><i class="bi bi-building me-1"></i><?= safe_output($lead['company_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end d-flex flex-column gap-1 align-items-end">
                        <span class="badge" style="background:<?= htmlspecialchars($lead['stage_color'] ?? '#6c757d') ?>;color:#fff;font-size:.78rem">
                            <?= safe_output($lead['stage_name'] ?? '—') ?>
                        </span>
                        <?php if ($lead['converted']): ?>
                        <span class="badge" style="background:#052c65;color:#fff;font-size:.75rem"><i class="bi bi-check2-circle me-1"></i>Converted</span>
                        <?php endif; ?>
                        <?php if ($lead['is_won'] && !$lead['converted']): ?>
                        <span class="badge" style="background:#0d6efd;color:#fff;font-size:.72rem">Won</span>
                        <?php endif; ?>
                        <?php if ($lead['is_lost']): ?>
                        <span class="badge" style="background:#dc3545;color:#fff;font-size:.72rem">Lost</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stage progress bar -->
                <?php if ($stage_total > 0 && !$lead['is_lost']): ?>
                <div class="mt-3">
                    <div class="lv-label">Pipeline Progress — <?= safe_output($lead['stage_name'] ?? '—') ?> (<?= $stage_index ?>/<?= $stage_total ?>)</div>
                    <div class="prob-bar" style="height:8px"><div class="prob-fill" style="width:<?= $stage_progress_pct ?>%;background:<?= htmlspecialchars($lead['stage_color'] ?? '#0d6efd') ?>"></div></div>
                </div>
                <?php endif; ?>

                <!-- Lead value + probability + score -->
                <div class="row g-2 mt-3">
                    <div class="col-4">
                        <div class="lv-label">Lead Value</div>
                        <div class="fw-bold text-primary" style="font-size:1.05rem">TZS <?= number_format((float)$lead['lead_value'], 0) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="lv-label">Probability</div>
                        <div class="fw-semibold"><?= (int)$lead['probability'] ?>%</div>
                        <div class="prob-bar"><div class="prob-fill" style="width:<?= (int)$lead['probability'] ?>%;background:<?= (int)$lead['probability'] >= 80 ? '#198754' : ((int)$lead['probability'] >= 50 ? '#ffc107' : '#dc3545') ?>"></div></div>
                    </div>
                    <div class="col-4">
                        <div class="lv-label">Lead Score</div>
                        <?php $score = (int)($lead['lead_score'] ?? 0); $sc = $score >= 70 ? '#198754' : ($score >= 40 ? '#ffc107' : '#dc3545'); ?>
                        <div class="fw-semibold" style="color:<?= $sc ?>"><?= $score ?>/100</div>
                        <div class="prob-bar"><div class="prob-fill" style="width:<?= $score ?>%;background:<?= $sc ?>"></div></div>
                    </div>
                </div>

                <?php if ($days_in_stage !== null): ?>
                <div class="mt-2 small text-muted">
                    <i class="bi bi-clock me-1"></i><?= $days_in_stage ?> day<?= $days_in_stage !== 1 ? 's' : '' ?> in current stage
                    <?php if ($days_in_stage > 14): ?><span class="badge ms-1" style="background:#ffc107;color:#000;font-size:.65rem">Stalled</span><?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Labels -->
                <?php if ($lead_labels || ($can_label && $all_labels)): ?>
                <div class="mt-3">
                    <div class="lv-label mb-1">Labels</div>
                    <div id="labelPills" class="d-flex flex-wrap gap-1">
                        <?php foreach ($lead_labels as $lbl): ?>
                        <span class="badge" style="background:<?= htmlspecialchars($lbl['color']) ?>;font-size:.72rem">
                            <?= safe_output($lbl['label_name']) ?>
                            <?php if ($can_label): ?>
                            <span style="cursor:pointer;margin-left:4px" onclick="removeLabel(<?= $lbl['label_id'] ?>)">&times;</span>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                        <?php if ($can_label && $all_labels): ?>
                        <button class="btn btn-outline-secondary btn-sm py-0 px-1" style="font-size:.72rem" onclick="showLabelPicker()">
                            <i class="bi bi-plus"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Contact & details -->
            <div class="lv-section">
                <div class="lv-title"><i class="bi bi-info-circle me-1"></i>Lead Information</div>
                <div class="row g-3">
                    <?php if ($lead['email']): ?>
                    <div class="col-sm-6">
                        <div class="lv-label">Email</div>
                        <div class="lv-value"><a href="mailto:<?= safe_output($lead['email']) ?>" class="text-decoration-none"><?= safe_output($lead['email']) ?></a></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($lead['phone']): ?>
                    <div class="col-sm-6">
                        <div class="lv-label">Phone</div>
                        <div class="lv-value"><a href="tel:<?= safe_output($lead['phone']) ?>" class="text-decoration-none"><?= safe_output($lead['phone']) ?></a></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($lead['mobile']): ?>
                    <div class="col-sm-6">
                        <div class="lv-label">Mobile</div>
                        <div class="lv-value"><?= safe_output($lead['mobile']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($lead['website']): ?>
                    <div class="col-sm-6">
                        <div class="lv-label">Website</div>
                        <div class="lv-value"><a href="<?= safe_output($lead['website']) ?>" target="_blank" rel="noopener" class="text-decoration-none text-truncate d-block" style="max-width:180px"><?= safe_output($lead['website']) ?></a></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-6">
                        <div class="lv-label">Source</div>
                        <div class="lv-value"><?= safe_output($source_label) ?></div>
                    </div>
                    <?php if ($lead['assigned_user_name']): ?>
                    <div class="col-sm-6">
                        <div class="lv-label">Assigned To</div>
                        <div class="lv-value"><i class="bi bi-person me-1 text-muted"></i><?= safe_output($lead['assigned_user_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($lead['expected_close_date']): ?>
                    <div class="col-sm-6">
                        <div class="lv-label">Expected Close</div>
                        <div class="lv-value <?= $is_overdue_close ? 'text-danger' : '' ?>">
                            <?= safe_output($lead['expected_close_date']) ?>
                            <?php if ($is_overdue_close): ?><span class="badge bg-danger ms-1" style="font-size:.65rem">Overdue</span><?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($lead['city'] || $lead['address']): ?>
                    <div class="col-sm-6">
                        <div class="lv-label">Location</div>
                        <div class="lv-value"><?= safe_output(implode(', ', array_filter([$lead['city'], $lead['country']]))) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-6">
                        <div class="lv-label">Created By</div>
                        <div class="lv-value"><?= safe_output($lead['created_by_name'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="lv-label">Created At</div>
                        <div class="lv-value"><?= safe_output(date('d M Y', strtotime($lead['created_at']))) ?></div>
                    </div>
                </div>

                <?php if ($lead['product_interest']): ?>
                <div class="mt-3">
                    <div class="lv-label">Product Interest</div>
                    <div class="lv-value" style="white-space:pre-wrap;font-weight:400"><?= safe_output($lead['product_interest']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($lead['notes']): ?>
                <div class="mt-3">
                    <div class="lv-label">Notes</div>
                    <div class="lv-value" style="white-space:pre-wrap;font-weight:400"><?= safe_output($lead['notes']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($lead['is_lost'] && $lead['lost_reason']): ?>
                <div class="mt-3">
                    <div class="lv-label text-danger">Lost Reason</div>
                    <div class="lv-value text-danger" style="font-weight:400"><?= safe_output($lead['lost_reason']) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Stage history -->
            <?php if ($stage_history): ?>
            <div class="lv-section">
                <div class="lv-title"><i class="bi bi-shuffle me-1"></i>Stage History</div>
                <?php foreach ($stage_history as $h): ?>
                <div class="d-flex align-items-center gap-2 mb-2" style="font-size:.82rem">
                    <span class="text-muted" style="min-width:130px"><?= date('d M Y H:i', strtotime($h['changed_at'])) ?></span>
                    <span class="text-muted"><?= safe_output($h['from_stage'] ?? '—') ?></span>
                    <i class="bi bi-arrow-right text-muted"></i>
                    <span class="fw-semibold"><?= safe_output($h['to_stage'] ?? '—') ?></span>
                    <span class="text-muted ms-auto">by <?= safe_output($h['changed_by_name'] ?? '—') ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Converted: links to Customer + Quotation -->
            <?php if ($lead['converted']): ?>
            <div class="lv-section" style="border-top:3px solid #198754">
                <div class="lv-title text-success"><i class="bi bi-check2-circle me-1"></i>Converted Records</div>
                <div class="row g-2">
                    <?php if ($lead['customer_id']): ?>
                    <div class="col-6">
                        <a href="<?= getUrl('customers') ?>?view=<?= (int)$lead['customer_id'] ?>" class="btn btn-outline-success btn-sm w-100">
                            <i class="bi bi-person-check me-1"></i><?= safe_output($lead['customer_code'] ?? 'Customer') ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if ($lead['quotation_id']): ?>
                    <div class="col-6">
                        <a href="<?= getUrl('quotations') ?>?view=<?= (int)$lead['quotation_id'] ?>" class="btn btn-outline-primary btn-sm w-100">
                            <i class="bi bi-file-earmark-text me-1"></i><?= safe_output($lead['quote_code'] ?? 'Quotation') ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- ── RIGHT COLUMN — Activity timeline ── -->
        <div class="col-lg-7">
            <div class="lv-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="lv-title mb-0"><i class="bi bi-clock-history me-1"></i>Activity Timeline</div>
                    <?php if ($can_act_add): ?>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addActivityModal">
                        <i class="bi bi-plus-circle me-1"></i> Log Activity
                    </button>
                    <?php endif; ?>
                </div>

                <div id="activityTimeline">
                    <div class="text-center text-muted py-4">
                        <div class="spinner-border spinner-border-sm me-1"></div> Loading…
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ── Add Activity Modal ── -->
<?php if ($can_act_add): ?>
<div class="modal fade" id="addActivityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title mb-0"><i class="bi bi-plus-circle me-1"></i>Log Activity</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addActivityForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="lead_id" value="<?= $id ?>">
                    <div id="act-add-msg" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold">Type</label>
                            <select class="form-select form-select-sm" name="activity_type">
                                <option value="call">📞 Call</option>
                                <option value="email">✉ Email</option>
                                <option value="meeting">👥 Meeting</option>
                                <option value="note" selected>📝 Note</option>
                                <option value="task">✅ Task</option>
                                <option value="site_visit">📍 Site Visit</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold">Status</label>
                            <select class="form-select form-select-sm" name="status">
                                <option value="pending">Pending</option>
                                <option value="done">Done</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Subject <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="subject" required maxlength="200">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold">Activity Date</label>
                            <input type="datetime-local" class="form-control form-control-sm" name="activity_date" value="<?= date('Y-m-d\TH:i') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold">Due Date <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="datetime-local" class="form-control form-control-sm" name="due_date">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Description</label>
                            <textarea class="form-control form-control-sm" name="description" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Outcome</label>
                            <textarea class="form-control form-control-sm" name="outcome" rows="2" placeholder="Result of this activity…"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-circle me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Edit Activity Modal ── -->
<?php if ($can_act_edit): ?>
<div class="modal fade" id="editActivityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title mb-0"><i class="bi bi-pencil me-1"></i>Edit Activity</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editActivityForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="activity_id" id="ea_id">
                    <div id="act-edit-msg" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold">Type</label>
                            <select class="form-select form-select-sm" name="activity_type" id="ea_type">
                                <option value="call">📞 Call</option>
                                <option value="email">✉ Email</option>
                                <option value="meeting">👥 Meeting</option>
                                <option value="note">📝 Note</option>
                                <option value="task">✅ Task</option>
                                <option value="site_visit">📍 Site Visit</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold">Status</label>
                            <select class="form-select form-select-sm" name="status" id="ea_status">
                                <option value="pending">Pending</option>
                                <option value="done">Done</option>
                                <option value="overdue">Overdue</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Subject <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="subject" id="ea_subject" required maxlength="200">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold">Activity Date</label>
                            <input type="datetime-local" class="form-control form-control-sm" name="activity_date" id="ea_date">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold">Due Date</label>
                            <input type="datetime-local" class="form-control form-control-sm" name="due_date" id="ea_due">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Description</label>
                            <textarea class="form-control form-control-sm" name="description" id="ea_desc" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Outcome</label>
                            <textarea class="form-control form-control-sm" name="outcome" id="ea_outcome" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-circle me-1"></i>Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const ACT_LIST_URL   = '<?= buildUrl('api/crm/get_activities.php') ?>';
const ACT_ADD_URL    = '<?= buildUrl('api/crm/add_activity.php') ?>';
const ACT_EDIT_URL   = '<?= buildUrl('api/crm/edit_activity.php') ?>';
const ACT_DEL_URL    = '<?= buildUrl('api/crm/delete_activity.php') ?>';
const OVERDUE_URL    = '<?= buildUrl('api/crm/mark_overdue_activities.php') ?>';
const LABEL_UPD_URL  = '<?= buildUrl('api/crm/update_lead_labels.php') ?>';
const LEAD_ID        = <?= $id ?>;
const CSRF           = '<?= csrf_token() ?>';
const CAN_ACT_EDIT   = <?= json_encode($can_act_edit) ?>;
const CAN_ACT_DEL    = <?= json_encode($can_act_del) ?>;
const CAN_LABEL      = <?= json_encode((bool)$can_label) ?>;
const CONVERT_URL    = '<?= buildUrl('api/crm/convert_lead.php') ?>';
const DELETE_URL     = '<?= buildUrl('api/crm/delete_lead.php') ?>';
const ALL_LABELS     = <?= json_encode($all_labels) ?>;
let currentLabelIds  = <?= json_encode(array_column($lead_labels, 'label_id')) ?>;
const esc = s => String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtDt = s => s ? new Date(s).toLocaleString(undefined,{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';

const TYPE_META = {
    call:       {icon:'bi-telephone-fill',   color:'#0d6efd', label:'Call'},
    email:      {icon:'bi-envelope-fill',    color:'#6f42c1', label:'Email'},
    meeting:    {icon:'bi-people-fill',      color:'#198754', label:'Meeting'},
    note:       {icon:'bi-sticky-fill',      color:'#ffc107', label:'Note'},
    task:       {icon:'bi-check2-square',    color:'#0dcaf0', label:'Task'},
    site_visit: {icon:'bi-geo-alt-fill',     color:'#fd7e14', label:'Site Visit'},
};
const STATUS_BADGE = {
    pending: '<span class="badge" style="background:#e9ecef;color:#495057;font-size:.68rem">Pending</span>',
    done:    '<span class="badge" style="background:#0d6efd;color:#fff;font-size:.68rem">Done</span>',
    overdue: '<span class="badge" style="background:#dc3545;color:#fff;font-size:.68rem">Overdue</span>',
};

function renderTimeline(activities) {
    if (!activities.length) {
        $('#activityTimeline').html('<div class="text-center text-muted py-4"><i class="bi bi-clock-history display-6 opacity-25 d-block mb-2"></i>No activities yet. Log the first one.</div>');
        return;
    }
    let html = '';
    activities.forEach(a => {
        const m = TYPE_META[a.activity_type] || {icon:'bi-dot', color:'#6c757d', label:a.activity_type};
        const editBtn = CAN_ACT_EDIT
            ? `<button class="btn btn-sm btn-outline-primary py-0 px-1" style="font-size:.72rem" onclick="editActivity(${JSON.stringify(a)})"><i class="bi bi-pencil"></i></button>`
            : '';
        const delBtn = CAN_ACT_DEL
            ? `<button class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:.72rem" onclick="deleteActivity(${a.activity_id}, ${JSON.stringify(a.subject)})"><i class="bi bi-trash"></i></button>`
            : '';
        const outcome = a.outcome ? `<div class="mt-1 small text-success"><i class="bi bi-check2 me-1"></i>${esc(a.outcome)}</div>` : '';
        const desc    = a.description ? `<div class="mt-1 small text-muted">${esc(a.description)}</div>` : '';
        const dueNote = a.due_date ? `<span class="ms-2 small text-muted"><i class="bi bi-alarm me-1"></i>Due: ${fmtDt(a.due_date)}</span>` : '';
        html += `
        <div class="activity-item" style="border-left-color:${m.color}">
            <div class="d-flex align-items-start gap-2">
                <div class="activity-icon" style="background:${m.color}22">
                    <i class="bi ${m.icon}" style="color:${m.color}"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                        <div>
                            <span class="fw-semibold" style="font-size:.88rem">${esc(a.subject)}</span>
                            <span class="badge ms-1" style="background:${m.color}22;color:${m.color};font-size:.65rem">${m.label}</span>
                            ${STATUS_BADGE[a.status] ?? ''}
                        </div>
                        <div class="d-flex gap-1">${editBtn}${delBtn}</div>
                    </div>
                    <div class="text-muted" style="font-size:.75rem">
                        <i class="bi bi-calendar3 me-1"></i>${fmtDt(a.activity_date)}
                        ${dueNote}
                        <span class="ms-2"><i class="bi bi-person me-1"></i>${esc(a.created_by_name||'')}</span>
                    </div>
                    ${desc}${outcome}
                </div>
            </div>
        </div>`;
    });
    $('#activityTimeline').html(html);
}

function loadActivities() {
    $.getJSON(ACT_LIST_URL, {lead_id: LEAD_ID})
        .done(res => { if (res.success) renderTimeline(res.data); })
        .fail(() => { $('#activityTimeline').html('<div class="alert alert-danger">Failed to load activities.</div>'); });
}

function editActivity(a) {
    $('#ea_id').val(a.activity_id);
    $('#ea_type').val(a.activity_type);
    $('#ea_status').val(a.status);
    $('#ea_subject').val(a.subject);
    $('#ea_desc').val(a.description || '');
    $('#ea_outcome').val(a.outcome || '');
    // datetime-local expects YYYY-MM-DDTHH:mm
    const toLocal = s => s ? s.substring(0,16) : '';
    $('#ea_date').val(toLocal(a.activity_date));
    $('#ea_due').val(toLocal(a.due_date || ''));
    $('#act-edit-msg').html('');
    new bootstrap.Modal(document.getElementById('editActivityModal')).show();
}

function deleteActivity(id, subject) {
    Swal.fire({icon:'warning',title:'Delete activity?',text:`"${subject}" will be removed.`,
        showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Yes, delete'})
    .then(r => {
        if (!r.isConfirmed) return;
        $.post(ACT_DEL_URL, {activity_id:id, _csrf:CSRF}, null, 'json')
            .done(res => {
                if (res.success) loadActivities();
                else Swal.fire({icon:'error',title:'Error',text:res.message});
            })
            .fail(() => Swal.fire({icon:'error',title:'Error',text:'Server error'}));
    });
}

function convertLead() {
    Swal.fire({
        icon:'question', title:'Convert this lead?',
        html:'This will create a <strong>Customer</strong> record and an open <strong>Quotation</strong>.<br>The lead will be marked as converted.',
        showCancelButton:true, confirmButtonColor:'#198754', confirmButtonText:'Yes, convert'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(CONVERT_URL, {lead_id:LEAD_ID, _csrf:CSRF}, null, 'json')
            .done(res => {
                if (res.success) {
                    Swal.fire({icon:'success', title:'Lead Converted!',
                        html:`Customer <strong>${esc(res.customer_code)}</strong> and Quotation <strong>${esc(res.quotation_code)}</strong> created.`,
                        confirmButtonText:'View Lead'
                    }).then(() => location.reload());
                } else {
                    Swal.fire({icon:'error',title:'Error',text:res.message});
                }
            })
            .fail(() => Swal.fire({icon:'error',title:'Error',text:'Server error'}));
    });
}

function deleteLead() {
    Swal.fire({icon:'warning',title:'Delete this lead?',text:'This cannot be undone.',
        showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Yes, delete'})
    .then(r => {
        if (!r.isConfirmed) return;
        $.post(DELETE_URL, {lead_id:LEAD_ID, _csrf:CSRF}, null, 'json')
            .done(res => {
                if (res.success) { window.location.href = '<?= getUrl('crm/leads') ?>'; }
                else Swal.fire({icon:'error',title:'Error',text:res.message});
            })
            .fail(() => Swal.fire({icon:'error',title:'Error',text:'Server error'}));
    });
}

function openEditModal() {
    // Redirect to leads list with edit param — leads page owns the edit modal
    window.location.href = '<?= getUrl('crm/leads') ?>?edit=<?= $id ?>';
}

// Form submits
$('#addActivityForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $(this).find('[type=submit]'), orig = btn.html();
    const actType = $(this).find('[name=activity_type]').val();
    const actSubject = $(this).find('[name=subject]').val();
    btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({url:ACT_ADD_URL,type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
        success: res => {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('addActivityModal')).hide();
                this.reset();
                loadActivities();
                // Offer follow-up for calls and meetings
                if (['call','meeting','site_visit'].includes(actType)) {
                    setTimeout(() => {
                        Swal.fire({
                            icon:'question', title:'Schedule Follow-up?',
                            html:`<small class="text-muted">After "${esc(actSubject)}"</small>`,
                            input:'date', inputLabel:'Follow-up date',
                            inputValue: new Date(Date.now()+3*86400000).toISOString().split('T')[0],
                            showCancelButton:true, confirmButtonText:'Schedule It',
                            cancelButtonText:'No, skip',
                        }).then(r2 => {
                            if (!r2.isConfirmed || !r2.value) return;
                            const fd2 = new FormData();
                            fd2.append('_csrf', CSRF);
                            fd2.append('lead_id', LEAD_ID);
                            fd2.append('activity_type', 'task');
                            fd2.append('subject', `Follow-up after ${actType} — ${actSubject}`);
                            fd2.append('status', 'pending');
                            fd2.append('due_date', r2.value + 'T09:00');
                            $.ajax({url:ACT_ADD_URL,type:'POST',data:fd2,contentType:false,processData:false,
                                success: () => loadActivities()
                            });
                        });
                    }, 400);
                } else {
                    Swal.fire({icon:'success',title:'Logged',text:res.message,timer:1500,showConfirmButton:false});
                }
            } else { $('#act-add-msg').html(`<div class="alert alert-danger py-1 px-2 small">${res.message}</div>`); }
        },
        error: () => { $('#act-add-msg').html('<div class="alert alert-danger py-1 px-2 small">Server error</div>'); },
        complete: () => btn.prop('disabled',false).html(orig)
    });
});

$('#editActivityForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $(this).find('[type=submit]'), orig = btn.html();
    btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({url:ACT_EDIT_URL,type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
        success: res => {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('editActivityModal')).hide();
                loadActivities();
                Swal.fire({icon:'success',title:'Updated',text:res.message,timer:1500,showConfirmButton:false});
            } else { $('#act-edit-msg').html(`<div class="alert alert-danger py-1 px-2 small">${res.message}</div>`); }
        },
        error: () => { $('#act-edit-msg').html('<div class="alert alert-danger py-1 px-2 small">Server error</div>'); },
        complete: () => btn.prop('disabled',false).html(orig)
    });
});

// Label management
function showLabelPicker() {
    if (!CAN_LABEL || !ALL_LABELS.length) return;
    const opts = ALL_LABELS.map(l =>
        `<option value="${l.label_id}" ${currentLabelIds.includes(parseInt(l.label_id)) ? 'selected' : ''}
            style="background:${l.color};color:#fff">${l.label_name}</option>`
    ).join('');
    Swal.fire({
        title: 'Manage Labels', html:
            `<select id="swal-labels" class="form-select" multiple size="${Math.min(ALL_LABELS.length, 6)}">${opts}</select>`,
        showCancelButton: true, confirmButtonText: 'Save Labels',
        didOpen: () => { $('#swal-labels').select2({ theme:'bootstrap-5', width:'100%', allowClear:true, placeholder:'Pick labels…' }); }
    }).then(r => {
        if (!r.isConfirmed) return;
        const selected = $('#swal-labels').val() || [];
        const fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('lead_id', LEAD_ID);
        selected.forEach(id => fd.append('label_ids[]', id));
        $.ajax({ url: LABEL_UPD_URL, type:'POST', data:fd, contentType:false, processData:false, dataType:'json',
            success: res => { if (res.success) location.reload(); else Swal.fire({ icon:'error', text: res.message }); }
        });
    });
}

function removeLabel(label_id) {
    currentLabelIds = currentLabelIds.filter(id => id !== label_id);
    const fd = new FormData();
    fd.append('_csrf', CSRF);
    fd.append('lead_id', LEAD_ID);
    currentLabelIds.forEach(id => fd.append('label_ids[]', id));
    $.ajax({ url: LABEL_UPD_URL, type:'POST', data:fd, contentType:false, processData:false, dataType:'json',
        success: res => { if (res.success) location.reload(); }
    });
}

$(function(){
    loadActivities();
    // Silently mark overdue activities on page load
    $.post(OVERDUE_URL, { _csrf: CSRF });
});
</script>

<?php includeFooter(); ob_end_flush(); ?>
