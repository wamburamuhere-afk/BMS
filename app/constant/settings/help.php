<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/support_articles.php';

// Help & Support is open to every signed-in user (canView('help'), support_center_plan.md D8).
autoEnforcePermission('help');

require_once __DIR__ . '/../../../header.php';

$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ctx  = helpRuntimeContext();
$lang = function_exists('currentLanguage') && currentLanguage() === 'sw' ? 'sw' : 'en';
$all  = supportTenantList($ctx, $lang);
$q     = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$topic = ctype_digit((string)($_GET['topic'] ?? '')) ? (int)$_GET['topic'] : 0;
$artId = ctype_digit((string)($_GET['article'] ?? '')) ? (int)$_GET['article'] : 0;

$article = $artId ? supportTenantArticle($artId, $ctx, $lang) : null;
if ($article) supportArticleCountView($artId);
$results = $q !== '' ? supportTenantSearch($q, $ctx, $lang) : [];
if ($q !== '') supportLogSearch($q, count($results));
$note = $q !== '' ? supportSearchNote($q, $ctx) : '';

$topics = [];
foreach ($all as $a) {
    if (!$a['category_id']) continue;
    $topics[$a['category_id']] ??= ['id' => $a['category_id'], 'name' => $a['category'], 'icon' => $a['category_icon'], 'sort' => $a['category_sort'], 'n' => 0];
    $topics[$a['category_id']]['n']++;
}
usort($topics, fn($x, $y) => [$x['sort'], $x['name']] <=> [$y['sort'], $y['name']]);
$topicName = '';
foreach ($topics as $tp) if ($tp['id'] === $topic) $topicName = $tp['name'];
$faq = array_values(array_filter($all, fn($a) => $a['is_faq']));
$popular = array_slice($all, 0, 6);

$helpUrl = getUrl('help');
$link = fn(array $qs) => $h($helpUrl . '?' . http_build_query($qs));
$artItem = function (array $a) use ($h, $link): string {
    return '<a class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2" href="' . $link(['article' => $a['id']]) . '">'
        . '<span class="help-ic"><i class="bi ' . ($a['has_video'] ? 'bi-play-circle' : 'bi-file-text') . '"></i></span>'
        . '<span class="flex-grow-1"><span class="d-block fw-semibold">' . $h($a['title']) . '</span>'
        . ($a['category'] !== '' ? '<small class="text-muted">' . $h($a['category']) . '</small>' : '') . '</span>'
        . '<i class="bi bi-chevron-right text-muted"></i></a>';
};

$__sup = isAdmin() ? supportSettings() : [];
$__sup_tel = static fn(string $n): string => preg_replace('/[^0-9+]/', '', $n);
$__sup_has = !empty($__sup) && ($__sup['support_email'] !== '' || $__sup['support_phone'] !== '' || $__sup['support_whatsapp'] !== '');
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-4 mb-3">
        <div>
            <h4 class="mb-0"><i class="bi bi-question-circle text-primary me-1"></i><?= t('Help & Support') ?></h4>
            <div class="text-muted small"><?= t('Learn how to do anything in the system, step by step.') ?></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 <?= $__sup_has ? 'col-lg-8' : '' ?>">
            <form method="get" action="<?= $h($helpUrl) ?>" class="mb-3" role="search">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="search" class="form-control" name="q" value="<?= $h($q) ?>" maxlength="100" placeholder="<?= $h(t('Search help')) ?>" aria-label="<?= $h(t('Search help')) ?>">
                    <button class="btn btn-primary" type="submit"><?= t('Search') ?></button>
                </div>
            </form>

<?php if ($artId): ?>
            <a href="<?= $h($helpUrl) ?>" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i><?= t('All help') ?></a>
    <?php if (!$article): ?>
            <div class="alert alert-light border mt-2"><?= t('This help article is not available.') ?></div>
    <?php else: ?>
            <div class="card border-0 shadow-sm mt-2">
                <div class="card-body p-4 help-art">
                    <?php if ($article['category'] !== ''): ?><div class="text-muted small mb-1"><?= $h($article['category']) ?></div><?php endif; ?>
                    <h5 class="fw-bold mb-3"><?= $h($article['title']) ?></h5>
                    <div><?= $article['html'] /* built by supportRenderBody() from escaped text only */ ?></div>
                    <div class="d-flex flex-wrap gap-2 my-3">
                        <?php if ($article['take']): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= $h($article['take']['url']) ?>"><?= t('Take me there') ?> → <?= $h($article['take']['label']) ?></a>
                        <?php endif; ?>
                        <?php if ($article['video_url'] !== ''): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= $h($article['video_url']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-play-circle me-1"></i><?= t('Watch the video') ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2 small text-muted border-top pt-3" id="helpFeedback">
                        <?php if (isset($_SESSION['support_voted'][$artId])): ?>
                            <?= t('Thanks for your feedback.') ?>
                        <?php else: ?>
                            <span><?= t('Was this helpful?') ?></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary js-vote" data-v="1" aria-label="<?= $h(t('Yes')) ?>"><i class="bi bi-hand-thumbs-up"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary js-vote" data-v="0" aria-label="<?= $h(t('No')) ?>"><i class="bi bi-hand-thumbs-down"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
    <?php endif; ?>

<?php elseif ($q !== ''): ?>
            <?php if ($note !== ''): ?><div class="alert alert-light border py-2 small"><?= $h($note) ?></div><?php endif; ?>
            <h6 class="text-muted text-uppercase small fw-bold"><?= count($results) ?> <?= t('results') ?></h6>
            <?php if ($results): ?>
            <div class="list-group shadow-sm"><?php foreach ($results as $a) echo $artItem($a); ?></div>
            <?php else: ?>
            <div class="border rounded p-4 text-center text-muted">
                <div class="fw-semibold text-body"><?= t('No articles found.') ?></div>
                <div class="small"><?= t('Try other words. Searches with no answer help us write new articles.') ?></div>
            </div>
            <?php endif; ?>

<?php elseif ($topic): ?>
            <a href="<?= $h($helpUrl) ?>" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i><?= t('All help') ?></a>
            <h5 class="fw-bold mt-2"><?= $h($topicName !== '' ? $topicName : t('Topic')) ?></h5>
            <?php $inTopic = array_values(array_filter($all, fn($a) => $a['category_id'] === $topic)); ?>
            <?php if ($inTopic): ?>
            <div class="list-group shadow-sm"><?php foreach ($inTopic as $a) echo $artItem($a); ?></div>
            <?php else: ?>
            <div class="border rounded p-4 text-center text-muted"><?= t('No articles found.') ?></div>
            <?php endif; ?>

<?php elseif (!$all): ?>
            <div class="border rounded p-5 text-center text-muted">
                <i class="bi bi-journal-text d-block mb-2" style="font-size:2rem"></i>
                <?= t('Help articles are being prepared. Please check back soon.') ?>
            </div>

<?php else: ?>
            <?php if ($topics): ?>
            <h6 class="text-muted text-uppercase small fw-bold"><?= t('Topics') ?></h6>
            <div class="row g-2 mb-4">
                <?php foreach ($topics as $tp): ?>
                <div class="col-6 col-md-4 col-xl-3">
                    <a class="card border h-100 text-decoration-none help-topic" href="<?= $link(['topic' => $tp['id']]) ?>">
                        <div class="card-body p-3">
                            <span class="help-ic mb-2"><i class="bi bi-<?= $h($tp['icon']) ?>"></i></span>
                            <div class="fw-semibold text-body"><?= $h($tp['name']) ?></div>
                            <small class="text-muted"><?= (int)$tp['n'] ?> <?= t('articles') ?></small>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($faq): ?>
            <h6 class="text-muted text-uppercase small fw-bold"><?= t('Frequently asked') ?></h6>
            <div class="list-group shadow-sm mb-4"><?php foreach ($faq as $a) echo $artItem($a); ?></div>
            <?php endif; ?>
            <h6 class="text-muted text-uppercase small fw-bold"><?= t('Popular') ?></h6>
            <div class="list-group shadow-sm"><?php foreach ($popular as $a) echo $artItem($a); ?></div>
<?php endif; ?>
        </div>

        <?php if ($__sup_has): ?>
        <div class="col-12 col-lg-4">
            <!-- BJP's contact details come from Superadmin › Support (control DB), shown to company admins only. -->
            <div class="card border-0 shadow-sm rounded-4 bg-light">
                <div class="card-body p-4 text-center">
                    <div class="bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 60px; height: 60px;">
                        <i class="bi bi-headset text-primary" style="font-size: 1.5rem;"></i>
                    </div>
                    <h5 class="fw-bold"><?= t('Need More Help?') ?></h5>
                    <p class="text-muted small mb-3"><?= t('Contact our support team for assistance with technical issues or questions.') ?></p>
                    <div class="d-grid gap-2">
                        <?php if ($__sup['support_email'] !== ''): ?>
                        <a href="mailto:<?= safe_output($__sup['support_email'], '') ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-envelope me-1"></i> <?= safe_output($__sup['support_email'], '') ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($__sup['support_phone'] !== ''): ?>
                        <a href="tel:<?= safe_output($__sup_tel($__sup['support_phone']), '') ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-telephone me-1"></i> <?= safe_output($__sup['support_phone'], '') ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($__sup['support_whatsapp'] !== ''): ?>
                        <a href="https://wa.me/<?= safe_output(ltrim($__sup_tel($__sup['support_whatsapp']), '+'), '') ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-whatsapp me-1"></i> <?= t('WhatsApp') ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php if ($__sup['support_hours'] !== ''): ?>
                    <div class="small text-muted mt-2"><i class="bi bi-clock me-1"></i><?= safe_output($__sup['support_hours'], '') ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.help-ic { width: 30px; height: 30px; border-radius: 8px; background: #e7f0ff; color: #0d6efd; display: inline-grid; place-items: center; flex: 0 0 auto; }
.help-topic:hover { border-color: #9ec5fe !important; }
.help-art ol, .help-art ul { padding-left: 1.3rem; }
.help-art li { margin-bottom: 4px; }
.help-art .help-path { background: #e7f0ff; color: #084298; border-radius: 4px; padding: 0 5px; font-weight: 600; }
.help-art .help-tip { background: rgba(13,110,253,.06); border-left: 3px solid #0d6efd; padding: 8px 10px; border-radius: 4px; margin: 8px 0; }
</style>

<?php if ($article && !isset($_SESSION['support_voted'][$artId])): ?>
<script>
document.querySelectorAll('.js-vote').forEach(function (b) {
    b.addEventListener('click', function () {
        const box = document.getElementById('helpFeedback');
        fetch(<?= json_encode(buildUrl('api/support_help.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'feedback', id: <?= (int)$artId ?>, helpful: b.dataset.v })
        }).finally(function () { box.textContent = <?= json_encode(t('Thanks for your feedback.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; });
    });
});
</script>
<?php endif; ?>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
