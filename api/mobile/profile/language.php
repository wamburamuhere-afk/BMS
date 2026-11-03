<?php
// scope-audit: skip — the caller's own preference only
// Same storage as the web language switch (api/set_language.php): setting user_language_{id}.
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
mobileJsonBody();

// Same rule as the web switch: when an admin set one language for the whole
// company (Settings → General → Language Mode), nobody can change their own.
if (function_exists('orgLanguage') && ($orgLang = orgLanguage()) !== null) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => t('Language is set by your organization'),
                      'language' => $orgLang, 'language_locked' => true]);
    exit;
}

$lang = (string)($_POST['language'] ?? $_POST['lang'] ?? '');
if (!in_array($lang, ['en', 'sw'], true)) { http_response_code(422); echo json_encode(['success'=>false,'message'=>"language must be 'en' or 'sw'"]); exit; }

require_once __DIR__ . '/../../../helpers.php';
save_setting('user_language_' . (int)$_SESSION['user_id'], $lang);
$_SESSION['user_lang'] = $lang;
echo json_encode(['success' => true, 'language' => $lang]);
