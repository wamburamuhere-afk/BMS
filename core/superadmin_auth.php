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

        static $cache = [];
        if (array_key_exists($id, $cache)) return $cache[$id];

        try {
            $st = getControlPdo()->prepare(
                "SELECT id, name, email, created_at, last_login FROM superadmins WHERE id = ? LIMIT 1"
            );
            $st->execute([$id]);
            $row = $st->fetch();
        } catch (Throwable $e) {
            error_log('currentSuperadmin: ' . $e->getMessage());
            return $cache[$id] = null;
        }

        if (!$row) {
            // The account is gone — drop the session rather than leaving a
            // half-authenticated request running.
            superadminLogout();
            return $cache[$id] = null;
        }
        return $cache[$id] = $row;
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
        return '/app/superadmin/login.php';
    }
}
