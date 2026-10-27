<?php
/**
 * POS "Record Old Sale" — screen and report test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_old_sale_ui_cli.php
 *
 * Verifies:
 *   1. POS screen wiring (source): button only for pos_past_sales, pop-up with
 *      quick days + date/time + preview, old-sale payload, confirmation, hold
 *      blocked, pay-button label kept, no "start a shift" stop in this mode.
 *   2. The POS page renders for the local admin with no PHP warnings, shows the
 *      button in Swahili, and its main inline script parses (node --check).
 *   3. POS Settings saves the day limit within 1–365.
 *   4. Z-report (real page, in a rolled-back transaction): an old sale gets a
 *      badge and a "Recorded later" section; a back-dated shift gets its note.
 *   5. Sales history API returns is_past_entry and created_at; shift history
 *      and the POS dashboard show the badges.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['is_admin'] = true;

$failures = 0; $passes = 0;
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});
function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function has(string $hay, string $needle): bool { return strpos($hay, $needle) !== false; }

$pos  = file_get_contents("$root/app/bms/pos/pos.php");
$js   = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
$set  = file_get_contents("$root/app/constant/settings/pos_config_settings.php");

// ─────────────────────────────────────────────────────────────────────────
section('1. POS screen wiring');
// ─────────────────────────────────────────────────────────────────────────
ok(has($pos, "\$can_record_old_sale = canView('pos_past_sales');") && has($pos, '<?php if ($can_record_old_sale): ?>'), 'button only for people with pos_past_sales');
ok(has($pos, 'id="oldSaleBtn"') && has($pos, "t('Record Old Sale')") && has($pos, "t('Old Sale')"), 'header button: full label on desktop, short on phones');
ok(has($pos, 'id="oldSaleWhenBtn"') && has($pos, 'id="oldSaleDayList"') && has($pos, 'id="oldSaleTimeList"') && has($pos, 'id="oldSaleDate"') && has($pos, 'id="oldSaleTime"') && has($pos, 'id="oldSalePreview"'), 'pop-up: one "When" field with day + time lists, live preview');
ok(!has($pos, 'id="oldSaleQuick"') && !has($pos, 'type="date"'), 'no separate quick-day buttons or date input (day asked once)');
ok(has($js, 'maxDays: <?= (int)($old_sale_max_days ?? 30) ?>') && has($js, 'for (let n = 0; n <= OLD_SALE_T.maxDays; n++)'), 'day list limited to the allowed days (today back to the limit, never a future day)');
ok(has($pos, 'id="oldSaleChip"') && has($pos, 'exitOldSaleMode()'), 'chip with the chosen time and an exit button');
ok(has($pos, 'align-items-center pos-hdr-collapsed">') && has($pos, 'id="posActionsBtn"') && has($pos, 'data-bs-target="#posActionsSheet"'), 'phone header is one line with an Actions button that opens the sheet');
ok(has($pos, '#posHeaderBar.pos-hdr-collapsed #oldSalePopover { visibility: visible; }') && has($pos, 'display: block !important; height: 0; overflow: hidden; visibility: hidden;'), 'hoisted CSS hides the desktop buttons on phones but keeps the old-sale pop-up usable');
ok(has($pos, 'id="posActionsSheet"') && has($pos, 'onclick="posAction(openCashDrawer)"') && has($pos, 'onclick="posAction(toggleOldSalePopover)"') && has($pos, 'onclick="posAction(endShift)"') && has($pos, 'onclick="posAction(startShift)"') && has($pos, "href=\"<?= getUrl('pos/dashboard') ?>\""), 'sheet rows call the same functions as the desktop buttons');
ok(strpos($pos, 'onclick="posAction(startShift)"') < strpos($pos, 'onclick="posAction(openCashDrawer)"') && strpos($pos, 'onclick="posAction(endShift)"') > strpos($pos, "Today's totals, past shifts and receipts"), 'Start Shift first (no shift), End Shift last');
ok(has($pos, "<?= \$shift_active ? '' : 'disabled' ?>") && has($pos, "t('Start a shift first')"), 'Open Drawer greyed out without a shift');
ok(has($pos, '<i class="bi bi-grid-fill"></i> <?= t(\'Actions\') ?> <i class="bi bi-chevron-down"></i>') && has($pos, 'background: #fff; color: #0d6efd; font-size: .82rem; font-weight: 700;'), 'Actions is a solid white button with icon and ⌄ (plainly tappable)');
ok(has($pos, 'class="pos-scan-txt"') && has($pos, '#posHeaderBar #scannerReadyBadge > * { display: none; }'), 'phone scanner badge is a dot, label kept for screen readers');
ok(has($js, "el.addEventListener('hidden.bs.offcanvas', function () { fn(); }, { once: true });"), 'a sheet row runs its action only after the sheet has closed');
ok(has($js, "\$('#posActOldSaleTitle').text(on ? OLD_SALE_T.sheetOn") && has($js, "\$('#posActOldSaleExit').toggleClass('d-none', !on);"), 'old-sale row shows the chosen time, with a stop row, while recording');
ok(!has($js, 'togglePosHeader') && !has($js, 'posHeaderExpanded'), 'old fold/unfold code removed');
ok(has($pos, '.pos-old-sale-pop {') && has($pos, 'position: fixed; top: auto; left: 0; right: 0; bottom: 0;'), 'pop-up becomes a bottom sheet on phones');
ok(!preg_match('/pos-old-sale[^{]*\{[^}]*#ffc107/', $pos), 'white and blue only (no yellow)');
ok(has($js, "if (!oldSale) {\n        Swal.fire({\n            icon: 'warning',\n            title: PT.noActiveShiftTitle") || has($js, "if (!oldSale) {\r\n        Swal.fire({"), 'no "start a shift" stop while recording an old sale');
ok(has($js, 'paymentData.old_sale = 1;') && has($js, 'paymentData.sold_at = oldSale.at;') && has($js, 'delete paymentData.receipt_number;') && has($js, 'paymentData.client_uuid = oldSaleUuid();'), 'payload: old_sale + sold_at, server numbers the receipt, one-time id');
ok(has($js, 'confirmOldSale(paymentData, function () {') && has($js, 'res.similar && res.similar.length'), 'confirmation step, with the likely-duplicate warning');
ok(has($js, "if (oldSale) {\n        Swal.fire({ icon: 'info', text: OLD_SALE_T.noHold });") || has($js, "Swal.fire({ icon: 'info', text: OLD_SALE_T.noHold });"), 'Hold is blocked in this mode');
ok(substr_count($js, "prop('disabled', false).html(payButtonHtml());") === 4 &&!has($js, "PT.processPaymentBtn);\n            }"), 'every pay-button reset keeps the mode’s label');
ok(has($js, 'const savedReceipt = response.receipt_number || currentReceiptNumber;'), 'success message shows the server’s dated receipt number');
ok(strpos($js, 'let oldSale = null;') < strpos($js, 'function processPayment()'), 'oldSale is declared before anything reads it');

// ─────────────────────────────────────────────────────────────────────────
section('2. POS page renders (admin, Swahili)');
// ─────────────────────────────────────────────────────────────────────────
$harness = tempnam(sys_get_temp_dir(), 'posr') . '.php';
file_put_contents($harness, '<?php
$_SERVER += ["HTTP_HOST"=>"dev.bms.local","SERVER_NAME"=>"dev.bms.local","REQUEST_METHOD"=>"GET","REQUEST_URI"=>"/pos","SCRIPT_NAME"=>"/index.php","REMOTE_ADDR"=>"127.0.0.1","HTTPS"=>"off","HTTP_USER_AGENT"=>"cli-render"];
chdir(' . var_export($root, true) . ');
require_once "roots.php";
$_SESSION["user_id"]=4; $_SESSION["username"]="admin"; $_SESSION["role"]="admin"; $_SESSION["role_id"]=1; $_SESSION["is_admin"]=true; $_SESSION["user_lang"]="sw";
include "app/bms/pos/pos.php";');
$out = []; $rc = 0;
exec('php -d display_errors=1 -d error_reporting=' . E_ALL . ' ' . escapeshellarg($harness) . ' 2>&1', $out, $rc);
@unlink($harness);
$html = implode("\n", $out);
ok($rc === 0 && strlen($html) > 10000, 'page rendered');
ok(!preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $html), 'no PHP warnings or errors in the page');
ok(has($html, 'Rekodi Mauzo ya Zamani') && has($html, 'Uzo huu ulifanyika lini?') && has($html, 'HIFADHI UZO WA ZAMANI'), 'button, pop-up and pay label in Swahili');
if (preg_match('#<script>\s*let cart = \[\];(.*?)</script>#s', $html, $m)) {
    $tmpJs = tempnam(sys_get_temp_dir(), 'posjs') . '.js';
    file_put_contents($tmpJs, 'let cart = [];' . $m[1]);
    $o2 = []; $rc2 = 0;
    exec('node --check ' . escapeshellarg($tmpJs) . ' 2>&1', $o2, $rc2);
    @unlink($tmpJs);
    if ($rc2 !== 0 && preg_match('/not recognized|not found/i', implode(' ', $o2))) pass('node not installed — JS parse check skipped');
    else ok($rc2 === 0, 'the POS script parses' . ($rc2 ? ' — ' . implode(' ', array_slice($o2, 0, 4)) : ''));
} else {
    fail('POS script block not found in the rendered page');
}

// ─────────────────────────────────────────────────────────────────────────
section('3. POS Settings — day limit');
// ─────────────────────────────────────────────────────────────────────────
ok(has($set, "save_setting('pos_old_sale_max_days', (\$old_days >= 1 && \$old_days <= 365) ? \$old_days : 30);"), 'saved only within 1–365, else 30');
ok(has($set, 'name="pos_old_sale_max_days" min="1" max="365"'), 'number field with the same range');

// ─────────────────────────────────────────────────────────────────────────
section('4. Z-report shows old sales (rolled back)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, start_time, end_time, starting_cash, status, is_past_entry)
                   VALUES ('TEST-ZR-BACK', 4, 1, '2026-10-22 10:15:00', '2026-10-22 16:40:00', 0, 'closed', 1)")->execute();
    $zs = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO pos_sales (receipt_number, shift_id, user_id, payment_method, grand_total, sale_status, is_return_sale, is_past_entry, sale_date, created_at)
                   VALUES ('RCP-TESTZR-0001', ?, 4, 'cash', 12300, 'completed', 0, 1, '2026-10-22 14:30:00', '2026-10-27 17:42:00')")->execute([$zs]);
    $_GET = ['shift_id' => $zs];
    $_SESSION['user_lang'] = 'en'; loadLanguage('en');
    ob_start();
    (function () { include $GLOBALS['root'] . '/app/bms/pos/zreport.php'; })();
    $z = ob_get_clean();
    ok(has($z, 'RCP-TESTZR-0001') && has($z, 'badge badge-past'), 'the old sale is listed with a badge');
    ok(has($z, 'Recorded later (1)') && has($z, '22/10/2026 14:30') && has($z, '27/10/2026 17:42'), '"Recorded later" shows the sale time and when it was entered');
    ok(has($z, 'Back-dated shift.'), 'a back-dated shift carries its note');

    // ─────────────────────────────────────────────────────────────────────
    section('5. Sales history and badges');
    // ─────────────────────────────────────────────────────────────────────
    $_GET = ['start_date' => '2026-10-22', 'end_date' => '2026-10-22', 'search' => 'RCP-TESTZR'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    (function () { include $GLOBALS['root'] . '/api/pos/get_sales.php'; })();
    $g = json_decode(ob_get_clean(), true);
    $row = $g['data'][0] ?? [];
    ok(($row['is_past_entry'] ?? null) === 1 && ($row['created_at'] ?? '') === '2026-10-27 17:42:00', 'get_sales returns is_past_entry and when it was entered');
} finally {
    $pdo->rollBack();
}
$dash = file_get_contents("$root/app/bms/pos/pos_dashboard.php");
ok(substr_count($dash, 'oldSaleBadge(') === 4, 'POS dashboard sales tables and cards show the old-sale badge');
$sh = file_get_contents("$root/app/bms/pos/shift_history.php");
ok(has($sh, "!empty(\$s['is_past_entry'])") && has($sh, 'T_BACKDATED_LABEL'), 'shift history marks back-dated shifts (table and cards)');
