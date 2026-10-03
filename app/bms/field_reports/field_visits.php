<?php
// Field Reports (marketing) — record field visits and produce the daily report.
// Every user sees only their own visits; admins see all staff (core/field_reports.php).
ob_start();
$page_title = 'Field Reports';
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
?>
<div class="container-fluid mt-4" id="frPage">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 fr-sticky">
        <div>
            <h4 class="mb-0"><i class="bi bi-geo-alt text-primary me-2"></i><?= t('Field Reports') ?></h4>
            <small class="text-muted"><?= $is_admin ? t('Field visits by all staff') : t('Your field visits') ?></small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($can_create): ?>
            <button class="btn btn-primary" id="btnAddVisit"><i class="bi bi-plus-circle me-1"></i><?= t('Add Visit') ?></button>
            <button class="btn btn-outline-primary" id="btnSubmitDay"><i class="bi bi-send-check me-1"></i><?= t("Submit Today's Report") ?></button>
            <?php endif; ?>
            <button class="btn btn-outline-primary" id="btnReport"><i class="bi bi-printer me-1"></i><?= t('Report') ?></button>
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
            <div class="col-12 col-md-auto d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-quick="today"><?= t('Today') ?></button>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-quick="yesterday"><?= t('Yesterday') ?></button>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-quick="week"><?= t('This Week') ?></button>
            </div>
        </div>
    </div></div>

    <div id="dayStatus" class="mb-3"></div>

    <!-- Stats -->
    <div class="row g-2 mb-3" id="statCards"></div>

    <?php if ($is_admin): ?>
    <!-- Admin: per-staff summary -->
    <div class="card border-0 shadow-sm mb-3 d-none" id="staffSummaryCard"><div class="card-body p-3">
        <h6 class="fw-bold mb-2"><i class="bi bi-people text-primary me-1"></i><?= t('Summary by staff') ?></h6>
        <table class="table table-sm align-middle w-100" id="staffTable">
            <thead class="table-light"><tr>
                <th><?= t('Staff') ?></th><th class="text-center"><?= t('Visits') ?></th><th class="text-center"><?= t('People visited') ?></th>
                <th class="text-center"><?= t('Places') ?></th><th class="text-center"><?= t('Business cards given') ?></th>
                <th class="text-center"><?= t('Trial links given') ?></th><th class="text-center"><?= t('Trainings given') ?></th>
                <th class="text-center"><?= t('Joined our system') ?></th>
            </tr></thead><tbody></tbody>
        </table>
    </div></div>
    <?php endif; ?>

    <!-- Visits -->
    <div id="tableView" class="card border-0 shadow-sm"><div class="card-body p-2">
        <table id="visitsTable" class="table table-hover align-middle w-100">
            <thead class="table-dark"><tr>
                <th>#</th><th><?= t('Date') ?></th><th><?= t('Time') ?></th>
                <?php if ($is_admin): ?><th><?= t('Staff') ?></th><?php endif; ?>
                <th><?= t('Place visited') ?></th><th><?= t('Client name') ?></th><th><?= t('Phone') ?></th><th><?= t('Business') ?></th>
                <th class="text-center"><?= t('Card') ?></th><th class="text-center"><?= t('Trial') ?></th><th class="text-center"><?= t('Training') ?></th>
                <th class="text-center"><?= t('Joined') ?></th><th class="text-end"><?= t('Actions') ?></th>
            </tr></thead><tbody></tbody>
        </table>
    </div></div>
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- Add / Edit visit -->
<div class="modal fade" id="visitModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="visitModalTitle"><i class="bi bi-geo-alt me-1"></i><?= t('Add Visit') ?></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form id="visitForm" autocomplete="off" novalidate>
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="visit_id" id="vId">
                <input type="hidden" name="latitude" id="vLat"><input type="hidden" name="longitude" id="vLng"><input type="hidden" name="gps_accuracy_m" id="vAcc">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label"><?= t('Date') ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="visit_date" id="vDate" max="<?= $today ?>" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label"><?= t('Time') ?></label>
                        <input type="time" class="form-control" name="visit_time" id="vTime">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label"><?= t('Place visited') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="location" id="vLocation" maxlength="255" placeholder="<?= t('e.g. Kariakoo, Congo Street') ?>" required>
                        <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnGps"><i class="bi bi-crosshair me-1"></i><?= t('Use my location (GPS)') ?></button>
                            <small class="text-muted" id="gpsInfo"></small>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 d-none" id="btnGpsClear"><?= t('Clear') ?></button>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label"><?= t('Client name') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="client_name" id="vName" maxlength="150" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label"><?= t('Phone') ?> <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control" name="client_phone" id="vPhone" maxlength="30" placeholder="07XX XXX XXX" required>
                        <div class="small mt-1 d-none" id="phoneWarn"></div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label"><?= t('Business') ?> <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="business_type" id="vBusiness" required>
                            <option value=""><?= t('-- Select --') ?></option>
                            <?php foreach (frBusinessTypes() as $code => $label): ?>
                            <option value="<?= $code ?>"><?= t($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 d-none" id="vOtherWrap">
                        <label class="form-label"><?= t('Describe the business') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="business_other" id="vOther" maxlength="150">
                    </div>
                    <div class="col-12">
                        <label class="form-label d-block"><?= t('Given to the client') ?></label>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="gave_business_card" value="1" id="vCard"><label class="form-check-label" for="vCard"><?= t('Business card') ?></label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="gave_trial_link" value="1" id="vTrial"><label class="form-check-label" for="vTrial"><?= t('Free trial link (14 days)') ?></label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="gave_training" value="1" id="vTraining"><label class="form-check-label" for="vTraining"><?= t('Training about our system') ?></label></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label"><?= t('Interest') ?></label>
                        <select class="form-select select2-static" name="interest" id="vInterest">
                            <option value=""><?= t('-- Select --') ?></option>
                            <?php foreach (frInterestLabels() as $code => $label): ?>
                            <option value="<?= $code ?>"><?= t($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label"><?= t('Notes') ?></label>
                        <textarea class="form-control" name="notes" id="vNotes" rows="2" maxlength="2000"></textarea>
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

<!-- Report options -->
<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="bi bi-printer me-1"></i><?= t('Report') ?></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <p class="small text-muted mb-3" id="reportScope"></p>
            <label class="form-label fw-bold"><?= t('Language') ?></label>
            <div class="mb-3 d-flex gap-3">
                <div class="form-check"><input class="form-check-input" type="radio" name="rLang" id="rLangSw" value="sw" <?= $userLang === 'sw' ? 'checked' : '' ?>><label class="form-check-label" for="rLangSw">Kiswahili</label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="rLang" id="rLangEn" value="en" <?= $userLang === 'en' ? 'checked' : '' ?>><label class="form-check-label" for="rLangEn">English</label></div>
            </div>
            <label class="form-label fw-bold"><?= t('Page') ?></label>
            <div class="d-flex gap-3">
                <div class="form-check"><input class="form-check-input" type="radio" name="rOrient" id="rLand" value="landscape" checked><label class="form-check-label" for="rLand"><?= t('Landscape') ?></label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="rOrient" id="rPort" value="portrait"><label class="form-check-label" for="rPort"><?= t('Portrait') ?></label></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
            <button type="button" class="btn btn-outline-primary" id="btnExcel"><i class="bi bi-download me-1"></i><?= t('Download Excel') ?></button>
            <button type="button" class="btn btn-primary" id="btnOpenReport"><i class="bi bi-printer me-1"></i><?= t('Open Report') ?></button>
        </div>
    </div></div>
</div>

<style>
@media (max-width: 767.98px) { .fr-sticky { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 6px 0; } }
.fr-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.72rem; font-weight:600; }
.fr-yes { background:#0d6efd; color:#fff; } .fr-no { background:#e9ecef; color:#495057; }
.fr-stat { background:#e7f0ff; border:1px solid #b6ccfe; }
</style>

<script>
$(function () {
    const IS_ADMIN = <?= json_encode($is_admin) ?>, CAN_CREATE = <?= json_encode($can_create) ?>, TODAY = <?= json_encode($today) ?>;
    const API = '<?= buildUrl('api/field_reports/') ?>', PRINT_URL = '<?= getUrl('field_reports/print') ?>', EXPORT_URL = '<?= getUrl('api/field_reports/export.php') ?>';
    const CSRF = <?= json_encode(csrf_token()) ?>;
    const L = <?= json_encode([
        'visits' => t('Visits'), 'people' => t('People visited'), 'places' => t('Places'), 'cards' => t('Business cards given'),
        'trials' => t('Trial links given'), 'trainings' => t('Trainings given'), 'joined' => t('Joined our system'),
        'yes' => t('Yes'), 'no' => t('No'), 'edit' => t('Edit'), 'delete' => t('Delete'), 'markJoined' => t('Mark as joined'),
        'unmarkJoined' => t('Remove joined mark'), 'noRecords' => t('No visits recorded for this period.'),
        'deleteQ' => t('Delete this visit?'), 'cannotUndo' => t('This cannot be undone.'), 'yesDelete' => t('Yes, Delete'),
        'cancel' => t('Cancel'), 'error' => t('Error'), 'serverError' => t('Server error. Please try again.'),
        'saving' => t('Saving...'), 'addVisit' => t('Add Visit'), 'editVisit' => t('Edit Visit'),
        'submitQ' => t("Submit today's report?"), 'submitText' => t('You can still add or edit visits later; the report will show that it changed.'),
        'yesSubmit' => t('Yes, Submit'), 'submitted' => t('Report for %date% submitted at %time% — %n% visits.'),
        'changedAfter' => t('Updated after it was submitted — submit again to refresh.'), 'notSubmitted' => t('The report for %date% has not been submitted yet.'),
        'gpsGetting' => t('Getting your location...'), 'gpsNo' => t('This device or browser cannot share location.'),
        'gpsDenied' => t('Location was not allowed or not found. You can still type the place.'),
        'dupOwn' => t('You already visited this number on %date% (%name%).'), 'dupAdmin' => t('Already visited on %date% by %staff% (%name%).'),
        'scopeAll' => t('All staff'), 'scopeMe' => t('Your visits'), 'staff' => t('Staff'), 'date' => t('Date'),
        'submitOneDay' => t('Choose a single day to submit its report.'),
    ]) ?>;

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dmy = s => { const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; };
    const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const yn = v => v ? `<span class="fr-badge fr-yes">${L.yes}</span>` : `<span class="fr-badge fr-no">${L.no}</span>`;
    let rows = [];

    $('.select2-static').not('#visitModal .select2-static').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#visitModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#visitModal'), placeholder: <?= json_encode(t('Select...')) ?>, allowClear: true, width: '100%' });
        });
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

    function actions(r) {
        let items = '';
        if (r.can_edit) items += `<li><button class="dropdown-item py-2 rounded" data-act="edit" data-id="${r.visit_id}"><i class="bi bi-pencil text-primary me-2"></i>${L.edit}</button></li>`;
        if (r.can_edit) items += `<li><button class="dropdown-item py-2 rounded" data-act="joined" data-id="${r.visit_id}" data-joined="${r.joined ? 0 : 1}"><i class="bi ${r.joined ? 'bi-person-dash' : 'bi-person-check'} text-primary me-2"></i>${r.joined ? L.unmarkJoined : L.markJoined}</button></li>`;
        if (r.can_delete) items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" data-act="delete" data-id="${r.visit_id}"><i class="bi bi-trash text-danger me-2"></i>${L.delete}</button></li>`;
        if (!items) return '';
        return `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill me-1"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
    }

    function renderCards(list) {
        if (!list.length) { $('#cardView').html(`<div class="col-12 text-center py-5 text-muted">${L.noRecords}</div>`); return; }
        $('#cardView').html(list.map((r, i) => `
            <div class="col-12"><div class="card border-0 shadow-sm">
                <div class="card-body p-3" style="font-size:.8rem">
                    <div class="d-flex justify-content-between"><div class="fw-bold">${esc(r.client_name)}</div><small class="text-muted">${dmy(r.visit_date)} ${esc(r.visit_time)}</small></div>
                    <div><i class="bi bi-geo-alt text-primary"></i> ${esc(r.location)}</div>
                    <div><i class="bi bi-telephone text-primary"></i> ${esc(r.client_phone)} · ${esc(r.business_label)}</div>
                    ${IS_ADMIN ? `<div class="text-muted">${L.staff}: ${esc(r.staff_name)}</div>` : ''}
                    <div class="d-flex flex-wrap gap-1 mt-1">${yn(r.gave_business_card)} ${yn(r.gave_trial_link)} ${yn(r.gave_training)} ${r.joined ? `<span class="fr-badge fr-yes">${L.joined}</span>` : ''}</div>
                </div>
                ${(r.can_edit || r.can_delete) ? `<div class="card-footer bg-white border-top p-0"><div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                    ${r.can_edit ? `<button class="btn btn-sm btn-outline-primary" data-act="edit" data-id="${r.visit_id}" style="flex:1;min-width:0;padding:3px 4px;font-size:.72rem"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-primary" data-act="joined" data-id="${r.visit_id}" data-joined="${r.joined ? 0 : 1}" style="flex:1;min-width:0;padding:3px 4px;font-size:.72rem"><i class="bi ${r.joined ? 'bi-person-dash' : 'bi-person-check'}"></i></button>` : ''}
                    ${r.can_delete ? `<button class="btn btn-sm btn-outline-danger" data-act="delete" data-id="${r.visit_id}" style="flex:1;min-width:0;padding:3px 4px;font-size:.72rem"><i class="bi bi-trash"></i></button>` : ''}
                </div></div>` : ''}
            </div></div>`).join(''));
    }

    function renderStats(s) {
        const keys = ['visits', 'people', 'places', 'cards', 'trials', 'trainings', 'joined'];
        $('#statCards').html(keys.map(k => `<div class="col-6 col-md"><div class="card border-0 shadow-sm text-center p-2 fr-stat">
            <div class="fs-4 fw-bold text-primary">${Number(s[k] || 0)}</div><div class="small">${L[k]}</div></div></div>`).join(''));
    }

    function renderDayStatus(res) {
        const f = filters(), single = f.date_from === f.date_to, own = !IS_ADMIN || String(f.user_id) === String(<?= $me ?>);
        $('#btnSubmitDay').toggle(CAN_CREATE && single && own);
        if (!single || !own) { $('#dayStatus').empty(); return; }
        const d = res.day_status;
        if (d) {
            $('#dayStatus').html(`<div class="alert alert-primary py-2 mb-0"><i class="bi bi-check-circle-fill me-1"></i><strong>${esc(L.submitted.replace('%date%', dmy(f.date_from)).replace('%time%', d.submitted_time).replace('%n%', d.visit_count))}</strong>
                ${d.changed_after_submit ? `<div class="small mt-1"><i class="bi bi-exclamation-circle me-1"></i>${esc(L.changedAfter)}</div>` : ''}</div>`);
        } else {
            $('#dayStatus').html(`<div class="alert alert-light border py-2 mb-0 small"><i class="bi bi-clock me-1"></i>${esc(L.notSubmitted.replace('%date%', dmy(f.date_from)))}</div>`);
        }
    }

    function loadData() {
        $.getJSON(API + 'list.php', filters()).done(function (res) {
            if (!res.success) { Swal.fire({ icon: 'error', title: L.error, text: res.message }); return; }
            rows = res.rows;
            table.clear().rows.add(rows.map((r, i) => {
                const cells = [i + 1, dmy(r.visit_date), esc(r.visit_time || '—')];
                if (IS_ADMIN) cells.push(esc(r.staff_name));
                cells.push(esc(r.location) + (r.latitude !== null ? ` <a href="https://maps.google.com/?q=${r.latitude},${r.longitude}" target="_blank" rel="noopener" title="GPS"><i class="bi bi-pin-map text-primary"></i></a>` : ''),
                    esc(r.client_name), esc(r.client_phone), esc(r.business_label),
                    `<div class="text-center">${yn(r.gave_business_card)}</div>`, `<div class="text-center">${yn(r.gave_trial_link)}</div>`,
                    `<div class="text-center">${yn(r.gave_training)}</div>`, `<div class="text-center">${yn(r.joined)}</div>`, actions(r));
                cells._row = r;
                return cells;
            })).draw();
            renderStats(res.stats);
            renderDayStatus(res);
            if (staffTable) {
                $('#staffSummaryCard').toggleClass('d-none', !res.staff_summary.length);
                staffTable.clear().rows.add(res.staff_summary.map(s => [esc(s.staff_name), s.visits, s.people, s.places, s.cards, s.trials, s.trainings, s.joined])).draw();
            }
        }).fail(() => Swal.fire({ icon: 'error', title: L.error, text: L.serverError }));
    }

    function applyView() { const m = window.innerWidth < 768; $('#tableView').toggleClass('d-none', m); $('#cardView').toggleClass('d-none', !m); }
    applyView(); $(window).on('resize', applyView);

    $('#fFrom, #fTo, #fStaff').on('change', loadData);
    $('[data-quick]').on('click', function () {
        const now = new Date(); let from = now, to = now;
        if ($(this).data('quick') === 'yesterday') { from = to = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1); }
        if ($(this).data('quick') === 'week') { from = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7)); }
        $('#fFrom').val(ymd(from)); $('#fTo').val(ymd(to)); loadData();
    });

    // ── Add / edit ───────────────────────────────────────────────────
    function setGps(lat, lng, acc) {
        $('#vLat').val(lat ?? ''); $('#vLng').val(lng ?? ''); $('#vAcc').val(acc ?? '');
        const on = lat !== null && lat !== undefined && lat !== '';
        $('#gpsInfo').text(on ? `📍 ${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}${acc ? ' (±' + Math.round(acc) + ' m)' : ''}` : '');
        $('#btnGpsClear').toggleClass('d-none', !on);
    }
    function resetForm(keep) {
        const keepDate = $('#vDate').val(), keepLoc = $('#vLocation').val(), keepLat = $('#vLat').val(), keepLng = $('#vLng').val(), keepAcc = $('#vAcc').val();
        $('#visitForm')[0].reset();
        $('#vId').val(''); $('#vBusiness, #vInterest').val('').trigger('change');
        $('#phoneWarn').addClass('d-none').empty(); $('#vOtherWrap').addClass('d-none');
        if (keep) { $('#vDate').val(keepDate); $('#vLocation').val(keepLoc); setGps(keepLat || null, keepLng || null, keepAcc || null); }
        else { setGps(null); }
        const now = new Date(); $('#vTime').val(String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0'));
        if (!keep) $('#vDate').val(filters().date_to <= TODAY ? (filters().date_from === filters().date_to ? filters().date_from : TODAY) : TODAY);
        $('.is-invalid').removeClass('is-invalid');
    }
    $('#btnAddVisit').on('click', function () {
        resetForm(false); $('#visitModalTitle').html(`<i class="bi bi-geo-alt me-1"></i>${L.addVisit}`); $('#btnSaveAgain').removeClass('d-none');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('visitModal')).show();
    });
    $('#vBusiness').on('change', function () { $('#vOtherWrap').toggleClass('d-none', $(this).val() !== 'other'); });
    $('#btnGps').on('click', function () {
        if (!navigator.geolocation) { $('#gpsInfo').text(L.gpsNo); return; }
        $('#gpsInfo').text(L.gpsGetting);
        navigator.geolocation.getCurrentPosition(p => setGps(p.coords.latitude, p.coords.longitude, p.coords.accuracy),
            () => { setGps(null); $('#gpsInfo').text(L.gpsDenied); }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    });
    $('#btnGpsClear').on('click', () => setGps(null));
    $('#vPhone').on('blur', function () {
        const phone = $(this).val().trim();
        if (phone.replace(/\D/g, '').length < 9) { $('#phoneWarn').addClass('d-none'); return; }
        $.getJSON(API + 'check_phone.php', { phone, exclude_id: $('#vId').val() || 0 }, function (res) {
            const m = res && res.match;
            if (!m) { $('#phoneWarn').addClass('d-none').empty(); return; }
            const txt = (m.staff_name ? L.dupAdmin : L.dupOwn).replace('%date%', dmy(m.visit_date)).replace('%staff%', m.staff_name || '').replace('%name%', m.client_name);
            $('#phoneWarn').removeClass('d-none').html(`<span class="text-primary"><i class="bi bi-info-circle me-1"></i>${esc(txt)}</span>`);
        });
    });

    let again = false;
    $('#visitForm [type=submit]').on('click', function () { again = $(this).data('again') === 1; });
    $('#visitForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'), orig = btn.map((i, b) => $(b).html()).get();
        btn.prop('disabled', true);
        $.ajax({ url: API + 'save.php', type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json' })
            .done(function (res) {
                $('.is-invalid').removeClass('is-invalid');
                if (res.success) {
                    if (again) { resetForm(true); $('#vName').trigger('focus'); }
                    else bootstrap.Modal.getInstance(document.getElementById('visitModal')).hide();
                    loadData();
                    Swal.fire({ icon: 'success', title: res.message, timer: 1400, showConfirmButton: false });
                } else {
                    Object.keys(res.errors || {}).forEach(k => $(`#visitForm [name="${k}"]`).addClass('is-invalid'));
                    Swal.fire({ icon: 'error', title: L.error, text: res.message });
                }
            })
            .fail(x => { const r = x.responseJSON || {}; Object.keys(r.errors || {}).forEach(k => $(`#visitForm [name="${k}"]`).addClass('is-invalid')); Swal.fire({ icon: 'error', title: L.error, text: r.message || L.serverError }); })
            .always(() => btn.each((i, b) => $(b).prop('disabled', false).html(orig[i])));
    });

    $(document).on('click', '[data-act]', function () {
        const id = $(this).data('id'), r = rows.find(x => x.visit_id == id), act = $(this).data('act');
        if (!r) return;
        if (act === 'edit') {
            resetForm(false);
            $('#visitModalTitle').html(`<i class="bi bi-pencil me-1"></i>${L.editVisit}`); $('#btnSaveAgain').addClass('d-none');
            $('#vId').val(r.visit_id); $('#vDate').val(r.visit_date); $('#vTime').val(r.visit_time); $('#vLocation').val(r.location);
            $('#vName').val(r.client_name); $('#vPhone').val(r.client_phone); $('#vOther').val(r.business_other);
            $('#vCard').prop('checked', !!r.gave_business_card); $('#vTrial').prop('checked', !!r.gave_trial_link); $('#vTraining').prop('checked', !!r.gave_training);
            $('#vNotes').val(r.notes); setGps(r.latitude, r.longitude, null);
            // Set before showing: Select2 (initialised on first show) reads the current value.
            $('#vBusiness').val(r.business_type).trigger('change'); $('#vInterest').val(r.interest || '').trigger('change');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('visitModal')).show();
        } else if (act === 'delete') {
            Swal.fire({ title: L.deleteQ, text: L.cannotUndo, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: L.yesDelete, cancelButtonText: L.cancel })
                .then(x => { if (x.isConfirmed) post('delete.php', { visit_id: id }); });
        } else if (act === 'joined') {
            post('toggle_joined.php', { visit_id: id, joined: $(this).data('joined') });
        }
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

    // ── Report ─────────────────────────────────────────────────────
    $('#btnReport').on('click', function () {
        const f = filters();
        const who = IS_ADMIN ? (f.user_id ? $('#fStaff option:selected').text() : L.scopeAll) : L.scopeMe;
        $('#reportScope').text(`${L.staff}: ${who} · ${L.date}: ${dmy(f.date_from)}${f.date_to !== f.date_from ? ' – ' + dmy(f.date_to) : ''}`);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('reportModal')).show();
    });
    const reportQuery = () => '?' + $.param(Object.assign(filters(), { lang: $('input[name=rLang]:checked').val(), orient: $('input[name=rOrient]:checked').val() }));
    $('#btnOpenReport').on('click', () => window.open(PRINT_URL + reportQuery(), '_blank'));
    $('#btnExcel').on('click', () => { window.location.href = EXPORT_URL + reportQuery(); });

    loadData();
});
</script>

<?php includeFooter(); ?>
