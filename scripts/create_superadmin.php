<?php
/**
 * scripts/create_superadmin.php — create or update a platform operator.
 *
 *   php scripts/create_superadmin.php --email=ops@example.com --name="Ops Team"
 *   php scripts/create_superadmin.php --email=ops@example.com --password='...'
 *   php scripts/create_superadmin.php --email=ops@example.com --reset-password
 *   php scripts/create_superadmin.php --list
 *
 * With no --password, a strong one is generated and printed ONCE. It is never
 * stored in plaintext and cannot be recovered afterwards — only reset.
 *
 * CLI ONLY. scripts/ is blocked over HTTP by .htaccess, and this refuses to run
 * under any web SAPI regardless. That matters more here than anywhere else in
 * the codebase: this creates the credential that governs every tenant.
 *
 * The superadmins table ships EMPTY on purpose — there is no default account and
 * no default password. This script is how the first operator comes into being,
 * deliberately, at go-live.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../core/control_db.php';

// ── Arguments ───────────────────────────────────────────────────────────────
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) {
        $args[strtolower($m[1])] = $m[2] ?? true;
    }
}

function out(string $s): void { echo $s . "\n"; }
function bail(string $s): void { out("\n  ERROR: $s\n"); exit(1); }

try {
    $pdo = getControlPdo();
} catch (Throwable $e) {
    bail("Cannot reach the control database.\n         " . $e->getMessage()
       . "\n         Has migrations/2026_08_31_control_db_foundation.php been run?");
}

// The hardening columns are required for the lockout logic in core/superadmin_auth.php.
$cols = $pdo->query("
    SELECT column_name FROM information_schema.columns
    WHERE table_schema = " . $pdo->quote(controlDbName()) . " AND table_name = 'superadmins'
")->fetchAll(PDO::FETCH_COLUMN);
foreach (['failed_attempts', 'locked_until', 'last_login'] as $need) {
    if (!in_array($need, $cols, true)) {
        bail("superadmins.$need is missing.\n         Run: php migrations/2026_08_31_superadmin_login_hardening.php");
    }
}

// ── --list ──────────────────────────────────────────────────────────────────
if (isset($args['list'])) {
    $rows = $pdo->query("SELECT id, name, email, created_at, last_login FROM superadmins ORDER BY id")->fetchAll();
    if (!$rows) { out("\n  No superadmins exist yet.\n"); exit(0); }
    out("\n  Platform operators:\n");
    printf("  %-4s %-28s %-34s %s\n", 'ID', 'NAME', 'EMAIL', 'LAST LOGIN');
    foreach ($rows as $r) {
        printf("  %-4s %-28s %-34s %s\n", $r['id'],
            mb_strimwidth((string)$r['name'], 0, 27, '…'),
            mb_strimwidth((string)$r['email'], 0, 33, '…'),
            $r['last_login'] ?? 'never');
    }
    out('');
    exit(0);
}

// ── Validate input ──────────────────────────────────────────────────────────
$email = strtolower(trim((string)($args['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bail('A valid --email is required. Use --list to see existing operators.');
}

/** Strong generated password: length 20, guaranteed letters + digits (§20). */
function generateSuperadminPassword(int $len = 20): string
{
    $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!#%*+-=?@^_'];
    $out = '';
    foreach ($sets as $s) { $out .= $s[random_int(0, strlen($s) - 1)]; }   // one from each
    $all = implode('', $sets);
    for ($i = strlen($out); $i < $len; $i++) { $out .= $all[random_int(0, strlen($all) - 1)]; }
    return str_shuffle($out);
}

$generated = false;
$password  = $args['password'] ?? null;
if (!is_string($password) || $password === '') {
    $password  = generateSuperadminPassword();
    $generated = true;
} else {
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        bail('Password must be at least 8 characters and contain a letter and a digit (.claude/security.md §20).');
    }
}

$st = $pdo->prepare("SELECT id, name FROM superadmins WHERE email = ? LIMIT 1");
$st->execute([$email]);
$existing = $st->fetch();

if ($existing) {
    // Never silently change an existing operator's password — that would let a
    // mistyped email quietly take over someone else's account.
    if (!isset($args['reset-password']) && !isset($args['password'])) {
        bail("An operator with email {$email} already exists (id {$existing['id']}).\n"
           . "         To set a new password, re-run with --reset-password.");
    }
    $pdo->prepare("UPDATE superadmins SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $existing['id']]);
    $id = (int)$existing['id'];
    out("\n  Password reset for operator #{$id} ({$email}).");
} else {
    $name = trim((string)($args['name'] ?? ''));
    if ($name === '') { $name = strstr($email, '@', true) ?: 'Operator'; }

    $pdo->prepare("INSERT INTO superadmins (name, email, password_hash) VALUES (?,?,?)")
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $id = (int)$pdo->lastInsertId();
    out("\n  Created platform operator #{$id}: {$name} <{$email}>");
}

if ($generated) {
    out("\n  Generated password (shown once — store it in your password manager now):\n");
    out("      {$password}\n");
    out("  It is stored only as a hash and cannot be recovered. Use --reset-password if lost.");
}
out("\n  Sign in at: https://" . (getenv('SUPERADMIN_SUBDOMAIN') ?: 'superadmin')
  . '.' . (getenv('TENANT_BASE_DOMAIN') ?: '<your-domain>') . "/app/superadmin/login.php\n");
