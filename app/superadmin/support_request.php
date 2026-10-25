<?php
/**
 * app/superadmin/support_request.php — one request that reached BJP: the conversation
 * (internal notes included), reply / internal note, saved replies, assign, status,
 * priority, and the company's context in its own words (product, Simple Mode, Shop wording).
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../core/support_inbox.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();
$me = currentSuperadmin();
$h  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$id = ctype_digit((string)($_GET['id'] ?? '')) ? (int)$_GET['id'] : 0;
$t  = $id ? supportInboxGet($id) : null;
if (!$t) http_response_code(404);
$msgs = $t ? supportRequestMessages($id, true) : [];
$co = $t ? supportTenantContext((int)$t['tenant_id']) : [];
$agents = supportAgents();
$canned = supportCannedList(true);
$statuses = ['open' => 'Open', 'in_progress' => 'In progress', 'waiting_on_customer' => 'Waiting on company', 'resolved' => 'Resolved', 'closed' => 'Closed'];
$pageLabel = '';
if ($t && $t['page_key']) {
    $cat = helpNavCatalog()[$t['page_key']] ?? null;
    $pageLabel = $cat ? ($co['shop'] && $cat['shop_label'] ? $cat['shop_label'] : $cat['label']) : $t['page_key'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= $t ? $h($t['ref_no']) : 'Request' ?> | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .msg { border: 1px solid #dee2e6; border-radius: 8px; padding: 9px 12px; max-width: 88%; }
    .msg-bjp { align-self: flex-end; background: #f5f9ff; border-color: #cfe2ff; }
    .msg-note { align-self: flex-end; background: #f1f3f5; border: 1px dashed #adb5bd; }
    .side { border: 1px solid #b6ccfe; border-radius: 8px; }
    .side .hd { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; padding: 8px 12px; font-weight: 600; }
    .kv { display: flex; justify-content: space-between; gap: 8px; padding: 3px 0; font-size: .88rem; }
    .kv span:first-child { color: #6c757d; }
</style>
</head>
<body>
<?php renderSuperadminHeader('support', $me); ?>
<div class="container-fluid p-3">
    <a href="<?= $h(saUrl('support?tab=inbox')) ?>" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Inbox</a>
<?php if (!$t): ?>
    <div class="alert alert-warning mt-2">That request does not exist, or it is a company's internal request that was not sent to BJP.</div>
<?php else: ?>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
        <h5 class="mb-0 fw-bold"><?= $h($t['subject']) ?></h5>
        <span class="font-monospace small text-muted"><?= $h($t['ref_no']) ?><?= $t['forwarded_from'] ? ' · forwarded from ' . $h($t['forwarded_from']) : '' ?></span>
    </div>
    <div class="small mb-3 <?= $t['sla']['overdue'] ? 'text-danger fw-semibold' : 'text-muted' ?>"><i class="bi bi-clock me-1"></i>First reply: <?= $h($t['sla_label']) ?></div>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="d-flex flex-column gap-2 mb-3">
            <?php foreach ($msgs as $m): ?>
                <?php if ($m['author_type'] === 'system'): ?>
                <div class="align-self-center small text-muted bg-light rounded-pill px-3 py-1"><?= $h($m['body']) ?></div>
                <?php else: $cls = $m['is_internal'] ? 'msg msg-note' : ($m['author_type'] === 'bjp' ? 'msg msg-bjp' : 'msg'); ?>
                <div class="<?= $cls ?>">
                    <div class="small text-muted mb-1">
                        <strong class="text-body"><?= $h($m['author_name'] ?? '') ?></strong>
                        <?= $m['author_type'] === 'bjp' ? '· BJP' : '· ' . $h($t['company_name'] ?? 'Company') ?>
                        · <?= $h(date('d M Y H:i', strtotime((string)$m['created_at']))) ?>
                        <?php if ($m['is_internal']): ?><span class="badge text-bg-secondary ms-1">Internal note — BJP only</span><?php endif; ?>
                    </div>
                    <div style="white-space:pre-wrap"><?= $h($m['body']) ?></div>
                    <?php foreach ($m['attachments'] as $a): ?>
                    <a class="d-inline-flex align-items-center gap-1 small me-2 mt-1" href="<?= $h('/actions/superadmin_support_attachment.php?id=' . (int)$a['id']) ?>"><i class="bi bi-paperclip"></i><?= $h($a['original_name']) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>

            <form id="agentReply" class="border rounded p-2" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= (int)$id ?>">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Reply type">
                        <input type="radio" class="btn-check" name="internal" id="rt_reply" value="0" checked><label class="btn btn-outline-primary" for="rt_reply"><i class="bi bi-reply me-1"></i>Reply to company</label>
                        <input type="radio" class="btn-check" name="internal" id="rt_note" value="1"><label class="btn btn-outline-secondary" for="rt_note"><i class="bi bi-lock me-1"></i>Internal note</label>
                    </div>
                    <?php if ($canned): ?>
                    <select class="form-select form-select-sm" id="cannedPick" style="width:auto" aria-label="Insert a saved reply">
                        <option value="">Insert saved reply…</option>
                        <?php foreach ($canned as $cr): ?><option value="<?= (int)$cr['id'] ?>"><?= $h($cr['title']) ?></option><?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <textarea class="form-control" name="body" id="agentBody" rows="5" maxlength="10000" placeholder="Write to the company… (they see it in their own BMS and by email)" aria-label="Reply"></textarea>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2">
                    <input class="form-control form-control-sm" style="max-width:280px" type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf" aria-label="Attach files">
                    <div class="d-flex gap-2 align-items-center" id="afterWrap">
                        <label class="small text-muted" for="after">After sending:</label>
                        <select class="form-select form-select-sm" name="after" id="after" style="width:auto">
                            <option value="waiting_on_customer">Wait for the company</option>
                            <option value="in_progress">Keep in progress</option>
                            <option value="resolved">Mark resolved</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Send</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-12 col-lg-4 d-flex flex-column gap-3">
            <div class="side">
                <div class="hd">Request</div>
                <div class="p-3">
                    <div class="mb-2"><label class="form-label small mb-1" for="sel_status">Status</label>
                        <select class="form-select form-select-sm js-field" data-action="set_status" data-name="status" id="sel_status">
                            <?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"<?= $t['status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                    <div class="mb-2"><label class="form-label small mb-1" for="sel_prio">Priority</label>
                        <select class="form-select form-select-sm js-field" data-action="set_priority" data-name="priority" id="sel_prio">
                            <?php foreach (SUPPORT_PRIORITIES as $k => $l): ?><option value="<?= $k ?>"<?= $t['priority'] === $k ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select></div>
                    <div class="mb-2"><label class="form-label small mb-1" for="sel_agent">Assigned to</label>
                        <select class="form-select form-select-sm js-field" data-action="assign" data-name="agent_id" id="sel_agent">
                            <option value="">Nobody</option>
                            <?php foreach ($agents as $ag): ?><option value="<?= (int)$ag['id'] ?>"<?= (int)$t['assigned_to'] === (int)$ag['id'] ? ' selected' : '' ?>><?= $h($ag['name']) ?><?= (int)$ag['id'] === (int)$me['id'] ? ' (me)' : '' ?></option><?php endforeach; ?></select></div>
                    <div class="kv"><span>Type</span><span><?= $h(SUPPORT_CATEGORIES[$t['category']] ?? $t['category']) ?></span></div>
                    <div class="kv"><span>Sent</span><span><?= $h(date('d M Y H:i', strtotime((string)$t['created_at']))) ?></span></div>
                    <?php if ($t['rating']): ?><div class="kv"><span>Rating</span><span><?= (int)$t['rating'] ?> / 5<?= $t['rating_comment'] ? ' — ' . $h($t['rating_comment']) : '' ?></span></div><?php endif; ?>
                </div>
            </div>
            <div class="side">
                <div class="hd">Who asked</div>
                <div class="p-3">
                    <div class="kv"><span>Name</span><span><?= $h($t['user_name']) ?></span></div>
                    <div class="kv"><span>Role</span><span><?= $h($t['user_role'] ?? '—') ?></span></div>
                    <div class="kv"><span>Email</span><span class="text-break"><?= $h($t['user_email'] ?? '—') ?></span></div>
                    <div class="kv"><span>Page</span><span><?= $h($pageLabel !== '' ? $pageLabel : '—') ?></span></div>
                    <div class="kv"><span>Address</span><span class="text-break"><?= $h($t['page_url'] ?? '—') ?></span></div>
                    <div class="small text-muted mt-1 text-break"><?= $h($t['browser'] ?? '') ?></div>
                </div>
            </div>
            <div class="side">
                <div class="hd">Company</div>
                <div class="p-3">
                    <?php $tn = $co['tenant'] ?? null; ?>
                    <?php if ($tn): ?>
                    <div class="fw-semibold"><a class="text-decoration-none" href="<?= $h(saUrl('tenants/view?id=' . (int)$tn['id'])) ?>"><?= $h($tn['company_name']) ?></a></div>
                    <div class="small text-muted mb-2"><?= $h($tn['subdomain']) ?></div>
                    <div class="kv"><span>Product</span><span><?= $h($tn['product_name'] ?? '—') ?></span></div>
                    <div class="kv"><span>Plan</span><span><?= $h($tn['plan_name'] ?? ($tn['plan'] ?? '—')) ?></span></div>
                    <div class="kv"><span>Status</span><span><?= $h(ucfirst((string)$tn['status'])) ?></span></div>
                    <?php $ends = $tn['subscription_ends_at'] ?: $tn['trial_ends_at']; ?>
                    <div class="kv"><span>Ends</span><span><?= $ends ? $h(date('d M Y', strtotime((string)$ends))) : '—' ?></span></div>
                    <div class="kv"><span>Simple Mode</span><span><?= $co['simple'] === null ? '—' : ($co['simple'] ? 'On' : 'Off') ?></span></div>
                    <div class="kv"><span>Wording</span><span><?= $co['shop'] === null ? '—' : ($co['shop'] ? 'Shop / Sell' : 'Warehouse / POS') ?></span></div>
                    <?php if ($co['shop']): ?><div class="small text-primary mt-1">They see "Sell" and "Shop › Shops" — answer in those words.</div><?php endif; ?>
                    <?php else: ?><div class="small text-muted">Company details not available.</div><?php endif; ?>
                    <?php if (count($co['recent'] ?? []) > 1): ?>
                    <div class="small fw-semibold mt-3 mb-1">Their recent requests</div>
                    <?php foreach ($co['recent'] as $rr): if ((int)$rr['id'] === $id) continue; ?>
                    <div class="small"><a class="text-decoration-none" href="<?= $h(saUrl('support/request?id=' . (int)$rr['id'])) ?>"><?= $h($rr['ref_no']) ?></a> <?= $h(mb_strimwidth((string)$rr['subject'], 0, 40, '…')) ?></div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php if ($t): ?>
<script>
const SA_CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const URL_ = '/actions/superadmin_support_inbox.php';
const ID = <?= (int)$id ?>;
const CANNED = <?= json_encode(array_column($canned, 'body', 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function postFd(fd) {
    fd.append('_csrf', SA_CSRF_TOKEN);
    return fetch(URL_, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
        .then(function (r) { if (r && r.success) { location.reload(); } else { Swal.fire({ icon: 'error', title: 'Error', text: (r && r.message) || 'Not saved.' }); } })
        .catch(function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Not saved.' }); });
}
$('#cannedPick').on('change', function () {
    const t = CANNED[this.value]; if (!t) return;
    const el = document.getElementById('agentBody');
    el.value = el.value ? el.value + '\n\n' + t : t; el.focus(); this.value = '';
});
$('input[name="internal"]').on('change', function () {
    const note = this.value === '1';
    $('#afterWrap label[for="after"], #after').toggle(!note);
    $('#agentBody').attr('placeholder', note ? 'Only BJP agents see internal notes.' : 'Write to the company… (they see it in their own BMS and by email)');
});
$('#agentReply').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]'), orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Sending...');
    postFd(new FormData(this)).finally(function () { btn.prop('disabled', false).html(orig); });
});
$('.js-field').on('change', function () {
    const fd = new FormData(); fd.append('action', $(this).data('action')); fd.append('id', ID); fd.append($(this).data('name'), this.value);
    postFd(fd);
});
</script>
<?php endif; ?>
</body>
</html>
