<?php
/**
 * PHP Upload Limits — Regression Guard
 *
 * Backup & Restore (and many other BMS upload paths — signed contracts,
 * GRN scans, e-signatures, RFQ attachments) require uploads larger than
 * PHP's stock 2 MB default. The project ships two config files that bump
 * the runtime limits for both possible PHP runtimes:
 *
 *   .htaccess   — for Apache mod_php hosts (<IfModule> wrapped)
 *   .user.ini   — for PHP-FPM and CGI hosts
 *
 * This suite locks in:
 *   - both files exist
 *   - each declares upload_max_filesize / post_max_size / memory_limit /
 *     max_execution_time / max_input_time at the expected values
 *   - the Apache-layer DoS safety net (LimitRequestBody) is present
 *   - the FilesMatch deny block for .user.ini / .htaccess / .htpasswd is
 *     present (defence in depth — these config files must never be
 *     downloadable over HTTP)
 *
 * Run:  php tests/test_php_upload_limits_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $m): void    { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void    { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

echo "\n\033[1m═══ PHP Upload Limits — Regression Guard ═══\033[0m\n";

$htaccess  = $root . '/.htaccess';
$userIni   = $root . '/.user.ini';

// ─────────────────────────────────────────────────────────────────────────────
section('1. Both config files exist');
// ─────────────────────────────────────────────────────────────────────────────

check(is_file($htaccess), '.htaccess exists at project root', '.htaccess is missing');
check(is_file($userIni),  '.user.ini exists at project root',  '.user.ini is missing');

$htaccessSrc = is_file($htaccess) ? file_get_contents($htaccess) : '';
$userIniSrc  = is_file($userIni)  ? file_get_contents($userIni)  : '';

// Expected values — change here and the rest of the suite re-verifies.
$expected = [
    'upload_max_filesize' => '100M',
    'post_max_size'       => '100M',
    'memory_limit'        => '256M',
    'max_execution_time'  => '300',
    'max_input_time'      => '300',
];

// ─────────────────────────────────────────────────────────────────────────────
section('2. .htaccess — Apache mod_php directives present');
// ─────────────────────────────────────────────────────────────────────────────

foreach (['mod_php.c', 'mod_php7.c', 'mod_php8.c'] as $mod) {
    check(
        str_contains($htaccessSrc, '<IfModule ' . $mod . '>'),
        ".htaccess wraps directives in <IfModule $mod> guard",
        ".htaccess is missing the <IfModule $mod> wrapper — directive may parse on FPM hosts"
    );
}

foreach ($expected as $key => $val) {
    // Looking for: php_value <key>   <val>   (any whitespace, case-insensitive name)
    $pattern = '/php_value\s+' . preg_quote($key, '/') . '\s+' . preg_quote($val, '/') . '\b/i';
    $count   = preg_match_all($pattern, $htaccessSrc);
    check(
        $count >= 3,
        ".htaccess sets $key = $val in each of 3 mod_php variants (found $count)",
        ".htaccess does not set $key = $val in every <IfModule> block (found $count of 3)"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. .htaccess — security hardening directives');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match('/LimitRequestBody\s+104857600\b/', $htaccessSrc),
    '.htaccess caps request body at 100 MB via LimitRequestBody',
    '.htaccess is missing the Apache-layer LimitRequestBody DoS safety net'
);

check(
    str_contains($htaccessSrc, '<FilesMatch'),
    '.htaccess contains a <FilesMatch> directive for config-file protection',
    '.htaccess is missing the <FilesMatch> directive'
);

foreach (['htaccess', 'htpasswd', 'user', 'env'] as $needle) {
    check(
        (bool) preg_match('/<FilesMatch[^>]*' . $needle . '/i', $htaccessSrc),
        ".htaccess <FilesMatch> pattern includes '$needle' (denies config download)",
        ".htaccess <FilesMatch> pattern does not include '$needle' — that config file would be downloadable"
    );
}

check(
    (bool) preg_match('/<FilesMatch[^>]*>[\s\S]*?Require\s+all\s+denied[\s\S]*?<\/FilesMatch>/i', $htaccessSrc),
    'The FilesMatch block contains "Require all denied"',
    'The FilesMatch block exists but does not enforce "Require all denied"'
);

check(
    str_contains($htaccessSrc, 'Options -Indexes'),
    'Existing Options -Indexes directive preserved',
    'Options -Indexes was removed — directory listings would expose files'
);

// ─────────────────────────────────────────────────────────────────────────────
section('4. .user.ini — PHP-FPM / CGI directives present');
// ─────────────────────────────────────────────────────────────────────────────

foreach ($expected as $key => $val) {
    // Looking for: <key> = <val>   (allow any whitespace around =)
    $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=\s*' . preg_quote($val, '/') . '\s*$/mi';
    check(
        (bool) preg_match($pattern, $userIniSrc),
        ".user.ini sets $key = $val",
        ".user.ini does not set $key = $val"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. Cross-file consistency — both files agree');
// ─────────────────────────────────────────────────────────────────────────────
// If the .htaccess and .user.ini disagree, behavior would diverge between
// mod_php and FPM hosts. Pin them to the same values.

foreach ($expected as $key => $val) {
    $inHt   = (bool) preg_match('/php_value\s+' . preg_quote($key, '/') . '\s+' . preg_quote($val, '/') . '\b/i', $htaccessSrc);
    $inIni  = (bool) preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*' . preg_quote($val, '/') . '\s*$/mi', $userIniSrc);
    check(
        $inHt && $inIni,
        "$key = $val agrees across .htaccess and .user.ini",
        "$key value differs (or missing) between the two config files — runtime drift between mod_php and FPM"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. Numeric sanity — post_max_size ≥ upload_max_filesize');
// ─────────────────────────────────────────────────────────────────────────────
// PHP itself silently caps uploads at min(upload_max_filesize, post_max_size).
// If post_max_size is smaller than upload_max_filesize, the upload_max_filesize
// raise is wasted. Pin the relationship.

function bytesFromShorthand(string $v): int {
    $v = trim($v);
    $unit = strtolower(substr($v, -1));
    $n = (int) $v;
    return match ($unit) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => $n,
    };
}

$umf = bytesFromShorthand($expected['upload_max_filesize']);
$pms = bytesFromShorthand($expected['post_max_size']);
check(
    $pms >= $umf,
    "post_max_size ($pms B) ≥ upload_max_filesize ($umf B)",
    "post_max_size < upload_max_filesize — PHP will silently cap uploads at post_max_size"
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures === 0) {
    echo "\033[32m✅ PHP upload-limit invariants intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ PHP upload-limit regression — see failures above.\033[0m\n\n";
exit(1);
