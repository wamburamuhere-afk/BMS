<?php
/**
 * api/cron/trial_reminders.php — daily: "your access ends soon" emails for trials and paid plans,
 * 7, 3 and 1 day before the end date and on the day (core/subscription_reminders.php).
 * Safe to run several times a day: each step is sent once per period.
 *
 * Authentication: Bearer token matching the CRON_SECRET env variable.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/subscription_reminders.php';

header('Content-Type: application/json');

$expectedSecret = trim((string)getenv('CRON_SECRET'));
if ($expectedSecret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'CRON_SECRET not configured']);
    exit;
}
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHeader === '' && function_exists('getallheaders')) {
    $hdrs       = getallheaders();
    $authHeader = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
}
if (!hash_equals('Bearer ' . $expectedSecret, trim($authHeader))) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

ignore_user_abort(true);
set_time_limit(120);

try {
    echo json_encode(['ok' => true] + runSubscriptionReminders() + ['ran_at' => date('c')]);
} catch (Throwable $e) {
    error_log('trial_reminders.php fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}
