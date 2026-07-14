<?php
/**
 * new_document.php — entry chooser for "Create Document", shown before the
 * actual editor (create_document.php). Two steps, no page reload between
 * them:
 *   1. Pick a category, or skip straight to a completely blank letter.
 *   2. Within that category, pick one of its content-based templates, or
 *      still start blank (with the category pre-set).
 * Either path lands on create_document.php — blank, or pre-filled from the
 * chosen template — so create_document.php itself doesn't need to know
 * anything about this chooser beyond the optional ?template_id / ?category_id
 * query params it already reads.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';

includeHeader();

if (!canCreate('documents')) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

$project_id = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;
if ($project_id !== null && !userCan('project', $project_id)) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

// Only categories that actually have at least one usable (content-based,
// active) template are worth showing — an empty category is a dead click.
$categories = $pdo->query("
    SELECT tc.id, tc.category_name, COUNT(dt.id) AS template_count
    FROM template_categories tc
    JOIN document_templates dt ON dt.category_id = tc.id AND dt.content IS NOT NULL AND dt.is_active = 1
    GROUP BY tc.id, tc.category_name
    ORDER BY tc.category_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$blank_url = getUrl('create_document') . ($project_id ? '?project_id=' . $project_id : '');
?>

<div class="container-fluid mt-4 mb-5" id="newDocumentPage">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-file-earmark-plus me-2 text-primary"></i>Create Document</h4>
            <p class="text-muted mb-0 small">Start from a template, or write on a completely blank page.</p>
        </div>
        <a href="<?= $project_id ? getUrl('project_view') . '?id=' . $project_id : getUrl('document_library') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>

    <!-- Step 1 -->
    <div id="step1">
        <div class="row g-3 mb-2">
            <div class="col-md-4">
                <a href="<?= htmlspecialchars($blank_url) ?>" class="card border-0 shadow-sm text-decoration-none h-100 new-doc-card new-doc-card-blank">
                    <div class="card-body text-center py-4">
                        <i class="bi bi-file-earmark display-6 text-primary"></i>
                        <div class="fw-bold mt-2">Start Blank</div>
                        <div class="text-muted small">Zero data — build the whole letter from scratch.</div>
                    </div>
                </a>
            </div>
            <?php foreach ($categories as $cat): ?>
            <div class="col-md-4">
                <button type="button" class="card border-0 shadow-sm text-decoration-none h-100 w-100 new-doc-card btn-cat"
                        data-id="<?= (int)$cat['id'] ?>" data-name="<?= htmlspecialchars($cat['category_name']) ?>">
                    <div class="card-body text-center py-4">
                        <i class="bi bi-folder2-open display-6 text-secondary"></i>
                        <div class="fw-bold mt-2"><?= htmlspecialchars($cat['category_name']) ?></div>
                        <div class="text-muted small"><?= (int)$cat['template_count'] ?> template<?= (int)$cat['template_count'] === 1 ? '' : 's' ?></div>
                    </div>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (empty($categories)): ?>
        <div class="text-muted small">No template categories with usable templates yet — start blank above, then use "Save as Template" once you've written something worth reusing.</div>
        <?php endif; ?>
    </div>

    <!-- Step 2 (populated by JS) -->
    <div id="step2" class="d-none">
        <button type="button" class="btn btn-link px-0 mb-2" id="btnBackToCategories"><i class="bi bi-arrow-left me-1"></i> All categories</button>
        <h5 class="fw-bold mb-3" id="step2Title"></h5>
        <div class="row g-3" id="step2List"></div>
    </div>
</div>

<style>
.new-doc-card { cursor: pointer; transition: transform .1s, box-shadow .1s; border: 1px solid #eee !important; }
.new-doc-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.08) !important; }
.new-doc-card-blank { border-color: #cfe2ff !important; }
</style>

<script>
const NEW_DOC_BLANK_URL = <?= json_encode($blank_url) ?>;
const NEW_DOC_PROJECT_ID = <?= json_encode($project_id) ?>;

$(document).ready(function () {
    $('.btn-cat').on('click', function () {
        const catId = $(this).data('id');
        const catName = $(this).data('name');
        openCategory(catId, catName);
    });

    $('#btnBackToCategories').on('click', function () {
        $('#step2').addClass('d-none');
        $('#step1').removeClass('d-none');
    });

    function docUrl(params) {
        const url = new URL(NEW_DOC_BLANK_URL, window.location.origin);
        Object.keys(params).forEach(k => { if (params[k]) url.searchParams.set(k, params[k]); });
        return url.pathname + url.search;
    }

    function openCategory(catId, catName) {
        $('#step1').addClass('d-none');
        $('#step2').removeClass('d-none');
        $('#step2Title').text(catName);
        $('#step2List').html('<div class="col-12 text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span> Loading templates...</div>');

        $.getJSON('<?= buildUrl('api/document/get_letter_templates.php') ?>', { category_id: catId }, function (res) {
            if (!res.success) {
                $('#step2List').html('<div class="col-12 text-center text-danger py-4">' + (res.message || 'Could not load templates.') + '</div>');
                return;
            }
            let html = `
                <div class="col-md-4">
                    <a href="${docUrl({ category_id: catId, project_id: NEW_DOC_PROJECT_ID })}" class="card border-0 shadow-sm text-decoration-none h-100 new-doc-card new-doc-card-blank">
                        <div class="card-body text-center py-4">
                            <i class="bi bi-file-earmark display-6 text-primary"></i>
                            <div class="fw-bold mt-2">Blank (${safeOutput(catName)})</div>
                            <div class="text-muted small">Zero data, category pre-set.</div>
                        </div>
                    </a>
                </div>`;
            res.templates.forEach(function (t) {
                html += `
                <div class="col-md-4">
                    <button type="button" class="card border-0 shadow-sm text-decoration-none h-100 w-100 new-doc-card btn-tpl" data-id="${t.id}" data-catid="${catId}">
                        <div class="card-body text-center py-4">
                            <i class="bi bi-file-earmark-text display-6 text-secondary"></i>
                            <div class="fw-bold mt-2">${safeOutput(t.template_name)}</div>
                            <div class="text-muted small">Used ${t.usage_count || 0} time(s)</div>
                        </div>
                    </button>
                </div>`;
            });
            $('#step2List').html(html);

            $('#step2List').off('click', '.btn-tpl').on('click', '.btn-tpl', function () {
                const tplId = $(this).data('id');
                const tplCatId = $(this).data('catid');
                $.post('<?= buildUrl('api/document/use_template.php') ?>', { template_id: tplId, _csrf: CSRF_TOKEN })
                    .always(function () {
                        window.location.href = docUrl({ template_id: tplId, category_id: tplCatId, project_id: NEW_DOC_PROJECT_ID });
                    });
            });
        }).fail(function () {
            $('#step2List').html('<div class="col-12 text-center text-danger py-4">Server error loading templates.</div>');
        });
    }
});

function safeOutput(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
