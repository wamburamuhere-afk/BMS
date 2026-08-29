<?php
/**
 * core/session_guard.php — early release of the PHP session file lock.
 *
 * THE PROBLEM
 * PHP's default `files` session handler takes an EXCLUSIVE lock on the session
 * file at session_start() and holds it until the script ends. BMS never called
 * session_write_close(), so every request belonging to one logged-in user was
 * serialised — opening a second page while the first was still loading made the
 * second wait for the whole of the first to finish before it could even begin.
 *
 * Measured before this change (4 concurrent requests, 1.5s of work each):
 *   same session cookie  -> waited 0.001s / 1.444s / 2.925s / 4.412s for the lock
 *   different cookies    -> waited 0.001s / 0.001s / 0.001s / 0.001s
 * That is why signing in from a different browser profile felt instant while the
 * everyday profile crawled: a different profile means a different session file,
 * and therefore no lock to queue behind.
 *
 * THE FIX
 * bmsSessionRelease() drops the lock as soon as the bootstrap has read the
 * session. After session_write_close(), $_SESSION remains an ordinary readable
 * AND writable PHP array, so no existing page logic has to change — flash
 * messages, csrf_token(), the project-scope cache and header.php's role writes
 * all keep working exactly as before.
 *
 * Anything written to $_SESSION after the release is persisted by
 * bmsSessionPersist() on shutdown. It re-opens the session for a few
 * microseconds and writes back ONLY the keys this request actually changed, so
 * it cannot clobber a concurrent request's writes (the old behaviour, in which
 * the last request to finish overwrote the whole session, was in fact worse).
 *
 * @see changelog.md — 2026-08-29
 */

if (!function_exists('bmsSessionRelease')) {

    /**
     * Release the session lock for the rest of this request.
     * Safe to call more than once; a no-op when no session is active.
     */
    function bmsSessionRelease(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        if (!empty($GLOBALS['__bms_session_released'])) return;   // already released

        $GLOBALS['__bms_session_snapshot'] = $_SESSION ?? [];
        $GLOBALS['__bms_session_id']       = session_id();
        $GLOBALS['__bms_session_released'] = true;

        session_write_close();                 // <-- the lock is gone from here on
        register_shutdown_function('bmsSessionPersist');
    }

    /**
     * Write back any $_SESSION changes made after the lock was released.
     * Registered as a shutdown function by bmsSessionRelease(); not called directly.
     */
    function bmsSessionPersist(): void
    {
        if (empty($GLOBALS['__bms_session_released'])) return;
        $GLOBALS['__bms_session_released'] = false;               // never run twice

        // The page re-opened the session itself (e.g. a guarded session_start(),
        // or bmsSessionReopen() before a destroy). PHP writes it out normally —
        // nothing for us to do, and touching it here would fight that.
        if (session_status() === PHP_SESSION_ACTIVE) return;

        // The session was destroyed during this request — must not resurrect it.
        if (!empty($GLOBALS['__bms_session_destroyed'])) return;

        $before = $GLOBALS['__bms_session_snapshot'] ?? [];
        $after  = $_SESSION ?? [];
        if ($after === $before) return;        // unchanged -> never take the lock at all

        // Re-open the SAME session briefly. use_cookies=0 because headers are
        // normally already sent by shutdown time and the browser already holds
        // this cookie; re-sending it would emit a "headers already sent" warning.
        session_id($GLOBALS['__bms_session_id']);
        if (!@session_start(['use_cookies' => 0, 'use_only_cookies' => 0, 'read_and_close' => false])) {
            error_log('bmsSessionPersist: could not re-open session to save changes');
            return;
        }

        foreach ($after as $k => $v) {
            if (!array_key_exists($k, $before) || $before[$k] !== $v) {
                $_SESSION[$k] = $v;            // added or modified by this request
            }
        }
        foreach ($before as $k => $_ignored) {
            if (!array_key_exists($k, $after)) {
                unset($_SESSION[$k]);          // removed by this request
            }
        }

        session_write_close();
    }

    /**
     * Re-acquire a real session before code that needs an ACTIVE session —
     * session_unset(), session_destroy(), session_regenerate_id().
     *
     * Those functions fail (and only raise a warning) when the session has been
     * closed, which would silently break a security logout. Call this first and
     * they behave exactly as they did before the early release existed.
     */
    function bmsSessionReopen(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        if (!empty($GLOBALS['__bms_session_id'])) {
            session_id($GLOBALS['__bms_session_id']);
        }
        @session_start(['use_cookies' => 0, 'use_only_cookies' => 0]);
        // The live session file now wins over our pre-release snapshot.
        $GLOBALS['__bms_session_snapshot'] = $_SESSION ?? [];
    }

    /**
     * Mark the session as destroyed so bmsSessionPersist() does not recreate it.
     * Call immediately after session_destroy().
     */
    function bmsSessionMarkDestroyed(): void
    {
        $GLOBALS['__bms_session_destroyed'] = true;
        $GLOBALS['__bms_session_released']  = false;
    }
}
