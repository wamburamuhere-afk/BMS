<?php
/**
 * api/support_help.php — the ? Help panel's data (support_center_plan.md, Phase 3).
 *
 * Every answer is limited to PUBLISHED articles this signed-in user may read
 * (module + role, via canView), in this company's own wording. Titles are returned
 * as plain text (the client inserts them as text); article bodies are returned as
 * HTML that supportRenderBody() built from escaped text only.
 *
 * Actions (POST + CSRF): home · search · article · feedback
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/support_articles.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// This endpoint never includes header.php, so load the caller's saved language
// here or articles would always come back in English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('help')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$ctx  = helpRuntimeContext();
$lang = function_exists('currentLanguage') && currentLanguage() === 'sw' ? 'sw' : 'en';
$item = fn(array $a) => ['id' => $a['id'], 'title' => $a['title'], 'category' => $a['category'], 'has_video' => $a['has_video']];

try {
    switch ((string)($_POST['action'] ?? '')) {
        case 'home': {
            $pk = (string)($_POST['page_key'] ?? '');
            $pk = isset(helpNavCatalog()[$pk]) ? $pk : null;
            $all = supportTenantList($ctx, $lang);
            $here = $pk ? array_values(array_filter($all, fn($a) => in_array($pk, $a['page_keys'], true))) : [];
            $hereIds = array_column($here, 'id');
            $popular = array_values(array_filter($all, fn($a) => !in_array($a['id'], $hereIds, true)));
            echo json_encode([
                'success' => true,
                'page' => $pk ? (helpNavPath($pk, $ctx) ?? '') : '',
                'here' => array_map($item, array_slice($here, 0, 8)),
                'popular' => array_map($item, array_slice($popular, 0, 5)),
                'total' => count($all),
            ]);
            break;
        }
        case 'search': {
            $q = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 100);
            $hits = $q === '' ? [] : supportTenantSearch($q, $ctx, $lang);
            if ($q !== '' && (string)($_POST['log'] ?? '') === '1') supportLogSearch($q, count($hits));
            echo json_encode(['success' => true, 'note' => supportSearchNote($q, $ctx), 'results' => array_map($item, $hits)]);
            break;
        }
        case 'article': {
            $id = ctype_digit((string)($_POST['id'] ?? '')) ? (int)$_POST['id'] : 0;
            $pk = (string)($_POST['page_key'] ?? '');
            $a = $id ? supportTenantArticle($id, $ctx, $lang, isset(helpNavCatalog()[$pk]) ? $pk : null) : null;
            if (!$a) { http_response_code(404); echo json_encode(['success' => false, 'message' => t('This help article is not available.')]); break; }
            supportArticleCountView($id);
            $a['voted'] = isset($_SESSION['support_voted'][$id]);
            echo json_encode(['success' => true, 'article' => $a]);
            break;
        }
        case 'feedback': {
            $id = ctype_digit((string)($_POST['id'] ?? '')) ? (int)$_POST['id'] : 0;
            if (!$id || !supportTenantArticle($id, $ctx, $lang)) { http_response_code(404); echo json_encode(['success' => false, 'message' => t('This help article is not available.')]); break; }
            $helpful = (string)($_POST['helpful'] ?? '') === '1';
            $counted = supportArticleVote($id, $helpful);
            if ($counted) logActivity($pdo, (int)$_SESSION['user_id'], 'help_feedback', "Help article #{$id} marked " . ($helpful ? 'helpful' : 'not helpful'));
            echo json_encode(['success' => true, 'counted' => $counted, 'message' => t('Thanks for your feedback.')]);
            break;
        }
        case 'dismiss_notice': {
            require_once __DIR__ . '/../core/support_notices.php';
            require_once __DIR__ . '/../core/support_requests.php';
            $nid = ctype_digit((string)($_POST['id'] ?? '')) ? (int)$_POST['id'] : 0;
            $ok = supportDismissNotice($nid, supportTenantId(), (int)$_SESSION['user_id']);
            if ($ok) logActivity($pdo, (int)$_SESSION['user_id'], 'notice_dismiss', "Dismissed platform notice #{$nid}");
            if (!$ok) http_response_code(404);
            echo json_encode(['success' => $ok]);
            break;
        }
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('support_help: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Help is not available right now. Please try again.')]);
}
