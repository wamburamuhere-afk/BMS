<?php
/**
 * includes/restaurant/form_kit.php — ONE professional layout for every restaurant
 * form with more than three fields (owner-approved 2026-11-03).
 *
 *   <div class="modal fade rf-modal" …>
 *     <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
 *       … modal-body:
 *         <div class="rf-sec">
 *           <div class="rf-sec-title"><i class="bi bi-person"></i> Customer</div>
 *           <div class="rf-grid">
 *             <div class="rf-field">            ← half width on desktop
 *             <div class="rf-field rf-full">    ← full width
 *         …
 *       modal-footer.rf-foot: [Cancel] [Save]   (stays visible; full-width on a phone)
 *
 *  - Sections with small titles, separated by thin lines.
 *  - Two columns from 768px, one column on a phone (the modal is full screen there).
 *  - Labels above, same size; required = red *, help text under its field (.rf-help).
 *  - Every <select class="rf-select2"> becomes a searchable Select2 inside its modal
 *    (click → see the options → type to narrow them).
 *  - Money fields: <div class="input-group rf-money"><span class="input-group-text rf-cur">TSh</span>…
 *    The prefix is the company currency (Settings), TSh by default — rfMoneyPrefix().
 *  - Choice cards (.rf-choices / .rf-choice) for important radio choices.
 * Sizes are in px on purpose: the app's global stylesheet changes the root size.
 * Create and Edit use the same modal; only the title changes.
 */

if (!function_exists('rfMoneyPrefix')) {
    /** The currency shown in front of money fields: the company currency, TSh by default. */
    function rfMoneyPrefix(): string
    {
        $s = function_exists('bmsCurrencySymbol') ? trim(bmsCurrencySymbol()) : 'TSh';
        return $s !== '' ? $s : 'TSh';
    }
}

if (!function_exists('rfKitAssets')) {
    /** Print the shared CSS + JS once per page. */
    function rfKitAssets(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
<style>
.rf-modal .modal-content { border: 0; border-radius: 14px; overflow: hidden; }
.rf-modal .modal-header { padding: 14px 20px; }
.rf-modal .modal-title { font-size: 17px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.rf-modal .modal-body { padding: 18px 20px; font-size: 14px; }
/* A <form> wrapping body + footer: keep Bootstrap's scrollable body and the footer in view. */
.rf-modal .modal-content > form { display: flex; flex-direction: column; min-height: 0; flex: 1 1 auto; overflow: hidden; }
.rf-modal .modal-content > form > .modal-body { overflow-y: auto; }
.rf-sec { padding-bottom: 16px; margin-bottom: 16px; border-bottom: 1px solid #eef0f3; }
.rf-sec:last-of-type { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }
.rf-sec-title { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #6c757d; margin-bottom: 10px; }
.rf-sec-title i { color: #0d6efd; font-size: 13px; }
.rf-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px 16px; }
@media (min-width: 768px) { .rf-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.rf-grid > .rf-full { grid-column: 1 / -1; }
.rf-field { min-width: 0; }
.rf-field > .form-label, .rf-field .rf-label { display: block; font-size: 13px; font-weight: 600; color: #344054; margin-bottom: 5px; }
.rf-field .form-control, .rf-field .form-select { font-size: 14px; min-height: 40px; border-radius: 8px; }
.rf-field textarea.form-control { min-height: 72px; }
.rf-field .input-group > .form-control, .rf-field .input-group > .form-select { border-radius: 0 8px 8px 0; }
.rf-field .input-group > .input-group-text:first-child { border-radius: 8px 0 0 8px; }
.rf-field .input-group > .btn:last-child { border-radius: 0 8px 8px 0; min-width: 0; }
.rf-req { color: #dc3545; margin-left: 2px; }
.rf-opt { color: #98a2b3; font-weight: 400; font-size: 12px; }
.rf-help { font-size: 12px; color: #6c757d; margin-top: 4px; line-height: 1.4; }
.rf-money .rf-cur { font-weight: 600; font-size: 13px; color: #344054; background: #f8f9fa; min-width: 54px; justify-content: center; }
.rf-money .form-control { text-align: right; font-variant-numeric: tabular-nums; }
.rf-check { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 10px; min-height: 44px; }
.rf-check .form-check-input { margin: 0; width: 2.2em; height: 1.2em; flex: 0 0 auto; }
.rf-check label { margin: 0; font-size: 14px; }
.rf-choices { display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; }
@media (min-width: 576px) { .rf-choices.rf-c2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } .rf-choices.rf-c3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
.rf-choice { position: relative; display: flex; align-items: flex-start; gap: 10px; padding: 11px 12px; border: 1px solid #dee2e6; border-radius: 10px; cursor: pointer; background: #fff; margin: 0; font-size: 14px; transition: border-color .15s, background .15s; min-width: 0 !important; text-align: left; }
.rf-choice:hover { border-color: #b6ccfe; }
.rf-choice input[type=radio], .rf-choice input[type=checkbox]:not([role=switch]) { margin-top: 3px; flex: 0 0 auto; accent-color: #0d6efd; width: 16px; height: 16px; }
.rf-choice .form-switch .form-check-input[role=switch] { width: 2.2em; height: 1.2em; margin: 2px 0 0 0; float: none; cursor: pointer; }
.rf-choice .form-switch { flex: 0 0 auto; min-height: 0; }
.rf-choice .rf-ct { font-weight: 600; line-height: 1.3; }
.rf-choice .rf-cd { display: block; font-size: 12px; color: #6c757d; font-weight: 400; margin-top: 2px; }
.rf-choice:has(input:checked) { border-color: #0d6efd; background: #e7f0ff; }
.rf-choice.rf-on { border-color: #0d6efd; background: #e7f0ff; }
.rf-modal .modal-footer.rf-foot { padding: 12px 20px; gap: 8px; border-top: 1px solid #eef0f3; background: #fff; }
.rf-foot .btn { min-width: 110px; border-radius: 8px; font-weight: 600; margin: 0; }
@media (max-width: 575.98px) {
    .rf-modal .modal-content { border-radius: 0; }
    .rf-modal .modal-body { padding: 16px; }
    .rf-modal .modal-footer.rf-foot { display: flex; flex-wrap: nowrap; padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); }
    .rf-foot .btn { flex: 1 1 0; min-width: 0; padding: 11px 8px; }
}
/* Select2 inside the kit: same height and radius as the other fields. */
.rf-field .select2-container--bootstrap-5 .select2-selection { min-height: 40px; border-radius: 8px; font-size: 14px; display: flex; align-items: center; }
.rf-field .select2-container { width: 100% !important; }
.rf-field .is-invalid + .select2-container .select2-selection { border-color: #dc3545; }
/* Page-level form card (not a modal) — Purchases. */
.rf-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 18px 20px; }
@media (max-width: 575.98px) { .rf-card { padding: 14px; border-radius: 12px; } }
</style>
<script>
/** Turn every .rf-select2 inside `root` into a searchable Select2 (dropdown kept inside its modal). */
window.rfSelect2 = function (root) {
    if (!window.jQuery || !jQuery.fn.select2) return;
    const $root = jQuery(root || document);
    $root.find('select.rf-select2').each(function () {
        const $s = jQuery(this);
        if ($s.hasClass('select2-hidden-accessible')) return;
        const $modal = $s.closest('.modal');
        $s.select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $modal.length ? $modal : jQuery(document.body),
            placeholder: $s.data('placeholder') || $s.find('option[value=""]').first().text() || '',
            allowClear: $s.data('allow-clear') === true || $s.data('allow-clear') === 'true',
            minimumResultsForSearch: 0
        });
    });
};
/** After changing a Select2's options or value in code, refresh what it shows. */
window.rfRefresh = function (sel) { if (window.jQuery) jQuery(sel).trigger('change.select2'); };
jQuery(function () {
    rfSelect2(document);   // ready before any "edit" code sets a value
    jQuery(document).on('shown.bs.modal', '.rf-modal', function () { rfSelect2(this); });
});
</script>
        <?php
    }
}
