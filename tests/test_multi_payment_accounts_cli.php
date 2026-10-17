<?php
/**
 * Multiple bank + mobile money accounts on Payment Settings, printed on
 * quotations / invoices / payment vouchers (Layout A · compact lines).
 *
 * Runs against the live local DB inside a transaction that is rolled back.
 * Run: php tests/test_multi_payment_accounts_cli.php   (exit 0 = all pass)
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_accounts.php";

$failures = 0;
$passes   = 0;
function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

echo "\n\033[1m═══ Multiple payment accounts ═══\033[0m\n";

global $pdo;
$pdo->beginTransaction();
$keys = PAYMENT_ACCOUNT_SETTING_KEYS;
$in   = implode(',', array_fill(0, count($keys), '?'));
$pdo->prepare("DELETE FROM system_settings WHERE setting_key IN ($in)")->execute($keys);

try {
    section('Sanitize');
    $rows = paymentAccountsSanitize([
        ['label' => '  Selcom ', 'number' => ' 5527110528186 ', 'swift' => 'X', 'name' => ''],
        ['label' => '', 'number' => '123'],
        ['label' => 'NMB', 'number' => ''],
        'garbage',
        ['label' => str_repeat('A', 300), 'number' => '1', 'show' => false],
    ], 'bank');
    check(count($rows) === 2, 'rows missing label or number are dropped', 'expected 2 rows, got ' . count($rows));
    check($rows[0]['label'] === 'Selcom' && $rows[0]['number'] === '5527110528186', 'values are trimmed', 'trim failed');
    check($rows[0]['show'] === true, 'show defaults to true', 'show default wrong');
    check(mb_strlen($rows[1]['label']) === 100 && $rows[1]['show'] === false, 'label capped at 100, show=false kept', 'cap/show wrong');
    check(paymentAccountsSanitize('nope', 'mobile') === [], 'non-array input gives empty list', 'non-array not handled');

    section('Legacy fallback (before the page is ever saved)');
    foreach (['bank_name' => 'NMB', 'account_number' => '0129929291221', 'swift_code' => 'NMIBTZTZ',
              'account_name' => 'BJP TECHNOLOGIES (T) LTD', 'mpesa_paybill' => '70067946', 'mpesa_account_no' => '70067946'] as $k => $v) {
        save_setting($k, $v);
    }
    $pa = getPaymentAccounts($pdo);
    check(count($pa['bank']) === 1 && $pa['bank'][0]['number'] === '0129929291221' && $pa['bank'][0]['swift'] === 'NMIBTZTZ', 'legacy bank read as one account', 'legacy bank not read');
    check(count($pa['mobile']) === 1 && $pa['mobile'][0]['number'] === '70067946', 'legacy mobile read as one account', 'legacy mobile not read');
    check($pa['mode'] === 'shared' && $pa['default_name'] === 'BJP TECHNOLOGIES (T) LTD', 'default name + shared mode', 'mode/name wrong');
    $html = renderPaymentAccountsBlock($pa);
    check(strpos($html, 'Acc: 70067946') === false, 'paybill reference equal to number is not repeated', 'duplicate reference printed');

    section('Save two banks + two mobiles (same name for all)');
    $bank = [
        ['label' => 'Selcom', 'number' => '5527110528186', 'swift' => '', 'name' => 'IGNORED IN SHARED MODE', 'show' => true],
        ['label' => 'CRDB Bank', 'number' => '0150382716400', 'swift' => 'CORUTZTZ', 'name' => '', 'show' => true],
    ];
    $mobile = [
        ['label' => 'M-Pesa Lipa Namba', 'number' => '70067946', 'ref' => '', 'name' => '', 'show' => true],
        ['label' => 'Mixx by Yas', 'number' => '15544321', 'ref' => '998877', 'name' => '', 'show' => true],
    ];
    save_setting('payment_bank_accounts', json_encode(paymentAccountsSanitize($bank, 'bank')));
    save_setting('payment_mobile_accounts', json_encode(paymentAccountsSanitize($mobile, 'mobile')));
    save_setting('payment_name_mode', 'shared');
    $pa = getPaymentAccounts($pdo);
    check(count($pa['bank']) === 2 && count($pa['mobile']) === 2, 'JSON keys override legacy keys (2 + 2)', 'JSON not read');
    $html = renderPaymentAccountsBlock($pa);
    check(substr_count($html, 'Account Name:') === 1 && strpos($html, 'BJP TECHNOLOGIES (T) LTD') !== false, 'shared name prints once on top', 'shared name not printed once');
    check(strpos($html, 'IGNORED IN SHARED MODE') === false, 'per-account name ignored in shared mode', 'per-account name leaked');
    check(substr_count($html, 'class="pa-line"') === 4, 'one line per account', 'expected 4 lines');
    check(strpos($html, 'SWIFT: CORUTZTZ') !== false && strpos($html, 'Acc: 998877') !== false, 'SWIFT and reference shown inline', 'SWIFT/ref missing');
    check(strpos($html, '>Bank Transfer</strong>') !== false && strpos($html, '>Mobile Money</strong>') !== false, 'section headings present', 'headings missing');
    check(strpos(renderPaymentAccountsBlock($pa, 'h4'), '<h4>Bank Transfer</h4>') !== false, 'voucher h4 headings', 'h4 heading missing');
    check(strpos(renderPaymentAccountsBlock($pa, 'script'), '<script>') === false, 'heading tag is whitelisted', 'heading tag not whitelisted');

    section('Some accounts have a different name');
    save_setting('payment_name_mode', 'each');
    $bank[0]['name'] = 'BJP TECH PROJECTS ACCOUNT';
    save_setting('payment_bank_accounts', json_encode(paymentAccountsSanitize($bank, 'bank')));
    $html = renderPaymentAccountsBlock(getPaymentAccounts($pdo));
    check(strpos($html, 'Account Name: <span class="pa-no">BJP TECHNOLOGIES (T) LTD</span>') !== false, 'default name stays on top', 'default not on top');
    check(strpos($html, '(BJP TECH PROJECTS ACCOUNT)') !== false, 'different name shown in brackets on its line', 'bracket name missing');
    check(substr_count($html, '(BJP TECHNOLOGIES (T) LTD)') === 0, 'default-name accounts carry no brackets', 'default repeated in brackets');

    $all = array_map(fn($a) => $a + ['name' => 'SHOP NAME'], array_map(fn($a) => array_diff_key($a, ['name' => 1]), $bank));
    save_setting('payment_bank_accounts', json_encode(paymentAccountsSanitize($all, 'bank')));
    save_setting('payment_mobile_accounts', json_encode([]));
    $html = renderPaymentAccountsBlock(getPaymentAccounts($pdo));
    check(strpos($html, 'Account Name: <span class="pa-no">SHOP NAME</span>') !== false && strpos($html, '(SHOP NAME)') === false, 'when every account has the same custom name it prints once', 'custom common name not hoisted');

    section('Hidden accounts and empty state');
    $hidden = array_map(fn($a) => ['show' => false] + $a, $bank);
    save_setting('payment_bank_accounts', json_encode(paymentAccountsSanitize($hidden, 'bank')));
    save_setting('check_payable_to', '');
    $pa = getPaymentAccounts($pdo);
    check(!paymentAccountsHasAny($pa), 'all hidden + no cheque → box not shown', 'hidden accounts still counted');
    save_setting('check_payable_to', 'BJP TECHNOLOGIES');
    check(paymentAccountsHasAny(getPaymentAccounts($pdo)), 'cheque alone shows the box', 'cheque ignored');

    section('Escaping and overflow protection');
    save_setting('payment_bank_accounts', json_encode([['label' => '<script>x</script>', 'number' => str_repeat('9', 60), 'name' => '"><b>', 'show' => true]]));
    $html = renderPaymentAccountsBlock(getPaymentAccounts($pdo));
    check(strpos($html, '<script>x') === false && strpos($html, '&lt;script&gt;') !== false, 'labels are HTML-escaped', 'XSS not escaped');
    check(strpos($html, '"><b>') === false, 'names are HTML-escaped', 'name not escaped');
    check(strpos($html, 'overflow-wrap:anywhere') !== false && strpos($html, 'min-width:0') !== false && strpos($html, 'flex-wrap:wrap') !== false,
          'box CSS wraps long numbers/names inside the box', 'overflow CSS missing');

    section('Templates use the shared helper');
    $tpls = [
        'app/bms/invoice/invoice_print.php', 'app/bms/invoice/invoice_print_onyx.php', 'app/bms/invoice/invoice_print_summit.php',
        'app/bms/invoice/invoice_print_wave.php', 'app/bms/sales/quotations/print_quotation.php', 'app/bms/sales/quotations/print_quotation_meadow.php',
        'app/bms/sales/quotations/print_quotation_noir.php', 'app/bms/sales/quotations/print_quotation_terra.php',
        'app/constant/accounts/payment_voucher_print.php',
    ];
    foreach ($tpls as $t) {
        $s = (string)file_get_contents("$root/$t");
        check(strpos($s, 'getPaymentAccounts($pdo)') !== false && strpos($s, 'renderPaymentAccountsBlock($pa') !== false
              && strpos($s, "\$bank['bank_name']") === false && strpos($s, "\$pay['bank_name']") === false,
              "$t renders all accounts", "$t still uses the single-account block");
    }
    $page = (string)file_get_contents("$root/app/constant/settings/payment_settings.php");
    check(strpos($page, "hash_equals(\$_SESSION['csrf_token']") !== false && strpos($page, 'name="_csrf"') !== false, 'settings form is CSRF-protected', 'CSRF missing');
    check(strpos($page, 'id="accountModal"') !== false && strpos($page, 'paymentAccountsSanitize(') !== false, 'settings page uses popup + server-side sanitize', 'popup/sanitize missing');
} finally {
    $pdo->rollBack();
}

echo "\n\033[1mResult: $passes passed, $failures failed\033[0m\n";
exit($failures ? 1 : 0);
