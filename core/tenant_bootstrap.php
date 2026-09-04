<?php
/**
 * core/tenant_bootstrap.php
 * -------------------------
 * Produces the $pdo that the whole application runs on — the single place where
 * "which database does this request talk to?" is answered.
 *
 * WHY THIS FILE EXISTS AT ALL (a correction to ternant.md's Phase 3):
 * the plan said "rewrite includes/config.php". That cannot work — config.php is
 * gitignored and has never been tracked, so a rewrite would never reach
 * production through the git deploy, and every environment would silently keep
 * its old single-tenant connection. The logic therefore lives here, in a tracked
 * file, and config.php stays a thin per-environment file that calls into it.
 * See docs/MULTI_TENANCY_CONVENTIONS.md §6.1.
 *
 * SAFE BY DEFAULT. With TENANT_MODE unset, bmsConnectPdo() connects using the
 * DB_* constants exactly as the application always has. Nothing about request
 * handling changes until an operator explicitly switches multi-tenancy on. That
 * is what lets this phase — the one that touches every single request — deploy
 * ahead of Phase 7 with no behavioural risk.
 *
 * How includes/config.php should call it (one-time manual edit per environment,
 * because config.php is per-environment and untracked):
 *
 *     date_default_timezone_set('Africa/Dar_es_Salaam');
 *     define('DB_SERVER', 'localhost');
 *     define('DB_USERNAME', 'root');
 *     define('DB_PASSWORD', '');
 *     define('DB_NAME', 'bms');
 *
 *     require_once __DIR__ . '/../core/tenant_bootstrap.php';
 *     $pdo = bmsConnectPdo();
 *
 * Public API:
 *   bmsConnectPdo(): PDO          → the connection for this request
 *   bmsCurrentTenant(): ?array    → resolved tenant row, or null in single-tenant mode
 *   bmsCurrentTenantId(): ?int
 *   bmsCurrentDbConfig(): array   → host/user/pass/name THIS request owns
 *   bmsCurrentDbName(): string    → the database name THIS request owns
 *   bmsTenantPathPrefix(): string → '' for the legacy install, 't{id}/' otherwise
 *   bmsBackupDir(): string        → the backup directory THIS request owns
 *
 * THE RULE THESE ACCESSORS EXIST TO ENFORCE (added 2026-09-03 after an
 * incident — see tenant_isolation_plan.md):
 *
 *     Application code must never name a database or a directory.
 *     It asks the request which one it is on.
 *
 * `DB_NAME` and `ROOT_DIR` are global and reachable from anywhere, so being
 * tenant-aware used to be optional — and optional means eventually forgotten.
 * api/backup_actions.php built its own mysqli from the DB_* constants, so a
 * tenant's Restore wrote over the MAIN database and destroyed it. Use these
 * accessors; never re-derive a database or path from the constants.
 */

require_once __DIR__ . '/tenant_resolver.php';
require_once __DIR__ . '/tenant_crypto.php';
require_once __DIR__ . '/feature_registry.php';

if (!function_exists('bmsCurrentTenant')) {
    /** The tenant row this request resolved to, or null when running single-tenant. */
    function bmsCurrentTenant(): ?array
    {
        return $GLOBALS['__bms_tenant'] ?? null;
    }
}

if (!function_exists('bmsCurrentTenantId')) {
    function bmsCurrentTenantId(): ?int
    {
        $t = bmsCurrentTenant();
        return $t ? (int)$t['id'] : null;
    }
}

if (!function_exists('bmsLegacyPdo')) {
    /**
     * The original single-tenant connection, byte-for-byte the behaviour that
     * shipped before multi-tenancy: same DSN, same attributes, same timezone,
     * same die() on failure.
     */
    function bmsLegacyPdo(): PDO
    {
        if (!defined('DB_SERVER') || !defined('DB_NAME')) {
            throw new RuntimeException(
                'bmsConnectPdo(): the DB_* constants are not defined. includes/config.php must '
                . 'define DB_SERVER/DB_USERNAME/DB_PASSWORD/DB_NAME before calling this.'
            );
        }
        try {
            $pdo = new PDO('mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME, DB_USERNAME, DB_PASSWORD);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("SET time_zone = '+03:00'");
            return $pdo;
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('bmsTenantHalt')) {
    /**
     * End the request with a plain, self-contained page.
     *
     * No database, no header.php, no assets — this runs precisely when the
     * tenant's database is unavailable or must not be touched, so it cannot
     * depend on anything that needs one. Deliberately says nothing about whether
     * a given subdomain exists beyond what the visitor already knows.
     */
    function bmsTenantHalt(int $httpCode, string $title, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$t}</title>
<style>
 body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
      font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
      background:#f6f7f9;color:#1f2430;padding:24px}
 .card{max-width:520px;background:#fff;border:1px solid #e3e6ec;border-radius:12px;
       padding:32px 36px;box-shadow:0 1px 3px rgba(16,24,40,.06)}
 h1{margin:0 0 12px;font-size:20px;font-weight:600}
 p{margin:0;color:#525a6b}
 @media(prefers-color-scheme:dark){
   body{background:#14161a;color:#e7e9ee}
   .card{background:#1c1f26;border-color:#2c313b;box-shadow:none}
   p{color:#a4acbb}}
</style></head>
<body><div class="card"><h1>{$t}</h1><p>{$m}</p></div></body></html>
HTML;
        exit;
    }
}

if (!function_exists('bmsConnectPdo')) {
    /**
     * Resolve the tenant for this request and return its connection.
     *
     * Every branch that is not a live, active tenant either falls back to the
     * legacy connection (when the request is legitimately not tenant-scoped) or
     * stops the request outright. It never guesses.
     */
    function bmsConnectPdo(): PDO
    {
        // Cleared up front, not just set on success: a CLI process that connects
        // for one tenant and then another must never carry the first tenant's
        // feature entitlements into the second. Null means "everything on",
        // which is correct for every non-tenant path below.
        $GLOBALS['__bms_features'] = null;

        $r = resolveTenantFromRequest();

        // ── Single-tenant: multi-tenancy switched off ────────────────────────
        if ($r['status'] === 'disabled') {
            return bmsLegacyPdo();
        }

        // ── The control database is unreachable ──────────────────────────────
        // Falling back to the main database here would serve one company's data
        // on another company's hostname, so the request stops instead.
        if ($r['status'] === 'error') {
            error_log('bmsConnectPdo: tenant resolution error: ' . ($r['error'] ?? 'unknown'));
            bmsTenantHalt(503, 'Service temporarily unavailable',
                'We could not reach the account directory. Please try again in a few minutes.');
        }

        // ── Not a tenant address ─────────────────────────────────────────────
        // The root domain, a reserved label, CLI (migrations, tests, cron). These
        // are the platform's own surfaces and use the main database.
        if ($r['status'] === 'none') {
            return bmsLegacyPdo();
        }

        // ── Looked like a tenant, but there is no such tenant ────────────────
        // Must NOT fall back: otherwise anyone who invents a hostname is served
        // the main database's data.
        if ($r['status'] === 'unknown') {
            bmsTenantHalt(404, 'Account not found',
                'There is no account at this address. Please check the web address and try again.');
        }

        $tenant = $r['tenant'];
        $status = (string)($tenant['status'] ?? '');

        if ($status === 'suspended') {
            // Scoped to this tenant alone — every other tenant is unaffected.
            bmsTenantHalt(403, 'Account suspended',
                'This account is currently suspended. Please contact your administrator.');
        }
        if ($status === 'deleted') {
            bmsTenantHalt(410, 'Account closed',
                'This account has been closed and its data is no longer available.');
        }
        if ($status !== 'active' && $status !== 'trial') {
            bmsTenantHalt(403, 'Account unavailable',
                'This account is not currently available. Please contact your administrator.');
        }

        // ── Cross-tenant session guard ───────────────────────────────────────
        // A session cookie carrying another tenant's id must never be honoured
        // here. ternant.md put this in header.php; it lives here instead because
        // 565 files include config.php while far fewer include header.php — a
        // guard that misses the API surface is not a guard.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionTenant = $_SESSION['tenant_id'] ?? null;
            if ($sessionTenant !== null && (int)$sessionTenant !== (int)$tenant['id']) {
                $_SESSION = [];
                if (ini_get('session.use_cookies') && !headers_sent()) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                }
                session_destroy();
                bmsTenantHalt(403, 'Please sign in again',
                    'Your session belongs to a different account. Please sign in again.');
            }
        }

        // ── Connect as the tenant's own MySQL user ───────────────────────────
        $password = decryptTenantSecret((string)$tenant['db_password_encrypted']);
        if ($password === null) {
            // Wrong or missing TENANT_CRED_KEY. Never fall back to the main
            // database — that is the failure mode this whole design exists to
            // prevent.
            error_log('bmsConnectPdo: cannot decrypt credentials for tenant '
                . $tenant['id'] . ' (' . $tenant['subdomain'] . ') — check TENANT_CRED_KEY.');
            bmsTenantHalt(503, 'Service temporarily unavailable',
                'We could not open this account right now. Please try again shortly.');
        }

        try {
            $pdo = new PDO(
                'mysql:host=' . $tenant['db_host'] . ';dbname=' . $tenant['db_name'] . ';charset=utf8mb4',
                $tenant['db_username'],
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec("SET time_zone = '+03:00'");
        } catch (PDOException $e) {
            // The message can carry credentials — log it, never show it.
            error_log('bmsConnectPdo: tenant ' . $tenant['id'] . ' (' . $tenant['subdomain']
                . ') connection failed: ' . $e->getMessage());
            bmsTenantHalt(503, 'Service temporarily unavailable',
                'We could not open this account right now. Please try again shortly.');
        }

        $GLOBALS['__bms_tenant'] = $tenant;
        // Which feature areas this company's subscription includes (Phase 11).
        // One small indexed read on the control connection that is already open,
        // resolved here — once — so every later check is an array lookup rather
        // than a query. Fails open if the control tables are not present yet.
        bmsPrimeTenantFeatures((int)$tenant['id']);
        // Cache the plaintext for bmsCurrentDbConfig() so callers that need
        // credentials (the mysqli-based dump/restore path) never decrypt twice.
        // Request-scoped only — never written anywhere.
        $GLOBALS['__bms_tenant_pw'] = $password;
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Pin the session to this tenant so the guard above has something to
            // compare against on the next request.
            $_SESSION['tenant_id'] = (int)$tenant['id'];
        }

        return $pdo;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// RESOURCE ACCESSORS — "which database / which directory does this request own?"
// ═════════════════════════════════════════════════════════════════════════════

if (!function_exists('bmsCurrentDbConfig')) {
    /**
     * Connection details for the database THIS request owns.
     *
     * Exists because some code legitimately needs *credentials* rather than the
     * PDO handle — restoring a dump needs mysqli's multi_query, which PDO cannot
     * do cleanly. Such code used to read the DB_* constants, which are the MAIN
     * database on every tenant's request. That is what destroyed a database.
     *
     * @return array{host:string,user:string,pass:string,name:string}
     * @throws RuntimeException if a tenant's credentials cannot be decrypted —
     *         never silently falls back to the main database.
     */
    function bmsCurrentDbConfig(): array
    {
        $t = bmsCurrentTenant();

        if ($t === null) {
            // Single-tenant, CLI, or a platform host — the legacy connection.
            if (!defined('DB_SERVER') || !defined('DB_NAME')) {
                throw new RuntimeException('bmsCurrentDbConfig(): DB_* constants are not defined.');
            }
            return [
                'host' => DB_SERVER,
                'user' => defined('DB_USERNAME') ? DB_USERNAME : '',
                'pass' => defined('DB_PASSWORD') ? DB_PASSWORD : '',
                'name' => DB_NAME,
            ];
        }

        $pass = $GLOBALS['__bms_tenant_pw'] ?? null;
        if ($pass === null) {
            $pass = decryptTenantSecret((string)($t['db_password_encrypted'] ?? ''));
        }
        if ($pass === null) {
            throw new RuntimeException(
                'bmsCurrentDbConfig(): cannot decrypt credentials for tenant ' . ($t['id'] ?? '?')
                . ' — check TENANT_CRED_KEY.'
            );
        }

        return [
            'host' => (string)$t['db_host'],
            'user' => (string)$t['db_username'],
            'pass' => (string)$pass,
            'name' => (string)$t['db_name'],
        ];
    }
}

if (!function_exists('bmsCurrentDbName')) {
    /** The database name this request owns. Use instead of reading DB_NAME. */
    function bmsCurrentDbName(): string
    {
        $t = bmsCurrentTenant();
        if ($t !== null) return (string)$t['db_name'];
        return defined('DB_NAME') ? (string)DB_NAME : '';
    }
}

if (!function_exists('bmsTenantPathPrefix')) {
    /**
     * Sub-path this request's files live under, '' or 't{id}/'.
     *
     * Returns '' for the LEGACY install — the tenant whose db_name is the
     * environment's own DB_NAME. That tenant *is* the original single-tenant
     * site, so its files already live at the unprefixed paths recorded in
     * document_library, and moving them would invalidate every stored path.
     * Only tenants provisioned into their own database get a prefix. Each host
     * judges against its own DB_NAME, so demo and production stay independent.
     */
    function bmsTenantPathPrefix(): string
    {
        $t = bmsCurrentTenant();
        if ($t === null) return '';
        if (defined('DB_NAME') && (string)$t['db_name'] === (string)DB_NAME) return '';
        return 't' . (int)$t['id'] . '/';
    }
}

if (!function_exists('bmsEnsureDirGuard')) {
    /**
     * Create $dir if needed and drop a deny-all .htaccess in it.
     *
     * Database dumps must never be fetchable over HTTP, and a directory created
     * at runtime would otherwise inherit no protection at all.
     */
    function bmsEnsureDirGuard(string $dir): void
    {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ht = rtrim($dir, '/\\') . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\nOptions -Indexes\n");
        }
    }
}

if (!function_exists('bmsUploadsRel')) {
    /**
     * Web/DB-relative uploads path for THIS request, e.g. 'uploads/contracts/'
     * or 'uploads/t9002/contracts/'.
     *
     * This is the value that gets STORED (document_library.file_path, and the
     * *_path columns on individual records), so it must carry the tenant
     * prefix — otherwise two tenants' rows point at the same file on disk.
     *
     * Always paired with bmsUploadsDir() for the same $sub: the stored path and
     * the written path must agree, or the file is written somewhere nothing
     * ever reads. Returns a trailing slash when $sub is given.
     */
    function bmsUploadsRel(string $sub = ''): string
    {
        $sub  = trim(str_replace('\\', '/', $sub), '/');
        $path = 'uploads/' . bmsTenantPathPrefix();
        return $sub === '' ? $path : $path . $sub . '/';
    }
}

if (!function_exists('bmsUploadsDir')) {
    /**
     * Absolute filesystem directory for THIS request's uploads, created on
     * demand with the standard deny-executables guard (.claude/security.md §19).
     *
     * The legacy install keeps the unprefixed path, so every path already in
     * document_library stays valid and no file has to move. Only tenants
     * provisioned into their own database get a prefix — and they have no
     * existing files, so this can never invalidate stored data.
     */
    function bmsUploadsDir(string $sub = ''): string
    {
        $base = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/';
        $dir  = $base . bmsUploadsRel($sub);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        bmsEnsureUploadGuard($dir);
        return $dir;
    }
}

if (!function_exists('bmsEnsureUploadGuard')) {
    /**
     * Drop the standard "no executables here" .htaccess into an uploads
     * directory. A directory created at runtime would otherwise inherit no
     * protection, which is how an uploaded .php becomes a shell.
     * Mirrors the policy in .claude/security.md §19.
     */
    function bmsEnsureUploadGuard(string $dir): void
    {
        $ht = rtrim($dir, '/\\') . '/.htaccess';
        if (is_file($ht)) return;
        @file_put_contents($ht,
            "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n"
          . "    Require all denied\n"
          . "</FilesMatch>\n"
          . "Options -ExecCGI\n"
          . "RemoveHandler .php .phtml .php5\n"
          . "RemoveType .php .phtml .php5\n");
    }
}

if (!function_exists('bmsBackupDir')) {
    /**
     * The backup directory THIS request owns, with a trailing slash.
     *
     * Every tenant subdomain is served from one webroot, so ROOT_DIR is
     * identical for all of them: a shared backups/ meant any tenant could list
     * and download every other tenant's full database dump. Each tenant now
     * gets its own subdirectory; the legacy install keeps the unprefixed path
     * so its existing dumps stay exactly where they are.
     */
    function bmsBackupDir(): string
    {
        $base = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/backups/';
        $dir  = $base . bmsTenantPathPrefix();
        bmsEnsureDirGuard($dir);
        return $dir;
    }
}
