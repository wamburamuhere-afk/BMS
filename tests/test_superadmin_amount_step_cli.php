<?php
/**
 * Superadmin money fields accept any whole amount (2026-10-21).
 *   php tests/test_superadmin_amount_step_cli.php
 *
 * Record Payment had min="1" step="1000": the browser then accepts only
 * 1, 1001, 2001 … and refused 10,000 ("nearest valid values are 9001 and 10001").
 * The billing amounts (min="0" step="1000") refused anything not a whole thousand.
 * The server stores whole shillings, so step="1" is the right rule. Static, no DB.
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }

$fields = [
    'app/superadmin/tenant_view.php' => ['amount_tzs', 'billing_amount_tzs'],
    'app/superadmin/tenant_new.php'  => ['billing_amount_tzs'],
];
foreach ($fields as $file => $names) {
    $src = file_get_contents("$root/$file");
    foreach ($names as $name) {
        // The <input …> tag holding this name (attributes may span lines).
        ok(preg_match('/<input\b[^>]*name="' . $name . '"[^>]*>/s', $src, $m) === 1, "$file: $name field found");
        $tag = $m[0] ?? '';
        ok(preg_match('/\bstep="1"/', $tag) === 1, "$file: $name accepts any whole amount (step=\"1\")");
        ok(preg_match('/\bstep="(?!1")\d+/', $tag) === 0, "$file: $name has no step that skips amounts like 10,000");
    }
}
$act = file_get_contents("$root/actions/superadmin_record_payment.php");
ok(strpos($act, "(int)(\$_POST['amount_tzs']") !== false, 'server stores whole shillings, matching step="1"');

echo "\nPasses: $pass\nFailures: $fail\n";
exit($fail === 0 ? 0 : 1);
