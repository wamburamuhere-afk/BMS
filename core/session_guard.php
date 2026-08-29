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
 * WHY session.use_cookies IS TURNED OFF AT RELEASE TIME
 * By shutdown the response headers have usually already gone out, and PHP then
 * refuses all three of the calls the write-back needs:
 *   ini_set('session.*') -> "Session ini settings cannot be changed after
 *                            headers have already been sent"
 *   session_id()         -> "Session ID cannot be changed after headers have
 *                            already been sent"
 *   session_start()      -> "Cannot start session when headers already sent"
 * The last two gates are lifted when session.use_cookies is 0, and the ini
 * itself can only be changed BEFORE any output. So it is switched off here, at
 * release time, while that is still legal. Suppressing the cookie is exactly
 * what we want anyway: the browser already holds it, and the write-back must
 * not try to re-send it.
 *
 * An earlier revision passed use_cookies through session_start()'s options
 * array instead. That is too late — session_id() had already failed, so
 * session_start() minted a brand-new session and the request's changes were
 * written to an orphan file and silently lost. Sentry caught it in production
 * (ErrorException at line 78, /purchase_orders); see the regression test in
 * tests/test_session_guard_cli.php.
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

        // Only release when the browser already holds this session's cookie.
        // On the one request where the session is brand new (login) the cookie
        // is still in flight, so keep PHP's default behaviour for that request
        // rather than risk writing the new session back under the wrong id.
        $sid = session_id();
        if ($sid === '' || ($_COOKIE[session_name()] ?? '') !== $sid) return;

        $GLOBALS['__bms_session_snapshot'] = $_SESSION ?? [];
        $GLOBALS['__bms_session_id']       = $sid;
        $GLOBALS['__bms_session_released'] = true;

        session_write_close();                 // <-- the lock is gone from here on

        // Must happen after the session is closed and before any output. See the
        // file header: this is what keeps the shutdown write-back legal.
        //   use_cookies=0   lifts the "headers already sent" gate on session_id()
        //                   and session_start(), and stops a pointless Set-Cookie.
        //   cache_limiter=''  stops session_start() trying to emit Cache-Control,
        //                   which otherwise fails with "Session cache limiter
        //                   cannot be sent after headers have already been sent"
        //                   and makes the whole re-open fail.
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');

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

        // The page re-opened the session itself (e.g. bmsSessionReopen() before a
        // destroy). PHP writes it out normally — nothing for us to do.
        if (session_status() === PHP_SESSION_ACTIVE) return;

        // The session was destroyed during this request — must not resurrect it.
        if (!empty($GLOBALS['__bms_session_destroyed'])) return;

        $before = $GLOBALS['__bms_session_snapshot'] ?? [];
        $after  = $_SESSION ?? [];
        if ($after === $before) return;        // unchanged -> never take the lock at all

        // Legal even after headers are sent, because bmsSessionRelease() already
        // set session.use_cookies=0 and session.cache_limiter=''.
        //
        // No options array: every entry in it is applied as an ini_set at start
        // time, which fails once headers are sent ("Setting option ... failed").
        // The settings that matter were applied at release time instead.
        session_id($GLOBALS['__bms_session_id']);
        if (!session_start()) {
            error_log('bmsSessionPersist: could not re-open session to save changes');
            return;
        }

        // Belt and braces: never write into a session that is not the one we read.
        if (session_id() !== ($GLOBALS['__bms_session_id'] ?? '')) {
            session_abort();                   // close without writing anything
            error_log('bmsSessionPersist: session id changed on reopen; changes not saved');
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

        // As in bmsSessionPersist(): no options array — use_cookies and
        // cache_limiter were already set at release time, and re-applying them
        // here fails once headers have been sent.
        $want = $GLOBALS['__bms_session_id'] ?? '';
        if ($want !== '') session_id($want);
        session_start();

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
