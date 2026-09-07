<?php
/**
 * api/finalize_login.php
 * ----------------------------------------------------------------------------
 * The slow half of signing in, split out of actions/login.php so the login
 * request itself never waits on it.
 *
 * startUserSession() (core/session_tracker.php) does a real GeoIP HTTP lookup
 * (ip-api.com, up to a 3s timeout) and can trigger an SMTP-sent notification
 * email (unfamiliar login / concurrent login) — both genuine network calls
 * that have nothing to do with verifying a password. Running them inline in
 * actions/login.php used to make login itself only as fast as whichever of
 * those was slowest that day.
 *
 * login.php's JS fires this via navigator.sendBeacon immediately after a
 * successful login response, in parallel with the redirect to dashboard — it
 * never blocks the redirect and the user never sees it. Because this file
 * boots through roots.php, it inherits the normal early session-lock release
 * (core/session_guard.php), so its own slow work never holds up the very next
 * page the browser loads either.
 *
 * Idempotent: a session that already has session_row_id has already been
 * finalized (e.g. a duplicate/retried beacon), so this is a safe no-op.
 */

require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

if (!empty($_SESSION['session_row_id'])) {
    echo json_encode(['ok' => true, 'already' => true]);
    exit;
}

// sendBeacon may fire as the tab is navigating away — keep running
// server-side to completion regardless (the GeoIP call / email send should
// still happen), and lift the default execution time cap for the same reason.
ignore_user_abort(true);
set_time_limit(0);

require_once __DIR__ . '/../core/session_tracker.php';

try {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $sid = startUserSession($pdo, (int) $_SESSION['user_id'], $ip, $ua, session_id());
    if ($sid) $_SESSION['session_row_id'] = $sid;

    if (function_exists('logActivity')) {
        logActivity($pdo, (int) $_SESSION['user_id'], 'Login', 'Logged in to the system');
    }

    echo json_encode(['ok' => true, 'session_row_id' => $sid]);
} catch (Throwable $e) {
    error_log('finalize_login: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
