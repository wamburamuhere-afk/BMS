<?php
/**
 * scripts/migrate_tenant_one.php — ternant.md Phase 7: register the existing
 * production install as Tenant #1 and retire its root/blank-password DB
 * connection.
 *
 *   php scripts/migrate_tenant_one.php --owner-email=you@example.com
 *   php scripts/migrate_tenant_one.php --owner-email=you@example.com --subdomain=bjp --company="BJP Technologies Co. Ltd"
 *
 * WHAT THIS DOES NOT DO, ON PURPOSE:
 *   - Does not rename or move the `bms` database. docs/MULTI_TENANCY_CONVENTIONS.md
 *     §1: "Tenant #1 is the exception — the existing production database keeps
 *     the name `bms`." This script only ever reads DB_NAME, never computes one.
 *   - Does not touch a single document/upload. bmsTenantPathPrefix() already
 *     gives an empty prefix to any tenant whose db_name matches this install's
 *     own DB_NAME — which is exactly this row — so uploads/ stays exactly
 *     where it is.
 *   - Does not change how the site is reached. The bare production hostname
 *     resolves to 'status: none' in resolveTenantFromRequest() and falls
 *     through to bmsLegacyPdo() regardless of this row's existence — this
 *     script is what makes that fallback connection a properly-scoped user
 *     instead of root/blank, not a URL/DNS change.
 *
 * RESUMABLE. If this is re-run after a partial failure (row inserted but the
 * MySQL user step never completed), it picks up from the existing row rather
 * than inserting a second one or refusing outright — same "reserve row first,
 * finalise last" discipline core/tenant_provisioner.php's provisionTenant()
 * already uses, for the same reason: the row's own auto-increment id is what
 * the bms_u{id} username is derived from, so it must exist before the user
 * can be named.
 *
 * CLI ONLY.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../includes/config.php';   // DB_NAME/DB_SERVER — read, never computed
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/tenant_provisioner.php';   // generateTenantDbPassword, getProvisioningPdo, tenantSubdomainError
require_once __DIR__ . '/../core/tenant_crypto.php';        // encryptTenantSecret

function out(string $s): void { echo $s . "\n"; }
function bail(string $s): void { out("\n  ERROR: $s\n"); exit(1); }

if (!defined('DB_NAME') || !defined('DB_SERVER')) {
    bail('DB_NAME/DB_SERVER are not defined — includes/config.php did not load correctly.');
}

// ── Arguments ───────────────────────────────────────────────────────────────
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) {
        $args[strtolower($m[1])] = $m[2] ?? true;
    }
}

$ownerEmail = strtolower(trim((string)($args['owner-email'] ?? '')));
if ($ownerEmail === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    bail('A valid --owner-email is required.');
}

$subdomain = strtolower(trim((string)($args['subdomain'] ?? 'bjp')));
if ($err = tenantSubdomainError($subdomain)) {
    bail("--subdomain: $err");
}

$companyName = trim((string)($args['company'] ?? 'BJP Technologies Co. Ltd'));
if ($companyName === '') {
    bail('--company cannot be empty.');
}

try {
    $cpdo = getControlPdo();
} catch (Throwable $e) {
    bail("Cannot reach the control database.\n         " . $e->getMessage()
       . "\n         Run: php scripts/setup_control_db.php");
}

out("\n  BMS — Phase 7: register this install as Tenant #1");
out("  Target database: " . DB_NAME . "\n");

// ── Idempotency / resume ─────────────────────────────────────────────────────
$st = $cpdo->prepare("SELECT * FROM tenants WHERE db_name = ? LIMIT 1");
$st->execute([DB_NAME]);
$row = $st->fetch();

if ($row && !empty($row['db_username']) && !empty($row['db_password_encrypted'])) {
    out("  Already done. Tenant #{$row['id']} ({$row['company_name']}) is registered");
    out("  against `" . DB_NAME . "` with credentials `{$row['db_username']}`.");
    out("  Nothing to do — this script is safe to re-run and just reports status.\n");
    exit(0);
}

if ($row) {
    // A previous run got as far as reserving the row but not finishing —
    // resume with the SAME id rather than inserting a second row.
    $tenantId = (int)$row['id'];
    out("  Resuming an incomplete run — tenant row #{$tenantId} already reserved.");
} else {
    // A subdomain collision here means a DIFFERENT tenant already holds this
    // label — surfaced before any row is written, not after.
    if (!tenantSubdomainAvailable($subdomain)) {
        bail("Subdomain \"$subdomain\" is already taken by another tenant. Choose a different --subdomain.");
    }

    $cpdo->prepare("
        INSERT INTO tenants (company_name, subdomain, db_host, db_name, db_username, db_password_encrypted, status, owner_email, created_at, activated_at)
        VALUES (?, ?, ?, ?, '', '', 'active', ?, NOW(), NOW())
    ")->execute([$companyName, $subdomain, DB_SERVER, DB_NAME, $ownerEmail]);
    $tenantId = (int)$cpdo->lastInsertId();
    out("  Reserved tenant row #{$tenantId} ({$companyName}, subdomain \"$subdomain\").");
}

// ── Dedicated MySQL user, scoped to THIS database only ──────────────────────
// Same three statements provisionTenant() uses for every other tenant —
// the only difference is the database already exists, so there is no schema
// step here at all.
$dbUser     = 'bms_u' . $tenantId;
$dbPassword = generateTenantDbPassword();

try {
    $admin = getProvisioningPdo();
    $admin->exec("DROP USER IF EXISTS " . $admin->quote($dbUser) . "@'%'");
    $admin->exec("CREATE USER " . $admin->quote($dbUser) . "@'%' IDENTIFIED BY " . $admin->quote($dbPassword));
    $admin->exec("GRANT ALL PRIVILEGES ON `" . DB_NAME . "`.* TO " . $admin->quote($dbUser) . "@'%'");
    $admin->exec("FLUSH PRIVILEGES");
    out("  Created MySQL user `$dbUser`, scoped only to `" . DB_NAME . "`.");
} catch (Throwable $e) {
    bail("Could not create the dedicated MySQL user.\n         " . $e->getMessage()
       . "\n         The tenant row (#{$tenantId}) is already reserved — re-run this"
       . "\n         script once the underlying issue is fixed; it will resume from here.");
}

// ── Prove the new credentials actually work BEFORE anything depends on them ──
try {
    $verify = new PDO(
        'mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        $dbUser, $dbPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $verify->query("SELECT 1")->fetchColumn();
    out("  Verified `$dbUser` can connect to `" . DB_NAME . "` and run a query.");
} catch (Throwable $e) {
    bail("The new user was created but a connection with it failed: " . $e->getMessage()
       . "\n         Do NOT edit config.php yet. Re-run this script after investigating.");
}

// ── Finalise the tenant row ───────────────────────────────────────────────────
$encrypted = encryptTenantSecret($dbPassword);
$cpdo->prepare("UPDATE tenants SET db_username = ?, db_password_encrypted = ? WHERE id = ?")
     ->execute([$dbUser, $encrypted, $tenantId]);

out("\n  Tenant #{$tenantId} is fully registered.\n");
out("  ── Last manual step — update includes/config.php on THIS server ──\n");
out("      define('DB_USERNAME', '{$dbUser}');");
out("      define('DB_PASSWORD', '{$dbPassword}');");
out("");
out("  DB_SERVER and DB_NAME stay exactly as they are — only the two lines above change.");
out("  The password above is shown once and is stored only in encrypted form");
out("  (tenants.db_password_encrypted, decryptable only with this server's own");
out("  TENANT_CRED_KEY). Save it now if you need it again before editing config.php.");
out("");
out("  After editing config.php: reload Apache (sudo systemctl reload apache2) so no");
out("  worker keeps using the old root connection, then verify a normal login still");
out("  works before doing anything else.\n");
