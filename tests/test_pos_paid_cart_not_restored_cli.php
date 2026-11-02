<?php
/**
 * tests/test_pos_paid_cart_not_restored_cli.php — a PAID order must never come back into the till's cart (2026-11-02).
 *
 * The till keeps the cart in the browser (localStorage 'pos_cart') so a refresh does not lose an order being built,
 * and reloads it when the page opens. The saved copy used to be cleared only when the "Sale Completed" popup closed,
 * so a refresh / closed tab / lost power while the popup showed brought the paid order back, and paying it again
 * recorded the sale twice (reproduced in a browser: one soap, two sales, stock down by 2).
 *
 * Fix: clear the saved copy as soon as the server confirms the sale. Retail and restaurant share this code.
 * Static checks on the order of the statements (the JS has no test runner here); the behaviour was proven in a browser.
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }

$js = str_replace("\r\n", "\n", file_get_contents("$root/app/bms/pos/pos_scripts_new.php"));

$fn = strpos($js, 'function submitPayment(paymentData) {');
ok($fn !== false, 'submitPayment() exists');
$succ = strpos($js, "success: function(response) {\n            if (response.success) {", $fn);
ok($succ !== false, 'submitPayment() has the success branch');
$clear = strpos($js, 'clearCartStorage();', $succ);
$popup = strpos($js, 'Swal.fire({', $succ);
$reset = strpos($js, '// === RESET FOR NEXT CUSTOMER ===', $succ);
ok($clear !== false && $popup !== false && $clear < $popup, 'the saved cart is cleared BEFORE the "Sale Completed" popup is shown');
ok($clear !== false && $reset !== false && $clear < $reset, 'the saved cart is cleared before (not only in) the reset that runs when the popup closes');
// nothing between the early clear and the popup may save the cart back
$between = substr($js, $clear, $popup - $clear);
ok(strpos($between, 'saveCartToStorage') === false, 'nothing between the clear and the popup saves the cart back');
// the WhatsApp receipt is still built from the cart, so the in-memory cart must not be emptied early
ok(strpos($between, 'cart = [];') === false && strpos($between, 'buildWhatsAppReceiptText(') !== false, 'the in-memory cart stays until the popup closes (the WhatsApp receipt is built from it)');
// the clear sits inside the success branch only: a refused / failed payment keeps the cart for a retry
$branchStart = $succ + strlen("success: function(response) {\n            if (response.success) {");
ok(trim(substr($js, $branchStart, strpos($js, 'clearCartStorage();', $branchStart) - $branchStart)) === '' || preg_match('#^\s*(//[^\n]*\n\s*)*$#', substr($js, $branchStart, strpos($js, 'clearCartStorage();', $branchStart) - $branchStart)), 'the clear is the first statement of the success branch, so a failed payment keeps the cart');
// the normal reset is unchanged
ok(substr_count(substr($js, $reset, 400), 'clearCartStorage();') === 1 && strpos(substr($js, $reset, 400), 'cart = [];') !== false, 'the "Next Customer" reset still empties the cart and its saved copy');
// the page still loads a saved (unpaid) cart — the feature itself is kept
ok(strpos($js, 'loadCartFromStorage();') !== false && strpos($js, "localStorage.setItem('pos_cart'") !== false, 'an UNPAID order is still kept across a refresh (save + load are untouched)');

echo "\nPasses:   $pass\nFailures: $fail\n";
exit($fail ? 1 : 0);
