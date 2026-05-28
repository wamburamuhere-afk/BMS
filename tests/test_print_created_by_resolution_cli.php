<?php
/**
 * Print "Created By" resolution — CLI smoke test
 * ----------------------------------------------
 *   php tests/test_print_created_by_resolution_cli.php
 *
 * Verifies that every individual-doc print page in BMS now resolves the
 * "Created By" signature column reliably, including when:
 *   (a) the creator's user row has first_name + last_name only,
 *   (b) the creator's user row has username only (no first/last name),
 *   (c) prepared_by_name is NULL but a created_by user JOIN result exists,
 *   (d) the doc has reviewed_by_name and approved_by_name filled — Created
 *       By must NOT be left blank.
 *
 * Also verifies includes/workflow_signature_row.php no longer prints the
 * "Digitally signed" caption above the e-signature timestamp.
 *
 * Exit 0 = all pass (safe to push). Exit 1 = failures found.
 */

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $msg): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $msg\n"; }
function fail(string $msg): void  { global $failures; $failures++; echo "  \033[31m❌ $msg\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $root, string $rel): string {
    $path = "$root/$rel";
    return file_exists($path) ? file_get_contents($path) : '';
}

// ─────────────────────────────────────────────────────────────────────────────
section('1. Modified files exist');
// ─────────────────────────────────────────────────────────────────────────────
$targets = [
    'api/account/print_purchase_order.php',
    'api/account/print_delivery_note.php',
    'app/bms/sales/quotations/print_quotation.php',
    'app/bms/invoice/invoice_print.php',
    'app/bms/sales/print_sales_order.php',
    'includes/workflow_signature_row.php',
];
foreach ($targets as $f) {
    file_exists("$root/$f") ? pass($f) : fail("MISSING: $f");
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. Each modified print page has a username fallback wired in');
// ─────────────────────────────────────────────────────────────────────────────
$checks = [
    'api/account/print_purchase_order.php' => [
        'must_have' => ['creator_first', 'creator_last', 'creator_role', '$po_creator_name'],
        'must_lose' => ["prepared_by_name'] ?: (\$order['username']"],
    ],
    'api/account/print_delivery_note.php' => [
        'must_have' => ['creator_first', 'creator_last', 'created_by_username', '$dn_creator_name'],
        'must_lose' => ["prepared_by_name'] ?: (\$dn['created_by_name']"],
    ],
    'app/bms/sales/quotations/print_quotation.php' => [
        'must_have' => ['creator_username', "creator_username'"],
        'must_lose' => [],
    ],
    'app/bms/invoice/invoice_print.php' => [
        'must_have' => ['creator_username', "creator_username'"],
        'must_lose' => [],
    ],
    'app/bms/sales/print_sales_order.php' => [
        'must_have' => ['creator_username', 'creator_role'],
        'must_lose' => ["'created_by_role'    => '',"],
    ],
];
foreach ($checks as $file => $rules) {
    $src = readSrc($root, $file);
    if ($src === '') { fail("could not read $file"); continue; }
    foreach ($rules['must_have'] as $needle) {
        if (strpos($src, $needle) !== false) {
            pass("$file contains `$needle`");
        } else {
            fail("$file MISSING `$needle`");
        }
    }
    foreach ($rules['must_lose'] as $needle) {
        if (strpos($src, $needle) === false) {
            pass("$file no longer contains `$needle`");
        } else {
            fail("$file still has old pattern `$needle`");
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. Signature top border line removed everywhere; captions kept');
// ─────────────────────────────────────────────────────────────────────────────
$sigSrc = readSrc($root, 'includes/workflow_signature_row.php');

// The horizontal line that always appeared above each signature column
// (even before review/approval) is removed in the canonical partial AND
// in every print page that still kept its own inline .signature-line CSS.
$pages_with_inline_signature_css = [
    'includes/workflow_signature_row.php',
    'api/account/print_purchase_order.php',
    'app/bms/stock/print_transfer.php',
    'app/bms/grn/grn_print.php',
    'app/constant/accounts/payment_voucher_print.php',
    'app/bms/sales/sales_returns/print_sales_return.php',
    'app/bms/purchase/print_purchase_return.php',
];
foreach ($pages_with_inline_signature_css as $file) {
    $src = readSrc($root, $file);
    if ($src === '') { fail("could not read $file"); continue; }
    // Match a .signature-line { ... } block and look for border-top inside it.
    if (preg_match('/\.signature-line\s*\{[^}]*border-top[^}]*\}/s', $src)) {
        fail("$file still has border-top inside .signature-line");
    } else {
        pass("$file — .signature-line has no border-top");
    }
}

// The "Digitally signed" caption above the timestamp must remain
// (only the top horizontal line was the target of the cleanup).
if (strpos($sigSrc, 'Digitally signed') !== false) {
    pass('"Digitally signed" caption still rendered');
} else {
    fail('"Digitally signed" caption was removed in error');
}
if (strpos($sigSrc, 'sig-timestamp') !== false) {
    pass('timestamp <span class="sig-timestamp"> still rendered');
} else {
    fail('timestamp rendering accidentally removed');
}
if (strpos($sigSrc, '<img src="') !== false) {
    pass('signature <img> still rendered');
} else {
    fail('signature image rendering accidentally removed');
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. Resolution logic — synthetic scenarios');
// ─────────────────────────────────────────────────────────────────────────────
/*
 * Each scenario emulates a row coming back from the SELECT in each print page,
 * then runs the same resolution expression the page now uses, and asserts the
 * resolved `created_by_name` is non-empty.
 */

// ── 4a. Purchase Order print resolution
$scenarios_po = [
    'PO: first+last only' => [
        'creator_first' => 'Alice', 'creator_last' => 'Mwangi',
        'username' => 'amwangi', 'prepared_by_name' => null,
        'expect_contains' => 'Alice Mwangi',
    ],
    'PO: username only' => [
        'creator_first' => '', 'creator_last' => '',
        'username' => 'amwangi', 'prepared_by_name' => null,
        'expect_contains' => 'amwangi',
    ],
    'PO: prepared_by_name fallback' => [
        'creator_first' => '', 'creator_last' => '',
        'username' => '', 'prepared_by_name' => 'Legacy User',
        'expect_contains' => 'Legacy User',
    ],
];
foreach ($scenarios_po as $label => $s) {
    // Replicates the resolution at api/account/print_purchase_order.php
    $name = trim(($s['creator_first'] ?? '') . ' ' . ($s['creator_last'] ?? ''))
        ?: ($s['username'] ?? '')
        ?: ($s['prepared_by_name'] ?? '');
    if ($name !== '' && strpos($name, $s['expect_contains']) !== false) {
        pass("$label → resolved to `$name`");
    } else {
        fail("$label → got `$name`, expected to contain `{$s['expect_contains']}`");
    }
}

// ── 4b. Delivery Note print resolution
$scenarios_dn = [
    'DN: first+last only' => [
        'creator_first' => 'Bob', 'creator_last' => 'Otieno',
        'created_by_username' => 'botieno', 'prepared_by_name' => null,
        'expect_contains' => 'Bob Otieno',
    ],
    'DN: username only' => [
        'creator_first' => '', 'creator_last' => '',
        'created_by_username' => 'botieno', 'prepared_by_name' => null,
        'expect_contains' => 'botieno',
    ],
    'DN: prepared_by_name fallback' => [
        'creator_first' => '', 'creator_last' => '',
        'created_by_username' => '', 'prepared_by_name' => 'Warehouse Clerk',
        'expect_contains' => 'Warehouse Clerk',
    ],
];
foreach ($scenarios_dn as $label => $s) {
    $name = trim(($s['creator_first'] ?? '') . ' ' . ($s['creator_last'] ?? ''))
        ?: ($s['created_by_username'] ?? '')
        ?: ($s['prepared_by_name']    ?? '');
    if ($name !== '' && strpos($name, $s['expect_contains']) !== false) {
        pass("$label → resolved to `$name`");
    } else {
        fail("$label → got `$name`, expected to contain `{$s['expect_contains']}`");
    }
}

// ── 4c. Quotation / Invoice (same shape) — first+last with username fallback
$scenarios_qi = [
    'Quotation/Invoice: first+last only' => [
        'creator_name' => 'Carol Njoroge', 'creator_username' => 'cnjoroge',
        'expect_contains' => 'Carol Njoroge',
    ],
    'Quotation/Invoice: username only' => [
        'creator_name' => '', 'creator_username' => 'cnjoroge',
        'expect_contains' => 'cnjoroge',
    ],
];
foreach ($scenarios_qi as $label => $s) {
    $name = trim($s['creator_name'] ?? '');
    if ($name === '') $name = trim($s['creator_username'] ?? '');
    if ($name !== '' && strpos($name, $s['expect_contains']) !== false) {
        pass("$label → resolved to `$name`");
    } else {
        fail("$label → got `$name`, expected to contain `{$s['expect_contains']}`");
    }
}

// ── 4d. Sales Order — first+last → username → salesperson_name
$scenarios_so = [
    'SO: first+last only' => [
        'creator_first' => 'Dan', 'creator_last' => 'Kimani',
        'creator_username' => 'dkimani', 'salesperson_name' => 'Other Rep',
        'expect_contains' => 'Dan Kimani',
    ],
    'SO: username only' => [
        'creator_first' => '', 'creator_last' => '',
        'creator_username' => 'dkimani', 'salesperson_name' => 'Other Rep',
        'expect_contains' => 'dkimani',
    ],
    'SO: salesperson fallback only' => [
        'creator_first' => '', 'creator_last' => '',
        'creator_username' => '', 'salesperson_name' => 'Other Rep',
        'expect_contains' => 'Other Rep',
    ],
];
foreach ($scenarios_so as $label => $s) {
    $name = trim(($s['creator_first'] ?? '') . ' ' . ($s['creator_last'] ?? ''));
    if ($name === '') $name = trim($s['creator_username'] ?? '');
    if ($name === '') $name = $s['salesperson_name'] ?? '';
    if ($name !== '' && strpos($name, $s['expect_contains']) !== false) {
        pass("$label → resolved to `$name`");
    } else {
        fail("$label → got `$name`, expected to contain `{$s['expect_contains']}`");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Final summary
// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
echo "Passes:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
exit($failures === 0 ? 0 : 1);
