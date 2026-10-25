<?php
/**
 * app/superadmin/support_article.php — write or edit one help article, with a live
 * preview of exactly what a Smart POS / Smart BMS admin or staff member will see.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../core/support_articles.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();
$me = currentSuperadmin();
$h  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$id = ctype_digit((string)($_GET['id'] ?? '')) ? (int)$_GET['id'] : 0;
$a  = $id ? supportArticleGet($id) : null;
if ($id && !$a) {
    http_response_code(404);
}
$a = $a ?: ['id' => 0, 'title_en' => '', 'title_sw' => '', 'body_en' => '', 'body_sw' => '', 'simple_body_en' => '', 'simple_body_sw' => '',
            'video_url' => '', 'is_faq' => 0, 'page_keys' => [], 'category_id' => null, 'status' => 'draft'];
$categories = supportCategories();
$catalog = helpNavCatalog();
$groups = [];
foreach ($catalog as $k => $c) $groups[$c['group']][$k] = $c;
$isNew = (int)$a['id'] === 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= $isNew ? 'New article' : 'Edit article' ?> | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .panel-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .panel-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    textarea.body { font-family: ui-monospace, Consolas, monospace; font-size: .9rem; min-height: 220px; }
    .tok-btn { font-family: ui-monospace, Consolas, monospace; font-size: .78rem; }
    .preview { border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; }
    .preview .ph { background: #0d6efd; color: #fff; padding: 10px 14px; font-weight: 700; }
    .preview .pb { padding: 14px; font-size: .92rem; }
    .preview .pb ol, .preview .pb ul { padding-left: 1.2rem; }
    .help-path { background: #e7f0ff; color: #084298; border-radius: 4px; padding: 0 5px; font-weight: 600; }
    .help-tip { background: #f6f8fc; border-left: 3px solid #0d6efd; padding: 8px 10px; border-radius: 4px; margin: 8px 0; }
    @media (min-width: 992px) { .sticky-preview { position: sticky; top: 110px; } }
</style>
</head>
<body>

<?php renderSuperadminHeader('support', $me); ?>

<div class="container-fluid p-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <a href="<?= $h(saUrl('support?tab=articles')) ?>" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Articles</a>
            <h5 class="mb-0 fw-bold"><?= $isNew ? 'New article' : 'Edit article' ?>
                <?php if (!$isNew): ?><span class="badge rounded-pill ms-1" style="<?= $a['status'] === 'published' ? 'background:#0d6efd' : ($a['status'] === 'archived' ? 'background:#6c757d' : 'background:#e9ecef;color:#495057') ?>"><?= $h(ucfirst($a['status'])) ?></span><?php endif; ?>
            </h5>
        </div>
    </div>
    <?php if ($id && (int)$a['id'] === 0): ?>
    <div class="alert alert-warning">That article does not exist. You can write a new one below.</div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <form id="artForm" autocomplete="off">
                <input type="hidden" name="action" value="save_article">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <div class="card panel-card mb-3">
                    <div class="card-header">Article</div>
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-6"><label class="form-label" for="title_en">Title (English) <span class="text-danger">*</span></label>
                                <input class="form-control" id="title_en" name="title_en" maxlength="255" required value="<?= $h($a['title_en']) ?>"></div>
                            <div class="col-md-6"><label class="form-label" for="title_sw">Title (Swahili)</label>
                                <input class="form-control" id="title_sw" name="title_sw" maxlength="255" value="<?= $h($a['title_sw']) ?>"></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6"><label class="form-label" for="category_id">Category</label>
                                <select class="form-select select2-static" id="category_id" name="category_id">
                                    <option value="">No category</option>
                                    <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"<?= (int)$a['category_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= $h($c['name_en']) ?><?= $c['is_active'] ? '' : ' (hidden)' ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                            <div class="col-md-6"><label class="form-label" for="video_url">Video link (optional)</label>
                                <input type="url" class="form-control" id="video_url" name="video_url" maxlength="500" placeholder="https://www.youtube.com/…" value="<?= $h($a['video_url']) ?>">
                                <div class="form-text">YouTube, Vimeo or Loom (https). Opens in a new tab.</div></div>
                        </div>
                        <div class="mb-3"><label class="form-label" for="page_keys">Pages this article is about</label>
                            <select class="form-select" id="page_keys" name="page_keys[]" multiple>
                                <?php foreach ($groups as $g => $items): ?>
                                <optgroup label="<?= $h($g) ?>">
                                    <?php foreach ($items as $k => $c): ?>
                                    <option value="<?= $h($k) ?>"<?= in_array($k, $a['page_keys'], true) ? ' selected' : '' ?>><?= $h($c['label'] . ($c['shop_label'] ? ' / ' . $c['shop_label'] : '')) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">A company sees the article only if the user can open at least one of these pages. Leave empty for general help everyone sees.</div>
                        </div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="is_faq" name="is_faq" value="1"<?= $a['is_faq'] ? ' checked' : '' ?>>
                            <label class="form-check-label" for="is_faq">Also show in Frequently asked</label></div>
                    </div>
                </div>

                <div class="card panel-card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>Steps</span>
                        <span class="d-flex flex-wrap gap-1">
                            <?php foreach (['{shop}', '{shops}', '{Shop}', '{Shops}'] as $tk): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary tok-btn js-tok" data-tok="<?= $h($tk) ?>"><?= $h($tk) ?></button>
                            <?php endforeach; ?>
                            <select class="form-select form-select-sm" id="navInsert" style="width:auto" aria-label="Insert a page link">
                                <option value="">Insert page link…</option>
                                <?php foreach ($groups as $g => $items): ?><optgroup label="<?= $h($g) ?>"><?php foreach ($items as $k => $c): ?><option value="<?= $h($k) ?>"><?= $h($c['label']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                            </select>
                        </span>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-pills mb-2" role="tablist">
                            <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="pill" data-bs-target="#b-en" role="tab">English</button></li>
                            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="pill" data-bs-target="#b-sw" role="tab">Swahili</button></li>
                            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="pill" data-bs-target="#b-simple" role="tab">Simple Mode (optional)</button></li>
                        </ul>
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="b-en"><textarea class="form-control body" name="body_en" id="body_en" maxlength="20000" aria-label="English steps"><?= $h($a['body_en']) ?></textarea></div>
                            <div class="tab-pane fade" id="b-sw"><textarea class="form-control body" name="body_sw" id="body_sw" maxlength="20000" aria-label="Swahili steps" placeholder="Empty = English is shown"><?= $h($a['body_sw']) ?></textarea></div>
                            <div class="tab-pane fade" id="b-simple">
                                <p class="small text-muted mb-2">Shorter steps for companies on the simple shop screens (Smart POS). Empty = the normal steps are used.</p>
                                <textarea class="form-control body mb-2" name="simple_body_en" id="simple_body_en" maxlength="20000" aria-label="Simple Mode English steps" placeholder="English"><?= $h($a['simple_body_en']) ?></textarea>
                                <textarea class="form-control body" name="simple_body_sw" id="simple_body_sw" maxlength="20000" aria-label="Simple Mode Swahili steps" placeholder="Swahili"><?= $h($a['simple_body_sw']) ?></textarea>
                            </div>
                        </div>
                        <div class="small text-muted mt-2">
                            <b>Format:</b> <code>1. step</code> numbered steps · <code>- item</code> bullets · <code>Tip: …</code> tip box · <code>**bold**</code> · blank line = new paragraph.
                            <b>Page links:</b> <code>{nav:pos}</code> becomes the menu path the reader really sees, e.g. "Sell" or "Sales › POS". No HTML is allowed.
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <?php if ($a['status'] === 'published'): ?>
                    <button type="submit" class="btn btn-primary" data-publish="keep"><i class="bi bi-check-circle me-1"></i> Save changes</button>
                    <?php else: ?>
                    <button type="submit" class="btn btn-outline-primary" data-publish="keep"><i class="bi bi-save me-1"></i> Save draft</button>
                    <button type="submit" class="btn btn-primary" data-publish="1"><i class="bi bi-send me-1"></i> Save &amp; publish</button>
                    <?php endif; ?>
                    <a href="<?= $h(saUrl('support?tab=articles')) ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <div class="col-12 col-lg-5">
            <div class="sticky-preview">
                <div class="card panel-card">
                    <div class="card-header">Preview — as a company sees it</div>
                    <div class="card-body">
                        <div class="row g-2 mb-2">
                            <div class="col-4"><select class="form-select form-select-sm" id="pv_product" aria-label="Product"><option value="pos">Smart POS</option><option value="bms">Smart BMS</option></select></div>
                            <div class="col-4"><select class="form-select form-select-sm" id="pv_role" aria-label="User"><option value="admin">Admin</option><option value="staff">Staff (selling)</option></select></div>
                            <div class="col-4"><select class="form-select form-select-sm" id="pv_lang" aria-label="Language"><option value="en">English</option><option value="sw">Swahili</option></select></div>
                        </div>
                        <div id="pvNote" class="small mb-2"></div>
                        <div class="preview"><div class="ph" id="pvTitle">—</div><div class="pb" id="pvBody"><span class="text-muted">Start typing to see the preview.</span></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
const SA_CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const ART_URL = '/actions/superadmin_support_articles.php';
const LIST_URL = <?= json_encode(saUrl('support?tab=articles')) ?>;
const EDIT_URL = <?= json_encode(saUrl('support/article')) ?>;

$('#category_id').select2({ theme: 'bootstrap-5', width: '100%' });
$('#page_keys').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'General (everyone)', closeOnSelect: false });

let lastField = document.getElementById('body_en');
$('textarea.body, #title_en, #title_sw').on('focus', function () { lastField = this; });
function insertAtCursor(text) {
    const el = lastField; if (!el) return;
    const s = el.selectionStart ?? el.value.length, e = el.selectionEnd ?? el.value.length;
    el.value = el.value.slice(0, s) + text + el.value.slice(e);
    el.focus(); el.selectionStart = el.selectionEnd = s + text.length;
    schedulePreview();
}
$('.js-tok').on('click', function () { insertAtCursor($(this).data('tok')); });
$('#navInsert').on('change', function () { if (this.value) { insertAtCursor('{nav:' + this.value + '}'); this.value = ''; } });

let pvTimer = null, pvSeq = 0;
function schedulePreview() { clearTimeout(pvTimer); pvTimer = setTimeout(preview, 350); }
function preview() {
    const seq = ++pvSeq;
    const data = $('#artForm').serializeArray().filter(function (x) { return x.name !== 'action'; });
    data.push({ name: 'action', value: 'preview' }, { name: 'product', value: $('#pv_product').val() },
              { name: 'role', value: $('#pv_role').val() }, { name: 'lang', value: $('#pv_lang').val() },
              { name: '_csrf', value: SA_CSRF_TOKEN });
    $.ajax({ url: ART_URL, method: 'POST', dataType: 'json', data: $.param(data) }).done(function (r) {
        if (seq !== pvSeq || !r) return;
        // Server returns already-escaped HTML built only from allow-listed tags.
        $('#pvTitle').html(r.title_html || '—');
        $('#pvBody').html(r.body_html || '<span class="text-muted">No steps yet.</span>');
        const note = $('#pvNote').empty();
        if (r.visible === false) note.addClass('text-danger').text('This user would NOT see this article — none of its pages are open to them.');
        else note.removeClass('text-danger').addClass('text-muted').text(r.visible ? 'This user would see this article.' : '');
        if (r.errors && Object.keys(r.errors).length) note.append($('<div class="text-danger">').text(Object.values(r.errors)[0]));
    });
}
$('#artForm').on('input change', 'input, textarea, select', schedulePreview);
$('#pv_product, #pv_role, #pv_lang').on('change', preview);
preview();

let publishMode = 'keep';
$('#artForm [type="submit"]').on('click', function () { publishMode = $(this).data('publish'); });
$('#artForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"][data-publish="' + publishMode + '"]');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    const data = $(this).serializeArray();
    data.push({ name: 'publish', value: publishMode === '1' ? '1' : '0' }, { name: '_csrf', value: SA_CSRF_TOKEN });
    $.ajax({ url: ART_URL, method: 'POST', dataType: 'json', data: $.param(data) })
        .done(function (r) {
            if (r && r.success) {
                Swal.fire({ icon: 'success', title: 'Saved', text: r.message, timer: 1400, showConfirmButton: false })
                    .then(function () { location.href = EDIT_URL + (EDIT_URL.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(r.id); });
            } else {
                Swal.fire({ icon: 'error', title: 'Check the article', text: (r && r.message) || 'It could not be saved.' });
            }
        })
        .fail(function (xhr) {
            let msg = 'It could not be saved.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (err) {}
            Swal.fire({ icon: 'error', title: 'Check the article', text: msg });
        })
        .always(function () { btn.prop('disabled', false).html(orig); });
});
</script>
</body>
</html>
