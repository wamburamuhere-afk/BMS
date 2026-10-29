<?php
/**
 * includes/field_reports/followup_kit.php — the follow-up dialogs shared by the visits
 * page and the View details page (customer_visit_followups_plan.md), so both run the
 * exact same code.
 *
 * frRenderFollowUpKit() prints (once) two Bootstrap modals and window.FrFollow:
 *   FrFollow.done(fu, onSaved)      "Mark done": how it was done, what happened, and the
 *                                   next follow-up date (optional) — api/field_reports/followup_done.php
 *   FrFollow.set(opts, onSaved)     set a follow-up (opts.visit_id) or change an open one's
 *                                   date (opts.follow_up_id) — api/field_reports/followup_save.php
 *   FrFollow.cancel(fu, onSaved)    cancel an open follow-up (asks first)
 *   FrFollow.reopen(fu, onSaved)    re-open a done one
 * fu = { follow_up_id, client_name, due_date, purpose, visit_date }. onSaved(res) runs
 * after a successful save, before the success toast (§UI-4).
 * Dates use the server's day (one clock, customer_visits_ux_plan 1.3).
 */
if (!function_exists('frRenderFollowUpKit')) {
    function frRenderFollowUpKit(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        require_once ROOT_DIR . '/core/field_visit_followups.php';
        $L = [
            'error' => t('Error'), 'serverError' => t('Server error. Please try again.'),
            'setTitle' => t('Set a follow-up'), 'moveTitle' => t('Change the follow-up date'),
            'cancelQ' => t('Cancel this follow-up?'), 'cancelText' => t('It stays in the history as cancelled.'),
            'yesCancel' => t('Yes, cancel it'), 'no' => t('No'), 'chooseDate' => t('Choose a date.'),
            'dueOn' => t('Follow up on %date%'),
        ];
        ?>
<!-- Mark a follow-up done -->
<div class="modal fade" id="fuDoneModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="bi bi-check2-circle me-1"></i><?= te('Mark follow-up done') ?></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form id="fuDoneForm" autocomplete="off" novalidate>
            <div class="modal-body">
                <input type="hidden" name="follow_up_id" id="fuDoneId">
                <div class="p-2 rounded mb-3 fu-info" id="fuDoneInfo"></div>
                <label class="form-label fw-bold d-block"><?= te('How did you follow up?') ?></label>
                <div class="row g-2 mb-3">
                    <?php $icons = ['visit' => 'bi-geo-alt', 'call' => 'bi-telephone', 'whatsapp' => 'bi-whatsapp', 'sms' => 'bi-chat-dots', 'other' => 'bi-three-dots'];
                    foreach (frFollowUpMethods() as $code => $label): ?>
                    <div class="col"><input type="radio" class="btn-check" name="method" value="<?= $code ?>" id="fuM_<?= $code ?>" autocomplete="off">
                        <label class="btn btn-outline-primary w-100 h-100 py-2 fu-toggle" for="fuM_<?= $code ?>"><i class="bi <?= $icons[$code] ?> d-block fs-5"></i><?= te($label) ?></label></div>
                    <?php endforeach; ?>
                </div>
                <label class="form-label fw-bold" for="fuOutcome"><?= te('What happened?') ?> <small class="text-muted fw-normal">(<?= te('optional') ?>)</small></label>
                <textarea class="form-control mb-3" name="outcome" id="fuOutcome" rows="3" maxlength="2000" placeholder="<?= te('e.g. Asked us to come back after payday') ?>"></textarea>
                <label class="form-label fw-bold" for="fuNext"><?= te('Next follow-up') ?> <small class="text-muted fw-normal">(<?= te('optional') ?>)</small></label>
                <input type="date" class="form-control" name="next_date" id="fuNext">
                <div class="d-flex gap-2 mt-1 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-fun="1"><?= te('Tomorrow') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-fun="3"><?= te('In 3 days') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-fun="7"><?= te('In a week') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-fun="0"><?= te('No next follow-up') ?></button>
                </div>
                <div class="mt-2 d-none" id="fuNextPurposeWrap">
                    <label class="form-label small fw-bold mb-1" for="fuNextPurpose"><?= te('Reason for the next follow-up') ?> <span class="text-muted fw-normal">(<?= te('optional') ?>)</span></label>
                    <input type="text" class="form-control" name="next_purpose" id="fuNextPurpose" maxlength="255" placeholder="<?= te('e.g. Bring the price list') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= te('Cancel') ?></button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= te('Save') ?></button>
            </div>
        </form>
    </div></div>
</div>

<!-- Set / change a follow-up date -->
<div class="modal fade" id="fuSetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="fuSetTitle"><i class="bi bi-calendar-plus me-1"></i></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form id="fuSetForm" autocomplete="off" novalidate>
            <div class="modal-body">
                <input type="hidden" name="action" id="fuSetAction">
                <input type="hidden" name="visit_id" id="fuSetVisit">
                <input type="hidden" name="follow_up_id" id="fuSetId">
                <div class="p-2 rounded mb-3 fu-info" id="fuSetInfo"></div>
                <label class="form-label fw-bold" for="fuSetDate"><?= te('Follow up on') ?> <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="due_date" id="fuSetDate" required>
                <div class="d-flex gap-2 mt-1 mb-3 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-fus="1"><?= te('Tomorrow') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-fus="3"><?= te('In 3 days') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-fus="7"><?= te('In a week') ?></button>
                </div>
                <label class="form-label fw-bold" for="fuSetPurpose"><?= te('Reason') ?> <small class="text-muted fw-normal">(<?= te('optional') ?>)</small></label>
                <input type="text" class="form-control" name="purpose" id="fuSetPurpose" maxlength="255" placeholder="<?= te('e.g. Bring the price list') ?>">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= te('Cancel') ?></button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= te('Save') ?></button>
            </div>
        </form>
    </div></div>
</div>
<style>
.fu-info { background:#e7f0ff; border:1px solid #b6ccfe; }
.fu-toggle { min-width: 0 !important; white-space: normal; font-size: .8rem; line-height: 1.2; padding-left: 2px !important; padding-right: 2px !important; }
@media (max-width: 575.98px) { #fuDoneModal .form-control, #fuSetModal .form-control { font-size: 16px; } }
</style>
<script>
window.FrFollow = (function () {
    const API = <?= json_encode(buildUrl('api/field_reports/')) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
    const TODAY = <?= json_encode(date('Y-m-d')) ?>, L = <?= json_encode($L) ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dmy = s => { const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; };
    const addDays = (ymd, n) => { const [y, m, d] = ymd.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10); };
    const modal = id => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
    const info = fu => `<div class="fw-bold">${esc(fu.client_name)}</div>`
        + (fu.due_date ? `<small class="text-muted">${esc(L.dueOn.replace('%date%', dmy(fu.due_date)))}${fu.purpose ? ' · ' + esc(fu.purpose) : ''}</small>` : '');
    let onDone = null, onSet = null;

    function post(file, data, $btn, cb) {
        const orig = $btn ? $btn.html() : '';
        if ($btn) $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
        return $.ajax({ url: API + file, type: 'POST', data: Object.assign({ _csrf: CSRF }, data), dataType: 'json' })
            .done(res => {
                if (res.success) { cb && cb(res); Swal.fire({ icon: 'success', title: res.message, timer: 1600, showConfirmButton: false }); }
                else Swal.fire({ icon: 'error', title: L.error, text: res.message });
            })
            .fail(x => Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.serverError }))
            .always(() => { if ($btn) $btn.prop('disabled', false).html(orig); });
    }

    $('#fuNext').on('input change', function () { $('#fuNextPurposeWrap').toggleClass('d-none', !this.value); });
    $('[data-fun]').on('click', function () { const n = +$(this).data('fun'); $('#fuNext').val(n ? addDays(TODAY, n) : '').trigger('change'); });
    $('[data-fus]').on('click', function () {
        const v = addDays(TODAY, +$(this).data('fus')), min = $('#fuSetDate').attr('min') || TODAY;
        $('#fuSetDate').val(v < min ? min : v);
    });
    $('#fuDoneForm').on('submit', function (e) {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(this).entries());
        if (!data.next_date) delete data.next_purpose;
        post('followup_done.php', Object.assign(data, { done: 1 }), $(this).find('[type=submit]'), res => { modal('fuDoneModal').hide(); onDone && onDone(res); });
    });
    $('#fuSetForm').on('submit', function (e) {
        e.preventDefault();
        if (!$('#fuSetDate').val()) { Swal.fire({ icon: 'warning', title: L.chooseDate }); return; }
        post('followup_save.php', Object.fromEntries(new FormData(this).entries()), $(this).find('[type=submit]'), res => { modal('fuSetModal').hide(); onSet && onSet(res); });
    });

    return {
        done(fu, cb) {
            onDone = cb;
            $('#fuDoneForm')[0].reset();
            $('#fuDoneId').val(fu.follow_up_id); $('#fuDoneInfo').html(info(fu));
            $('#fuNext').attr('min', TODAY).trigger('change');
            modal('fuDoneModal').show();
        },
        set(opts, cb) {
            onSet = cb;
            $('#fuSetForm')[0].reset();
            const moving = !!opts.follow_up_id;
            $('#fuSetAction').val(moving ? 'reschedule' : 'add');
            $('#fuSetVisit').val(moving ? '' : opts.visit_id); $('#fuSetId').val(moving ? opts.follow_up_id : '');
            $('#fuSetTitle').html(`<i class="bi bi-calendar-plus me-1"></i>${esc(moving ? L.moveTitle : L.setTitle)}`);
            const min = opts.visit_date && opts.visit_date > TODAY ? opts.visit_date : TODAY;
            $('#fuSetDate').attr('min', min).val(moving && opts.due_date >= min ? opts.due_date : '');
            $('#fuSetPurpose').val(moving ? (opts.purpose || '') : '');
            $('#fuSetInfo').html(info(Object.assign({}, opts, { due_date: moving ? opts.due_date : '' })));
            modal('fuSetModal').show();
        },
        cancel(fu, cb) {
            Swal.fire({ title: L.cancelQ, text: L.cancelText, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: L.yesCancel, cancelButtonText: L.no })
                .then(x => { if (x.isConfirmed) post('followup_save.php', { action: 'cancel', follow_up_id: fu.follow_up_id }, null, cb); });
        },
        reopen(fu, cb) { post('followup_done.php', { follow_up_id: fu.follow_up_id, done: 0 }, null, cb); },
    };
})();
</script>
        <?php
    }
}
