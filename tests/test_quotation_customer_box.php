<?php
/**
 * Quotation Customer Box — Regression Test Suite
 *
 * Guards the fixes applied to the quotation print-out / details page:
 *   #2  print_quotation.php — postal_address / address de-duplication
 *   #3  print_quotation.php + quotation_view.php — email now resolves the
 *       customer's own address (company_email) and only falls back to the
 *       contact person's email (email) when company_email is blank.
 *   #4  print_quotation.php — content line spacing reduced and the totals
 *       box shows a "VAT" row, printed only when tax_amount > 0.
 *
 * Run:  php tests/test_quotation_customer_box.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 *
 * Sections 1-5 and 7 need no database and run everywhere, including CI.
 * Section 6 is a live-DB smoke test — it runs only when includes/config.php
 * is present (i.e. on a real install) and is skipped cleanly otherwise.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;
$skips    = 0;

function pass(string $m): void    { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void    { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function skip(string $m): void    { global $skips;    $skips++;    echo "  \033[33m⊘\033[0m  $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

$PRINT_REL = 'app/bms/sales/quotations/print_quotation.php';
$VIEW_REL  = 'app/bms/sales/quotations/quotation_view.php';

/**
 * Builds the customer address lines for the printout.
 *
 * IMPORTANT: this MUST mirror the address-line block in print_quotation.php.
 * If the algorithm there changes, update this function AND Section 3.
 * Section 3 statically locks the print file to this same algorithm.
 */
function q_addr_lines(string $postal, string $address): array
{
    $postal  = trim($postal);
    $address = trim($address);

    // Drop the postal line when the (fuller) address already contains it.
    if ($postal !== '' && $address !== '' && stripos($address, $postal) !== false) {
        $postal = '';
    }

    $lines = [];
    if ($postal !== '') {
        $lines[] = preg_match('/^\s*p\.?\s*o\.?\s*box/i', $postal)
            ? $postal
            : 'P.O. Box ' . $postal;
    }
    if ($address !== '') {
        $lines[] = $address;
    }
    return $lines;
}

/** True if any produced line is contained inside another (a visible duplicate). */
function has_dup_line(array $lines): bool
{
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            if ($i !== $j && $lines[$i] !== '' && stripos($lines[$j], $lines[$i]) !== false) {
                return true;
            }
        }
    }
    return false;
}

echo "\n\033[1m═══ Quotation Customer Box — Regression Suite ═══\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. PHP syntax (php -l)');
// ─────────────────────────────────────────────────────────────────────────────
foreach ([$PRINT_REL, $VIEW_REL] as $rel) {
    $path = "$root/$rel";
    if (!file_exists($path)) { fail("Missing file: $rel"); continue; }
    if (!function_exists('shell_exec')) { skip("shell_exec disabled — cannot lint $rel"); continue; }
    $out = (string) shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    check(!preg_match('/(Parse|Fatal) error/i', $out),
        "Syntax OK: $rel",
        "Syntax error in $rel —\n     " . trim($out));
}

$print = file_exists("$root/$PRINT_REL") ? file_get_contents("$root/$PRINT_REL") : '';
$view  = file_exists("$root/$VIEW_REL")  ? file_get_contents("$root/$VIEW_REL")  : '';

// ─────────────────────────────────────────────────────────────────────────────
section('2. Fix #3 — email resolves company_email, falls back to contact email');
// ─────────────────────────────────────────────────────────────────────────────
$coalesce = "COALESCE(NULLIF(TRIM(c.company_email), ''), c.email)";

check(str_contains($print, $coalesce),
    'print_quotation.php: c_email uses COALESCE(company_email → email)',
    'print_quotation.php: c_email does NOT use the COALESCE(company_email, email) expression');
check(str_contains($print, 'as c_email'),
    'print_quotation.php: resolved email still aliased as c_email',
    'print_quotation.php: c_email alias missing');
check(!preg_match('/c\.email\s+as\s+c_email/i', $print),
    'print_quotation.php: bare "c.email as c_email" (contact email) removed',
    'print_quotation.php: still selects the bare contact email (c.email as c_email)');

check(str_contains($view, $coalesce),
    'quotation_view.php: customer_email uses COALESCE(company_email → email)',
    'quotation_view.php: customer_email does NOT use the COALESCE expression');
check(!preg_match('/c\.email\s+AS\s+customer_email/i', $view),
    'quotation_view.php: bare "c.email AS customer_email" (contact email) removed',
    'quotation_view.php: still selects the bare contact email');

// ─────────────────────────────────────────────────────────────────────────────
section('3. Fix #2 — postal_address / address de-duplication is in place');
// ─────────────────────────────────────────────────────────────────────────────
check(str_contains($print, '$addr_lines'),
    'print_quotation.php: builds a de-duplicated $addr_lines list',
    'print_quotation.php: $addr_lines list missing');
check(str_contains($print, 'stripos($cust_address, $cust_postal)'),
    'print_quotation.php: drops the postal line when address already contains it',
    'print_quotation.php: postal-in-address containment check missing');
check(str_contains($print, 'p\.?\s*o\.?\s*box'),
    'print_quotation.php: "P.O. Box" prefix is guarded by a regex',
    'print_quotation.php: "P.O. Box" prefix regex missing');
check(str_contains($print, 'foreach ($addr_lines as $addr_line)'),
    'print_quotation.php: renders the de-duplicated address lines',
    'print_quotation.php: $addr_lines render loop missing');
check(!str_contains($print, "P.O. Box <?= htmlspecialchars(\$order['c_postal_address'])"),
    'print_quotation.php: old always-on "P.O. Box" + raw address render removed',
    'print_quotation.php: old duplicating render block still present');

// ─────────────────────────────────────────────────────────────────────────────
section('4. Fix #3 — SQL email-resolution semantics (in-memory SQLite)');
// ─────────────────────────────────────────────────────────────────────────────
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    skip('pdo_sqlite not available — COALESCE expression not verified here');
} else {
    try {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE customers (id INTEGER, email TEXT, company_email TEXT)");
        $ins = $db->prepare("INSERT INTO customers (id, email, company_email) VALUES (?,?,?)");
        $ins->execute([1, 'contact1@x.com', 'biz1@x.com']);  // company set      → company
        $ins->execute([2, 'contact2@x.com', '']);            // company empty    → contact
        $ins->execute([3, 'contact3@x.com', '   ']);         // company spaces   → contact
        $ins->execute([4, 'contact4@x.com', null]);          // company NULL     → contact
        $ins->execute([5, null,             'biz5@x.com']);  // contact NULL     → company

        $expected = [
            1 => 'biz1@x.com',
            2 => 'contact2@x.com',
            3 => 'contact3@x.com',
            4 => 'contact4@x.com',
            5 => 'biz5@x.com',
        ];
        // The exact expression used by both production files.
        $stmt = $db->query(
            "SELECT id, COALESCE(NULLIF(TRIM(company_email), ''), email) AS resolved
             FROM customers ORDER BY id"
        );
        foreach ($stmt as $r) {
            $id  = (int) $r['id'];
            $got = $r['resolved'];
            check($got === $expected[$id],
                "row $id → resolves to '{$expected[$id]}'",
                "row $id → resolved to " . var_export($got, true) . ", expected '{$expected[$id]}'");
        }
    } catch (Throwable $e) {
        fail('SQLite COALESCE check errored: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. Fix #2 — address de-duplication logic (unit cases)');
// ─────────────────────────────────────────────────────────────────────────────
$cases = [
    // [postal, address, expected lines, label]
    ['Nyerere Rd',          'Nyerere Rd',            ['Nyerere Rd'],                 'identical postal & address → one line'],
    ['MSANGAI',             "MSANGAI\nKILIMANJARO",  ["MSANGAI\nKILIMANJARO"],       'address contains postal → one line (address kept)'],
    ['P.O.BOX 3013, THEMI', 'P.O.BOX 3013, THEMI',   ['P.O.BOX 3013, THEMI'],        'identical P.O.BOX value → one line, no double prefix'],
    ['12345',               'Arusha Rd',             ['P.O. Box 12345', 'Arusha Rd'],'distinct values → two lines, postal prefixed'],
    ['P.O.BOX 999',         'Kariakoo St',           ['P.O.BOX 999', 'Kariakoo St'], 'postal already marked P.O.BOX → not double-prefixed'],
    ['PO BOX 12',           'Mwanza',                ['PO BOX 12', 'Mwanza'],        'PO BOX without dots → recognised, not double-prefixed'],
    ['500',                 '',                      ['P.O. Box 500'],               'only postal set → one prefixed line'],
    ['',                    'Mwenge',                ['Mwenge'],                     'only address set → one line'],
    ['',                    '',                      [],                             'both empty → no lines'],
    ['   ',                 '   ',                   [],                             'whitespace-only → no lines'],
];
foreach ($cases as [$p, $a, $exp, $label]) {
    $got = q_addr_lines($p, $a);
    check($got === $exp, $label,
        "$label — got " . json_encode($got) . " expected " . json_encode($exp));
}
// Cross-check: no case ever produces a visibly duplicated line.
foreach ($cases as [$p, $a, , $label]) {
    check(!has_dup_line(q_addr_lines($p, $a)),
        "no duplicated address line — $label",
        "duplicated address line produced — $label");
}

// ─────────────────────────────────────────────────────────────────────────────
// Section 6 bootstrap — required at file scope so roots.php populates $pdo.
// ─────────────────────────────────────────────────────────────────────────────
$pdo          = null;
$dbSkipReason = '';
if (!file_exists("$root/includes/config.php")) {
    $dbSkipReason = 'includes/config.php not present (CI / fresh checkout)';
} else {
    // roots.php configures session/cookie params; under CLI (output already
    // emitted) that raises harmless "headers already sent" warnings. An error
    // handler swallows every diagnostic during bootstrap regardless of how
    // roots.php tunes error_reporting — keeping the test output clean.
    set_error_handler(static fn() => true);
    try {
        ob_start();
        require_once "$root/roots.php";
        while (ob_get_level() > 0) { ob_end_clean(); }
    } catch (Throwable $e) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        $dbSkipReason = 'DB bootstrap failed: ' . $e->getMessage();
    } finally {
        restore_error_handler();
    }
    if ($dbSkipReason === '' && !($pdo instanceof PDO)) {
        $dbSkipReason = 'no PDO handle after bootstrap';
    }
}

section('6. Live-DB smoke test (real customers)');
if ($dbSkipReason !== '') {
    skip("$dbSkipReason — live-DB smoke test skipped");
} else {
    try {
        // Exactly the customer columns the two fixes touch.
        $rows = $pdo->query(
            "SELECT c.customer_id,
                    COALESCE(NULLIF(TRIM(c.company_email), ''), c.email) AS c_email,
                    c.postal_address AS c_postal_address,
                    c.address        AS c_address
             FROM customers c
             LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
        pass('print_quotation customer query runs on the live schema (' . count($rows) . ' rows read)');

        $bad = 0;
        foreach ($rows as $r) {
            $lines = q_addr_lines((string) $r['c_postal_address'], (string) $r['c_address']);
            if (has_dup_line($lines)) {
                $bad++;
                echo "     customer #{$r['customer_id']}: duplicated address line\n";
            }
            foreach ($lines as $ln) {
                if (preg_match('/p\.?\s*o\.?\s*box\s+p\.?\s*o\.?\s*box/i', $ln)) {
                    $bad++;
                    echo "     customer #{$r['customer_id']}: doubled 'P.O. Box' prefix\n";
                }
            }
        }
        check($bad === 0,
            'No duplicated / double-prefixed address line across ' . count($rows) . ' real customers',
            "$bad real customer(s) still render a duplicated address line");
    } catch (Throwable $e) {
        fail('Live-DB smoke test errored: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('7. Print layout — content line spacing & VAT row');
// ─────────────────────────────────────────────────────────────────────────────
// Line spacing — items table tightened, .box paragraphs tightened.
check(str_contains($print, 'line-height: 1.6;'),
    'print_quotation.php: items table line-height reduced to 1.6',
    'print_quotation.php: items table line-height is not 1.6');
check(!str_contains($print, 'line-height: 2.2;'),
    'print_quotation.php: old oversized line-height 2.2 removed',
    'print_quotation.php: items table still uses line-height 2.2');
check(str_contains($print, 'height: 0.75cm;'),
    'print_quotation.php: items table row height reduced to 0.75cm',
    'print_quotation.php: items table row height is not 0.75cm');
check(!str_contains($print, 'height: 0.9cm;'),
    'print_quotation.php: old row height 0.9cm removed',
    'print_quotation.php: items table still uses row height 0.9cm');
check(str_contains($print, '.box p { margin: 3px 0;'),
    'print_quotation.php: Customer / Quotation Information boxes tightened to 3px',
    'print_quotation.php: .box p line spacing is not 3px 0');
check(!str_contains($print, '.box p { margin: 5px 0;'),
    'print_quotation.php: old .box p margin 5px 0 removed',
    'print_quotation.php: .box p still uses the old 5px 0 margin');

// VAT row — labelled "VAT" (Option B) and shown only when tax_amount > 0.
check(str_contains($print, '<span>VAT:</span>'),
    'print_quotation.php: totals box shows the VAT row',
    'print_quotation.php: VAT row missing from the totals box');
check(!str_contains($print, 'VAT (18%)'),
    'print_quotation.php: fixed "(18%)" dropped from the VAT label (mixed-rate safe)',
    'print_quotation.php: VAT label still hard-codes "(18%)"');
check(!str_contains($print, '<span>Tax:</span>'),
    'print_quotation.php: old generic "Tax:" label removed',
    'print_quotation.php: totals box still uses the old "Tax:" label');
check(str_contains($print, "if (floatval(\$order['tax_amount']) > 0)"),
    'print_quotation.php: VAT row prints only when tax_amount > 0 (hidden at zero)',
    'print_quotation.php: VAT row is not gated by a tax_amount > 0 check');

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m════════════════════════════════════════\033[0m\n";
if ($failures === 0) {
    echo "\033[32m✅ All $passes test(s) passed";
    echo $skips ? " ($skips skipped) — safe to push.\033[0m\n" : " — safe to push.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ $failures test(s) FAILED  |  $passes passed  |  $skips skipped\033[0m\n";
echo "\033[31mFix the errors above — DO NOT push.\033[0m\n";
echo "\033[1m════════════════════════════════════════\033[0m\n\n";
exit(1);
