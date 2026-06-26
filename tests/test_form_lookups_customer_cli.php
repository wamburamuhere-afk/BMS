<?php
/**
 * test_form_lookups_customer_cli.php
 * Verifies the self-growing "Other → type → saved" dropdowns for customers and a
 * REAL end-to-end create+save through the actual add_customer.php endpoint.
 * Read-only except a self-cleaned test row.
 */
$root = dirname(__DIR__);
require_once $root . '/roots.php';
require_once $root . '/core/form_lookups.php';
global $pdo;

$pass = 0; $fail = 0;
function ok($m){ global $pass; $pass++; echo "  [PASS] $m\n"; }
function no($m){ global $fail; $fail++; echo "  [FAIL] $m\n"; }
function section($t){ echo "\n== $t ==\n"; }

// ── 1. Catalogue keys exist ──────────────────────────────────────────────
section('1. form_lookups catalogue');
foreach (['customer_type','payment_terms','currency'] as $k) {
    $n = count(formLookupOptions($pdo, $k));
    $n > 0 ? ok("$k has $n option(s)") : no("$k is empty");
}
// customer_type carries the original 4 values
$ctVals = array_map(fn($o)=>$o['value'], formLookupOptions($pdo,'customer_type'));
(count(array_intersect(['individual','business','government','ngo'], $ctVals)) === 4)
    ? ok('customer_type seeded with individual/business/government/ngo')
    : no('customer_type missing seed values');
// "cash" is in the shared payment_terms list
in_array('cash', array_map(fn($o)=>$o['value'], formLookupOptions($pdo,'payment_terms')), true)
    ? ok('"cash" present in shared payment_terms') : no('"cash" missing from payment_terms');

// ── 2. renderOtherSelect widget HTML ─────────────────────────────────────
section('2. renderOtherSelect widget');
$html = renderOtherSelect('customer_type','customer_type', formLookupOptions($pdo,'customer_type'), 'business', 'customer_type_other', 'Select Type');
(strpos($html,'class="form-select other-trigger"') !== false) ? ok('renders .other-trigger select') : no('missing .other-trigger');
(strpos($html,'value="other"') !== false)                    ? ok('renders the "Other" option')   : no('missing Other option');
(strpos($html,'name="customer_type_other"') !== false)       ? ok('renders the typed-value input') : no('missing typed input');
(strpos($html,'value="business" selected') !== false)        ? ok('pre-selects the current value') : no('did not pre-select');

// ── 3. type-new persistence (idempotent) ─────────────────────────────────
section('3. type-new persistence (idempotent)');
$testType = 'Cooperative ' . substr(md5(uniqid()),0,6);
$added = upsertFormLookup($pdo,'customer_type',$testType,1);
$added ? ok('new type added') : no('new type not added');
($added && upsertFormLookup($pdo,'customer_type',$testType,1) === false) ? ok('re-add is a no-op (idempotent)') : no('duplicate on re-add');
$pdo->prepare("DELETE FROM form_lookups WHERE lookup_key='customer_type' AND value=?")->execute([$testType]);

// ── 4. END-TO-END create + save via the real endpoint (subprocess) ───────
section('4. End-to-end: create a customer through add_customer.php');
$uniqName = 'ZZ_TEST_CUST_' . substr(md5(uniqid()),0,8);
$typeNew  = 'Embassy ' . substr(md5(uniqid()),0,5);
$payload  = http_build_query([
    'customer_name'  => $uniqName,
    'customer_type'  => 'other', 'customer_type_other' => $typeNew,
    'year'           => (string)date('Y'),
    'payment_terms'  => '30_days',
    'currency'       => 'TZS',
    'status'         => 'active',
]);
$runner = $root . '/tests/_tmp_cust_runner.php';
file_put_contents($runner, '<?php
require_once ' . var_export($root . '/roots.php', true) . ';
$_SESSION["user_id"]=4; $_SESSION["username"]="admin"; $_SESSION["is_admin"]=true; $_SESSION["role_id"]=1;
parse_str(' . var_export($payload, true) . ', $_POST);
$_SERVER["REQUEST_METHOD"]="POST";
require ' . var_export($root . '/api/add_customer.php', true) . ';
');
$out = shell_exec('php ' . escapeshellarg($runner) . ' 2>&1');
@unlink($runner);
$res = json_decode(trim((string)$out), true);
(is_array($res) && !empty($res['success'])) ? ok('endpoint returned success') : no('endpoint failed: ' . substr((string)$out,0,200));

$row = $pdo->prepare("SELECT customer_id, customer_type, payment_terms, currency FROM customers WHERE customer_name = ?");
$row->execute([$uniqName]);
$c = $row->fetch(PDO::FETCH_ASSOC);
if ($c) {
    ok('row exists in customers');
    ($c['customer_type'] === $typeNew) ? ok('customer_type saved as typed value') : no('customer_type wrong: '.$c['customer_type']);
    ($c['payment_terms'] === '30_days') ? ok('payment_terms saved') : no('payment_terms wrong: '.$c['payment_terms']);
    ($c['currency'] === 'TZS') ? ok('currency saved') : no('currency wrong: '.$c['currency']);
    in_array($typeNew, array_map(fn($o)=>$o['value'], formLookupOptions($pdo,'customer_type')), true)
        ? ok('typed type persisted to catalogue') : no('typed type not persisted');

    // cleanup: row + catalogue addition + auto-created ledger account
    $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$c['customer_id']]);
    $pdo->prepare("DELETE FROM form_lookups WHERE value = ?")->execute([$typeNew]);
    $pdo->prepare("DELETE FROM accounts WHERE account_code = ?")
        ->execute(['1-1200-CUST-' . str_pad((string)$c['customer_id'], 5, '0', STR_PAD_LEFT)]);
    echo "  (cleaned up test customer #{$c['customer_id']} + catalogue + ledger account)\n";
} else {
    no('row not found after create');
}

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
