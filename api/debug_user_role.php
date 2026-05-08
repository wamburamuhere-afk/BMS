<?php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

// Debug: Show current user's role
echo json_encode([
    'user_id' => $_SESSION['user_id'] ?? 'NOT SET',
    'role' => $_SESSION['role'] ?? 'NOT SET',
    'username' => $_SESSION['username'] ?? 'NOT SET',
    'all_session_data' => $_SESSION
]);
?>
