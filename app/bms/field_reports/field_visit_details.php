<?php
// Customer Visits — View details of one visit (customer_visit_followups_plan.md D6): every
// field of the row, who recorded / changed it, the follow-up timeline of this customer
// (all visits to the same phone the viewer may see) and the customer's other visits.
// Owner, or an admin once that day's report was submitted (frCanSee()). GET id.
ob_start();
$page_title = 'Visit details';
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/core/field_visit_followups.php';
require_once ROOT_DIR . '/core/vendor_context.php';
autoEnforcePermission('field_visits');
includeHeader();

$visitId   = (int)($_GET['id'] ?? 0);
$visit     = $visitId > 0 ? frGetVisit($pdo, $visitId) : null;
$allowed   = $visit && frCanSee($pdo, $visit);
$is_admin  = isAdmin();   // fresh call, after header.php
$isVendor  = isPlatformVendorContext();
$listUrl   = getUrl('field_reports');
if ($allowed) logActivity($pdo, (int)$_SESSION['user_id'], 'View field visit', "Viewed field visit #$visitId ({$visit['client_name']})");
?>
<div class="container-fluid mt-3" id="fvdPage">
<?php if (!$allowed): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
        <i class="bi bi-geo-alt text-primary" style="font-size:3rem"></i>
        <h5 class="mt-3"><?= $visit ? te('You do not have access to this visit.') : te('Visit not found') ?></h5>
        <a href="<?= $listUrl ?>" class="btn btn-primary mt-2"><i class="bi bi-arrow-left me-1"></i><?= te('Back to Customer Visits') ?></a>
    </div></div>
</div>
<?php http_response_code($visit ? 403 : 404); includeFooter(); return; endif; ?>

    <!-- Header -->
    <div class="d-flex align-items-start gap-2 mb-3 fvd-sticky">
        <div class="flex-grow-1 min-w-0">
            <h4 class="mb-0 text-break"><i class="bi bi-person-lines-fill text-primary me-2"></i><span id="hName"><?= safe_output($visit['client_name']) ?></span></h4>
            <small class="text-muted"><?= te('Visit details') ?> · <span id="hSub"></span></small>
        </div>
        <div class="d-none d-sm-flex gap-2 flex-shrink-0 no-print" id="hActions"></div>
        <div class="dropdown d-sm-none flex-shrink-0 no-print">
            <button class="btn btn-primary btn-sm dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill me-1"></i><?= te('Actions') ?></button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" id="hMenu"></ul>
        </div>
    </div>

    <div id="fvdLoading" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span><?= te('Loading...') ?></div>
    <div class="row g-3 d-none" id="fvdBody">
        <!-- Left: the client at a glance -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-light border-bottom"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-person me-1"></i><?= te('Client') ?></h6></div>
                <div class="card-body p-0"><table class="table table-sm table-borderless mb-0 fvd-kv" id="clientInfo"></table></div>
            </div>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-light border-bottom"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-clock-history me-1"></i><?= te('Record information') ?></h6></div>
                <div class="card-body p-0"><table class="table table-sm table-borderless mb-0 fvd-kv" id="recordInfo"></table></div>
            </div>
        </div>

        <!-- Right: follow-ups, the visit itself, the customer's other visits -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3 border-start border-4 border-primary">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-telephone-forward me-1"></i><?= te('Next follow-up') ?></h6>
                </div>
                <div class="card-body" id="nextFollow"></div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-light border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-list-check me-1"></i><?= te('Follow-up history') ?> <span class="badge bg-primary ms-1" id="fuCount">0</span></h6>
                    <small class="text-muted d-none d-md-inline"><?= te('All follow-ups with this customer') ?></small>
                </div>
                <ul class="list-group list-group-flush" id="fuTimeline"></ul>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-light border-bottom"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-geo-alt me-1"></i><?= te('Visit information') ?></h6></div>
                <div class="card-body p-0"><table class="table table-sm table-borderless mb-0 fvd-kv" id="visitInfo"></table></div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-light border-bottom"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-journal-text me-1"></i><?= te('Visits to this customer') ?> <span class="badge bg-primary ms-1" id="visitCount">0</span></h6></div>
                <div class="card-body p-2">
                    <div id="visitsTableWrap">
                    <table class="table table-hover align-middle w-100" id="custVisits" data-bms-cards="off"><!-- own phone cards: #custVisitCards -->
                        <thead class="fvd-thead"><tr>
                            <th><?= te('Date') ?></th><th><?= te('Time') ?></th><?php if ($is_admin): ?><th><?= te('Staff') ?></th><?php endif; ?>
                            <th><?= te('Place visited') ?></th><th><?= te('Response') ?></th><th><?= te('Follow-up') ?></th><th class="text-end no-print"><?= te('Actions') ?></th>
                        </tr></thead><tbody></tbody>
                    </table>
                    </div>
                    <div class="row g-2 d-none" id="custVisitCards"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media (max-width: 767.98px) { .fvd-sticky { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 6px 0; } }
.fvd-kv th { width: 40%; color: #6c757d; font-weight: 600; padding-left: 12px; white-space: nowrap; }
.fvd-kv td { word-break: break-word; padding-right: 12px; }
.fvd-kv .btn { min-width: 0; }   /* global .btn{min-width:85px} pushed Call/WhatsApp off a phone screen */
@media (max-width: 575.98px) {   /* phones: label above its value, nothing wider than the screen */
    .fvd-kv tr { display: block; padding: 4px 12px; border-bottom: 1px solid #f1f3f5; }
    .fvd-kv th, .fvd-kv td { display: block; width: 100%; padding: 0; white-space: normal; }
    .fvd-kv th { font-size: .78rem; }
    #fvdPage .fu-actions .btn { flex: 1 1 auto; }
}
.fvd-thead th { background: #fff !important; color: #212529; border-bottom: 2px solid #dee2e6; font-weight: 600; }
.fr-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.72rem; font-weight:600; }
.fr-yes { background:#0d6efd; color:#fff; } .fr-no { background:#e9ecef; color:#495057; } .fr-svc { background:#cfe2ff; color:#084298; }
.fu-st { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.72rem; font-weight:600; }
.fu-st-pending { background:#cfe2ff; color:#084298; } .fu-st-done { background:#052c65; color:#fff; } .fu-st-cancelled { background:#6c757d; color:#fff; }
.fu-item.fu-current { background: #f5f9ff; }
.fu-actions .btn { min-width: 0; }
#hActions .btn { min-width: 0; }
tr.fvd-current td { background: #e7f0ff !important; }
@media print {
    .no-print, header, nav, .navbar, footer, .sidebar, #sidebar { display: none !important; }
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #dee2e6 !important; }
    #visitsTableWrap { display: block !important; } #custVisitCards { display: none !important; }
    .col-lg-4, .col-lg-8 { width: 100% !important; }
}
</style>

<script>
$(function () {
    const VISIT_ID = <?= $visitId ?>, IS_ADMIN = <?= json_encode($is_admin) ?>, IS_VENDOR = <?= json_encode($isVendor) ?>;
    const API = '<?= buildUrl('api/field_reports/') ?>', CSRF = <?= json_encode(csrf_token()) ?>;
    const LIST_URL = '<?= $listUrl ?>', DETAILS_URL = '<?= getUrl('field_reports/details') ?>', CUSTOMER_URL = '<?= getUrl('customers/details') ?>';
    const BASE_DOMAIN = <?= json_encode((string)(tenantBaseDomain() ?? '')) ?>;
    const L = <?= json_encode([
        'error' => t('Error'), 'serverError' => t('Server error. Please try again.'), 'back' => t('Back'), 'print' => t('Print'),
        'edit' => t('Edit'), 'delete' => t('Delete'), 'markJoined' => t('Mark as joined'), 'unmarkJoined' => t('Remove joined mark'),
        'deleteQ' => t('Delete this visit?'), 'cannotUndo' => t('This cannot be undone.'), 'yesDelete' => t('Yes, Delete'), 'cancel' => t('Cancel'),
        'name' => t('Client name'), 'phone' => t('Phone'), 'business' => t('Business'), 'place' => t('Place visited'), 'staff' => t('Staff'),
        'date' => t('Date'), 'time' => t('Time'), 'response' => t('Response'), 'joined' => t('Joined our system'), 'gps' => t('GPS position'),
        'services' => t('Services offered'), 'given' => t('Given to the client'), 'notes' => t('Notes'), 'noGps' => t('No GPS'), 'gpsConfirmed' => t('GPS confirmed'),
        'cardShort' => t('Business card'), 'trialShort' => t('Trial link'), 'trainingShort' => t('Training'), 'yes' => t('Yes'), 'no' => t('No'),
        'joinedOn' => t('Joined on %date% (marked by %name%)'), 'notJoined' => t('Not yet'), 'company' => t('Company on the platform'), 'customer' => t('Customer'),
        'viewCustomer' => t('View customer'), 'recordedBy' => t('Recorded by'), 'recordedAt' => t('Recorded on'), 'changedBy' => t('Last changed by'),
        'changedAt' => t('Last changed on'), 'report' => t('Daily report'), 'submitted' => t('Submitted'), 'notSubmitted' => t('Not submitted yet'),
        'call' => t('Call'), 'whatsapp' => 'WhatsApp', 'visitNo' => t('Visit %n% of %t%'),
        'noFollow' => t('No follow-up is set for this visit.'), 'setFollow' => t('Set a follow-up'), 'markDone' => t('Mark done'),
        'changeDate' => t('Change date'), 'cancelFollow' => t('Cancel follow-up'), 'reopen' => t('Re-open'),
        'dueToday' => t('Follow up today'), 'dueTomorrow' => t('Follow up tomorrow'), 'dueIn' => t('Follow up in %d days'), 'daysLate' => t('%d days late'),
        'pending' => t('Not done yet'), 'done' => t('Done'), 'cancelled' => t('Cancelled'), 'noHistory' => t('No follow-ups yet.'),
        'by' => t('by %name%'), 'setBy' => t('Set by %name% on %date%'), 'newVisitRec' => t('New visit recorded'), 'openVisit' => t('Open that visit'),
        'onVisit' => t('On the visit of %date%'), 'reason' => t('Reason'), 'what' => t('What happened'),
        'viewDetails' => t('View details'), 'current' => t('This visit'), 'followUp' => t('Follow-up'), 'noRecords' => t('No visits recorded for this period.'),
        'interest' => array_map('t', frInterestLabels()), 'subdomainQ' => t('Company web address'),
        'subdomainHint' => t('The address they log in at, e.g. dukalajuma — leave blank if you do not know it yet.'), 'save' => t('Save'),
    ]) ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dmy = s => { const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; };
    const dmyt = s => { const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/); return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : dmy(s); };
    const waNum = p => { let d = String(p || '').replace(/\D/g, ''); if (d.length === 10 && d[0] === '0') d = '255' + d.slice(1); return d; };
    const interestColors = { interested: 'success', thinking: 'warning', not_interested: 'danger' };
    const interestBadge = (i, label) => i ? `<span class="badge bg-${interestColors[i]}${i === 'thinking' ? ' text-dark' : ''}">${esc(label)}</span>` : '<span class="text-muted">—</span>';
    const ynL = (label, v) => `<span class="fr-badge ${v ? 'fr-yes' : 'fr-no'}">${v ? '✓' : '✗'} ${esc(label)}</span>`;
    const kv = rows => rows.filter(Boolean).map(([k, v]) => `<tr><th>${esc(k)}</th><td>${v}</td></tr>`).join('');
    const dueBadge = d => d < 0 ? `<span class="badge bg-danger">${esc(L.daysLate.replace('%d', -d))}</span>`
        : `<span class="badge bg-primary">${esc(d === 0 ? L.dueToday : d === 1 ? L.dueTomorrow : L.dueIn.replace('%d', d))}</span>`;
    let data = null, visitsTable = null;

    function load() {
        $.getJSON(API + 'detail.php', { visit_id: VISIT_ID }).done(res => {
            if (!res.success) { Swal.fire({ icon: 'error', title: L.error, text: res.message }); return; }
            data = res; render();
        }).fail(x => Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.serverError }));
    }

    function render() {
        const v = data.visit, n = data.visits.length, pos = data.visits.slice().reverse().findIndex(x => x.is_current) + 1;
        $('#fvdLoading').addClass('d-none'); $('#fvdBody').removeClass('d-none');
        $('#hName').text(v.client_name);
        $('#hSub').text(dmy(v.visit_date) + (v.visit_time ? ' ' + v.visit_time : '') + (n > 1 ? ' · ' + L.visitNo.replace('%n%', pos).replace('%t%', n) : ''));
        renderActions(v);

        const phone = v.client_phone ? `${esc(v.client_phone)}<div class="mt-1 d-flex gap-1 no-print">
                <a class="btn btn-sm btn-outline-primary" href="tel:${esc(v.client_phone)}"><i class="bi bi-telephone me-1"></i>${L.call}</a>
                <a class="btn btn-sm btn-outline-primary" href="https://wa.me/${waNum(v.client_phone)}" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1"></i>${L.whatsapp}</a></div>` : '<span class="text-muted">—</span>';
        const gps = v.latitude !== null
            ? `<a href="https://maps.google.com/?q=${v.latitude},${v.longitude}" target="_blank" rel="noopener" class="text-decoration-none"><i class="bi ${v.gps_verified ? 'bi-geo-alt-fill text-success' : 'bi-pin-map text-warning'}"></i> ${Number(v.latitude).toFixed(6)}, ${Number(v.longitude).toFixed(6)}</a>${v.gps_accuracy_m !== null ? ` <span class="text-muted">±${v.gps_accuracy_m} m</span>` : ''}${v.gps_verified ? ` <span class="fr-badge fr-yes">${esc(L.gpsConfirmed)}</span>` : ''}`
            : `<span class="text-muted"><i class="bi bi-slash-circle"></i> ${esc(L.noGps)}</span>`;
        const joined = v.joined
            ? `<span class="fr-badge fr-yes">✓ ${esc(L.yes)}</span> <small class="text-muted">${esc(L.joinedOn.replace('%date%', dmy(v.joined_at)).replace('%name%', v.joined_marked_by_name || '—'))}</small>`
            : `<span class="fr-badge fr-no">${esc(L.notJoined)}</span>`;
        $('#clientInfo').html(kv([
            [L.name, esc(v.client_name)], [L.phone, phone], [L.business, esc(v.business_label)],
            [L.place, esc(v.location)], [L.gps, gps],
            IS_ADMIN ? [L.staff, esc(v.staff_name)] : null,
            [L.response, interestBadge(v.interest, v.interest_label)], [L.joined, joined],
            IS_VENDOR && v.tenant_subdomain ? [L.company, esc(v.tenant_subdomain)] : null,
            IS_VENDOR && v.customer_id ? [L.customer, `<a href="${CUSTOMER_URL}?id=${v.customer_id}"><i class="bi bi-person-badge me-1"></i>${esc(L.viewCustomer)}</a>`] : null,
        ]));
        $('#recordInfo').html(kv([
            [L.recordedBy, esc(v.created_by_name || v.staff_name || '—')], [L.recordedAt, esc(dmyt(v.created_at))],
            v.updated_at ? [L.changedBy, esc(v.updated_by_name || '—')] : null, v.updated_at ? [L.changedAt, esc(dmyt(v.updated_at))] : null,
            [L.report, v.day_submitted ? `<span class="fr-badge fr-yes">${esc(L.submitted)}</span>` : `<span class="fr-badge fr-no">${esc(L.notSubmitted)}</span>`],
        ]));
        $('#visitInfo').html(kv([
            [L.date, esc(dmy(v.visit_date))], [L.time, esc(v.visit_time || '—')],
            [L.services, (v.services || []).length ? v.services.map(s => `<span class="fr-badge fr-svc">${esc(s.name)}</span>`).join(' ') : '<span class="text-muted">—</span>'],
            [L.given, `<div class="d-flex flex-wrap gap-1">${ynL(L.cardShort, v.gave_business_card)} ${ynL(L.trialShort, v.gave_trial_link)} ${ynL(L.trainingShort, v.gave_training)}</div>`],
            [L.notes, v.notes ? esc(v.notes).replace(/\n/g, '<br>') : '<span class="text-muted">—</span>'],
        ]));
        renderNext(v);
        renderTimeline();
        renderVisits();
    }

    function renderActions(v) {
        const btn = [], menu = [];
        btn.push(`<button class="btn btn-secondary btn-sm px-2 shadow-sm" data-h="back"><i class="bi bi-arrow-left"></i> ${L.back}</button>`);
        menu.push(`<li><button class="dropdown-item py-2 rounded" data-h="back"><i class="bi bi-arrow-left text-secondary me-2"></i>${L.back}</button></li>`);
        btn.push(`<button class="btn btn-outline-primary btn-sm px-2 shadow-sm" data-h="print"><i class="bi bi-printer"></i> ${L.print}</button>`);
        menu.push(`<li><button class="dropdown-item py-2 rounded" data-h="print"><i class="bi bi-printer text-primary me-2"></i>${L.print}</button></li>`);
        if (v.can_edit) {
            const jl = v.joined ? L.unmarkJoined : L.markJoined, ji = v.joined ? 'bi-person-x' : 'bi-person-check';
            btn.push(`<button class="btn btn-outline-primary btn-sm px-2 shadow-sm" data-h="joined"><i class="bi ${ji}"></i> ${jl}</button>`);
            menu.push(`<li><button class="dropdown-item py-2 rounded" data-h="joined"><i class="bi ${ji} text-primary me-2"></i>${jl}</button></li>`);
            btn.push(`<a class="btn btn-primary btn-sm px-2 shadow-sm" href="${LIST_URL}?edit=${v.visit_id}&date=${v.visit_date}"><i class="bi bi-pencil"></i> ${L.edit}</a>`);
            menu.push(`<li><a class="dropdown-item py-2 rounded" href="${LIST_URL}?edit=${v.visit_id}&date=${v.visit_date}"><i class="bi bi-pencil text-primary me-2"></i>${L.edit}</a></li>`);
        }
        if (v.can_delete) {
            btn.push(`<button class="btn btn-outline-danger btn-sm px-2 shadow-sm" data-h="delete"><i class="bi bi-trash"></i> ${L.delete}</button>`);
            menu.push(`<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" data-h="delete"><i class="bi bi-trash text-danger me-2"></i>${L.delete}</button></li>`);
        }
        $('#hActions').html(btn.join('')); $('#hMenu').html(menu.join(''));
    }

    function renderNext(v) {
        const p = data.pending;
        if (!p) {
            $('#nextFollow').html(`<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted">${esc(L.noFollow)}</span>
                ${v.can_edit ? `<button class="btn btn-primary btn-sm no-print" data-fu="set"><i class="bi bi-calendar-plus me-1"></i>${L.setFollow}</button>` : ''}</div>`);
            return;
        }
        $('#nextFollow').html(`<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div><div class="fs-5 fw-bold text-primary">${esc(dmy(p.due_date))} ${dueBadge(p.days_left)}</div>
                    ${p.purpose ? `<div class="small mt-1"><span class="text-muted">${esc(L.reason)}:</span> ${esc(p.purpose)}</div>` : ''}
                    ${p.created_by_name ? `<div class="small text-muted">${esc(L.setBy.replace('%name%', p.created_by_name).replace('%date%', dmy(p.created_at)))}</div>` : ''}</div>
                ${p.can_change ? `<div class="d-flex gap-1 flex-wrap fu-actions no-print">
                    <button class="btn btn-sm btn-primary" data-fu="done" data-id="${p.follow_up_id}"><i class="bi bi-check2 me-1"></i>${L.markDone}</button>
                    <button class="btn btn-sm btn-outline-primary" data-fu="move" data-id="${p.follow_up_id}"><i class="bi bi-calendar-event me-1"></i>${L.changeDate}</button>
                    <button class="btn btn-sm btn-outline-danger" data-fu="cancel" data-id="${p.follow_up_id}"><i class="bi bi-x-circle me-1"></i>${L.cancelFollow}</button></div>` : ''}
            </div>`);
    }

    function renderTimeline() {
        const list = data.follow_ups;
        $('#fuCount').text(list.length);
        if (!list.length) { $('#fuTimeline').html(`<li class="list-group-item text-muted">${esc(L.noHistory)}</li>`); return; }
        $('#fuTimeline').html(list.map(f => {
            const other = f.visit_id !== VISIT_ID;
            const lines = [];
            if (f.purpose) lines.push(`<div><span class="text-muted">${esc(L.reason)}:</span> ${esc(f.purpose)}</div>`);
            if (f.status === 'done') {
                lines.push(`<div><i class="bi bi-check2-circle text-primary"></i> ${esc(f.method_label || L.done)}${f.done_by_name ? ' · ' + esc(L.by.replace('%name%', f.done_by_name)) : ''} · ${esc(dmyt(f.done_at))}</div>`);
                if (f.outcome) lines.push(`<div><span class="text-muted">${esc(L.what)}:</span> ${esc(f.outcome).replace(/\n/g, '<br>')}</div>`);
                if (f.result_visit_id) lines.push(`<div><i class="bi bi-geo-alt text-primary"></i> ${esc(L.newVisitRec)} — <a href="${DETAILS_URL}?id=${f.result_visit_id}">${esc(L.openVisit)}</a></div>`);
            }
            if (f.created_by_name) lines.push(`<div class="text-muted">${esc(L.setBy.replace('%name%', f.created_by_name).replace('%date%', dmy(f.created_at)))}</div>`);
            const acts = [];
            if (f.can_change && other) {
                acts.push(`<button class="btn btn-sm btn-outline-primary" data-fu="done" data-id="${f.follow_up_id}"><i class="bi bi-check2 me-1"></i>${L.markDone}</button>`);
            }
            if (f.can_reopen) {   // detail.php: the visit's last finished one, none open
                acts.push(`<button class="btn btn-sm btn-outline-secondary" data-fu="reopen" data-id="${f.follow_up_id}"><i class="bi bi-arrow-counterclockwise me-1"></i>${L.reopen}</button>`);
            }
            return `<li class="list-group-item fu-item${f.status === 'pending' && !other ? ' fu-current' : ''}">
                <div class="d-flex justify-content-between gap-2 flex-wrap">
                    <div class="fw-bold">${esc(dmy(f.due_date))} <span class="fu-st fu-st-${f.status}">${esc(L[f.status])}</span>${f.status === 'pending' ? ' ' + dueBadge(f.days_left) : ''}</div>
                    <small class="text-muted">${other ? `<a href="${DETAILS_URL}?id=${f.visit_id}">${esc(L.onVisit.replace('%date%', dmy(f.visit_date)))}</a>` : esc(L.current)}</small>
                </div>
                <div class="small mt-1">${lines.join('')}</div>
                ${acts.length ? `<div class="d-flex gap-1 mt-2 fu-actions no-print">${acts.join('')}</div>` : ''}
            </li>`;
        }).join(''));
    }

    const fuTxt = r => !r.follow_up_date ? '<span class="text-muted">—</span>' : esc(dmy(r.follow_up_date)) + (r.follow_up_done ? ' <i class="bi bi-check-circle-fill text-success"></i>' : '');
    function renderVisitCards(rows) {
        $('#custVisitCards').html(rows.map(r => `<div class="col-12"><div class="card border-0 shadow-sm${r.is_current ? ' border-start border-4 border-primary' : ''}">
            <div class="card-body p-2" style="font-size:.85rem">
                <div class="d-flex justify-content-between"><b>${esc(dmy(r.visit_date))} ${esc(r.visit_time)}</b>${r.is_current ? `<span class="fr-badge fr-svc">${esc(L.current)}</span>` : ''}</div>
                <div><i class="bi bi-geo-alt text-primary"></i> ${esc(r.location)}</div>
                ${IS_ADMIN ? `<div class="text-muted">${esc(L.staff)}: ${esc(r.staff_name)}</div>` : ''}
                <div class="mt-1">${interestBadge(r.interest, r.interest_label)}${r.joined ? ` <span class="fr-badge fr-yes">✓ ${esc(L.joined)}</span>` : ''}</div>
                ${r.follow_up_date ? `<div class="small mt-1"><i class="bi bi-calendar-event text-primary"></i> ${esc(L.followUp)}: ${fuTxt(r)}</div>` : ''}
            </div>
            ${r.is_current ? '' : `<div class="card-footer bg-white border-top p-0"><div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                <a class="btn btn-sm btn-outline-primary" href="${DETAILS_URL}?id=${r.visit_id}" style="flex:1;min-width:0;padding:3px 4px" title="${esc(L.viewDetails)}" aria-label="${esc(L.viewDetails)}"><i class="bi bi-eye"></i></a></div></div>`}
        </div></div>`).join(''));
    }

    function renderVisits() {
        const list = data.visits;
        $('#visitCount').text(list.length);
        const view = r => r.is_current ? `<span class="fr-badge fr-svc">${esc(L.current)}</span>`
            : `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="min-width:0"><i class="bi bi-gear-fill me-1"></i></button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2"><li><a class="dropdown-item py-2 rounded" href="${DETAILS_URL}?id=${r.visit_id}"><i class="bi bi-eye text-primary me-2"></i>${L.viewDetails}</a></li></ul></div>`;
        if (!visitsTable) {
            visitsTable = $('#custVisits').DataTable({
                responsive: false, scrollX: true, pageLength: 25, order: [], dom: 'rtip',
                language: { emptyTable: L.noRecords, zeroRecords: L.noRecords },
                columnDefs: [{ targets: -1, orderable: false }],
                createdRow: (tr, d) => { if (d._row && d._row.is_current) $(tr).addClass('fvd-current'); },
                drawCallback: function () { renderVisitCards(this.api().rows({ page: 'current' }).data().toArray().map(r => r._row)); }
            });
        }
        visitsTable.clear().rows.add(list.map(r => {
            const cells = [esc(dmy(r.visit_date)), esc(r.visit_time || '—')];
            if (IS_ADMIN) cells.push(esc(r.staff_name));
            cells.push(esc(r.location) + (r.notes ? `<div class="small text-muted text-truncate" style="max-width:280px">${esc(r.notes)}</div>` : ''),
                interestBadge(r.interest, r.interest_label) + (r.joined ? ` <span class="fr-badge fr-yes">✓ ${esc(L.joined)}</span>` : ''), fuTxt(r), view(r));
            cells._row = r;
            return cells;
        })).draw();
        applyView();
    }
    function applyView() {
        const m = window.innerWidth < 768;
        $('#visitsTableWrap').toggleClass('d-none', m); $('#custVisitCards').toggleClass('d-none', !m);
        if (!m && visitsTable) visitsTable.columns.adjust();
    }
    $(window).on('resize', applyView);

    function post(file, payload, after) {
        $.ajax({ url: API + file, type: 'POST', data: Object.assign({ _csrf: CSRF }, payload), dataType: 'json' })
            .done(res => {
                if (res.success) { after ? after(res) : load(); Swal.fire({ icon: 'success', title: res.message, timer: 1400, showConfirmButton: false }); }
                else Swal.fire({ icon: 'error', title: L.error, text: res.message });
            })
            .fail(x => Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.serverError }));
    }
    function askSubdomain() {
        return Swal.fire({ title: L.subdomainQ, text: L.subdomainHint, input: 'text', inputAttributes: { autocapitalize: 'off', autocomplete: 'off', maxlength: 63 },
            inputPlaceholder: 'dukalajuma' + (BASE_DOMAIN ? '.' + BASE_DOMAIN : ''), showCancelButton: true, confirmButtonText: L.save, cancelButtonText: L.cancel, confirmButtonColor: '#0d6efd' })
            .then(x => x.isConfirmed ? String(x.value || '').trim() : null);
    }

    $(document).on('click', '[data-h]', function () {
        const act = $(this).data('h'), v = data && data.visit;
        if (act === 'back') { if (document.referrer.indexOf(LIST_URL) !== -1 && history.length > 1) history.back(); else window.location.href = LIST_URL + '?date=' + (v ? v.visit_date : ''); }
        else if (act === 'print') window.print();
        else if (!v) return;
        else if (act === 'joined') {
            if (IS_VENDOR && !v.joined) askSubdomain().then(sub => { if (sub !== null) post('toggle_joined.php', { visit_id: v.visit_id, joined: 1, subdomain: sub }); });
            else post('toggle_joined.php', { visit_id: v.visit_id, joined: v.joined ? 0 : 1 });
        } else if (act === 'delete') {
            Swal.fire({ title: L.deleteQ, text: L.cannotUndo, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: L.yesDelete, cancelButtonText: L.cancel })
                .then(x => { if (x.isConfirmed) post('delete.php', { visit_id: v.visit_id }, () => setTimeout(() => { window.location.href = LIST_URL + '?date=' + v.visit_date; }, 1200)); });
        }
    });
    $(document).on('click', '[data-fu]', function () {
        if (!data) return;
        const act = $(this).data('fu'), id = +$(this).data('id'), v = data.visit;
        const f = data.follow_ups.find(x => x.follow_up_id === id) || (data.pending && data.pending.follow_up_id === id ? data.pending : null);
        const fu = f ? Object.assign({ client_name: v.client_name }, f) : null;
        if (act === 'set') FrFollow.set({ visit_id: v.visit_id, visit_date: v.visit_date, client_name: v.client_name }, load);
        else if (!fu) return;
        else if (act === 'done') FrFollow.done(fu, load);
        else if (act === 'move') FrFollow.set(Object.assign({ visit_date: v.visit_date }, fu), load);
        else if (act === 'cancel') FrFollow.cancel(fu, load);
        else if (act === 'reopen') FrFollow.reopen(fu, load);
    });
    load();
});
</script>

<?php require_once ROOT_DIR . '/includes/field_reports/followup_kit.php'; frRenderFollowUpKit(); ?>
<?php includeFooter(); ?>
