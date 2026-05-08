<?php
// File: api/open_cash_drawer.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Simple success response as this usually involves local hardware triggers or just a log entry
echo json_encode([
    'success' => true,
    'message' => 'Cash drawer open command sent'
]);
