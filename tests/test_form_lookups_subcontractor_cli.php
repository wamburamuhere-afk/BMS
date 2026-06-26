<?php
/**
 * test_form_lookups_subcontractor_cli.php
 * Verifies the self-growing "Other → type → saved" dropdowns for sub-contractors
 * (shared form_lookups infra), and a REAL end-to-end create+save through the
 * actual add_sub_contractor.php endpoint. Read-only except a self-cleaned test row.
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
foreach (['sub_contractor_type','payment_terms','currency'] as $k) {
    $n = count(formLookupOptions($pdo, $k));
    $n > 0 ? ok("$k has $n option(s)") : no("$k is empty");
}

// ── 2. renderOtherSelect widget HTML ─────────────────────────────────────
section('2. renderOtherSelect widget');
$html = renderOtherSelect('sc_type','supplier_type', formLookupOptions($pdo,'sub_contractor_type'), '', 'supplier_type_other', 'Select Type');
(strpos($html,'class="form-select other-trigger"') !== false) ? ok('renders .other-trigger select') : no('missing .other-trigger');
(strpos($html,'value="other"') !== false)                    ? ok('renders the "Other" option')   : no('missing Other option');
(strpos($html,'name="supplier_type_other"') !== false)       ? ok('renders the typed-value input') : no('missing typed input');
(strpos($html,'other-input-box mt-2 d-none') !== false)      ? ok('typed input hidden by default') : no('input not hidden');

// ── 3. upsert: type-new persists + idempotent (does not pollute) ─────────
section('3. type-new persistence (idempotent)');
$testType = 'Crane Hire ' . substr(md5(uniqid()),0,6);   // unique → safe to clean
$added = upsertFormLookup($pdo,'sub_contractor_type',$testType,1);
$added ? ok('new type added to catalogue') : no('new type was not added');
$again = upsertFormLookup($pdo,'sub_contractor_type',$testType,1);
$again === false ? ok('re-adding same value is a no-op (idempotent)') : no('duplicate created on re-add');
$vals = array_map(fn($o)=>$o['value'], formLookupOptions($pdo,'sub_contractor_type'));
in_array($testType,$vals,true) ? ok('typed type now appears in the list') : no('typed type not in list');
// cleanup
$pdo->prepare("DELETE FROM form_lookups WHERE lookup_key='sub_contractor_type' AND value=?")->execute([$testType]);

// ── 4. "Other" resolution logic (what the endpoint does) ─────────────────
section('4. Other-resolution logic');
$resolve = function($val,$otherKey,$post){
    if ($val === 'other') return trim($post[$otherKey] ?? '');
    return $val;
};
($resolve('other','supplier_type_other',['supplier_type_other'=>'Scaffolder']) === 'Scaffolder') ? ok('supplier_type=other resolves to typed value') : no('type resolution wrong');
($resolve('30_days','x',[]) === '30_days') ? ok('a normal selection passes through unchanged') : no('normal value altered');

// ── 5. END-TO-END create + save via the real endpoint (subprocess) ───────
section('5. End-to-end: create a sub-contractor through add_sub_contractor.php');
$uniqName = 'ZZ_TEST_SC_' . substr(md5(uniqid()),0,8);
$typeNew  = 'Borehole Driller ' . substr(md5(uniqid()),0,5);
$termNew  = 'Net 14 ' . substr(md5(uniqid()),0,4);
$payload  = http_build_query([
    'supplier_name'      => $uniqName,
    'supplier_type'      => 'other',  'supplier_type_other' => $typeNew,
    'year'               => (string)date('Y'),
    'payment_terms'      => 'other',  'payment_terms_other' => $termNew,
    'currency'           => 'TZS',
    'status'             => 'active',
]);

// Subprocess: authenticate, set POST, include endpoint (it exit()s after echoing JSON).
$runner = $root . '/tests/_tmp_sc_runner.php';
file_put_contents($runner, '<?php
require_once ' . var_export($root . '/roots.php', true) . ';
$_SESSION["user_id"]=4; $_SESSION["username"]="admin"; $_SESSION["is_admin"]=true; $_SESSION["role_id"]=1;
parse_str(' . var_export($payload, true) . ', $_POST);
$_SERVER["REQUEST_METHOD"]="POST";
require ' . var_export($root . '/api/add_sub_contractor.php', true) . ';
');
$out = shell_exec('php ' . escapeshellarg($runner) . ' 2>&1');
@unlink($runner);
$res = json_decode(trim((string)$out), true);

if (is_array($res) && !empty($res['success'])) {
    ok('endpoint returned success');
} else {
    no('endpoint did not succeed: ' . substr((string)$out,0,200));
}

// Verify the row really saved with RESOLVED values (not the word "other").
$row = $pdo->prepare("SELECT supplier_id, supplier_type, payment_terms, currency, status FROM sub_contractors WHERE supplier_name = ?");
$row->execute([$uniqName]);
$sc = $row->fetch(PDO::FETCH_ASSOC);
if ($sc) {
    ok('row exists in sub_contractors');
    ($sc['supplier_type'] === $typeNew) ? ok('supplier_type saved as the typed value') : no('supplier_type wrong: '.$sc['supplier_type']);
    ($sc['payment_terms'] === $termNew) ? ok('payment_terms saved as the typed value') : no('payment_terms wrong: '.$sc['payment_terms']);
    ($sc['currency'] === 'TZS')         ? ok('currency saved') : no('currency wrong: '.$sc['currency']);
    // And the typed values were persisted to the catalogue for next time.
    in_array($typeNew, array_map(fn($o)=>$o['value'], formLookupOptions($pdo,'sub_contractor_type')), true) ? ok('typed type persisted to catalogue') : no('typed type not persisted');
    in_array($termNew, array_map(fn($o)=>$o['value'], formLookupOptions($pdo,'payment_terms')), true) ? ok('typed terms persisted to catalogue') : no('typed terms not persisted');

    // ── cleanup: remove the test row + its catalogue additions + ledger acct ──
    $pdo->prepare("DELETE FROM sub_contractors WHERE supplier_id = ?")->execute([$sc['supplier_id']]);
    $pdo->prepare("DELETE FROM form_lookups WHERE value IN (?,?)")->execute([$typeNew,$termNew]);
    // The endpoint auto-creates an actor GL sub-account (2-1200-SUB-#####) — remove it too.
    $pdo->prepare("DELETE FROM accounts WHERE account_code = ?")
        ->execute(['2-1200-SUB-' . str_pad((string)$sc['supplier_id'], 5, '0', STR_PAD_LEFT)]);
    echo "  (cleaned up test sub-contractor #{$sc['supplier_id']} + catalogue + ledger account)\n";
} else {
    no('row not found after create');
}

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
