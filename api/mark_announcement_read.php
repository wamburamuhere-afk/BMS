<?php
// API: Mark an announcement read by the current user (Tier 4, Phase 4.2).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$id = intval($_POST['announcement_id'] ?? 0);
if (!$id) { echo json_encode(['success' => false, 'message' => 'Announcement id is required']); exit; }

try {
    // insert-ignore: reading twice is a no-op
    $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)")
        ->execute([$id, (int)$_SESSION['user_id']]);
    echo json_encode(['success' => true, 'message' => 'Marked read']);
} catch (Exception $e) {
    error_log("mark_announcement_read error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
