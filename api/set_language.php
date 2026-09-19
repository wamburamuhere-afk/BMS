<?php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
csrf_check();

$lang = $_POST['lang'] ?? '';
if (!in_array($lang, ['en', 'sw'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid language']);
    exit;
}

require_once __DIR__ . '/../helpers.php';
save_setting('user_language_' . $_SESSION['user_id'], $lang);
$_SESSION['user_lang'] = $lang;

echo json_encode(['success' => true, 'lang' => $lang]);
