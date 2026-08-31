<?php
/**
 * core/tenant_migration_bootstrap.php
 * ------------------------------------
 * What `roots.php` is to an app migration, this is to a `migrations/tenant/`
 * migration: it connects `$pdo` and hands control to the file.
 *
 * The critical difference: an app migration always connects to the ONE
 * database named by `includes/config.php`. A tenant migration must connect to
 * WHICHEVER tenant `core/tenant_migration_runner.php` is currently processing
 * — a different database on every subprocess invocation. There is no
 * config.php entry for "the current tenant"; the runner passes the connection
 * details via environment variables instead, one subprocess per (tenant,
 * migration file) pair.
 *
 * Why environment variables and not argv: passing a database password as a
 * command-line argument makes it visible to anyone who can run `ps` on the
 * host for the process's lifetime. Environment variables of a subprocess are
 * only readable by the same user (or root) via /proc, which is the same
 * exposure every other secret in this deploy already accepts.
 *
 * A migration file under migrations/tenant/ starts with exactly:
 *
 *     <?php
 *     if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
 *     require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
 *     global $pdo;   // connected to the ONE tenant this run is processing
 *
 * It must never require roots.php or includes/config.php — doing so would
 * silently reconnect $pdo to the main `bms` database mid-migration, and every
 * statement after that point would run against the wrong database.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$__host = getenv('TENANT_MIGRATION_DB_HOST');
$__name = getenv('TENANT_MIGRATION_DB_NAME');
$__user = getenv('TENANT_MIGRATION_DB_USER');
$__pass = getenv('TENANT_MIGRATION_DB_PASS');   // may legitimately be ''

if ($__host === false || $__name === false || $__user === false || $__pass === false) {
    fwrite(STDERR,
        "tenant_migration_bootstrap: TENANT_MIGRATION_DB_* environment variables are not set.\n"
      . "Migration files under migrations/tenant/ must be run through\n"
      . "core/tenant_migration_runner.php, never invoked directly with `php`.\n"
    );
    exit(1);
}

// Defence in depth: a migration file under migrations/tenant/ must never run
// against the platform databases, even if a future bug in the runner tried to
// point it there.
if (in_array($__name, ['bms_control', 'mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
    fwrite(STDERR, "tenant_migration_bootstrap: refusing to run a tenant migration against '{$__name}'.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        'mysql:host=' . $__host . ';dbname=' . $__name . ';charset=utf8mb4',
        $__user,
        $__pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    // Never echo the password; $e->getMessage() from a connection failure does
    // not include it, only host/db/user, which is safe to surface.
    fwrite(STDERR, "tenant_migration_bootstrap: could not connect to {$__name}: " . $e->getMessage() . "\n");
    exit(1);
}

unset($__host, $__name, $__user, $__pass);
