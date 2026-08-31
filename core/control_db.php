<?php
/**
 * core/control_db.php
 * -------------------
 * The connection to `bms_control` — the registry that knows which tenants
 * exist, where their databases live, and how to authenticate to them.
 *
 * `bms_control` holds NO business data. It holds tenants, superadmins, and a
 * provisioning audit trail. Nothing in it is a financial record, so none of the
 * reporting rules in .claude/reporting-source.md apply to it.
 *
 * getControlPdo() is the ONLY function in this codebase permitted to hold its
 * own connection settings. Every other connection is derived from a tenant row
 * this one hands back (Phase 3). That is the whole point of the control DB: one
 * bootstrap connection, then everything else is data-driven.
 *
 * Credential resolution (first match wins):
 *   1. Environment variables CONTROL_DB_HOST / _NAME / _USER / _PASS
 *      — preferred in production; lets the control DB use its own
 *        least-privilege MySQL user without touching any file.
 *   2. The app's existing DB_SERVER / DB_USERNAME / DB_PASSWORD constants from
 *      includes/config.php, with the database name `bms_control`.
 *      — the zero-config local/WAMP path.
 *
 * Phase 9 hardens production onto a dedicated `bms_control_app` user restricted
 * to the three control tables. Until then, path 2 reuses the app credentials,
 * which is why this file must never be the thing that grants privileges — it
 * only consumes whatever it is given.
 *
 * Public API:
 *   controlDbName(): string        → configured control database name
 *   getControlPdo(): PDO           → shared connection (throws on failure)
 *   controlDbReady(): bool         → true if it connects AND the registry exists
 */

if (!function_exists('controlDbName')) {
    /** The control database's name. Overridable so staging can run its own registry. */
    function controlDbName(): string
    {
        $n = getenv('CONTROL_DB_NAME');
        return (is_string($n) && $n !== '') ? $n : 'bms_control';
    }
}

if (!function_exists('controlDbSettings')) {
    /**
     * Resolve host/name/user/pass for the control connection.
     * Kept separate from getControlPdo() so the provisioner (Phase 2) and the
     * migration can report *which* server they are about to touch before
     * touching it.
     *
     * @return array{host:string,name:string,user:string,pass:string,source:string}
     */
    function controlDbSettings(): array
    {
        $envHost = getenv('CONTROL_DB_HOST');
        $envUser = getenv('CONTROL_DB_USER');
        $envPass = getenv('CONTROL_DB_PASS');

        // An explicit CONTROL_DB_USER is what marks the environment as "configured".
        // Host and password alone are not enough — a blank password is legitimate.
        if (is_string($envUser) && $envUser !== '') {
            return [
                'host'   => (is_string($envHost) && $envHost !== '') ? $envHost : 'localhost',
                'name'   => controlDbName(),
                'user'   => $envUser,
                'pass'   => is_string($envPass) ? $envPass : '',
                'source' => 'environment (CONTROL_DB_*)',
            ];
        }

        // Fall back to the app's own credentials. config.php is per-environment and
        // untracked, so these constants are the local source of truth.
        if (!defined('DB_SERVER') || !defined('DB_USERNAME')) {
            $cfg = __DIR__ . '/../includes/config.php';
            if (is_file($cfg)) require_once $cfg;
        }

        if (!defined('DB_USERNAME')) {
            throw new RuntimeException(
                'Cannot resolve control-database credentials: neither CONTROL_DB_USER nor '
                . 'includes/config.php\'s DB_USERNAME is available.'
            );
        }

        return [
            'host'   => defined('DB_SERVER') ? DB_SERVER : 'localhost',
            'name'   => controlDbName(),
            'user'   => DB_USERNAME,
            'pass'   => defined('DB_PASSWORD') ? DB_PASSWORD : '',
            'source' => 'includes/config.php (app credentials)',
        ];
    }
}

if (!function_exists('getControlPdo')) {
    /**
     * Shared PDO handle for `bms_control`.
     *
     * Stricter than the app's legacy connection on purpose — this is new code with
     * no back-compat burden, and it handles credentials:
     *   - real prepared statements (no emulation)
     *   - exceptions on error
     *   - associative fetches by default
     *
     * @throws RuntimeException if the control database cannot be reached.
     */
    function getControlPdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;

        $s = controlDbSettings();

        try {
            $pdo = new PDO(
                'mysql:host=' . $s['host'] . ';dbname=' . $s['name'] . ';charset=utf8mb4',
                $s['user'],
                $s['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $pdo->exec("SET time_zone = '+03:00'");
        } catch (PDOException $e) {
            // Deliberately does not echo the password or the DSN's credentials.
            throw new RuntimeException(
                'Cannot connect to control database "' . $s['name'] . '" on ' . $s['host']
                . ' as user "' . $s['user'] . '" (credentials from ' . $s['source'] . '). '
                . 'Has migrations/2026_08_31_control_db_foundation.php been run? '
                . 'Underlying error: ' . $e->getMessage()
            );
        }

        return $pdo;
    }
}

if (!function_exists('controlDbReady')) {
    /**
     * True if the control DB is reachable AND the tenant registry exists.
     *
     * Connecting is not enough — during a partial deploy the database can exist
     * while its tables do not, and a caller that trusts a bare connection would
     * fail later with a confusing "table doesn't exist". Used by health checks
     * and by Phase 3 to decide whether multi-tenancy is live at all.
     */
    function controlDbReady(): bool
    {
        try {
            $pdo = getControlPdo();
            $pdo->query('SELECT 1 FROM tenants LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
