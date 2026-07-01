<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Admin / explicitly-granted only.
autoEnforcePermission('notification_rules');

$page_title = 'Notification Rules';
require_once __DIR__ . '/../../../header.php';
?>

<div class="container-fluid mt-4" id="nrApp">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-bell-fill text-primary me-2"></i>Notification Rules</h4>
            <p class="text-muted mb-0 small">Choose, per event, <strong>who</strong> is notified and on <strong>which channel</strong>. Only users who already have access to that area can be picked.</p>
        </div>
    </div>

    <!-- Global controls -->
    <div class="card border-0 shadow-sm mb-4" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
        <div class="card-body d-flex flex-wrap gap-4 align-items-center">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="g_master" onchange="setGlobal('notif_master_enabled', this.checked)">
                <label class="form-check-label fw-semibold" for="g_master">Master switch <span class="text-muted small">(all notifications)</span></label>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="g_email" onchange="setGlobal('enable_email_notifications', this.checked)">
                <label class="form-check-label fw-semibold" for="g_email">Email channel <span class="text-muted small">(global on/off)</span></label>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="g_digest" onchange="setGlobal('notif_digest_enabled', this.checked)">
                <label class="form-check-label fw-semibold" for="g_digest">AI daily digest <span class="text-muted small">(one summary email/day)</span></label>
            </div>
            <div class="text-muted small ms-auto"><i class="bi bi-info-circle me-1"></i>In-app always works; email also needs SMTP set in <a href="<?= getUrl('system_settings') ?>">Settings → Email</a>.</div>
        </div>
    </div>

    <div id="nrLoading" class="text-center py-5 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading events…</div>
    <div id="nrAccordion" class="accordion d-none"></div>
</div>

<!-- Add Target Modal -->
<div class="modal fade" id="addTargetModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i> Add Target</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="addTargetForm" autocomplete="off">
        <div class="modal-body">
          <input type="hidden" id="t_event_key" name="event_key">
          <div class="mb-2 small text-muted">Event: <strong id="t_event_label"></strong></div>

          <div class="mb-3">
            <label class="form-label">Notify <span class="text-danger">*</span></label>
            <select class="form-select" id="t_target_type" name="target_type">
              <option value="permission">Everyone with access</option>
              <option value="role">A specific role</option>
              <option value="user">A specific user</option>
            </select>
          </div>

          <div class="mb-3 d-none" id="t_role_wrap">
            <label class="form-label">Role <span class="text-danger">*</span></label>
            <select class="form-select select2-role" id="t_role" name="role_id" style="width:100%"></select>
          </div>

          <div class="mb-3 d-none" id="t_user_wrap">
            <label class="form-label">User <span class="text-danger">*</span></label>
            <select class="form-select select2-user" id="t_user" name="user_id" style="width:100%"></select>
            <div class="form-text">If the chosen user lacks access to this area, they simply won't be notified (rules can't grant access).</div>
          </div>

          <label class="form-label">Channels <span class="text-danger">*</span></label>
          <div class="d-flex gap-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="t_inapp" checked><label class="form-check-label" for="t_inapp">In-app</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="t_email"><label class="form-check-label" for="t_email">Email</label></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Add</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-people me-1"></i> Who gets notified</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="previewBody"></div>
    </div>
  </div>
</div>

<script>
const NR_API = '<?= buildUrl('api/notifications/rules_api.php') ?>';
let NR_DATA = { roles: [], users: [] };

function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function loadRules() {
    $('#nrLoading').removeClass('d-none'); $('#nrAccordion').addClass('d-none');
    $.getJSON(NR_API, { action: 'list' }, function (res) {
        if (!res.success) { Swal.fire({icon:'error',title:'Error',text:res.message||'Failed to load'}); return; }
        NR_DATA = res;
        $('#g_master').prop('checked', res.globals.notif_master_enabled === '1');
        $('#g_email').prop('checked', res.globals.enable_email_notifications === '1');
        $('#g_digest').prop('checked', res.globals.notif_digest_enabled === '1');
        renderAccordion(res.events);
        $('#nrLoading').addClass('d-none'); $('#nrAccordion').removeClass('d-none');
    }).fail(() => { $('#nrLoading').html('<span class="text-danger">Failed to load.</span>'); });
}

function renderAccordion(events) {
    // group by module
    const groups = {};
    events.forEach(e => { (groups[e.module || 'Other'] = groups[e.module || 'Other'] || []).push(e); });
    let html = '';
    let i = 0;
    Object.keys(groups).sort().forEach(mod => {
        i++;
        const items = groups[mod].map(eventCard).join('');
        html += `
        <div class="accordion-item">
          <h2 class="accordion-header">
            <button class="accordion-button ${i>1?'collapsed':''}" type="button" data-bs-toggle="collapse" data-bs-target="#nrg${i}">
              <i class="bi bi-folder2-open text-primary me-2"></i> ${esc(mod)} <span class="badge bg-primary rounded-pill ms-2">${groups[mod].length}</span>
            </button>
          </h2>
          <div id="nrg${i}" class="accordion-collapse collapse ${i===1?'show':''}">
            <div class="accordion-body p-2">${items}</div>
          </div>
        </div>`;
    });
    $('#nrAccordion').html(html);
}

function eventCard(e) {
    const chips = (e.rules || []).map(r => `
        <span class="badge bg-light text-dark border me-1 mb-1" style="font-weight:500;">
            ${esc(r.label)} <span class="text-muted">· ${esc(r.channels)}</span>
            <a href="#" class="text-danger ms-1" onclick="delRule(${r.id});return false;" title="Remove"><i class="bi bi-x-circle"></i></a>
        </span>`).join('');
    const noRules = (e.rules || []).length === 0
        ? `<span class="text-muted small fst-italic">No rule — defaults to in-app for everyone with access${e.scope_aware ? ' (project-scoped)' : ''}.</span>` : '';
    const off = e.is_active ? '' : 'opacity:.55;';
    return `
    <div class="card border-0 shadow-sm mb-2" style="${off}">
      <div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div style="min-width:240px;flex:1;">
            <div class="fw-semibold">${esc(e.title)}
              ${e.scope_aware ? '<span class="badge" style="background:#cfe2ff;color:#084298;">project-scoped</span>' : ''}
            </div>
            <div class="text-muted small">${esc(e.description || '')} <span class="badge bg-light text-muted border ms-1">${esc(e.event_key)}</span></div>
            <div class="mt-2">${chips}${noRules}</div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <div class="form-check form-switch" title="Enable/disable this event">
              <input class="form-check-input" type="checkbox" ${e.is_active?'checked':''} onchange="toggleEvent('${esc(e.event_key)}', this.checked)">
            </div>
            <div class="dropdown">
              <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button>
              <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                <li><button class="dropdown-item py-2 rounded" onclick="openAdd('${esc(e.event_key)}','${esc(e.title)}')"><i class="bi bi-person-plus text-primary me-2"></i>Add target</button></li>
                <li><button class="dropdown-item py-2 rounded" onclick="previewEvent('${esc(e.event_key)}','${esc(e.title)}')"><i class="bi bi-people text-primary me-2"></i>Preview recipients</button></li>
                <li><button class="dropdown-item py-2 rounded" onclick="testSend('${esc(e.event_key)}')"><i class="bi bi-send text-primary me-2"></i>Test send (to me)</button></li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>`;
}

function setGlobal(key, on) {
    $.post(NR_API, { action: 'set_global', key: key, value: on ? 1 : 0 }, function (res) {
        if (!res.success) Swal.fire({icon:'error',title:'Error',text:res.message});
    }, 'json');
}

function toggleEvent(key, on) {
    $.post(NR_API, { action: 'toggle_event', event_key: key, is_active: on ? 1 : 0 }, function (res) {
        if (!res.success) Swal.fire({icon:'error',title:'Error',text:res.message}); else loadRules();
    }, 'json');
}

function openAdd(key, title) {
    $('#t_event_key').val(key); $('#t_event_label').text(title);
    $('#t_target_type').val('permission').trigger('change');
    $('#t_inapp').prop('checked', true); $('#t_email').prop('checked', false);
    new bootstrap.Modal(document.getElementById('addTargetModal')).show();
}

$('#t_target_type').on('change', function () {
    $('#t_role_wrap').toggleClass('d-none', this.value !== 'role');
    $('#t_user_wrap').toggleClass('d-none', this.value !== 'user');
});

$('#addTargetForm').on('submit', function (e) {
    e.preventDefault();
    const tt = $('#t_target_type').val();
    const payload = {
        action: 'save', event_key: $('#t_event_key').val(), target_type: tt,
        channel_email: $('#t_email').is(':checked') ? 1 : 0,
        channel_inapp: $('#t_inapp').is(':checked') ? 1 : 0,
    };
    if (tt === 'role') payload.target_id = $('#t_role').val();
    if (tt === 'user') payload.target_id = $('#t_user').val();
    $.post(NR_API, payload, function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('addTargetModal')).hide();
            loadRules();
            Swal.fire({icon:'success',title:'Added',text:res.message,timer:1500,showConfirmButton:false});
        } else { Swal.fire({icon:'error',title:'Error',text:res.message}); }
    }, 'json');
});

function delRule(id) {
    Swal.fire({title:'Remove target?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Remove'})
      .then(r => { if (!r.isConfirmed) return;
        $.post(NR_API, { action: 'delete', id: id }, function (res) {
            if (res.success) { loadRules(); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); }
        }, 'json');
      });
}

function previewEvent(key, title) {
    $('#previewBody').html('<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Resolving…</div>');
    new bootstrap.Modal(document.getElementById('previewModal')).show();
    $.getJSON(NR_API, { action: 'preview', event_key: key }, function (res) {
        if (!res.success) { $('#previewBody').html('<div class="text-danger">'+esc(res.message)+'</div>'); return; }
        if (!res.count) { $('#previewBody').html('<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Nobody would be notified. Check the rule targets and that those users have access to <strong>'+esc(title)+'</strong>.</div>'); return; }
        let rows = res.recipients.map(r => `<tr>
            <td>${esc(r.name)} ${r.is_admin?'<span class="badge bg-primary ms-1">admin</span>':''}</td>
            <td class="text-muted">${esc(r.email||'—')}</td>
            <td>${r.channels.inapp?'<span class="badge" style="background:#cfe2ff;color:#084298;">In-app</span> ':''}${r.channels.email?'<span class="badge bg-primary">Email</span>':''}</td>
        </tr>`).join('');
        $('#previewBody').html('<p class="small text-muted">'+res.count+' recipient(s) for <strong>'+esc(title)+'</strong>:</p>'+
            '<table class="table table-sm align-middle"><thead><tr><th>Name</th><th>Email</th><th>Channels</th></tr></thead><tbody>'+rows+'</tbody></table>');
    });
}

function testSend(key) {
    Swal.fire({ title: 'Sending test…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.post(NR_API, { action: 'test_send', event_key: key }, function (res) {
        Swal.fire({ icon: res.success ? 'success' : 'error', title: res.success ? 'Sent' : 'Failed', text: res.message });
    }, 'json');
}

// Init Select2 for role/user pickers when modal opens
$('#addTargetModal').on('shown.bs.modal', function () {
    const roleOpts = '<option value="">-- Select role --</option>' + NR_DATA.roles.map(r => `<option value="${r.role_id}">${esc(r.role_name)}</option>`).join('');
    const userOpts = '<option value="">-- Select user --</option>' + NR_DATA.users.map(u => `<option value="${u.user_id}">${esc(u.name)}</option>`).join('');
    ['#t_role','#t_user'].forEach(sel => { if ($(sel).hasClass('select2-hidden-accessible')) $(sel).select2('destroy'); });
    $('#t_role').html(roleOpts); $('#t_user').html(userOpts);
    $('#t_role').select2({ theme:'bootstrap-5', dropdownParent: $('#addTargetModal'), placeholder:'Select role', allowClear:true, width:'100%' });
    $('#t_user').select2({ theme:'bootstrap-5', dropdownParent: $('#addTargetModal'), placeholder:'Type to search user', allowClear:true, width:'100%' });
});

$(document).ready(loadRules);
</script>

<?php require_once __DIR__ . '/../../../footer.php'; ?>
