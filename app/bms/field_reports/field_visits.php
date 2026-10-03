<?php
// Ziara za Wateja / Customer Visits (marketing) — record customer visits and produce the
// daily report. Every user sees only their own visits; admins see all staff
// (core/field_reports.php). Phone-first layout: customer_visits_ux_plan.md.
ob_start();
$page_title = 'Customer Visits';
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/core/field_reports.php';
autoEnforcePermission('field_visits');
includeHeader();

$can_create = canCreate('field_visits');
$can_edit   = canEdit('field_visits');
$is_admin   = isAdmin();   // fresh call, after header.php
$me         = (int)$_SESSION['user_id'];
$today      = date('Y-m-d');

$staff = [];
if ($is_admin) {
    $staff = $pdo->query("SELECT user_id, first_name, last_name, username FROM users WHERE is_active = 1 ORDER BY first_name, last_name, username")
                 ->fetchAll(PDO::FETCH_ASSOC);
}
$userLang = function_exists('currentLanguage') && currentLanguage() === 'sw' ? 'sw' : 'en';
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
            <button class="btn btn-outline-primary" id="btnReport" title="<?= t('Opens the report for the dates and staff chosen below') ?>"><i class="bi bi-printer me-1"></i><?= t('Print report') ?></button>
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
        <div class="collapse" id="moreStats"><div class="row g-2 pt-1" id="statCards"></div></div>
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
                <th>S/NO</th><th><?= t('Date') ?></th><th><?= t('Time') ?></th>
                <?php if ($is_admin): ?><th><?= t('Staff') ?></th><?php endif; ?>
                <th><?= t('Place visited') ?></th><th><?= t('Client name') ?></th><th><?= t('Phone') ?></th><th><?= t('Business') ?></th>
                <th class="text-center"><?= t('Card') ?></th><th class="text-center"><?= t('Trial') ?></th><th class="text-center"><?= t('Training') ?></th>
                <th><?= t('Response') ?></th><th><?= t('Follow-up') ?></th>
                <th class="text-center"><?= t('Joined') ?></th><th class="text-end"><?= t('Actions') ?></th>
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
                <input type="hidden" name="latitude" id="vLat"><input type="hidden" name="longitude" id="vLng"><input type="hidden" name="gps_accuracy_m" id="vAcc">
                <div class="row g-3">
                    <!-- 1. Phone first: a known number fills the rest -->
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-bold" for="vPhone"><?= t('Phone') ?> <span class="text-danger">*</span></label>
                        <input type="tel" inputmode="tel" class="form-control form-control-lg" name="client_phone" id="vPhone" maxlength="30" placeholder="07XX XXX XXX" required>
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
                            <div class="col-12 col-md-4"><input type="checkbox" class="btn-check" name="gave_business_card" value="1" id="vCard" autocomplete="off"><label class="btn btn-outline-primary w-100 py-2 fr-toggle" for="vCard"><i class="bi bi-person-vcard me-1"></i><?= t('Business card') ?></label></div>
                            <div class="col-12 col-md-4"><input type="checkbox" class="btn-check" name="gave_trial_link" value="1" id="vTrial" autocomplete="off"><label class="btn btn-outline-primary w-100 py-2 fr-toggle" for="vTrial"><i class="bi bi-link-45deg me-1"></i><?= t('Free trial link (14 days)') ?></label></div>
                            <div class="col-12 col-md-4"><input type="checkbox" class="btn-check" name="gave_training" value="1" id="vTraining" autocomplete="off"><label class="btn btn-outline-primary w-100 py-2 fr-toggle" for="vTraining"><i class="bi bi-easel me-1"></i><?= t('Training about our system') ?></label></div>
                        </div>
                    </div>

                    <!-- 3. How the client responded: three coloured buttons (tap again to clear) -->
                    <div class="col-12">
                        <label class="form-label fw-bold d-block"><?= t('How did the client respond?') ?></label>
                        <div class="row g-2">
                            <?php foreach (frInterestLabels() as $code => $label): [$color, $icon] = $interestStyles[$code]; ?>
                            <div class="col-4"><input type="radio" class="btn-check fr-interest" name="interest" value="<?= $code ?>" id="vInt_<?= $code ?>" autocomplete="off"><label class="btn btn-outline-<?= $color ?> w-100 py-2 fr-toggle" for="vInt_<?= $code ?>"><i class="bi <?= $icon ?> d-block fs-5"></i><?= t($label) ?></label></div>
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
            <div class="modal-footer">
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
    .fr-head-actions .btn:not(#btnAddVisit) { flex: 1 1 0; min-width: 0; }
}
.fr-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.72rem; font-weight:600; }
.fr-yes { background:#0d6efd; color:#fff; } .fr-no { background:#e9ecef; color:#495057; }
.fr-stat { background:#e7f0ff; border:1px solid #b6ccfe; }
.fr-main-stat { background:#0d6efd; color:#fff; }
.fr-toggle { min-width: 0 !important; white-space: normal; }   /* global .btn{min-width:85px} */
.fr-quick .btn { min-width: 0; }
.fr-thead th { background: #fff !important; color: #212529; border-bottom: 2px solid #dee2e6; font-weight: 600; }
.fr-view-switch .btn { min-width: 0; }
.fr-follow-actions .btn { flex: 1 1 0; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: .85rem; padding: .55rem .25rem; }   /* ~38 px: a finger-sized target */
@media (max-width: 767.98px) { .fr-head-actions .btn:not(#btnAddVisit) { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: .85rem; } }
</style>

<script>
$(function () {
    const IS_ADMIN = <?= json_encode($is_admin) ?>, CAN_CREATE = <?= json_encode($can_create) ?>, CAN_EDIT = <?= json_encode($can_edit) ?>, ME = <?= $me ?>, TODAY = <?= json_encode($today) ?>;
    const API = '<?= buildUrl('api/field_reports/') ?>', PRINT_URL = '<?= getUrl('field_reports/print') ?>';
    const CSRF = <?= json_encode(csrf_token()) ?>, USER_LANG = <?= json_encode($userLang) ?>;
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
        'gpsDenied' => t('Location was not allowed or not found. You can still type the place.'),
        'gpsOk' => t('Location confirmed (±%m% m)'), 'gpsWeak' => t('Location found but not precise (±%m% m) — move outside and try again.'),
        'gpsHint' => t('Tap GPS to confirm where you are.'), 'nearby' => t('Near: %place%'), 'useNearby' => t('Use “%place%”'),
        'dupOwn' => t('You already visited this number on %date% (%name%).'), 'dupAdmin' => t('Already visited on %date% by %staff% (%name%).'),
        'known' => t('Known client — this will be visit no. %n%. Details filled in.'),
        'scopeAll' => t('All staff'), 'scopeMe' => t('Your visits'), 'staff' => t('Staff'), 'date' => t('Date'),
        'submitOneDay' => t('Choose a single day to submit its report.'),
        'cardShort' => t('Business card'), 'trialShort' => t('Trial link'), 'trainingShort' => t('Training'),
        'now' => t('Now'), 'when' => t('Visit time: %when%'), 'joinedBtn' => t('Joined'),
        'call' => t('Call'), 'followedUp' => t('Followed up'), 'newVisit' => t('New visit'),
        'dueToday' => t('due today'), 'daysLate' => t('%d days late'), 'followUp' => t('Follow-up'), 'gpsConfirmed' => t('GPS confirmed'),
        'interest' => array_map('t', frInterestLabels()),
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
    const gpsMark = r => r.latitude !== null
        ? ` <a href="https://maps.google.com/?q=${r.latitude},${r.longitude}" target="_blank" rel="noopener" title="${esc(r.gps_verified ? L.gpsConfirmed : 'GPS')}"><i class="bi ${r.gps_verified ? 'bi-geo-alt-fill text-success' : 'bi-pin-map text-primary'}"></i></a>` : '';
    let rows = [], follows = [];

    $('.select2-static').not('#visitModal .select2-static').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#visitModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#visitModal'), placeholder: <?= json_encode(t('Select...')) ?>, allowClear: true, width: '100%' });
        });
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

    function joinedBtn(r, small) {
        if (!r.can_edit) return r.joined ? `<span class="fr-badge fr-yes">✓ ${L.joinedBtn}</span>` : '';
        return `<button type="button" class="btn btn-sm ${r.joined ? 'btn-success' : 'btn-outline-success'} ${small ? '' : 'w-100'}" data-act="joined" data-id="${r.visit_id}" data-joined="${r.joined ? 0 : 1}" title="${esc(r.joined ? L.unmarkJoined : L.markJoined)}" style="min-width:0">
            <i class="bi ${r.joined ? 'bi-check-circle-fill' : 'bi-person-check'}"></i> ${L.joinedBtn}</button>`;
    }

    function actions(r) {
        let items = '';
        if (r.can_edit) items += `<li><button class="dropdown-item py-2 rounded" data-act="edit" data-id="${r.visit_id}"><i class="bi bi-pencil text-primary me-2"></i>${L.edit}</button></li>`;
        if (r.can_delete) items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" data-act="delete" data-id="${r.visit_id}"><i class="bi bi-trash text-danger me-2"></i>${L.delete}</button></li>`;
        if (!items) return '';
        return `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="min-width:0"><i class="bi bi-gear-fill me-1"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
    }

    function renderCards(list) {
        if (!list.length) { $('#cardView').html(`<div class="col-12 text-center py-5 text-muted">${L.noRecords}</div>`); return; }
        $('#cardView').html(list.map(r => `
            <div class="col-12"><div class="card border-0 shadow-sm">
                <div class="card-body p-3" style="font-size:.85rem">
                    <div class="d-flex justify-content-between gap-2"><div class="fw-bold text-break">${esc(r.client_name)}</div><small class="text-muted text-nowrap">${dmy(r.visit_date)} ${esc(r.visit_time)}</small></div>
                    <div><i class="bi bi-geo-alt text-primary"></i> ${esc(r.location)}${gpsMark(r)}</div>
                    <div><a href="tel:${esc(r.client_phone)}" class="text-decoration-none"><i class="bi bi-telephone text-primary"></i> ${esc(r.client_phone)}</a> · ${esc(r.business_label)}</div>
                    ${IS_ADMIN ? `<div class="text-muted">${L.staff}: ${esc(r.staff_name)}</div>` : ''}
                    <div class="d-flex flex-wrap gap-1 mt-1">${ynL(L.cardShort, r.gave_business_card)} ${ynL(L.trialShort, r.gave_trial_link)} ${ynL(L.trainingShort, r.gave_training)} ${r.interest ? interestBadge(r) : ''}</div>
                    ${r.follow_up_date ? `<div class="mt-1 small"><i class="bi bi-calendar-event text-primary"></i> ${L.followUp}: ${followTxt(r)}</div>` : ''}
                </div>
                <div class="card-footer bg-white border-top p-0"><div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                    <div style="flex:2;min-width:0">${joinedBtn(r, false)}</div>
                    ${r.can_edit ? `<button class="btn btn-sm btn-outline-primary" data-act="edit" data-id="${r.visit_id}" style="flex:1;min-width:0;padding:3px 4px" title="${esc(L.edit)}"><i class="bi bi-pencil"></i></button>` : ''}
                    ${r.can_delete ? `<button class="btn btn-sm btn-outline-danger" data-act="delete" data-id="${r.visit_id}" style="flex:1;min-width:0;padding:3px 4px" title="${esc(L.delete)}"><i class="bi bi-trash"></i></button>` : ''}
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

    function renderFollowUps(list) {
        follows = list || [];
        $('#followCard').toggleClass('d-none', !follows.length);
        $('#followCount').text(follows.length);
        $('#followList').html(follows.map(f => `
            <li class="list-group-item">
                <div class="d-flex justify-content-between gap-2">
                    <div class="text-break"><div class="fw-bold">${esc(f.client_name)}</div>
                        <small class="text-muted">${esc(f.location)} · ${esc(f.business_label)}${IS_ADMIN ? ' · ' + esc(f.staff_name) : ''}</small></div>
                    <span class="badge ${f.days_overdue > 0 ? 'bg-danger' : 'bg-primary'} align-self-start text-nowrap">${f.days_overdue > 0 ? esc(L.daysLate.replace('%d', f.days_overdue)) : esc(L.dueToday)}</span>
                </div>
                <div class="d-flex gap-1 mt-2 fr-follow-actions">
                    <a class="btn btn-sm btn-primary" href="tel:${esc(f.client_phone)}"><i class="bi bi-telephone me-1"></i>${L.call}</a>
                    ${CAN_CREATE ? `<button class="btn btn-sm btn-outline-primary" data-fact="visit" data-id="${f.visit_id}"><i class="bi bi-plus-circle me-1"></i>${L.newVisit}</button>` : ''}
                    ${f.can_edit ? `<button class="btn btn-sm btn-outline-success" data-fact="done" data-id="${f.visit_id}"><i class="bi bi-check2 me-1"></i>${L.followedUp}</button>` : ''}
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

    function loadData() {
        $.getJSON(API + 'list.php', filters()).done(function (res) {
            if (!res.success) { Swal.fire({ icon: 'error', title: L.error, text: res.message }); return; }
            rows = res.rows;
            $('#visitsCount').text(rows.length);
            renderFollowUps(res.follow_ups);
            table.clear().rows.add(rows.map((r, i) => {
                const cells = [i + 1, dmy(r.visit_date), esc(r.visit_time || '—')];
                if (IS_ADMIN) cells.push(esc(r.staff_name));
                cells.push(esc(r.location) + gpsMark(r), esc(r.client_name), esc(r.client_phone), esc(r.business_label),
                    `<div class="text-center">${yn(r.gave_business_card)}</div>`, `<div class="text-center">${yn(r.gave_trial_link)}</div>`,
                    `<div class="text-center">${yn(r.gave_training)}</div>`, interestBadge(r), followTxt(r),
                    `<div class="text-center">${joinedBtn(r, true)}</div>`, actions(r));
                cells._row = r;
                return cells;
            })).draw();
            renderStats(res.stats);
            renderDayStatus(res);
            renderStaff(res.staff_summary || []);
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
    function setGps(lat, lng, acc) {
        $('#vLat').val(lat ?? ''); $('#vLng').val(lng ?? ''); $('#vAcc').val(acc ?? '');
        const on = lat !== null && lat !== undefined && lat !== '';
        const precise = on && (acc === null || acc === undefined || acc === '' || Number(acc) <= 100);
        $('#gpsInfo').html(!on ? esc(L.gpsHint)
            : `<span class="${precise ? 'text-success' : 'text-warning'}"><i class="bi ${precise ? 'bi-geo-alt-fill' : 'bi-exclamation-triangle'}"></i> ${esc((precise ? L.gpsOk : L.gpsWeak).replace('%m%', acc ? Math.round(acc) : '—'))}</span>`);
        $('#btnGpsClear').toggleClass('d-none', !on);
        if (!on) { nearbyPlace = null; $('#btnUseNearby').addClass('d-none'); }
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
        $('#visitForm')[0].reset();
        $('#vId').val(''); $('#vBusiness').val('').trigger('change');
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
    function openNew(prefillPhone) {
        resetForm(false);
        $('#visitModalTitle').html(`<i class="bi bi-geo-alt me-1"></i>${L.addVisit}`); $('#btnSaveAgain').removeClass('d-none');
        if (prefillPhone) $('#vPhone').val(prefillPhone);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('visitModal')).show();
        if (prefillPhone) lookupPhone(true);
    }
    $('#btnAddVisit').on('click', () => openNew(''));
    $('#vBusiness').on('change', function () { $('#vOtherWrap').toggleClass('d-none', $(this).val() !== 'other'); });
    $('#btnGps').on('click', function () {
        if (!navigator.geolocation) { $('#gpsInfo').text(L.gpsNo); return; }
        $('#gpsInfo').text(L.gpsGetting);
        navigator.geolocation.getCurrentPosition(p => {
            setGps(p.coords.latitude, p.coords.longitude, p.coords.accuracy);
            $.getJSON(API + 'nearby.php', { lat: p.coords.latitude, lng: p.coords.longitude }, res => showNearby(res && res.success ? res.place : null));
        }, () => { setGps(null); $('#gpsInfo').text(L.gpsDenied); }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    });
    $('#btnGpsClear').on('click', () => setGps(null));
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
        if (errs.visit_date || errs.visit_time) setWhen(true, $('#vDate').val(), $('#vTime').val());
        Swal.fire({ icon: 'error', title: L.error, text: r.message || L.serverError });
    }

    $(document).on('click', '[data-act]', function () {
        const id = $(this).data('id'), r = rows.find(x => x.visit_id == id), act = $(this).data('act');
        if (!r) return;
        if (act === 'edit') {
            resetForm(false);
            $('#visitModalTitle').html(`<i class="bi bi-pencil me-1"></i>${L.editVisit}`); $('#btnSaveAgain').addClass('d-none');
            $('#vId').val(r.visit_id); setWhen(true, r.visit_date, r.visit_time); $('#vLocation').val(r.location);
            $('#vName').val(r.client_name); $('#vPhone').val(r.client_phone); $('#vOther').val(r.business_other);
            $('#vCard').prop('checked', !!r.gave_business_card); $('#vTrial').prop('checked', !!r.gave_trial_link); $('#vTraining').prop('checked', !!r.gave_training);
            $('.fr-interest').prop('checked', false); if (r.interest) $(`#vInt_${r.interest}`).prop('checked', true);
            $('#vFollowUp').val(r.follow_up_date || '').attr('min', r.visit_date);
            $('#vNotes').val(r.notes); setGps(r.latitude, r.longitude, r.gps_accuracy_m);   // the stored accuracy, re-saved unchanged
            // Set before showing: Select2 (initialised on first show) reads the current value.
            $('#vBusiness').val(r.business_type).trigger('change');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('visitModal')).show();
        } else if (act === 'delete') {
            Swal.fire({ title: L.deleteQ, text: L.cannotUndo, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: L.yesDelete, cancelButtonText: L.cancel })
                .then(x => { if (x.isConfirmed) post('delete.php', { visit_id: id }); });
        } else if (act === 'joined') {
            post('toggle_joined.php', { visit_id: id, joined: $(this).data('joined') });
        }
    });
    $(document).on('click', '[data-fact]', function () {
        const id = $(this).data('id'), f = follows.find(x => x.visit_id == id);
        if (!f) return;
        if ($(this).data('fact') === 'done') post('followup_done.php', { visit_id: id, done: 1 });
        else openNew(f.client_phone);
    });
    $('#visitModal').on('hidden.bs.modal', () => $('#vFollowUp').attr('min', TODAY));

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
    loadData();
});
</script>

<?php includeFooter(); ?>
