<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration (legacy): MM GL accounts provisioning...\n";
require __DIR__ . '/tenant/2026_09_26_mm_gl_accounts.php';
