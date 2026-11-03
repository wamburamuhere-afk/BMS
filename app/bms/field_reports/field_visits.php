<?php
// Ziara za Wateja / Customer Visits (marketing) — record customer visits and produce the
// daily report. Every user sees only their own visits; admins see all staff
// (core/field_reports.php). Phone-first layout: customer_visits_ux_plan.md.
ob_start();
$page_title = 'Customer Visits';
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/core/field_reports.php';
require_once ROOT_DIR . '/core/vendor_context.php';
autoEnforcePermission('field_visits');
includeHeader();

$can_create = canCreate('field_visits');
$can_edit   = canEdit('field_visits');
// BJP's own install (platform root): a joined client's company and the customer it became.
$isVendor   = isPlatformVendorContext();
$is_admin   = isAdmin();   // fresh call, after header.php
$me         = (int)$_SESSION['user_id'];
$today      = date('Y-m-d');

$staff = [];
if ($is_admin) {
    $staff = $pdo->query("SELECT user_id, first_name, last_name, username FROM users WHERE is_active = 1 ORDER BY first_name, last_name, username")
                 ->fetchAll(PDO::FETCH_ASSOC);
}
$userLang = function_exists('currentLanguage') && currentLanguage() === 'sw' ? 'sw' : 'en';
// Services (Services page) a Sales Officer can say they intend to sell on a visit.
try { $serviceOptions = frServiceOptions($pdo); } catch (PDOException $e) { $serviceOptions = []; }
$interestStyles = ['interested' => ['success', 'bi-emoji-smile'], 'thinking' => ['warning', 'bi-hourglass-split'], 'not_interested' => ['danger', 'bi-emoji-frown']];
?>
<div class="container-fluid mt-3" id="frPage">
    <!-- Header (sticky on phones: the "record" button is always one tap away) -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 fr-sticky">
        <div>
            <h4 class="mb-0"><i class="bi bi-geo-alt text-primary me-2"></i><?= t('Customer Visits') ?></h4>
            <small class="text-muted"><?= $is_admin ? t('Visits by all staff') : t('Your customer visits') ?></small>
        </div>
        <div class="d-flex flex-wrap gap-2 fr-head-actions">
            <?php if ($can_create): ?>
            <button class="btn btn-primary fw-bold" id="btnAddVisit"><i class="bi bi-plus-circle me-1"></i><?= t('Record a visit') ?></button>
            <button class="btn btn-outline-primary" id="btnSubmitDay"><i class="bi bi-send-check me-1"></i><?= t("Submit Today's Report") ?></button>
            <?php endif; ?>
            <!-- Report: PDF / WhatsApp / email / print — for the dates and staff chosen below -->
            <div class="dropdown fr-report-menu">
                <button class="btn btn-outline-primary dropdown-toggle w-100" type="button" id="btnReportMenu" data-bs-toggle="dropdown" aria-expanded="false" title="<?= t('Opens the report for the dates and staff chosen below') ?>"><i class="bi bi-file-earmark-text me-1"></i><?= t('Report') ?></button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" aria-labelledby="btnReportMenu">
                    <li><button class="dropdown-item py-2 rounded" type="button" data-frs="download"><i class="bi bi-file-earmark-pdf text-primary me-2"></i><?= t('Download PDF') ?></button></li>
                    <li><button class="dropdown-item py-2 rounded" type="button" data-frs="whatsapp"><i class="bi bi-whatsapp text-primary me-2"></i><?= t('Share on WhatsApp') ?></button></li>
                    <li><button class="dropdown-item py-2 rounded" type="button" data-frs="email"><i class="bi bi-envelope text-primary me-2"></i><?= t('Send by email') ?></button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><button class="dropdown-item py-2 rounded" type="button" id="btnReport"><i class="bi bi-printer text-primary me-2"></i><?= t('Print report') ?></button></li>
                    <li><button class="dropdown-item py-2 rounded" type="button" id="btnExcel"><i class="bi bi-file-earmark-spreadsheet text-primary me-2"></i><?= t('Download Excel') ?></button></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Follow-ups due (today / overdue) -->
    <div class="card border-0 shadow-sm mb-3 d-none fr-follow" id="followCard">
        <div class="card-header bg-white py-2 d-flex align-items-center">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-telephone-forward me-2"></i><?= t('To follow up') ?> <span class="badge bg-primary ms-1" id="followCount">0</span></h6>
        </div>
        <ul class="list-group list-group-flush" id="followList"></ul>
    </div>

    <div id="dayStatus" class="mb-3"></div>

    <!-- The three numbers that matter in the field -->
    <div class="row g-2 mb-2" id="mainStats"></div>
    <div class="mb-3">
        <button class="btn btn-sm btn-link px-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#moreStats" aria-expanded="false" id="moreStatsBtn">
            <i class="bi bi-bar-chart me-1"></i><?= t('More statistics') ?> <i class="bi bi-chevron-down small"></i>
        </button>
        <div class="collapse" id="moreStats"><div class="row g-2 pt-1" id="statCards"></div>
            <div class="card border-0 shadow-sm mt-2 d-none" id="serviceStatsCard"><div class="card-body p-2">
                <div class="small fw-bold text-primary mb-1"><i class="bi bi-briefcase me-1"></i><?= t('Services offered most') ?></div>
                <div class="d-flex flex-wrap gap-1" id="serviceStats"></div>
            </div></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold mb-1"><?= t('From') ?></label>
                <input type="date" class="form-control" id="fFrom" value="<?= $today ?>" max="<?= $today ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold mb-1"><?= t('To') ?></label>
                <input type="date" class="form-control" id="fTo" value="<?= $today ?>" max="<?= $today ?>">
            </div>
            <?php if ($is_admin): ?>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-bold mb-1"><?= t('Staff') ?></label>
                <select class="form-select select2-static" id="fStaff">
                    <option value=""><?= t('All staff') ?></option>
                    <?php foreach ($staff as $s): $n = trim($s['first_name'] . ' ' . $s['last_name']) ?: $s['username']; ?>
                    <option value="<?= (int)$s['user_id'] ?>"><?= safe_output($n) ?><?= (int)$s['user_id'] === $me ? ' (' . t('me') . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-auto d-flex gap-2 flex-nowrap fr-quick">
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-quick="today"><?= t('Today') ?></button>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-quick="yesterday"><?= t('Yesterday') ?></button>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-quick="week"><?= t('This Week') ?></button>
            </div>
        </div>
    </div></div>

    <?php if ($is_admin): ?>
    <!-- One table at a time: switch between the visits and the per-staff summary -->
    <div class="btn-group mb-2 w-100 fr-view-switch" role="group" aria-label="<?= t('Show') ?>">
        <input type="radio" class="btn-check" name="frView" id="viewVisits" value="visits" autocomplete="off" checked>
        <label class="btn btn-outline-primary" for="viewVisits"><i class="bi bi-list-ul me-1"></i><?= t('Visits') ?> <span class="badge bg-primary ms-1" id="visitsCount">0</span></label>
        <input type="radio" class="btn-check" name="frView" id="viewStaff" value="staff" autocomplete="off">
        <label class="btn btn-outline-primary" for="viewStaff"><i class="bi bi-people me-1"></i><?= t('Summary by staff') ?></label>
    </div>
    <!-- Admin: per-staff summary — table on desktop, list on phones -->
    <div class="card border-0 shadow-sm mb-3 d-none" id="staffSummaryCard"><div class="card-body p-3">
        <div id="staffTableWrap">
        <table class="table table-sm align-middle w-100" id="staffTable">
            <thead class="fr-thead"><tr>
                <th><?= t('Staff') ?></th><th class="text-center"><?= t('Visits') ?></th><th class="text-center"><?= t('People visited') ?></th>
                <th class="text-center"><?= t('Places') ?></th><th class="text-center"><?= t('Business cards given') ?></th>
                <th class="text-center"><?= t('Trial links given') ?></th><th class="text-center"><?= t('Trainings given') ?></th>
                <th class="text-center"><?= t('Joined our system') ?></th>
            </tr></thead><tbody></tbody>
        </table>
        </div>
        <ul class="list-group list-group-flush d-none" id="staffList"></ul>
        <p class="text-muted text-center py-3 mb-0 d-none" id="staffEmpty"><?= t('No visits recorded for this period.') ?></p>
    </div></div>
    <?php endif; ?>

    <!-- Visits -->
    <div id="tableView" class="card border-0 shadow-sm"><div class="card-body p-2">
        <table id="visitsTable" class="table table-hover align-middle w-100">
            <thead class="fr-thead"><tr>
                <th><?= t('S/No') ?></th><th><?= t('Date') ?></th><th><?= t('Time') ?></th>
                <?php if ($is_admin): ?><th><?= t('Staff') ?></th><?php endif; ?>
                <th><?= t('Place visited') ?></th><th><?= t('Client name') ?></th><th><?= t('Phone') ?></th><th><?= t('Business') ?></th><th><?= t('Services offered') ?></th>
                <th class="text-center"><?= t('Card') ?></th><th class="text-center"><?= t('Trial') ?></th><th class="text-center"><?= t('Training') ?></th>
                <th><?= t('Response') ?></th><th><?= t('Follow-up') ?></th>
                <th class="text-end"><?= t('Actions') ?></th>
            </tr></thead><tbody></tbody>
        </table>
    </div></div>
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- Record / edit a visit — full screen on phones -->
<div class="modal fade" id="visitModal" tabindex="-1" data-no-autoclose="true"><!-- footer.php ajaxSuccess would close it after "Save & Add Another" -->
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="visitModalTitle"><i class="bi bi-geo-alt me-1"></i><?= t('Record a visit') ?></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form id="visitForm" autocomplete="off" novalidate>
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="visit_id" id="vId">
                <input type="hidden" name="from_follow_up_id" id="vFromFollowUp"><!-- "New visit" from To follow up: saving closes that follow-up -->
                <input type="hidden" name="latitude" id="vLat"><input type="hidden" name="longitude" id="vLng"><input type="hidden" name="gps_accuracy_m" id="vAcc">
                <div class="row g-3">
                    <!-- 1. Phone first: a known number fills the rest -->
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-bold" for="vPhone"><?= t('Phone') ?> <small class="text-muted fw-normal">(<?= t('optional') ?>)</small></label>
                        <input type="tel" inputmode="tel" class="form-control form-control-lg" name="client_phone" id="vPhone" maxlength="30" placeholder="07XX XXX XXX">
                        <div class="small mt-1 d-none" id="phoneWarn"></div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-bold" for="vName"><?= t('Client name') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" name="client_name" id="vName" maxlength="150" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-bold" for="vBusiness"><?= t('Business') ?> <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="business_type" id="vBusiness" required>
                            <option value=""><?= t('-- Select --') ?></option>
                            <?php foreach (frBusinessTypes() as $code => $label): ?>
                            <option value="<?= $code ?>"><?= t($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 d-none" id="vOtherWrap">
                        <label class="form-label fw-bold" for="vOther"><?= t('Describe the business') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="business_other" id="vOther" maxlength="150">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold" for="vServices"><?= t('Services you intend to sell') ?> <small class="text-muted fw-normal">(<?= t('optional') ?>)</small></label>
                        <input type="hidden" name="services_sent" value="1">
                        <?php if ($serviceOptions): ?>
                        <select class="form-select" name="service_ids[]" id="vServices" multiple>
                            <?php foreach ($serviceOptions as $so): ?>
                            <option value="<?= (int)$so['product_id'] ?>"><?= safe_output($so['product_name']) ?><?= !empty($so['contract_item_no']) ? ' (' . safe_output($so['contract_item_no']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= t('Choose one or more services you plan to sell to this customer.') ?></div>
                        <?php else: ?>
                        <div class="form-text"><?= t('No services yet. Add them on the Services page to choose them here.') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold" for="vLocation"><?= t('Place visited') ?> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="location" id="vLocation" maxlength="255" placeholder="<?= t('e.g. Kariakoo, Congo Street') ?>" required>
                            <button type="button" class="btn btn-outline-primary" id="btnGps" title="<?= t('Use my location (GPS)') ?>"><i class="bi bi-crosshair"></i> <span class="d-none d-sm-inline">GPS</span></button>
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                            <small class="text-muted" id="gpsInfo"><?= t('Tap GPS to confirm where you are.') ?></small>
                            <button type="button" class="btn btn-sm btn-link p-0 d-none" id="btnUseNearby"></button>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 d-none" id="btnGpsClear"><?= t('Clear') ?></button>
                        </div>
                    </div>

                    <!-- 2. What the client received: big tap buttons -->
                    <div class="col-12">
                        <label class="form-label fw-bold d-block"><?= t('Given to the client') ?></label>
                        <div class="row g-2">
                            <div class="col-4"><input type="checkbox" class="btn-check" name="gave_business_card" value="1" id="vCard" autocomplete="off"><label class="btn btn-outline-primary w-100 h-100 py-2 fr-toggle" for="vCard"><i class="bi bi-person-vcard d-block fs-5"></i><?= t('Business card') ?></label></div>
                            <div class="col-4"><input type="checkbox" class="btn-check" name="gave_trial_link" value="1" id="vTrial" autocomplete="off"><label class="btn btn-outline-primary w-100 h-100 py-2 fr-toggle" for="vTrial"><i class="bi bi-link-45deg d-block fs-5"></i><?= t('Free trial link (14 days)') ?></label></div>
                            <div class="col-4"><input type="checkbox" class="btn-check" name="gave_training" value="1" id="vTraining" autocomplete="off"><label class="btn btn-outline-primary w-100 h-100 py-2 fr-toggle" for="vTraining"><i class="bi bi-easel d-block fs-5"></i><?= t('Training about our system') ?></label></div>
                        </div>
                    </div>

                    <!-- 3. How the client responded: three coloured buttons (tap again to clear) -->
                    <div class="col-12">
                        <label class="form-label fw-bold d-block"><?= t('How did the client respond?') ?></label>
                        <div class="row g-2">
                            <?php foreach (frInterestLabels() as $code => $label): [$color, $icon] = $interestStyles[$code]; ?>
                            <div class="col-4"><input type="radio" class="btn-check fr-interest" name="interest" value="<?= $code ?>" id="vInt_<?= $code ?>" autocomplete="off"><label class="btn btn-outline-<?= $color ?> w-100 h-100 py-2 fr-toggle" for="vInt_<?= $code ?>"><i class="bi <?= $icon ?> d-block fs-5"></i><?= t($label) ?></label></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 4. When to come back -->
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-bold" for="vFollowUp"><?= t('Follow up on') ?> <small class="text-muted fw-normal">(<?= t('optional') ?>)</small></label>
                        <input type="date" class="form-control" name="follow_up_date" id="vFollowUp" min="<?= $today ?>">
                        <div class="d-flex gap-2 mt-1 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-fu="1"><?= t('Tomorrow') ?></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-fu="7"><?= t('In a week') ?></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-fu="0"><?= t('No follow-up') ?></button>
                        </div>
                        <div class="form-text"><?= t('A new date sets the next follow-up; every follow-up is kept in View details.') ?></div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-bold" for="vNotes"><?= t('Notes') ?></label>
                        <textarea class="form-control" name="notes" id="vNotes" rows="2" maxlength="2000"></textarea>
                    </div>

                    <!-- 5. When — "now" unless changed -->
                    <div class="col-12">
                        <div class="d-flex align-items-center gap-2 small text-muted" id="vWhenSummary">
                            <i class="bi bi-clock"></i><span id="vWhenText"></span>
                            <button type="button" class="btn btn-sm btn-link p-0" id="btnChangeWhen"><?= t('Change date / time') ?></button>
                        </div>
                        <div class="row g-2 d-none" id="vWhenFields">
                            <div class="col-6">
                                <label class="form-label fw-bold" for="vDate"><?= t('Date') ?> <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="visit_date" id="vDate" max="<?= $today ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold" for="vTime"><?= t('Time') ?></label>
                                <input type="time" class="form-control" name="visit_time" id="vTime">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer fr-form-foot">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                <button type="submit" class="btn btn-outline-primary" data-again="1" id="btnSaveAgain"><i class="bi bi-plus-circle me-1"></i><?= t('Save & Add Another') ?></button>
                <button type="submit" class="btn btn-primary" data-again="0"><i class="bi bi-check-circle me-1"></i><?= t('Save') ?></button>
            </div>
        </form>
    </div></div>
</div>

<style>
@media (max-width: 767.98px) {
    .fr-sticky { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 6px 0; }
    .fr-head-actions { width: 100%; }
    .fr-head-actions #btnAddVisit { flex: 1 1 100%; padding: .7rem; font-size: 1.05rem; }
    .fr-head-actions > .btn:not(#btnAddVisit), .fr-head-actions > .fr-report-menu { flex: 1 1 0; min-width: 0; }
}
.fr-report-menu .dropdown-toggle { min-width: 0; }
/* The <form> sits between .modal-content and the body/footer: make it the flex column
   .modal-dialog-scrollable expects, so the body scrolls and Save always stays on screen
   (on a short screen, or a phone with the keyboard open, it fell off the bottom). */
#visitForm { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
#visitForm > .modal-body { overflow-y: auto; min-height: 0; }
/* Phone: compact but readable — sizes in px because this app's root font is 12px.
   Inputs stay 16px (iOS zooms into anything smaller); buttons stay finger-sized (38px+). */
@media (max-width: 575.98px) {
    #visitModal .modal-dialog { margin: 0; }   /* a global rule pushed the full-screen form 5px off the bottom */
    #visitModal .modal-header { padding: 10px 14px; }
    #visitModal .modal-title { font-size: 16px; }
    #visitModal .modal-body { padding: 12px; }
    #visitModal .modal-body > .row { --bs-gutter-y: 10px; }
    #visitModal .form-label { font-size: 13px; margin-bottom: 3px; }
    #visitModal .form-control, #visitModal .form-select { font-size: 16px; padding: 6px 10px; }
    #visitModal .fr-toggle { font-size: 12px; line-height: 1.25; padding: 6px 3px !important; }
    #visitModal .fr-toggle .fs-5 { font-size: 16px !important; }
    #visitModal [data-fu] { font-size: 12px; padding: 4px 10px; }
    .fr-form-foot { flex-wrap: nowrap; gap: 6px; padding: 8px 10px; }
    .fr-form-foot .btn { flex: 1 1 0; min-width: 0; min-height: 40px; margin: 0; font-size: 13px; line-height: 1.2; padding: 6px 4px; white-space: normal; }
    .fr-form-foot .btn-secondary { flex: 0 0 auto; padding: 6px 10px; }
}
.fr-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.72rem; font-weight:600; }
.fr-yes { background:#0d6efd; color:#fff; } .fr-no { background:#e9ecef; color:#495057; }
.fr-svc { background:#cfe2ff; color:#084298; }
.fr-stat { background:#e7f0ff; border:1px solid #b6ccfe; }
.fr-main-stat { background:#0d6efd; color:#fff; }
.fr-toggle { min-width: 0 !important; white-space: normal; }   /* global .btn{min-width:85px} */
.fr-quick .btn { min-width: 0; }
.fr-thead th { background: #fff !important; color: #212529; border-bottom: 2px solid #dee2e6; font-weight: 600; }
.fr-view-switch .btn { min-width: 0; }
.fr-follow-actions .btn { flex: 1 1 0; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: .85rem; padding: .55rem .25rem; }   /* ~38 px: a finger-sized target */
@media (max-width: 767.98px) { .fr-head-actions .btn:not(#btnAddVisit) { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: .85rem; } }
</style>

<?php if ($isVendor): ?>
<!-- Make real customer (platform root only) -->
<div class="modal fade" id="mkCustModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-badge me-1"></i> <?= t('Make real customer') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="mkCustForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="visit_id" id="mkVisitId">
                    <input type="hidden" name="mode" value="create">
                    <div class="p-2 rounded mb-3" style="background:#e7f0ff;border:1px solid #b6ccfe;" id="mkCompany"></div>
                    <div id="mkExisting" class="mb-3"></div>
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label small fw-bold"><?= t('Customer / company name') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="customer_name" id="mkName" maxlength="255" required></div>
                        <div class="col-md-6"><label class="form-label small fw-bold"><?= t('Contact person') ?></label>
                            <input type="text" class="form-control" name="contact_person" id="mkContact" maxlength="100"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold"><?= t('Phone') ?></label>
                            <input type="tel" class="form-control" name="phone" id="mkPhone" maxlength="50"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold"><?= t('Email') ?></label>
                            <input type="email" class="form-control" name="email" id="mkEmail" maxlength="100"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold"><?= t('TIN') ?> <span class="text-muted fw-normal">(<?= t('optional') ?>)</span></label>
                            <input type="text" class="form-control" name="tin_number" id="mkTin" maxlength="100"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold"><?= t('Business') ?></label>
                            <input type="text" class="form-control" name="business" id="mkBusiness" maxlength="255"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold"><?= t('Physical address') ?></label>
                            <input type="text" class="form-control" name="address" id="mkAddress" maxlength="255"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold"><?= t('Postal address') ?></label>
                            <input type="text" class="form-control" name="postal_address" id="mkPostal" maxlength="255"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary" id="mkCreateBtn"><i class="bi bi-check-circle me-1"></i> <?= t('Create customer') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(function () {
    const IS_ADMIN = <?= json_encode($is_admin) ?>, CAN_CREATE = <?= json_encode($can_create) ?>, CAN_EDIT = <?= json_encode($can_edit) ?>, ME = <?= $me ?>, TODAY = <?= json_encode($today) ?>;
    const API = '<?= buildUrl('api/field_reports/') ?>', PRINT_URL = '<?= getUrl('field_reports/print') ?>';
    const CSRF = <?= json_encode(csrf_token()) ?>, USER_LANG = <?= json_encode($userLang) ?>;
    const IS_VENDOR = <?= json_encode($isVendor) ?>, BASE_DOMAIN = <?= json_encode((string)(tenantBaseDomain() ?? '')) ?>, CUSTOMER_URL = '<?= getUrl('customers/details') ?>';
    const DETAILS_URL = '<?= getUrl('field_reports/details') ?>';
    // One clock — the server's (EAT), whatever the phone's clock or time zone says
    // (customer_visits_ux_plan 1.3): "now" = server time at page load + time elapsed since.
    const SERVER_NOW = <?= json_encode(date('Y-m-d H:i:s')) ?>, LOADED_AT = Date.now();
    function serverNow() {
        const m = SERVER_NOW.match(/^(\d+)-(\d+)-(\d+) (\d+):(\d+):(\d+)$/);
        const d = new Date(Date.UTC(+m[1], m[2] - 1, +m[3], +m[4], +m[5], +m[6]) + (Date.now() - LOADED_AT));
        const p = n => String(n).padStart(2, '0');
        return { date: d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate()), time: p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()), d };
    }
    const addDays = (ymdStr, n) => { const [y, m, d] = ymdStr.split('-').map(Number); const t = new Date(Date.UTC(y, m - 1, d + n)); return t.toISOString().slice(0, 10); };
    const L = <?= json_encode([
        'visits' => t('Visits'), 'people' => t('People visited'), 'places' => t('Places'), 'cards' => t('Business cards given'),
        'trials' => t('Trial links given'), 'trainings' => t('Trainings given'), 'joined' => t('Joined our system'),
        'visitsMain' => t('Visits'), 'joinedMain' => t('Joined'), 'followMain' => t('To follow up'),
        'yes' => t('Yes'), 'no' => t('No'), 'edit' => t('Edit'), 'delete' => t('Delete'), 'markJoined' => t('Mark as joined'),
        'unmarkJoined' => t('Remove joined mark'), 'noRecords' => t('No visits recorded for this period.'),
        'deleteQ' => t('Delete this visit?'), 'cannotUndo' => t('This cannot be undone.'), 'yesDelete' => t('Yes, Delete'),
        'cancel' => t('Cancel'), 'error' => t('Error'), 'serverError' => t('Server error. Please try again.'),
        'addVisit' => t('Record a visit'), 'editVisit' => t('Edit Visit'),
        'submitQ' => t("Submit today's report?"), 'submitText' => t('You can still add or edit visits later; the report will show that it changed.'),
        'yesSubmit' => t('Yes, Submit'), 'submitted' => t('Report for %date% submitted at %time% — %n% visits.'),
        'changedAfter' => t('Updated after it was submitted — submit again to refresh.'), 'notSubmitted' => t('The report for %date% has not been submitted yet.'),
        'gpsGetting' => t('Getting your location...'), 'gpsNo' => t('This device or browser cannot share location.'),
        'gpsDenied' => t('Location was not allowed or not found. Turn on location for this browser and tap GPS again.'),
        'gpsOk' => t('Location confirmed (±%m% m)'), 'gpsWeak' => t('Location found but not precise (±%m% m) — move outside and try again.'),
        'gpsHint' => t('Tap GPS to confirm where you are.'), 'gpsHintReq' => t('GPS is required — tap GPS to capture where you are.'),
        'gpsImproving' => t('improving accuracy...'), 'gpsRough' => t('Very rough position — turn on "Precise location" for this browser in the phone settings.'),
        'gpsRequired' => t('Tap GPS to capture your location before saving.'), 'gpsWait' => t('GPS is still finding your location — wait a moment.'),
        'noGps' => t('No GPS'),'nearby' => t('Near: %place%'), 'useNearby' => t('Use “%place%”'),
        'dupOwn' => t('You already visited this number on %date% (%name%).'), 'dupAdmin' => t('Already visited on %date% by %staff% (%name%).'),
        'known' => t('Known client — this will be visit no. %n%. Details filled in.'),
        'scopeAll' => t('All staff'), 'scopeMe' => t('Your visits'), 'staff' => t('Staff'), 'date' => t('Date'),
        'submitOneDay' => t('Choose a single day to submit its report.'),
        'cardShort' => t('Business card'), 'trialShort' => t('Trial link'), 'trainingShort' => t('Training'),
        'now' => t('Now'), 'when' => t('Visit time: %when%'), 'joinedBtn' => t('Joined'),
        'call' => t('Call'), 'followedUp' => t('Followed up'), 'newVisit' => t('New visit'), 'viewDetails' => t('View details'),
        'dueToday' => t('due today'), 'daysLate' => t('%d days late'), 'followUp' => t('Follow-up'), 'gpsConfirmed' => t('GPS confirmed'),
        'interest' => array_map('t', frInterestLabels()),
        'services' => t('Services offered'), 'chooseServices' => t('Choose services...'),
        'addCompany' => t('Add company web address'), 'makeCustomer' => t('Make real customer'), 'viewCustomer' => t('View customer'),
        'subdomainQ' => t('Company web address'), 'subdomainHint' => t('The address they log in at, e.g. dukalajuma — leave blank if you do not know it yet.'),
        'subdomainReq' => t('Enter the company web address.'), 'save' => t('Save'), 'customerBadge' => t('Customer'),
        'company' => t('Company on the platform'), 'linkedAlready' => t('Already a customer — %s'), 'linkVisit' => t('Link this visit'),
        'useThis' => t('Use this customer'), 'sameClient' => t('These existing customers have the same phone or email:'),
        'notReady' => t('The platform database has not been upgraded yet. Ask the administrator to run the platform setup.'),
    ]) ?>;

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dmy = s => { const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; };
    const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const yn = v => v ? `<span class="fr-badge fr-yes">${L.yes}</span>` : `<span class="fr-badge fr-no">${L.no}</span>`;
    // Phone cards have no column headers, so each badge carries its name (1.2).
    const ynL = (label, v) => `<span class="fr-badge ${v ? 'fr-yes' : 'fr-no'}">${v ? '✓' : '✗'} ${label}</span>`;
    const interestColors = { interested: 'success', thinking: 'warning', not_interested: 'danger' };
    const interestBadge = r => r.interest ? `<span class="badge bg-${interestColors[r.interest]}${r.interest === 'thinking' ? ' text-dark' : ''}">${esc(r.interest_label)}</span>` : '<span class="text-muted">—</span>';
    const followTxt = r => !r.follow_up_date ? '<span class="text-muted">—</span>'
        : `${dmy(r.follow_up_date)}${r.follow_up_done ? ' <i class="bi bi-check-circle-fill text-success" title="' + esc(L.followedUp) + '"></i>' : ''}`;
    // 6 decimals ≈ 0.1 m — the coordinates themselves, not just an icon.
    const fmtCoords = (lat, lng) => Number(lat).toFixed(6) + ', ' + Number(lng).toFixed(6);
    const gpsMark = r => r.latitude !== null
        ? `<div class="small text-nowrap"><a href="https://maps.google.com/?q=${r.latitude},${r.longitude}" target="_blank" rel="noopener" class="text-decoration-none" title="${esc(r.gps_verified ? L.gpsConfirmed : 'GPS')}"><i class="bi ${r.gps_verified ? 'bi-geo-alt-fill text-success' : 'bi-pin-map text-warning'}"></i> ${fmtCoords(r.latitude, r.longitude)}</a>${r.gps_accuracy_m !== null ? ` <span class="text-muted">±${r.gps_accuracy_m} m</span>` : ''}</div>`
        : `<div class="small text-muted"><i class="bi bi-slash-circle"></i> ${esc(L.noGps)}</div>`;
    const servicesTxt = r => (r.services || []).length ? (r.services || []).map(s => esc(s.name)).join(', ') : '<span class="text-muted">—</span>';
    const servicesBadges = r => (r.services || []).map(s => `<span class="fr-badge fr-svc">${esc(s.name)}</span>`).join(' ');
    let rows = [], follows = [];

    $('.select2-static').not('#visitModal .select2-static').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#visitModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#visitModal'), placeholder: <?= json_encode(t('Select...')) ?>, allowClear: true, width: '100%' });
        });
        if ($('#vServices').length && !$('#vServices').hasClass('select2-hidden-accessible')) {
            $('#vServices').select2({ theme: 'bootstrap-5', dropdownParent: $('#visitModal'), placeholder: L.chooseServices, closeOnSelect: false, width: '100%' });
        }
        if (!$('#vId').val()) $('#vPhone').trigger('focus');
    });

    const table = $('#visitsTable').DataTable({
        responsive: false, scrollX: true, pageLength: 25, order: [[0, 'asc']], dom: 'frtip',
        language: { emptyTable: L.noRecords, zeroRecords: L.noRecords },
        columnDefs: [{ targets: -1, orderable: false }],
        drawCallback: function () { renderCards(this.api().rows({ page: 'current' }).data().toArray().map(r => r._row)); }
    });
    const staffTable = IS_ADMIN ? $('#staffTable').DataTable({ responsive: false, scrollX: true, paging: false, searching: false, info: false, order: [[1, 'desc']] }) : null;

    function filters() {
        let from = $('#fFrom').val() || TODAY, to = $('#fTo').val() || from;
        if (to < from) [from, to] = [to, from];
        return { date_from: from, date_to: to, user_id: IS_ADMIN ? ($('#fStaff').val() || '') : '' };
    }

    // "Joined" is a status (a badge) — marking it is an action, inside the Actions menu.
    const joinedBadge = r => (r.joined ? ` <span class="fr-badge fr-yes text-nowrap">✓ ${L.joinedBtn}${IS_VENDOR && r.tenant_subdomain ? ' · ' + esc(r.tenant_subdomain) : ''}</span>` : '')
        + (IS_VENDOR && r.customer_id ? ` <a href="${CUSTOMER_URL}?id=${r.customer_id}" class="fr-badge fr-svc text-nowrap text-decoration-none"><i class="bi bi-person-badge"></i> ${L.customerBadge}</a>` : '');
    // Platform root only: the next step for a joined client — name its company, then make it a customer.
    function vendorStep(r) {
        if (!IS_VENDOR || !r.joined) return null;
        if (r.customer_id) return { act: 'viewcust', icon: 'bi-person-badge', label: L.viewCustomer };
        if (!r.can_edit) return null;
        if (!r.tenant_subdomain) return { act: 'setsub', icon: 'bi-globe', label: L.addCompany };
        return { act: 'makecust', icon: 'bi-person-plus', label: L.makeCustomer };
    }

    function actions(r) {
        // View details first: every field of the row, the follow-up history, the customer's other visits.
        let items = `<li><a class="dropdown-item py-2 rounded" href="${DETAILS_URL}?id=${r.visit_id}"><i class="bi bi-eye text-primary me-2"></i>${L.viewDetails}</a></li>`;
        if (r.can_edit) items += `<li><button class="dropdown-item py-2 rounded" data-act="joined" data-id="${r.visit_id}" data-joined="${r.joined ? 0 : 1}"><i class="bi ${r.joined ? 'bi-person-x' : 'bi-person-check'} text-primary me-2"></i>${r.joined ? L.unmarkJoined : L.markJoined}</button></li>`;
        const vs = vendorStep(r);
        if (vs) items += `<li><button class="dropdown-item py-2 rounded" data-act="${vs.act}" data-id="${r.visit_id}"><i class="bi ${vs.icon} text-primary me-2"></i>${vs.label}</button></li>`;
        if (r.can_edit) items += `<li><button class="dropdown-item py-2 rounded" data-act="edit" data-id="${r.visit_id}"><i class="bi bi-pencil text-primary me-2"></i>${L.edit}</button></li>`;
        if (r.can_delete) items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" data-act="delete" data-id="${r.visit_id}"><i class="bi bi-trash text-danger me-2"></i>${L.delete}</button></li>`;
        return `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="min-width:0"><i class="bi bi-gear-fill me-1"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
    }

    function renderCards(list) {
        if (!list.length) { $('#cardView').html(`<div class="col-12 text-center py-5 text-muted">${L.noRecords}</div>`); return; }
        $('#cardView').html(list.map(r => `
            <div class="col-12"><div class="card border-0 shadow-sm">
                <div class="card-body p-3" style="font-size:.85rem">
                    <div class="d-flex justify-content-between gap-2"><div class="fw-bold text-break">${esc(r.client_name)}</div><small class="text-muted text-nowrap">${dmy(r.visit_date)} ${esc(r.visit_time)}</small></div>
                    <div><i class="bi bi-geo-alt text-primary"></i> ${esc(r.location)}${gpsMark(r)}</div>
                    <div>${r.client_phone ? `<a href="tel:${esc(r.client_phone)}" class="text-decoration-none"><i class="bi bi-telephone text-primary"></i> ${esc(r.client_phone)}</a> · ` : ''}${esc(r.business_label)}</div>
                    ${IS_ADMIN ? `<div class="text-muted">${L.staff}: ${esc(r.staff_name)}</div>` : ''}
                    ${(r.services || []).length ? `<div class="mt-1"><i class="bi bi-briefcase text-primary"></i> ${servicesBadges(r)}</div>` : ''}
                    <div class="d-flex flex-wrap gap-1 mt-1">${ynL(L.cardShort, r.gave_business_card)} ${ynL(L.trialShort, r.gave_trial_link)} ${ynL(L.trainingShort, r.gave_training)} ${r.interest ? interestBadge(r) : ''}${joinedBadge(r)}</div>
                    ${r.follow_up_date ? `<div class="mt-1 small"><i class="bi bi-calendar-event text-primary"></i> ${L.followUp}: ${followTxt(r)}</div>` : ''}
                </div>
                <div class="card-footer bg-white border-top p-0"><div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                    <a class="btn btn-sm btn-outline-primary" href="${DETAILS_URL}?id=${r.visit_id}" style="flex:1;min-width:0;padding:3px 4px" title="${esc(L.viewDetails)}" aria-label="${esc(L.viewDetails)}"><i class="bi bi-eye"></i></a>
                    ${vendorStep(r) ? `<button class="btn btn-sm btn-outline-primary" data-act="${vendorStep(r).act}" data-id="${r.visit_id}" style="flex:1;min-width:0;padding:3px 4px" title="${esc(vendorStep(r).label)}" aria-label="${esc(vendorStep(r).label)}"><i class="bi ${vendorStep(r).icon}"></i></button>` : ''}
                    ${r.can_edit ? `<button class="btn btn-sm btn-outline-primary" data-act="joined" data-id="${r.visit_id}" data-joined="${r.joined ? 0 : 1}" style="flex:1;min-width:0;padding:3px 4px" title="${esc(r.joined ? L.unmarkJoined : L.markJoined)}" aria-label="${esc(r.joined ? L.unmarkJoined : L.markJoined)}"><i class="bi ${r.joined ? 'bi-person-x' : 'bi-person-check'}"></i></button>` : ''}
                    ${r.can_edit ? `<button class="btn btn-sm btn-outline-primary" data-act="edit" data-id="${r.visit_id}" style="flex:1;min-width:0;padding:3px 4px" title="${esc(L.edit)}" aria-label="${esc(L.edit)}"><i class="bi bi-pencil"></i></button>` : ''}
                    ${r.can_delete ? `<button class="btn btn-sm btn-outline-danger" data-act="delete" data-id="${r.visit_id}" style="flex:1;min-width:0;padding:3px 4px" title="${esc(L.delete)}" aria-label="${esc(L.delete)}"><i class="bi bi-trash"></i></button>` : ''}
                </div></div>
            </div></div>`).join(''));
    }

    function renderStats(s) {
        const main = [['visitsMain', s.visits, 'bi-geo-alt'], ['joinedMain', s.joined, 'bi-person-check'], ['followMain', follows.length, 'bi-telephone-forward']];
        $('#mainStats').html(main.map(([k, v, ic]) => `<div class="col-4"><div class="card border-0 shadow-sm text-center p-2 fr-main-stat h-100">
            <div class="fs-3 fw-bold lh-1"><i class="bi ${ic} fs-6 me-1"></i>${Number(v || 0)}</div><div class="small">${L[k]}</div></div></div>`).join(''));
        const keys = ['people', 'places', 'cards', 'trials', 'trainings'];
        $('#statCards').html(keys.map(k => `<div class="col-6 col-md"><div class="card border-0 shadow-sm text-center p-2 fr-stat h-100">
            <div class="fs-4 fw-bold text-primary">${Number(s[k] || 0)}</div><div class="small">${L[k]}</div></div></div>`).join(''));
    }

    function renderServiceStats(list) {
        $('#serviceStatsCard').toggleClass('d-none', !list.length);
        $('#serviceStats').html(list.map(s => `<span class="fr-badge fr-svc">${esc(s.name)} <b>${Number(s.count)}</b></span>`).join(' '));
    }

    function renderFollowUps(list) {
        follows = list || [];
        $('#followCard').toggleClass('d-none', !follows.length);
        $('#followCount').text(follows.length);
        $('#followList').html(follows.map(f => `
            <li class="list-group-item">
                <div class="d-flex justify-content-between gap-2">
                    <div class="text-break"><a class="fw-bold text-decoration-none" href="${DETAILS_URL}?id=${f.visit_id}">${esc(f.client_name)}</a>
                        <small class="text-muted d-block">${esc(f.location)} · ${esc(f.business_label)}${IS_ADMIN ? ' · ' + esc(f.staff_name) : ''}</small>
                        ${f.purpose ? `<small class="d-block"><i class="bi bi-info-circle text-primary"></i> ${esc(f.purpose)}</small>` : ''}</div>
                    <span class="badge ${f.days_overdue > 0 ? 'bg-danger' : 'bg-primary'} align-self-start text-nowrap">${f.days_overdue > 0 ? esc(L.daysLate.replace('%d', f.days_overdue)) : esc(L.dueToday)}</span>
                </div>
                <div class="d-flex gap-1 mt-2 fr-follow-actions">
                    ${f.client_phone ? `<a class="btn btn-sm btn-primary" href="tel:${esc(f.client_phone)}"><i class="bi bi-telephone me-1"></i>${L.call}</a>` : ''}
                    ${CAN_CREATE ? `<button class="btn btn-sm btn-outline-primary" data-fact="visit" data-id="${f.follow_up_id}"><i class="bi bi-plus-circle me-1"></i>${L.newVisit}</button>` : ''}
                    ${f.can_edit ? `<button class="btn btn-sm btn-outline-success" data-fact="done" data-id="${f.follow_up_id}"><i class="bi bi-check2 me-1"></i>${L.followedUp}</button>` : ''}
                </div>
            </li>`).join(''));
    }

    function renderDayStatus(res) {
        const f = filters(), single = f.date_from === f.date_to, ownOrAll = !IS_ADMIN || !f.user_id || String(f.user_id) === String(ME);
        // The caller's OWN day — also for an admin looking at all staff (3.3).
        $('#btnSubmitDay').toggle(CAN_CREATE && single && ownOrAll);
        if (!single || !ownOrAll) { $('#dayStatus').empty(); return; }
        const d = res.day_status;
        if (d) {
            $('#dayStatus').html(`<div class="alert alert-primary py-2 mb-0"><i class="bi bi-check-circle-fill me-1"></i><strong>${esc(L.submitted.replace('%date%', dmy(f.date_from)).replace('%time%', d.submitted_time).replace('%n%', d.visit_count))}</strong>
                ${d.changed_after_submit ? `<div class="small mt-1"><i class="bi bi-exclamation-circle me-1"></i>${esc(L.changedAfter)}</div>` : ''}</div>`);
        } else if ((res.own_visit_count ?? 0) > 0 || !IS_ADMIN) {
            $('#dayStatus').html(`<div class="alert alert-light border py-2 mb-0 small"><i class="bi bi-clock me-1"></i>${esc(L.notSubmitted.replace('%date%', dmy(f.date_from)))}</div>`);
        } else {
            $('#dayStatus').empty();
        }
    }

    function renderStaff(list) {
        if (!staffTable) return;
        $('#staffEmpty').toggleClass('d-none', !!list.length);
        staffTable.clear().rows.add(list.map(s => [esc(s.staff_name), s.visits, s.people, s.places, s.cards, s.trials, s.trainings, s.joined])).draw();
        $('#staffList').html(list.map(s => `<li class="list-group-item px-0">
            <div class="fw-bold">${esc(s.staff_name)}</div>
            <div class="small text-muted">${L.visits}: <b>${s.visits}</b> · ${L.people}: <b>${s.people}</b> · ${L.joined}: <b>${s.joined}</b></div>
            <div class="small text-muted">${L.cardShort}: ${s.cards} · ${L.trialShort}: ${s.trials} · ${L.trainingShort}: ${s.trainings}</div></li>`).join(''));
    }

    function loadData(after) {
        $.getJSON(API + 'list.php', filters()).done(function (res) {
            if (!res.success) { Swal.fire({ icon: 'error', title: L.error, text: res.message }); return; }
            FrShare.invalidate();   // visits changed — the next PDF is built fresh
            rows = res.rows;
            $('#visitsCount').text(rows.length);
            renderFollowUps(res.follow_ups);
            table.clear().rows.add(rows.map((r, i) => {
                const cells = [i + 1, dmy(r.visit_date), esc(r.visit_time || '—')];
                if (IS_ADMIN) cells.push(esc(r.staff_name));
                cells.push(esc(r.location) + gpsMark(r), esc(r.client_name) + joinedBadge(r), r.client_phone ? esc(r.client_phone) : '<span class="text-muted">—</span>', esc(r.business_label), servicesTxt(r),
                    `<div class="text-center">${yn(r.gave_business_card)}</div>`, `<div class="text-center">${yn(r.gave_trial_link)}</div>`,
                    `<div class="text-center">${yn(r.gave_training)}</div>`, interestBadge(r), followTxt(r), actions(r));
                cells._row = r;
                return cells;
            })).draw();
            renderStats(res.stats);
            renderServiceStats(res.service_counts || []);
            renderDayStatus(res);
            renderStaff(res.staff_summary || []);
            if (typeof after === 'function') after();
        }).fail(() => Swal.fire({ icon: 'error', title: L.error, text: L.serverError }));
    }

    // Which table is showing (admins switch between visits and the per-staff summary).
    const showingStaff = () => IS_ADMIN && $('#viewStaff').is(':checked');
    function applyView() {
        const m = window.innerWidth < 768, staff = showingStaff();
        $('#tableView').toggleClass('d-none', m || staff); $('#cardView').toggleClass('d-none', !m || staff);
        $('#staffSummaryCard').toggleClass('d-none', !staff);
        $('#staffTableWrap').toggleClass('d-none', m); $('#staffList').toggleClass('d-none', !m);
        // the summary table is built while hidden (zero width) — re-measure it once shown
        if (staff && staffTable && !m) staffTable.columns.adjust();
    }
    applyView(); $(window).on('resize', applyView);
    $('input[name=frView]').on('change', applyView);
    if (window.innerWidth >= 768) $('#moreStats').addClass('show');   // open on desktop, closed on phones

    $('#fFrom, #fTo, #fStaff').on('change', loadData);
    $('[data-quick]').on('click', function () {
        const n = serverNow().d, now = new Date(n.getUTCFullYear(), n.getUTCMonth(), n.getUTCDate()); let from = now, to = now;
        if ($(this).data('quick') === 'yesterday') { from = to = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1); }
        if ($(this).data('quick') === 'week') { from = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7)); }
        $('#fFrom').val(ymd(from)); $('#fTo').val(ymd(to)); loadData();
    });

    // ── Record / edit ──────────────────────────────────────────────────
    let nearbyPlace = null;
    let gpsRequired = true;   // a new visit, or one that already has a position (save.php enforces the same)
    function setGps(lat, lng, acc, improving) {
        $('#vLat').val(lat ?? ''); $('#vLng').val(lng ?? ''); $('#vAcc').val(acc ?? '');
        const on = lat !== null && lat !== undefined && lat !== '';
        const precise = on && (acc === null || acc === undefined || acc === '' || Number(acc) <= 100);
        $('#gpsInfo').html(!on ? esc(gpsRequired ? L.gpsHintReq : L.gpsHint)
            : `<span class="${precise ? 'text-success' : 'text-warning'}"><i class="bi ${precise ? 'bi-geo-alt-fill' : 'bi-exclamation-triangle'}"></i> ${esc((precise ? L.gpsOk : L.gpsWeak).replace('%m%', acc ? Math.round(acc) : '—'))}${improving ? ' — ' + esc(L.gpsImproving) : ''}</span>`
              + `<br><span class="font-monospace text-body">${fmtCoords(lat, lng)}</span>`
              + (!improving && Number(acc) > 1000 ? `<br><span class="text-danger">${esc(L.gpsRough)}</span>` : ''));
        $('#btnGpsClear').toggleClass('d-none', !on);
        $('#btnGps').removeClass('btn-outline-danger').addClass('btn-outline-primary');
        if (!on) { nearbyPlace = null; $('#btnUseNearby').addClass('d-none'); }
    }
    // A phone's first fix is usually a rough Wi-Fi/cell guess; GPS sharpens it over the next
    // seconds. Watch for up to 20 s, keep the most accurate fix, stop early at ±30 m.
    // maximumAge 0: never reuse a cached position (e.g. the previous shop's).
    const GPS_GOOD_M = 30, GPS_MAX_MS = 20000;
    let gpsWatch = null, gpsTimer = null;
    const gpsBtnHtml = $('#btnGps').html();
    function stopGps() {
        if (gpsWatch !== null) navigator.geolocation.clearWatch(gpsWatch);
        clearTimeout(gpsTimer); gpsWatch = null; gpsTimer = null;
        $('#btnGps').prop('disabled', false).html(gpsBtnHtml);
    }
    function showNearby(place) {
        nearbyPlace = place;
        if (!place) { $('#btnUseNearby').addClass('d-none'); return; }
        if ($('#vLocation').val().trim() === '') {
            $('#vLocation').val(place.location);
            $('#btnUseNearby').removeClass('d-none').prop('disabled', true).text(L.nearby.replace('%place%', place.location));
        } else if ($('#vLocation').val().trim() !== place.location) {
            $('#btnUseNearby').removeClass('d-none').prop('disabled', false).text(L.useNearby.replace('%place%', place.location));
        }
    }
    $('#btnUseNearby').on('click', () => { if (nearbyPlace) { $('#vLocation').val(nearbyPlace.location); showNearby(nearbyPlace); } });

    function setWhen(open, date, time) {
        $('#vDate').val(date); $('#vTime').val(time);
        $('#vWhenFields').toggleClass('d-none', !open); $('#vWhenSummary').toggleClass('d-none', open);
        $('#vWhenText').text(L.when.replace('%when%', L.now + ' (' + dmy(date) + ' ' + time + ')'));
    }
    $('#btnChangeWhen').on('click', () => setWhen(true, $('#vDate').val(), $('#vTime').val()));

    function resetForm(keep) {
        const keepLoc = $('#vLocation').val(), keepLat = $('#vLat').val(), keepLng = $('#vLng').val(), keepAcc = $('#vAcc').val();
        const keepOpen = !$('#vWhenFields').hasClass('d-none'), keepDate = $('#vDate').val();
        stopGps(); gpsRequired = true;
        $('#visitForm')[0].reset();
        $('#vId').val(''); $('#vFromFollowUp').val(''); $('#vBusiness').val('').trigger('change'); $('#vServices').val(null).trigger('change');
        $('#phoneWarn').addClass('d-none').empty(); $('#vOtherWrap').addClass('d-none');
        $('#visitForm .is-invalid').removeClass('is-invalid');
        const now = serverNow();
        if (keep) {   // "Save & add another": same place, GPS and (if changed) day
            $('#vLocation').val(keepLoc); setGps(keepLat || null, keepLng || null, keepAcc || null);
            setWhen(keepOpen, keepOpen ? keepDate : now.date, now.time);
        } else {
            setGps(null);
            const f = filters();
            // Looking at an earlier single day → record for that day (time open to set).
            const day = (f.date_from === f.date_to && f.date_from < now.date) ? f.date_from : now.date;
            setWhen(day !== now.date, day, day !== now.date ? '' : now.time);
        }
    }
    function openNew(prefillPhone, fromFollowUpId) {
        resetForm(false);
        $('#visitModalTitle').html(`<i class="bi bi-geo-alt me-1"></i>${L.addVisit}`); $('#btnSaveAgain').toggleClass('d-none', !!fromFollowUpId);
        if (prefillPhone) $('#vPhone').val(prefillPhone);
        if (fromFollowUpId) {
            const f = follows.find(x => x.follow_up_id == fromFollowUpId);
            $('#vFromFollowUp').val(fromFollowUpId);
            if (f) { $('#vName').val(f.client_name); $('#vLocation').val(f.location); }
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('visitModal')).show();
        if (prefillPhone) lookupPhone(true);
    }
    $('#btnAddVisit').on('click', () => openNew(''));
    $('#vBusiness').on('change', function () { $('#vOtherWrap').toggleClass('d-none', $(this).val() !== 'other'); });
    $('#btnGps').on('click', function () {
        if (!navigator.geolocation) { $('#gpsInfo').text(L.gpsNo); return; }
        stopGps();
        let best = null, done = false;
        $('#gpsInfo').text(L.gpsGetting);
        $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <span class="d-none d-sm-inline">GPS</span>');
        const finish = () => {
            if (done) return;
            done = true; stopGps();
            if (!best) { setGps(null); $('#gpsInfo').text(L.gpsDenied); return; }
            setGps(best.lat, best.lng, best.acc);
            $.getJSON(API + 'nearby.php', { lat: best.lat, lng: best.lng }, res => showNearby(res && res.success ? res.place : null));
        };
        gpsWatch = navigator.geolocation.watchPosition(p => {
            if (done) return;
            if (!best || p.coords.accuracy < best.acc) {
                best = { lat: p.coords.latitude, lng: p.coords.longitude, acc: p.coords.accuracy };
                setGps(best.lat, best.lng, best.acc, true);
            }
            if (best.acc <= GPS_GOOD_M) finish();
        }, finish, { enableHighAccuracy: true, timeout: GPS_MAX_MS, maximumAge: 0 });
        gpsTimer = setTimeout(finish, GPS_MAX_MS);
    });
    $('#btnGpsClear').on('click', () => { stopGps(); setGps(null); });
    // Interest: a radio you can un-tap.
    $('.fr-interest').on('mousedown touchstart', function () { $(this).data('was', this.checked); })
        .on('click', function () { if ($(this).data('was')) { this.checked = false; $(this).data('was', false); } });
    $('[data-fu]').on('click', function () {
        const n = +$(this).data('fu'), base = $('#vDate').val() || serverNow().date;
        $('#vFollowUp').val(n ? addDays(base, n) : '');
    });

    // Phone first: a known number fills what is still empty (2.3).
    let lookupTimer = null, lastLookup = '';
    function lookupPhone(force) {
        const phone = $('#vPhone').val().trim();
        if (phone.replace(/\D/g, '').length < 9) { $('#phoneWarn').addClass('d-none'); lastLookup = ''; return; }
        if (!force && phone === lastLookup) return;
        lastLookup = phone;
        $.getJSON(API + 'check_phone.php', { phone, exclude_id: $('#vId').val() || 0 }, function (res) {
            const m = res && res.match;
            if (!m) { $('#phoneWarn').addClass('d-none').empty(); return; }
            const editing = !!$('#vId').val();
            if (!editing) {
                if (!$('#vName').val().trim()) $('#vName').val(m.client_name);
                if (!$('#vBusiness').val()) { $('#vBusiness').val(m.business_type).trigger('change'); if (m.business_type === 'other') $('#vOther').val(m.business_other); }
                if (!$('#vLocation').val().trim()) $('#vLocation').val(m.location);
            }
            const seen = (m.staff_name ? L.dupAdmin : L.dupOwn).replace('%date%', dmy(m.visit_date)).replace('%staff%', m.staff_name || '').replace('%name%', m.client_name);
            $('#phoneWarn').removeClass('d-none').html(`<span class="text-primary"><i class="bi bi-person-check me-1"></i>${esc(editing ? seen : L.known.replace('%n%', (m.visits || 1) + 1))}</span><br><small class="text-muted">${esc(seen)}</small>`);
        });
    }
    $('#vPhone').on('input', () => { clearTimeout(lookupTimer); lookupTimer = setTimeout(() => lookupPhone(false), 600); })
        .on('blur', () => lookupPhone(false));

    let again = false;
    $('#visitForm [type=submit]').on('click', function () { again = $(this).data('again') === 1; });
    $('#visitForm').on('submit', function (e) {
        e.preventDefault();
        if (gpsWatch !== null) { Swal.fire({ icon: 'info', title: L.gpsWait, timer: 1800, showConfirmButton: false }); return; }
        if (gpsRequired && !$('#vLat').val()) {
            $('#btnGps').removeClass('btn-outline-primary').addClass('btn-outline-danger');
            $('#gpsInfo').html(`<span class="text-danger"><i class="bi bi-exclamation-circle"></i> ${esc(L.gpsRequired)}</span>`);
            Swal.fire({ icon: 'warning', title: L.error, text: L.gpsRequired });
            return;
        }
        // Still "now"? Take the server's now at the moment of saving.
        if ($('#vWhenFields').hasClass('d-none') && !$('#vId').val()) { const n = serverNow(); $('#vDate').val(n.date); $('#vTime').val(n.time); }
        const btn = $(this).find('[type=submit]'), orig = btn.map((i, b) => $(b).html()).get();
        btn.prop('disabled', true);
        $.ajax({ url: API + 'save.php', type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json' })
            .done(function (res) {
                $('#visitForm .is-invalid').removeClass('is-invalid');
                if (res.success) {
                    if (again) { resetForm(true); $('#vPhone').trigger('focus'); }
                    else bootstrap.Modal.getOrCreateInstance(document.getElementById('visitModal')).hide();
                    loadData();
                    // After "Save & Add Another" the cursor goes back to Phone once the toast closes (it takes focus).
                    Swal.fire({ icon: 'success', title: res.message, timer: 1400, showConfirmButton: false })
                        .then(() => { if (again && $('#visitModal').hasClass('show')) $('#vPhone').trigger('focus'); });
                } else showErrors(res);
            })
            .fail(x => showErrors(x.responseJSON || {}))
            .always(() => btn.each((i, b) => $(b).prop('disabled', false).html(orig[i])));
    });
    function showErrors(r) {
        const errs = r.errors || {};
        Object.keys(errs).forEach(k => $(`#visitForm [name="${k}"]`).addClass('is-invalid'));
        if (errs.service_ids) $('#vServices').addClass('is-invalid');
        if (errs.visit_date || errs.visit_time) setWhen(true, $('#vDate').val(), $('#vTime').val());
        Swal.fire({ icon: 'error', title: L.error, text: r.message || L.serverError });
    }

    function openEdit(r) {
        resetForm(false);
        $('#visitModalTitle').html(`<i class="bi bi-pencil me-1"></i>${L.editVisit}`); $('#btnSaveAgain').addClass('d-none');
        $('#vId').val(r.visit_id); setWhen(true, r.visit_date, r.visit_time); $('#vLocation').val(r.location);
        $('#vName').val(r.client_name); $('#vPhone').val(r.client_phone); $('#vOther').val(r.business_other);
        $('#vCard').prop('checked', !!r.gave_business_card); $('#vTrial').prop('checked', !!r.gave_trial_link); $('#vTraining').prop('checked', !!r.gave_training);
        $('.fr-interest').prop('checked', false); if (r.interest) $(`#vInt_${r.interest}`).prop('checked', true);
        $('#vFollowUp').val(r.follow_up_date || '').attr('min', r.visit_date);
        gpsRequired = r.latitude !== null;   // an older visit saved without GPS stays editable
        $('#vNotes').val(r.notes); setGps(r.latitude, r.longitude, r.gps_accuracy_m);   // the stored accuracy, re-saved unchanged
        // Set before showing: Select2 (initialised on first show) reads the current value.
        $('#vBusiness').val(r.business_type).trigger('change');
        $('#vServices').val((r.service_ids || []).map(String)).trigger('change');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('visitModal')).show();
    }

    $(document).on('click', '[data-act]', function () {
        const id = $(this).data('id'), r = rows.find(x => x.visit_id == id), act = $(this).data('act');
        if (!r) return;
        if (act === 'edit') {
            openEdit(r);
        } else if (act === 'delete') {
            Swal.fire({ title: L.deleteQ, text: L.cannotUndo, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: L.yesDelete, cancelButtonText: L.cancel })
                .then(x => { if (x.isConfirmed) post('delete.php', { visit_id: id }); });
        } else if (act === 'joined') {
            const joining = String($(this).data('joined')) === '1';
            if (IS_VENDOR && joining) askSubdomain(false).then(sub => { if (sub !== null) post('toggle_joined.php', { visit_id: id, joined: 1, subdomain: sub }); });
            else post('toggle_joined.php', { visit_id: id, joined: $(this).data('joined') });
        } else if (act === 'setsub') {
            askSubdomain(true).then(sub => { if (sub) post('toggle_joined.php', { visit_id: id, joined: 1, subdomain: sub }); });
        } else if (act === 'makecust') {
            openMakeCustomer(id);
        } else if (act === 'viewcust') {
            window.location.href = CUSTOMER_URL + '?id=' + r.customer_id;
        }
    });
    $(document).on('click', '[data-fact]', function () {
        const id = $(this).data('id'), f = follows.find(x => x.follow_up_id == id);
        if (!f) return;
        // Done: how it went + the next date (includes/field_reports/followup_kit.php).
        // New visit: saving it also closes this follow-up (customer_visit_followups_plan.md D5).
        if ($(this).data('fact') === 'done') FrFollow.done({ follow_up_id: f.follow_up_id, client_name: f.client_name, due_date: f.follow_up_date, purpose: f.purpose }, loadData);
        else openNew(f.client_phone, f.follow_up_id);
    });
    $('#visitModal').on('hidden.bs.modal', () => { stopGps(); $('#vFollowUp').attr('min', TODAY); });

    // Resolves with the typed address ('' when optional and left blank) or null when cancelled.
    function askSubdomain(required) {
        return Swal.fire({
            title: L.subdomainQ, text: L.subdomainHint, input: 'text',
            inputAttributes: { autocapitalize: 'off', autocomplete: 'off', maxlength: 63 },
            inputPlaceholder: 'dukalajuma' + (BASE_DOMAIN ? '.' + BASE_DOMAIN : ''),
            showCancelButton: true, confirmButtonText: L.save, cancelButtonText: L.cancel, confirmButtonColor: '#0d6efd',
            inputValidator: v => (required && !String(v || '').trim()) ? L.subdomainReq : undefined,
        }).then(x => x.isConfirmed ? String(x.value || '').trim() : null);
    }

    function openMakeCustomer(id) {
        $.getJSON(API + 'make_customer.php', { visit_id: id }).done(res => {
            if (!res.success) { Swal.fire({ icon: 'error', title: L.error, text: res.message }); return; }
            if (!res.link_ready) { Swal.fire({ icon: 'warning', title: L.error, text: L.notReady }); return; }
            const p = res.prefill || {};
            $('#mkVisitId').val(id);
            $('#mkCompany').html(`<small class="text-muted">${L.company}</small><div class="fw-bold">${esc(res.tenant.company_name)} <span class="text-primary">· ${esc(res.tenant.subdomain)}</span></div>`);
            $('#mkName').val(p.customer_name || ''); $('#mkContact').val(p.contact_person || ''); $('#mkPhone').val(p.phone || '');
            $('#mkEmail').val(p.email || ''); $('#mkTin').val(''); $('#mkBusiness').val(p.business || '');
            $('#mkAddress').val(p.address || ''); $('#mkPostal').val(p.postal_address || '');
            const pick = (c, label) => `<div class="d-flex justify-content-between align-items-center border rounded p-2 mb-1">
                <div><span class="fw-bold">${esc(c.customer_name)}</span> <small class="text-muted">${esc(c.customer_code || '')} ${c.phone ? '· ' + esc(c.phone) : ''} ${c.email ? '· ' + esc(c.email) : ''}</small></div>
                <button type="button" class="btn btn-sm btn-outline-primary" data-mklink="${c.customer_id}">${label || L.useThis}</button></div>`;
            let html = '';
            if (res.linked_customer) html = `<div class="small fw-bold mb-1">${esc(L.linkedAlready.replace('%s', res.linked_customer.customer_code || ''))}</div>` + pick(res.linked_customer, L.linkVisit);
            else if ((res.matches || []).length) html = `<div class="small fw-bold mb-1">${L.sameClient}</div>` + res.matches.map(c => pick(c)).join('');
            $('#mkExisting').html(html);
            // An already-linked company can only be linked to its customer, never given a second one.
            $('#mkCreateBtn').toggleClass('d-none', !!res.linked_customer || !res.can_create);
            $('#mkCustForm .row :input').prop('disabled', !!res.linked_customer || !res.can_create);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('mkCustModal')).show();
        }).fail(x => Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.serverError }));
    }
    function mkSubmit(data, $btn) {
        const orig = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({ url: API + 'make_customer.php', type: 'POST', data: Object.assign({ _csrf: CSRF }, data), dataType: 'json' })
            .done(res => {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('mkCustModal')).hide();
                    loadData();
                    Swal.fire({ icon: 'success', title: res.message, timer: 2200, showConfirmButton: false });
                } else Swal.fire({ icon: 'error', title: L.error, text: res.message });
            })
            .fail(x => Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.serverError }))
            .always(() => $btn.prop('disabled', false).html(orig));
    }
    $('#mkCustForm').on('submit', function (e) {
        e.preventDefault();
        mkSubmit(Object.fromEntries(new FormData(this).entries()), $('#mkCreateBtn'));
    });
    $(document).on('click', '[data-mklink]', function () {
        mkSubmit({ visit_id: $('#mkVisitId').val(), mode: 'link', customer_id: $(this).data('mklink') }, $(this));
    });

    function post(file, data) {
        $.ajax({ url: API + file, type: 'POST', data: Object.assign({ _csrf: CSRF }, data), dataType: 'json' })
            .done(res => { if (res.success) { loadData(); Swal.fire({ icon: 'success', title: res.message, timer: 1400, showConfirmButton: false }); } else Swal.fire({ icon: 'error', title: L.error, text: res.message }); })
            .fail(x => Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.serverError }));
    }

    $('#btnSubmitDay').on('click', function () {
        const f = filters();
        if (f.date_from !== f.date_to) { Swal.fire({ icon: 'info', title: L.submitOneDay }); return; }
        Swal.fire({ title: L.submitQ, text: L.submitText, icon: 'question', showCancelButton: true, confirmButtonText: L.yesSubmit, cancelButtonText: L.cancel })
            .then(x => { if (x.isConfirmed) post('submit_day.php', { date: f.date_from }); });
    });

    // ── Report: one click — the page's dates + staff, in the user's language. Portrait /
    // landscape is the browser print dialog's "Layout"; the report adapts to it.
    $('#btnReport').on('click', function () {
        window.open(PRINT_URL + '?' + $.param(Object.assign(filters(), { lang: USER_LANG })), '_blank');
    });
    const reportParams = () => Object.assign(filters(), { lang: USER_LANG });
    $('#btnExcel').on('click', () => { window.location.href = API + 'export.php?' + $.param(reportParams()); });
    // PDF / WhatsApp / email (includes/field_reports/report_share.php). The PDF starts building
    // as soon as the menu opens, so a WhatsApp tap can hand it to the share sheet at once.
    document.getElementById('btnReportMenu').addEventListener('show.bs.dropdown', () => FrShare.prepare(reportParams()));
    $('[data-frs]').on('click', function () { FrShare[$(this).data('frs')](reportParams(), this); });

    // From View details: ?date= shows that day, ?edit= opens that visit's form.
    const qs = new URLSearchParams(window.location.search), qDate = qs.get('date') || '', qEdit = +(qs.get('edit') || 0);
    if (/^\d{4}-\d{2}-\d{2}$/.test(qDate) && qDate <= TODAY) $('#fFrom, #fTo').val(qDate);
    loadData(() => {
        if (qEdit) {
            const r = rows.find(x => x.visit_id === qEdit);
            if (r && r.can_edit) openEdit(r);
        }
        if (qDate || qEdit) window.history.replaceState(null, '', window.location.pathname);   // a reload starts clean
    });
});
</script>

<?php require_once ROOT_DIR . '/includes/field_reports/report_share.php'; frRenderShareKit(); ?>
<?php require_once ROOT_DIR . '/includes/field_reports/followup_kit.php'; frRenderFollowUpKit(); ?>
<?php includeFooter(); ?>
