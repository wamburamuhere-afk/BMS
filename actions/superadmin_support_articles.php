<?php
/**
 * actions/superadmin_support_articles.php — help articles + categories
 * (support_center_plan.md, Phase 2). Control database only.
 * Guard order mirrors actions/superadmin_platform_settings.php: host, session, POST, CSRF.
 *
 * Actions: save_article · set_status · save_category · set_category_active · preview
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/support_articles.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

function sa_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sa_out(405, ['success' => false, 'message' => 'Method not allowed.']);
$me = currentSuperadmin();
if ($me === null) sa_out(401, ['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
csrf_check();

$action = (string)($_POST['action'] ?? '');
$actor  = (int)$me['id'];
$id     = ctype_digit((string)($_POST['id'] ?? '')) ? (int)$_POST['id'] : 0;

try {
    switch ($action) {
        case 'save_article': {
            $r = supportValidateArticle($_POST, getControlPdo());
            if (!$r['ok']) sa_out(422, ['success' => false, 'message' => reset($r['errors']), 'errors' => $r['errors']]);
            if ($id && !supportArticleGet($id)) sa_out(404, ['success' => false, 'message' => 'That article no longer exists.']);
            $publish = (string)($_POST['publish'] ?? '0') === '1';
            $newId = supportArticleSave($r['values'], $id ?: null, $actor, $publish ? 'published' : null);
            logTenantAdminAction(null, null, 'support_article', ($id ? 'Updated' : 'Created') . " help article #{$newId}" . ($publish ? ' (published)' : ''));
            sa_out(200, ['success' => true, 'id' => $newId, 'message' => $publish ? 'Article published.' : 'Article saved.']);
        }
        case 'set_status': {
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['draft', 'published', 'archived'], true)) sa_out(422, ['success' => false, 'message' => 'Unknown status.']);
            if (!$id || !supportArticleGet($id)) sa_out(404, ['success' => false, 'message' => 'That article no longer exists.']);
            supportArticleSetStatus($id, $status, $actor);
            logTenantAdminAction(null, null, 'support_article', "Help article #{$id} → {$status}");
            $msg = ['draft' => 'Moved to drafts.', 'published' => 'Article published.', 'archived' => 'Article archived.'][$status];
            sa_out(200, ['success' => true, 'message' => $msg]);
        }
        case 'save_category': {
            $r = supportValidateCategory($_POST);
            if (!$r['ok']) sa_out(422, ['success' => false, 'message' => reset($r['errors']), 'errors' => $r['errors']]);
            if ($id) {
                $chk = getControlPdo()->prepare('SELECT 1 FROM support_categories WHERE id = ?');
                $chk->execute([$id]);
                if (!$chk->fetchColumn()) sa_out(404, ['success' => false, 'message' => 'That category no longer exists.']);
            }
            $cid = supportCategorySave($r['values'], $id ?: null);
            logTenantAdminAction(null, null, 'support_category', ($id ? 'Updated' : 'Created') . " help category #{$cid}");
            sa_out(200, ['success' => true, 'id' => $cid, 'message' => 'Category saved.']);
        }
        case 'set_category_active': {
            $active = (string)($_POST['active'] ?? '') === '1';
            if (!$id || !supportCategorySetActive($id, $active)) sa_out(404, ['success' => false, 'message' => 'That category no longer exists.']);
            logTenantAdminAction(null, null, 'support_category', "Help category #{$id} " . ($active ? 'shown' : 'hidden'));
            sa_out(200, ['success' => true, 'message' => $active ? 'Category shown.' : 'Category hidden.']);
        }
        case 'preview': {
            $r = supportValidateArticle($_POST, null);
            $v = $r['values'];
            foreach (['body_en', 'body_sw', 'simple_body_en', 'simple_body_sw'] as $k) {
                if (mb_strlen($v[$k]) > 20000) sa_out(422, ['success' => false, 'message' => 'This text is too long to preview.']);
            }
            $product = ($_POST['product'] ?? '') === 'bms' ? 'bms' : 'pos';
            $role    = ($_POST['role'] ?? '') === 'staff' ? 'staff' : 'admin';
            $lang    = ($_POST['lang'] ?? '') === 'sw' ? 'sw' : 'en';
            $ctx = helpPreviewContext($product, $role);
            $v['page_keys'] = array_values(array_filter($v['page_keys'], fn($k) => isset(helpNavCatalog()[$k])));
            $loc = supportArticleLocalized($v, $lang, !empty($ctx['simple']));
            sa_out(200, [
                'success'    => true,
                'title_html' => supportRenderTitle(mb_substr($loc['title'], 0, 255), $ctx),
                'body_html'  => supportRenderBody($loc['body'], $ctx),
                'visible'    => supportArticleVisible($v, $ctx),
                'errors'     => $r['errors'],
            ]);
        }
        default:
            sa_out(400, ['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('superadmin_support_articles: ' . $e->getMessage());
    sa_out(500, ['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
