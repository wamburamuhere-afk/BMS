<?php
/**
 * api/account/run_recurring_now.php
 * Manual "Run now" trigger for the recurring engine (the daily cron handles the
 * automatic path). Generates every due profile and returns the counts.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/recurring.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();
if (!canEdit('expenses')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot run recurring documents']);
    exit;
}

try {
    $summary = recurringRunAll($pdo);
    if (function_exists('save_setting')) save_setting('recurring_last_run', date('Y-m-d'));
    logActivity($pdo, $_SESSION['user_id'], "Ran recurring documents", "{$summary['generated']} generated, {$summary['skipped']} skipped");
    echo json_encode(['success' => true, 'message' => "Done: {$summary['generated']} document(s) generated.", 'summary' => $summary]);
} catch (Throwable $e) {
    error_log('run_recurring_now error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not run recurring documents.']);
}
