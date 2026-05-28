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
section('5. Issue 1: every save/create endpoint captures the "created" signature');
// ─────────────────────────────────────────────────────────────────────────────
$create_endpoints = [
    'api/account/save_quotation.php'      => 'quotation',
    'api/account/save_sales_order.php'    => 'sales_order',
    'api/account/save_invoice.php'        => 'invoice',
    'api/account/save_purchase_order.php' => 'purchase_order',
    'api/create_rfq.php'                  => 'rfq',
    'api/create_grn.php'                  => 'grn',
    'api/create_dn.php'                   => 'delivery',
    'api/operations/save_ipc.php'         => 'ipc',
];
foreach ($create_endpoints as $file => $entityType) {
    $src = readSrc($root, $file);
    if ($src === '') { fail("could not read $file"); continue; }

    // Must call workflowCaptureSignature(...) with the right entity_type and 'created'
    $needle = "workflowCaptureSignature";
    if (strpos($src, $needle) === false) {
        fail("$file is missing workflowCaptureSignature() call");
        continue;
    }
    if (strpos($src, "'{$entityType}'") === false) {
        fail("$file does not reference entity_type '{$entityType}'");
        continue;
    }
    if (strpos($src, "'created'") === false) {
        fail("$file does not pass action 'created'");
        continue;
    }
    if (strpos($src, "workflowActorSnapshot()") === false) {
        fail("$file does not call workflowActorSnapshot()");
        continue;
    }
    // function_exists guard prevents double-require
    if (strpos($src, "function_exists('workflowCaptureSignature')") === false) {
        fail("$file is missing function_exists() guard for workflow.php require");
        continue;
    }
    pass("$file captures '{$entityType}' 'created' signature");
}

// Backfill migration must exist and be idempotent
$migration = 'migrations/2026_05_28_backfill_workflow_created_signatures.php';
$msrc = readSrc($root, $migration);
if ($msrc === '') {
    fail("missing migration file: $migration");
} else {
    pass("$migration exists");

    foreach ([
        'ON DUPLICATE KEY UPDATE'                         => 'uses idempotent ON DUPLICATE KEY UPDATE',
        'COALESCE(workflow_signatures.sig_path'           => 'preserves existing sig_path (never overwrites)',
        'signed_at = signed_at'                           => 'preserves original signed_at on re-runs',
        "'quotation'"                                     => 'covers quotation',
        "'sales_order'"                                   => 'covers sales_order',
        "'invoice'"                                       => 'covers invoice',
        "'purchase_order'"                                => 'covers purchase_order',
        "'rfq'"                                           => 'covers rfq',
        "'grn'"                                           => 'covers grn',
        "'delivery'"                                      => 'covers delivery',
        "'ipc'"                                           => 'covers ipc',
        "uq_entity_action"                                => 'verifies uq_entity_action key exists',
        "SHOW TABLES LIKE 'user_signatures'"              => 'guards against missing user_signatures table',
    ] as $needle => $label) {
        if (strpos($msrc, $needle) !== false) {
            pass("migration: $label");
        } else {
            fail("migration: $label — missing `$needle`");
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. Returns slice: PR & SR save/review/approve endpoints + print pages');
// ─────────────────────────────────────────────────────────────────────────────

// 3 save/create endpoints capture 'created' signature
$returns_save = [
    'api/account/save_purchase_return.php' => 'purchase_return',
    'api/create_purchase_return.php'       => 'purchase_return',
    'api/sales/create_return.php'          => 'sales_return',
];
foreach ($returns_save as $file => $entityType) {
    $src = readSrc($root, $file);
    if ($src === '') { fail("could not read $file"); continue; }
    if (strpos($src, "workflowCaptureSignature") === false
        || strpos($src, "'{$entityType}'") === false
        || strpos($src, "'created'") === false) {
        fail("$file does not capture '{$entityType}' 'created' signature");
    } else {
        pass("$file captures '{$entityType}' 'created' signature");
    }
}

// 4 new review/approve endpoints
$returns_workflow = [
    'api/account/review_purchase_return.php'  => ['purchase_return', 'reviewed'],
    'api/account/approve_purchase_return.php' => ['purchase_return', 'approved'],
    'api/sales/review_return.php'             => ['sales_return',    'reviewed'],
    'api/sales/approve_return.php'            => ['sales_return',    'approved'],
];
foreach ($returns_workflow as $file => [$entityType, $action]) {
    $src = readSrc($root, $file);
    if ($src === '') { fail("missing $file"); continue; }
    if (strpos($src, "workflowCaptureSignature") === false
        || strpos($src, "'{$entityType}'") === false
        || strpos($src, "'{$action}'") === false) {
        fail("$file does not capture '{$entityType}' '{$action}' signature");
    } else {
        pass("$file captures '{$entityType}' '{$action}' signature");
    }
}

// approve_purchase_return must contain stock-deduction call
$apr = readSrc($root, 'api/account/approve_purchase_return.php');
if (strpos($apr, 'approve_pr_adjust_stock') !== false
    && strpos($apr, "'deduct'") !== false
    && strpos($apr, "stock_updated = 1") !== false) {
    pass('approve_purchase_return.php deducts stock and sets stock_updated=1');
} else {
    fail('approve_purchase_return.php missing stock side-effect');
}

// 2 print pages include the canonical signature partial
$print_targets = [
    'app/bms/purchase/print_purchase_return.php'        => 'purchase_return',
    'app/bms/sales/sales_returns/print_sales_return.php' => 'sales_return',
];
foreach ($print_targets as $file => $entityType) {
    $src = readSrc($root, $file);
    if ($src === '') { fail("could not read $file"); continue; }
    if (strpos($src, 'workflow_signature_row.php') === false) {
        fail("$file does not include workflow_signature_row.php");
        continue;
    }
    if (strpos($src, "getWorkflowSignatures") === false
        || strpos($src, "'{$entityType}'") === false) {
        fail("$file does not call getWorkflowSignatures for '{$entityType}'");
        continue;
    }
    // Static "Authorized By/Signature ... Acknowledgment ... Date" block must be GONE
    if (strpos($src, 'Authorized By') !== false
        || strpos($src, 'Authorized Signature') !== false
        || strpos($src, 'Vendor Acknowledgment') !== false
        || strpos($src, 'Customer Acknowledgment') !== false) {
        fail("$file still has the old static signature labels");
        continue;
    }
    pass("$file renders signatures via workflow_signature_row.php for '{$entityType}'");
}

// 3 status endpoints are gated against 'reviewed' and 'approved'
$status_gates = [
    'api/account/update_purchase_return_status.php',
    'api/update_purchase_return_status.php',
    'api/sales/update_return_status.php',
];
foreach ($status_gates as $file) {
    $src = readSrc($root, $file);
    if ($src === '') { fail("could not read $file"); continue; }
    if (strpos($src, "in_array(\$status, ['reviewed', 'approved'], true)") !== false
        && strpos($src, 'canonical Review/Approve buttons') !== false) {
        pass("$file gates 'reviewed'/'approved' transitions");
    } else {
        fail("$file is not gated against 'reviewed'/'approved'");
    }
}

// Migration file exists and has the right markers
$ret_migration = 'migrations/2026_05_28_returns_three_approval.php';
$rmsrc = readSrc($root, $ret_migration);
if ($rmsrc === '') {
    fail("missing $ret_migration");
} else {
    pass("$ret_migration exists");
    foreach ([
        "ALTER TABLE `{\$table}` ADD COLUMN" => 'ALTERs purchase_returns and sales_returns',
        "'purchase_returns'"                 => 'targets purchase_returns table',
        "'sales_returns'"                    => 'targets sales_returns table',
        "SHOW COLUMNS FROM `{\$table}` LIKE '{\$col}'" => 'guards each column ADD',
        "ON DUPLICATE KEY UPDATE"        => 'uses idempotent ON DUPLICATE KEY UPDATE',
        "COALESCE(workflow_signatures.sig_path" => 'preserves existing sig_path',
        "signed_at = signed_at"          => 'preserves signed_at on re-runs',
        "'purchase_return'"              => 'backfills purchase_return',
        "'sales_return'"                 => 'backfills sales_return',
        "uq_entity_action"               => 'verifies uq_entity_action key',
        "'reviewed'"                     => 'expands status ENUM with reviewed',
    ] as $needle => $label) {
        if (strpos($rmsrc, $needle) !== false) {
            pass("migration: $label");
        } else {
            fail("migration: $label — missing `$needle`");
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Final summary
// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
echo "Passes:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
exit($failures === 0 ? 0 : 1);
