<?php
/**
 * core/superadmin_auth.php
 * ------------------------
 * Authentication for PLATFORM OPERATORS — the people who create, suspend and
 * delete tenants. Completely separate from tenant user authentication.
 *
 * The separation is the security property, so it is enforced three ways:
 *
 *   1. DIFFERENT STORE. Superadmins live in bms_control.superadmins, reached only
 *      through getControlPdo(). No tenant's `users` table can mint one, and a
 *      tenant database compromise cannot create a platform operator.
 *   2. DIFFERENT SESSION KEY. $_SESSION['superadmin_id'], never 'user_id'. A
 *      tenant admin — even one with is_admin = 1 — is not a superadmin, and
 *      requireSuperadmin() never consults tenant session keys.
 *   3. DIFFERENT HOST. Once multi-tenancy is on, superadmin pages refuse to run
 *      on a tenant's subdomain, and refuse to run anywhere but the configured
 *      superadmin host. So a tenant cannot reach this surface at all.
 *
 * Public API:
 *   superadminId(): ?int
 *   isSuperadminLoggedIn(): bool
 *   currentSuperadmin(): ?array
 *   attemptSuperadminLogin(string $email, string $password): array
 *   superadminLogout(): void
 *   requireSuperadmin(): void            → redirects to the login page
 *   assertSuperadminHost(): void         → halts if reached from a tenant host
 *   superadminHostLabel(): string
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/tenant_resolver.php';

/** Lockout policy, per .claude/security.md §20. */
const SUPERADMIN_MAX_ATTEMPTS   = 5;
const SUPERADMIN_LOCKOUT_MINUTES = 15;

if (!function_exists('superadminHostLabel')) {
    /** The subdomain the superadmin panel lives on. Overridable per environment. */
    function superadminHostLabel(): string
    {
        $l = strtolower(trim((string)getenv('SUPERADMIN_SUBDOMAIN')));
        return $l !== '' ? $l : 'superadmin';
    }
}

if (!function_exists('superadminSessionReady')) {
    /** Make sure a session exists without assuming the caller started one. */
    function superadminSessionReady(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }
}

if (!function_exists('superadminId')) {
    function superadminId(): ?int
    {
        superadminSessionReady();
        $id = $_SESSION['superadmin_id'] ?? null;
        return ($id === null || $id === '') ? null : (int)$id;
    }
}

if (!function_exists('isSuperadminLoggedIn')) {
    function isSuperadminLoggedIn(): bool
    {
        return superadminId() !== null;
    }
}

if (!function_exists('currentSuperadmin')) {
    /**
     * The logged-in superadmin's row, re-read from the control database.
     *
     * Deliberately re-read rather than trusted from the session: an operator
     * deleted since they signed in must stop being a superadmin immediately, not
     * whenever their session happens to expire.
     */
    function currentSuperadmin(): ?array
    {
        $id = superadminId();
        if ($id === null) return null;

        // Backed by a global rather than a function-level `static` purely so it
        // can be invalidated — see forgetCurrentSuperadmin(). Negative results
        // are cached too (array_key_exists, not isset), so a deleted account
        // does not re-query on every call within a request.
        if (!isset($GLOBALS['__bms_sa_cache']) || !is_array($GLOBALS['__bms_sa_cache'])) {
            $GLOBALS['__bms_sa_cache'] = [];
        }
        if (array_key_exists($id, $GLOBALS['__bms_sa_cache'])) {
            return $GLOBALS['__bms_sa_cache'][$id];
        }

        try {
            $st = getControlPdo()->prepare(
                "SELECT id, name, email, created_at, last_login FROM superadmins WHERE id = ? LIMIT 1"
            );
            $st->execute([$id]);
            $row = $st->fetch();
        } catch (Throwable $e) {
            error_log('currentSuperadmin: ' . $e->getMessage());
            // Must drop the session, not just report "no row": leaving
            // $_SESSION['superadmin_id'] set here made isSuperadminLoggedIn()
            // (session-only) and this function (DB-backed) disagree — login.php
            // kept sending an "already signed in" visitor to '/', which
            // requireSuperadmin() kept bouncing back to /login on every one of
            // these errors, an infinite loop the browser reports as
            // ERR_TOO_MANY_REDIRECTS.
            superadminLogout();
            return $GLOBALS['__bms_sa_cache'][$id] = null;
        }

        if (!$row) {
            // The account is gone — drop the session rather than leaving a
            // half-authenticated request running.
            superadminLogout();
            return $GLOBALS['__bms_sa_cache'][$id] = null;
        }
        return $GLOBALS['__bms_sa_cache'][$id] = $row;
    }
}

if (!function_exists('forgetCurrentSuperadmin')) {
    /**
     * Drop the memoised operator row so the next currentSuperadmin() re-reads it.
     *
     * Exists because of a real defect this codebase's own test caught: after an
     * operator changed their own email, tenant_admin_log kept attributing their
     * subsequent actions to the OLD address for the rest of that request, because
     * the row had already been memoised. An audit trail that records a stale
     * identity is worse than one that costs an extra query.
     */
    function forgetCurrentSuperadmin(): void
    {
        $GLOBALS['__bms_sa_cache'] = [];
    }
}

if (!function_exists('attemptSuperadminLogin')) {
    /**
     * Verify credentials and open a superadmin session.
     *
     * @return array{ok:bool, error:?string}
     *
     * Failures return one generic message on purpose: distinguishing "no such
     * account" from "wrong password" would turn this into an account-enumeration
     * oracle for the platform's most valuable credential. The lockout message is
     * the one exception, because a user who is locked out needs to know to wait.
     */
    function attemptSuperadminLogin(string $email, string $password): array
    {
        $generic = 'Invalid email or password.';
        $email   = strtolower(trim($email));

        if ($email === '' || $password === '') {
            return ['ok' => false, 'error' => $generic];
        }

        try {
            $pdo = getControlPdo();
        } catch (Throwable $e) {
            error_log('attemptSuperadminLogin: control DB unreachable: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The account directory is unavailable. Please try again shortly.'];
        }

        $st = $pdo->prepare("SELECT * FROM superadmins WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        $sa = $st->fetch();

        // Constant-ish work whether or not the account exists, so response time
        // does not reveal which emails are registered.
        if (!$sa) {
            password_verify($password, '$2y$12$usesomesillystringfoeleadingtoanunusablehashvalue.');
            return ['ok' => false, 'error' => $generic];
        }

        if (!empty($sa['locked_until']) && strtotime((string)$sa['locked_until']) > time()) {
            $mins = max(1, (int)ceil((strtotime((string)$sa['locked_until']) - time()) / 60));
            return ['ok' => false, 'error' => "Too many failed attempts. Try again in {$mins} minute(s)."];
        }

        if (!password_verify($password, (string)$sa['password_hash'])) {
            // ORDER MATTERS. MySQL evaluates UPDATE assignments left to right, so
            // locked_until must be computed BEFORE failed_attempts is incremented —
            // otherwise `failed_attempts + 1` reads the already-incremented value
            // and the account locks one attempt early (4 instead of 5).
            $pdo->prepare("
                UPDATE superadmins
                   SET locked_until = IF(failed_attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until),
                       failed_attempts = failed_attempts + 1
                 WHERE id = ?
            ")->execute([SUPERADMIN_MAX_ATTEMPTS, SUPERADMIN_LOCKOUT_MINUTES, $sa['id']]);
            error_log('Failed superadmin login for ' . $email . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
            return ['ok' => false, 'error' => $generic];
        }

        $pdo->prepare("UPDATE superadmins SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")
            ->execute([$sa['id']]);

        superadminSessionReady();

        // A superadmin session and a tenant-user session must never coexist in
        // one browser session: whichever is established last wins outright.
        // Otherwise a page could read one identity while a guard checked the other.
        unset($_SESSION['user_id'], $_SESSION['role_id'], $_SESSION['role'],
              $_SESSION['user_role'], $_SESSION['first_name'], $_SESSION['last_name']);

        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);      // §20 — prevent session fixation
        }
        $_SESSION['superadmin_id'] = (int)$sa['id'];

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('superadminLogout')) {
    function superadminLogout(): void
    {
        superadminSessionReady();
        unset($_SESSION['superadmin_id']);
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }
}

if (!function_exists('assertSuperadminHost')) {
    /**
     * Refuse to serve the superadmin surface from a tenant's address.
     *
     * With multi-tenancy off (single-tenant / local development) there are no
     * tenant hosts, so this is a no-op and the panel is reachable normally.
     */
    function assertSuperadminHost(): void
    {
        if (!tenantModeEnabled()) return;

        $r = resolveTenantFromRequest();

        // Reached via a tenant's subdomain — never allowed.
        if (($r['status'] ?? '') === 'found' || ($r['status'] ?? '') === 'unknown') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Not found');
        }

        // Must be the configured superadmin host specifically, not merely "not a
        // tenant" — otherwise the panel would also answer on the marketing root.
        $host  = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
        $base  = tenantBaseDomain();
        $want  = superadminHostLabel() . ($base !== null ? '.' . $base : '');
        if ($base !== null && $host !== $want) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Not found');
        }
    }
}

if (!function_exists('requireSuperadmin')) {
    /**
     * Gate for every superadmin page. Checks the host first, so a tenant probing
     * the panel gets a flat 404 rather than a login page that confirms it exists.
     *
     * Never consults $_SESSION['user_id'] or is_admin — a tenant administrator is
     * not a platform operator.
     */
    function requireSuperadmin(): void
    {
        assertSuperadminHost();

        if (currentSuperadmin() === null) {
            if (!headers_sent()) {
                header('Location: ' . superadminLoginUrl());
            }
            exit;
        }
    }
}

if (!function_exists('superadminLoginUrl')) {
    function superadminLoginUrl(): string
    {
        return saUrl('login');
    }
}

// ─── Short, host-scoped panel URLs ──────────────────────────────────────────
// The panel lives on its own hostname, so it can own the root of that hostname:
// superadmin.example.tz/tenants rather than
// superadmin.example.tz/app/superadmin/tenants.php. The long form leaked the
// repository layout into the address bar for no benefit.
//
// These routes are HOST-SCOPED and that is not incidental. `$routes` in roots.php
// already maps 'profile', 'login' and 'logout' to TENANT pages; claiming those
// words globally would hijack them for every company. They are resolved only when
// the request is actually on the superadmin host, which no tenant can reach.

if (!function_exists('isSuperadminHost')) {
    /**
     * Is THIS request on the superadmin hostname?
     *
     * False with multi-tenancy off: a single-tenant install has no hostname to
     * scope to, so the panel keeps its literal /app/superadmin/... paths there
     * and nothing about local development changes.
     */
    function isSuperadminHost(): bool
    {
        if (!function_exists('tenantModeEnabled') || !tenantModeEnabled()) return false;

        $base = tenantBaseDomain();
        if ($base === null || $base === '') return false;

        $host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
        return $host === superadminHostLabel() . '.' . $base;
    }
}

if (!function_exists('superadminRouteMap')) {
    /** Short URL → the file that serves it. The single source for both directions. */
    function superadminRouteMap(): array
    {
        $d = dirname(__DIR__) . '/app/superadmin/';
        return [
            ''             => $d . 'index.php',
            'dashboard'    => $d . 'dashboard.php',
            'settings'     => $d . 'settings.php',
            'plans'        => $d . 'plans.php',
            'tenants'      => $d . 'tenants.php',
            'tenants/new'  => $d . 'tenant_new.php',
            'tenants/view' => $d . 'tenant_view.php',
            'features'     => $d . 'features.php',
            'profile'      => $d . 'profile.php',
            'login'        => $d . 'login.php',
            'logout'       => $d . 'logout.php',
        ];
    }
}

if (!function_exists('superadminShortUrlFor')) {
    /**
     * The short URL a legacy /app/superadmin/... path should redirect to, or null
     * if the path is not a panel page.
     *
     * Pulled out of handleRoute() so it can be asserted directly: a CLI test can
     * observe a 301 status but not the Location header, which would have left the
     * most important half of that redirect — where it actually points — untested.
     *
     * @param string $cleanUri path with no leading slash, e.g. 'app/superadmin/tenants.php'
     */
    function superadminShortUrlFor(string $cleanUri): ?string
    {
        $prefix = 'app/superadmin/';
        if (!str_starts_with($cleanUri, $prefix)) return null;

        $leaf = substr($cleanUri, strlen($prefix));
        if (str_ends_with($leaf, '.php')) $leaf = substr($leaf, 0, -4);
        if ($leaf === '') return null;

        foreach (superadminRouteMap() as $key => $file) {
            if (basename($file, '.php') === $leaf) return '/' . $key;
        }
        return null;
    }
}

if (!function_exists('saUrl')) {
    /**
     * A link to a panel page: '/tenants' on the superadmin host, and the literal
     * '/app/superadmin/tenants.php' anywhere the short routes are not active, so
     * single-tenant and local installs keep working unchanged.
     *
     * @param string $key a key of superadminRouteMap(), optionally with a query
     *                    string ('tenants/view?id=7')
     */
    function saUrl(string $key): string
    {
        $key   = ltrim($key, '/');
        $qs    = '';
        if (($p = strpos($key, '?')) !== false) {
            $qs  = substr($key, $p);
            $key = substr($key, 0, $p);
        }

        if (isSuperadminHost()) {
            return '/' . $key . $qs;
        }

        $map  = superadminRouteMap();
        $file = $map[$key] ?? null;
        if ($file === null) return '/' . $key . $qs;

        return '/app/superadmin/' . basename($file) . $qs;
    }
}

// ─── Self-service account management ────────────────────────────────────────
// Added 2026-09-02. Before this, the only way to change a superadmin's password
// was scripts/create_superadmin.php or raw SQL — an operator could not rotate
// their own credential from inside the panel they administer the platform with.

if (!function_exists('superadminPasswordError')) {
    /**
     * The one place the superadmin password rule is expressed.
     *
     * Deliberately identical to the tenant-signup rule in
     * core/tenant_registration.php (.claude/security.md §20): 8+ characters,
     * at least one letter and one digit. A platform operator's credential must
     * never be weaker than the customers' — and two different rules in one
     * codebase is how one of them silently rots.
     */
    function superadminPasswordError(string $pw, string $confirm): ?string
    {
        if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            return 'Password must be at least 8 characters and include a letter and a number.';
        }
        if ($confirm !== $pw) {
            return 'The two passwords do not match.';
        }
        return null;
    }
}

if (!function_exists('updateSuperadminProfile')) {
    /**
     * Change a superadmin's own name and/or email.
     *
     * @return array{ok:bool, error:?string}
     *
     * The current password is required even for a name change. Email IS a login
     * credential here, so changing it is a credential change; requiring the
     * password means a hijacked, still-open session cannot quietly move the
     * account to an attacker's address and lock the real operator out.
     */
    function updateSuperadminProfile(int $id, string $name, string $email, string $currentPassword): array
    {
        $name  = trim($name);
        $email = strtolower(trim($email));

        if ($name === '' || mb_strlen($name) < 2) {
            return ['ok' => false, 'error' => 'Please enter your name.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        }

        try {
            $pdo = getControlPdo();
        } catch (Throwable $e) {
            error_log('updateSuperadminProfile: control DB unreachable: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The account directory is unavailable. Please try again shortly.'];
        }

        $st = $pdo->prepare("SELECT * FROM superadmins WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $sa = $st->fetch();
        if (!$sa) {
            return ['ok' => false, 'error' => 'Your account no longer exists.'];
        }

        if (!password_verify($currentPassword, (string)$sa['password_hash'])) {
            return ['ok' => false, 'error' => 'Your current password is incorrect.'];
        }

        // Uniqueness is enforced here AND by the table's unique index; this
        // check exists to return a usable message rather than a 23000 error.
        $dup = $pdo->prepare("SELECT 1 FROM superadmins WHERE email = ? AND id <> ? LIMIT 1");
        $dup->execute([$email, $id]);
        if ($dup->fetchColumn()) {
            return ['ok' => false, 'error' => 'Another operator already uses that email address.'];
        }

        try {
            $pdo->prepare("UPDATE superadmins SET name = ?, email = ? WHERE id = ?")
                ->execute([$name, $email, $id]);
        } catch (Throwable $e) {
            error_log('updateSuperadminProfile: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The change could not be saved.'];
        }

        // The operator row is memoised per request; without this, anything later
        // in the SAME request — notably tenant_admin_log's attribution — would
        // still record the old name and email.
        forgetCurrentSuperadmin();

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('changeSuperadminPassword')) {
    /**
     * Change a superadmin's own password.
     *
     * @return array{ok:bool, error:?string}
     *
     * On success the session ID is regenerated. A password change is exactly the
     * moment to close any session fixated before it, and the operator stays
     * signed in on this device rather than being bounced to the login page.
     */
    function changeSuperadminPassword(int $id, string $current, string $new, string $confirm): array
    {
        if ($current === '' || $new === '') {
            return ['ok' => false, 'error' => 'Please fill in every field.'];
        }
        if ($err = superadminPasswordError($new, $confirm)) {
            return ['ok' => false, 'error' => $err];
        }

        try {
            $pdo = getControlPdo();
        } catch (Throwable $e) {
            error_log('changeSuperadminPassword: control DB unreachable: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The account directory is unavailable. Please try again shortly.'];
        }

        $st = $pdo->prepare("SELECT * FROM superadmins WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $sa = $st->fetch();
        if (!$sa) {
            return ['ok' => false, 'error' => 'Your account no longer exists.'];
        }

        if (!password_verify($current, (string)$sa['password_hash'])) {
            return ['ok' => false, 'error' => 'Your current password is incorrect.'];
        }
        if (password_verify($new, (string)$sa['password_hash'])) {
            return ['ok' => false, 'error' => 'The new password must be different from the current one.'];
        }

        try {
            // failed_attempts / locked_until are cleared: the operator has just
            // proved possession of the current password, so an earlier lockout
            // counter is stale and would only lock out the legitimate owner.
            $pdo->prepare("UPDATE superadmins SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
        } catch (Throwable $e) {
            error_log('changeSuperadminPassword: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The change could not be saved.'];
        }

        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        forgetCurrentSuperadmin();

        return ['ok' => true, 'error' => null];
    }
}
