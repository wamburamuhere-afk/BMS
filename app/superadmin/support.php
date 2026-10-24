<?php
/**
 * app/superadmin/support.php — BJP's Help & Support area (support_center_plan.md).
 * Tabs: Articles · Categories · Settings. Control database only; tenants never read
 * or edit anything here directly — they only see published articles, in their own words.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../core/support_articles.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();
$me  = currentSuperadmin();
$tab = in_array($_GET['tab'] ?? '', ['articles', 'categories', 'settings'], true) ? $_GET['tab'] : 'articles';
$h   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$s = supportSettings();
$e = fn(string $k) => $h($s[$k] ?? '');

$categories = supportCategories();
$catalog = helpNavCatalog();
$fStatus = in_array($_GET['status'] ?? '', ['draft', 'published', 'archived', 'all'], true) ? $_GET['status'] : '';
$fCat = ctype_digit((string)($_GET['category'] ?? '')) ? (string)$_GET['category'] : '';
$fQ = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$articles = $tab === 'articles' ? supportArticleList(['status' => $fStatus, 'category_id' => $fCat, 'q' => $fQ]) : [];
$counts = getControlPdo()->query("SELECT status, COUNT(*) n FROM support_articles GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$helpRow = getControlPdo()->query("SELECT COALESCE(SUM(helpful_yes),0) y, COALESCE(SUM(helpful_no),0) n FROM support_articles WHERE status='published'")->fetch(PDO::FETCH_ASSOC);
$helpPct = ($helpRow['y'] + $helpRow['n']) > 0 ? round(100 * $helpRow['y'] / ($helpRow['y'] + $helpRow['n'])) . '%' : '—';
$statusBadge = function (string $st) use ($h): string {
    $map = ['published' => ['#0d6efd', '#fff', 'Published'], 'draft' => ['#e9ecef', '#495057', 'Draft'], 'archived' => ['#6c757d', '#fff', 'Archived']];
    [$bg, $fg, $lbl] = $map[$st] ?? ['#e9ecef', '#495057', $st];
    return '<span class="badge rounded-pill" style="background:' . $bg . ';color:' . $fg . '">' . $h($lbl) . '</span>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Support | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .panel-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .panel-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    .stat { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; padding: 10px; text-align: center; }
    .stat b { display: block; font-size: 1.3rem; color: #0d6efd; }
    .page-chip { display: inline-block; background: #e7f0ff; color: #084298; border-radius: 4px; padding: 0 6px; font-size: .75rem; margin: 1px; }
    .tok { font-family: ui-monospace, Consolas, monospace; font-size: .85em; color: #6c757d; }
</style>
</head>
<body>

<?php renderSuperadminHeader('support', $me); ?>

<div class="container-fluid p-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <div>
            <h5 class="mb-0 fw-bold"><i class="bi bi-headset text-primary me-1"></i> Support</h5>
            <div class="text-muted small">Help articles every company sees in its own words, and how companies reach BJP.</div>
        </div>
        <?php if ($tab === 'articles'): ?>
        <a href="<?= $h(saUrl('support/article')) ?>" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> New article</a>
        <?php elseif ($tab === 'categories'): ?>
        <button type="button" class="btn btn-primary" id="btnAddCat"><i class="bi bi-plus-circle me-1"></i> Add category</button>
        <?php endif; ?>
    </div>

    <ul class="nav nav-tabs mb-3">
        <?php foreach (['articles' => ['bi-journal-text', 'Articles'], 'categories' => ['bi-tags', 'Categories'], 'settings' => ['bi-sliders', 'Settings']] as $k => [$ic, $lbl]): ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= $h(saUrl('support?tab=' . $k)) ?>"><i class="bi <?= $ic ?> me-1"></i><?= $lbl ?></a></li>
        <?php endforeach; ?>
    </ul>

<?php if ($tab === 'articles'): ?>
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="stat"><b><?= (int)($counts['published'] ?? 0) ?></b><span class="small text-muted">Published</span></div></div>
        <div class="col-6 col-md-3"><div class="stat"><b><?= (int)($counts['draft'] ?? 0) ?></b><span class="small text-muted">Drafts to review</span></div></div>
        <div class="col-6 col-md-3"><div class="stat"><b><?= (int)($counts['archived'] ?? 0) ?></b><span class="small text-muted">Archived</span></div></div>
        <div class="col-6 col-md-3"><div class="stat"><b><?= $h($helpPct) ?></b><span class="small text-muted">Found helpful</span></div></div>
    </div>

    <form class="row g-2 mb-3" method="get" action="<?= $h(saUrl('support')) ?>">
        <input type="hidden" name="tab" value="articles">
        <div class="col-12 col-md-4"><input type="search" class="form-control" name="q" value="<?= $h($fQ) ?>" placeholder="Search titles" aria-label="Search titles"></div>
        <div class="col-6 col-md-3">
            <select class="form-select" name="status" aria-label="Status">
                <option value="">Published + drafts</option>
                <?php foreach (['published' => 'Published', 'draft' => 'Drafts', 'archived' => 'Archived', 'all' => 'All'] as $k => $l): ?>
                <option value="<?= $k ?>"<?= $fStatus === $k ? ' selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <select class="form-select select2-static" name="category" aria-label="Category">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"<?= $fCat === (string)$c['id'] ? ' selected' : '' ?>><?= $h($c['name_en']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filter</button></div>
    </form>

    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-dark"><tr><th>Title</th><th>Category</th><th>Pages</th><th>Status</th><th class="text-end">Views</th><th class="text-end">Helpful</th><th>Updated</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php if (!$articles): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No articles found.</td></tr>
        <?php endif; ?>
        <?php foreach ($articles as $a):
            $votes = (int)$a['helpful_yes'] + (int)$a['helpful_no'];
            $pct = $votes ? round(100 * $a['helpful_yes'] / $votes) . '% of ' . $votes : '—'; ?>
            <tr>
                <td>
                    <a href="<?= $h(saUrl('support/article?id=' . (int)$a['id'])) ?>" class="fw-semibold text-decoration-none"><?= $h($a['title_en']) ?></a>
                    <?php if ($a['is_faq']): ?><span class="badge text-bg-light border ms-1">FAQ</span><?php endif; ?>
                    <?php if ($a['video_url']): ?><i class="bi bi-play-circle text-primary ms-1" title="Has a video"></i><?php endif; ?>
                    <?php if (trim((string)$a['title_sw']) === ''): ?><div class="small text-muted">No Swahili yet — English is shown</div><?php endif; ?>
                </td>
                <td><?= $h($a['category'] ?? '—') ?></td>
                <td><?php if (!$a['page_keys']): ?><span class="small text-muted">General (everyone)</span><?php endif;
                    foreach ($a['page_keys'] as $pk): ?><span class="page-chip"><?= $h($catalog[$pk]['label'] ?? $pk) ?></span><?php endforeach; ?></td>
                <td><?= $statusBadge($a['status']) ?></td>
                <td class="text-end"><?= (int)$a['views'] ?></td>
                <td class="text-end small"><?= $h($pct) ?></td>
                <td class="small text-muted"><?= $h(date('d M Y', strtotime((string)($a['updated_at'] ?? $a['created_at'])))) ?></td>
                <td class="text-end">
                    <div class="dropdown d-flex justify-content-end">
                        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions"><i class="bi bi-gear-fill me-1"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                            <li><a class="dropdown-item py-2 rounded" href="<?= $h(saUrl('support/article?id=' . (int)$a['id'])) ?>"><i class="bi bi-pencil text-primary me-2"></i> Edit</a></li>
                            <?php if ($a['status'] !== 'published'): ?>
                            <li><button class="dropdown-item py-2 rounded js-status" data-id="<?= (int)$a['id'] ?>" data-status="published"><i class="bi bi-send text-primary me-2"></i> Publish</button></li>
                            <?php endif; ?>
                            <?php if ($a['status'] === 'published'): ?>
                            <li><button class="dropdown-item py-2 rounded js-status" data-id="<?= (int)$a['id'] ?>" data-status="draft"><i class="bi bi-eye-slash text-primary me-2"></i> Move to drafts</button></li>
                            <?php endif; ?>
                            <?php if ($a['status'] !== 'archived'): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><button class="dropdown-item py-2 rounded text-danger js-status" data-id="<?= (int)$a['id'] ?>" data-status="archived"><i class="bi bi-archive text-danger me-2"></i> Archive</button></li>
                            <?php else: ?>
                            <li><button class="dropdown-item py-2 rounded js-status" data-id="<?= (int)$a['id'] ?>" data-status="draft"><i class="bi bi-arrow-counterclockwise text-primary me-2"></i> Restore to drafts</button></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

<?php elseif ($tab === 'categories'): ?>
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-dark"><tr><th>Name</th><th>Swahili</th><th>Icon</th><th class="text-end">Order</th><th class="text-end">Articles</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php if (!$categories): ?><tr><td colspan="7" class="text-center text-muted py-4">No categories yet.</td></tr><?php endif; ?>
        <?php foreach ($categories as $c): ?>
            <tr>
                <td class="fw-semibold"><?= $h($c['name_en']) ?></td>
                <td><?= $h($c['name_sw'] ?? '') ?></td>
                <td><i class="bi bi-<?= $h($c['icon']) ?> text-primary"></i></td>
                <td class="text-end"><?= (int)$c['sort_order'] ?></td>
                <td class="text-end"><?= (int)$c['article_count'] ?></td>
                <td><?= $c['is_active'] ? '<span class="badge rounded-pill" style="background:#0d6efd">Shown</span>' : '<span class="badge rounded-pill" style="background:#6c757d">Hidden</span>' ?></td>
                <td class="text-end">
                    <div class="dropdown d-flex justify-content-end">
                        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions"><i class="bi bi-gear-fill me-1"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                            <li><button class="dropdown-item py-2 rounded js-edit-cat"
                                data-id="<?= (int)$c['id'] ?>" data-name_en="<?= $h($c['name_en']) ?>" data-name_sw="<?= $h($c['name_sw'] ?? '') ?>"
                                data-icon="<?= $h($c['icon']) ?>" data-sort_order="<?= (int)$c['sort_order'] ?>"><i class="bi bi-pencil text-primary me-2"></i> Edit</button></li>
                            <li><button class="dropdown-item py-2 rounded js-cat-active" data-id="<?= (int)$c['id'] ?>" data-active="<?= $c['is_active'] ? '0' : '1' ?>">
                                <i class="bi <?= $c['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?> text-primary me-2"></i> <?= $c['is_active'] ? 'Hide' : 'Show' ?></button></li>
                        </ul>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="small text-muted">Names may use <span class="tok">{Shops}</span> / <span class="tok">{shops}</span>: Smart POS companies see "Shops", others "Warehouses".</p>

    <div class="modal fade" id="catModal" tabindex="-1" aria-labelledby="catModalTitle" aria-hidden="true">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="catModalTitle"><i class="bi bi-tags me-1"></i> Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="catForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="id" id="cat_id">
                    <div class="mb-3"><label class="form-label" for="cat_name_en">Name (English) <span class="text-danger">*</span></label><input class="form-control" id="cat_name_en" name="name_en" maxlength="120" required></div>
                    <div class="mb-3"><label class="form-label" for="cat_name_sw">Name (Swahili)</label><input class="form-control" id="cat_name_sw" name="name_sw" maxlength="120"></div>
                    <div class="row g-2">
                        <div class="col-8"><label class="form-label" for="cat_icon">Icon</label>
                            <select class="form-select" id="cat_icon" name="icon"><?php foreach (SUPPORT_CATEGORY_ICONS as $ic): ?><option value="<?= $h($ic) ?>"><?= $h($ic) ?></option><?php endforeach; ?></select></div>
                        <div class="col-4"><label class="form-label" for="cat_sort">Order</label><input type="number" class="form-control" id="cat_sort" name="sort_order" value="0"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save</button>
                </div>
            </form>
        </div></div>
    </div>

<?php else: ?>
    <form id="supportForm" autocomplete="off">
        <input type="hidden" name="action" value="save_support">
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="card panel-card h-100">
                    <div class="card-header"><i class="bi bi-telephone text-primary me-1"></i> Contact details</div>
                    <div class="card-body">
                        <p class="text-muted small">Shown to company admins only.</p>
                        <div class="mb-3">
                            <label class="form-label" for="support_email">Support email</label>
                            <input type="email" class="form-control" id="support_email" name="support_email" maxlength="191" value="<?= $e('support_email') ?>">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label" for="support_phone">Phone</label>
                                <input type="text" class="form-control" id="support_phone" name="support_phone" maxlength="30" placeholder="+255 …" value="<?= $e('support_phone') ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="support_whatsapp">WhatsApp</label>
                                <input type="text" class="form-control" id="support_whatsapp" name="support_whatsapp" maxlength="30" placeholder="+255 …" value="<?= $e('support_whatsapp') ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="support_hours">Office hours</label>
                            <input type="text" class="form-control" id="support_hours" name="support_hours" maxlength="191" placeholder="Mon–Fri 08:00–17:00 EAT" value="<?= $e('support_hours') ?>">
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="support_login_help">Login page help text</label>
                            <textarea class="form-control" id="support_login_help" name="support_login_help" rows="2" maxlength="500"><?= $e('support_login_help') ?></textarea>
                            <div class="form-text">Shown under "Need help signing in?" on every company's login page.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card panel-card h-100">
                    <div class="card-header"><i class="bi bi-inbox text-primary me-1"></i> Requests</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="support_recipients">Who receives new requests</label>
                            <input type="text" class="form-control" id="support_recipients" name="support_recipients" placeholder="agent1@…, agent2@…" value="<?= $e('support_recipients') ?>">
                            <div class="form-text">Email addresses, separated by commas. Empty = every superadmin.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="support_autoreply">Auto-reply text</label>
                            <textarea class="form-control" id="support_autoreply" name="support_autoreply" rows="2" maxlength="1000"><?= $e('support_autoreply') ?></textarea>
                        </div>
                        <label class="form-label">First-reply targets (business hours)</label>
                        <div class="row g-2 mb-3">
                            <?php foreach (['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'] as $k => $lbl): ?>
                            <div class="col-6 col-md-3">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?= $lbl ?></span>
                                    <input type="number" min="1" class="form-control" name="support_sla_<?= $k ?>" aria-label="<?= $lbl ?> target in hours" value="<?= $e('support_sla_' . $k) ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label" for="support_autoclose_days">Close resolved requests after (days)</label>
                                <input type="number" min="1" max="60" class="form-control" id="support_autoclose_days" name="support_autoclose_days" value="<?= $e('support_autoclose_days') ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="support_attach_max_mb">Attachment limit (MB)</label>
                                <input type="number" min="1" max="20" class="form-control" id="support_attach_max_mb" name="support_attach_max_mb" value="<?= $e('support_attach_max_mb') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save support settings</button>
        </div>
    </form>
<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
const SA_CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const ART_URL = '/actions/superadmin_support_articles.php';

function saPost(url, data, btn) {
    const orig = btn ? btn.html() : null;
    if (btn) btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    data.push({ name: '_csrf', value: SA_CSRF_TOKEN });
    return $.ajax({ url: url, method: 'POST', dataType: 'json', data: $.param(data) })
        .fail(function (xhr) {
            let msg = 'The change could not be saved.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (err) {}
            Swal.fire({ icon: 'error', title: 'Error', text: msg });
        })
        .always(function () { if (btn) btn.prop('disabled', false).html(orig); });
}
function done(res, reload) {
    if (res && res.success) {
        Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 1600, showConfirmButton: false });
        if (reload) setTimeout(function () { location.reload(); }, 900);
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'The change could not be saved.' });
    }
}

$('.select2-static').each(function () { $(this).select2({ theme: 'bootstrap-5', width: '100%' }); });

$('#supportForm').on('submit', function (e) {
    e.preventDefault();
    saPost('/actions/superadmin_support_settings.php', $(this).serializeArray(), $(this).find('[type="submit"]')).done(function (r) { done(r, false); });
});

$('.js-status').on('click', function () {
    const id = $(this).data('id'), status = $(this).data('status');
    const ask = status === 'archived'
        ? { title: 'Archive this article?', text: 'Companies stop seeing it. You can restore it later.', icon: 'warning', confirmButtonColor: '#dc3545', confirmButtonText: 'Archive' }
        : status === 'published'
        ? { title: 'Publish this article?', text: 'Every company that can open its pages will see it.', icon: 'question', confirmButtonText: 'Publish' }
        : { title: 'Move to drafts?', text: 'Companies stop seeing it until it is published again.', icon: 'question', confirmButtonText: 'Move to drafts' };
    Swal.fire(Object.assign({ showCancelButton: true }, ask)).then(function (r) {
        if (!r.isConfirmed) return;
        saPost(ART_URL, [{ name: 'action', value: 'set_status' }, { name: 'id', value: id }, { name: 'status', value: status }]).done(function (res) { done(res, true); });
    });
});

const catModal = document.getElementById('catModal') ? new bootstrap.Modal(document.getElementById('catModal')) : null;
$('#btnAddCat').on('click', function () { $('#catForm')[0].reset(); $('#cat_id').val(''); catModal.show(); });
$('.js-edit-cat').on('click', function () {
    const d = $(this).data();
    $('#cat_id').val(d.id); $('#cat_name_en').val(d.name_en); $('#cat_name_sw').val(d.name_sw);
    $('#cat_icon').val(d.icon); $('#cat_sort').val(d.sort_order); catModal.show();
});
$('#catForm').on('submit', function (e) {
    e.preventDefault();
    saPost(ART_URL, $(this).serializeArray(), $(this).find('[type="submit"]')).done(function (res) {
        if (res && res.success) catModal.hide();
        done(res, true);
    });
});
$('.js-cat-active').on('click', function () {
    saPost(ART_URL, [{ name: 'action', value: 'set_category_active' }, { name: 'id', value: $(this).data('id') }, { name: 'active', value: $(this).data('active') }]).done(function (res) { done(res, true); });
});
</script>
</body>
</html>
