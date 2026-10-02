// Phone cart sheet (POS Simple Mode) under the real jQuery 3.7.0 + Bootstrap 5.3.2,
// in jsdom: do Pay / Split / Add-customer tapped INSIDE the sheet actually run?
// Event order matters here (jQuery's copy of hidden.bs.offcanvas fires before the
// native listener), which only a real Bootstrap shows.
//
//   npm install jsdom@24          (any folder on NODE_PATH, or in this repo)
//   node tests/js/test_pos_mobile_sheet.js [path/to/pos_scripts_new.php] [label]
// Prints one JSON line; every check should be true / 1.
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '..', '..');
const scriptsPath = process.argv[2] || path.join(root, 'app/bms/pos/pos_scripts_new.php');
const label = process.argv[3] || 'current';
const src = fs.readFileSync(scriptsPath, 'utf8').replace(/\r\n/g, '\n');
const sheetCode = (src.match(/const posSheet = [\s\S]*?\n\}\);\n<\/script>/) || [''])[0].replace(/<\/script>$/, '');
const initCS = (src.match(/function initCustomerSelect\([\s\S]*?\n\}\n/) || [''])[0]
  .replace(/<\?= json_encode\(t\('Walk-in Customer'\)\) \?>/, '"Walk-in"').replace(/<\?= buildUrl\([^)]*\) \?>/, '/x');
if (!sheetCode) { console.log(JSON.stringify({ label, error: 'sheet code not found' })); process.exit(0); }

const html = `<!doctype html><html><body>
<nav class="bms-bnav" style="display:flex;height:47px"></nav>
<div id="pos-container"><div class="row g-0 pos-simple-layout"><div class="col-md-5">
  <div class="p-3 pos-cart-summary"><span id="cartTotal">TZS 500.00</span></div>
  <div class="p-3 pos-pay-fields"><select id="customerSelect"><option value="">Walk-in</option></select>
    <button type="button" id="btnQuickAddCustomer">+</button>
    <input type="radio" name="paymentMethod" value="cash" checked><input id="amountTendered" value="500"></div>
  <div class="px-3 pos-pay-actions"><div class="pos-pay-total">TOTAL</div><div class="d-grid">
    <button class="btn" onclick="processPayment()" id="processPaymentBtn">PAY</button>
    <button class="btn" onclick="openSplitPaymentModal()" id="splitBtn">SPLIT</button></div></div>
</div></div></div>
<button id="mobileCartFab" data-bs-toggle="offcanvas" data-bs-target="#mobileCartOffcanvas">cart</button>
<div class="offcanvas offcanvas-bottom" id="mobileCartOffcanvas" tabindex="-1">
  <div class="offcanvas-body"><div id="mobileCartOffcanvasItems"></div><div id="mobileSheetExtra"></div></div>
  <div id="mobileSheetFooterSlot"></div>
  <div id="mobileSheetDefaultFooter"><button data-bs-dismiss="offcanvas" onclick="processPayment()">OLD PAY</button></div>
</div></body></html>`;

const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true });
const w = dom.window;
Object.defineProperty(w, 'innerWidth', { value: 390, configurable: true });
w.eval(fs.readFileSync(root + '/assets/js/jquery-3.7.0.min.js', 'utf8'));
w.eval(fs.readFileSync(root + '/assets/js/bootstrap.bundle.min.js', 'utf8'));
w.eval(`
  window.__paid = 0; window.__split = 0; window.__quick = 0;
  function processPayment() { window.__paid++; }
  function openSplitPaymentModal() { window.__split++; }
  const POS_SIMPLE_MODE = true;
  $.fn.select2 = function (o) { if (o === 'destroy') { this.removeClass('select2-hidden-accessible'); return this; } this.addClass('select2-hidden-accessible'); this.data('s2parent', o && o.dropdownParent ? o.dropdownParent[0] : null); return this; };
  ${initCS}
  ${sheetCode}
  $('#btnQuickAddCustomer').on('click', function () { window.__quick++; });
`);

const sleep = ms => new Promise(r => setTimeout(r, ms));
const d = w.document, sheet = () => d.getElementById('mobileCartOffcanvas');
const waitFor = async (cond, ms = 3000) => { const t = Date.now(); while (!cond() && Date.now() - t < ms) await sleep(20); return cond(); };
const open = async () => { w.bootstrap.Offcanvas.getOrCreateInstance(sheet()).show(); return waitFor(() => sheet().classList.contains('show') && !!d.querySelector('#mobileSheetFooterSlot #processPaymentBtn')); };

(async () => {
  const r = { label };
  await sleep(50);
  r.opened = await open();
  r.fabHiddenWhileOpen = w.getComputedStyle(d.getElementById('mobileCartFab')).visibility === 'hidden';
  r.customerDropdownInSheet = w.$('#customerSelect').data('s2parent') === sheet();
  d.querySelector('#mobileSheetFooterSlot #processPaymentBtn').click();
  r.closedAfterPay = await waitFor(() => !sheet().classList.contains('show'));
  await waitFor(() => w.__paid > 0, 1500);
  r.payRan = w.__paid;
  r.backHome = !!d.querySelector('#pos-container .pos-pay-actions #processPaymentBtn') && !!d.querySelector('#pos-container .pos-pay-fields');
  r.fabVisibleAfter = w.getComputedStyle(d.getElementById('mobileCartFab')).visibility !== 'hidden';
  r.customerDropdownBackToBody = !w.$('#customerSelect').data('s2parent');

  await open(); d.querySelector('#mobileSheetFooterSlot #splitBtn').click();
  await waitFor(() => !sheet().classList.contains('show')); await waitFor(() => w.__split > 0, 1500);
  r.splitRan = w.__split;

  await open(); d.querySelector('#mobileSheetExtra #btnQuickAddCustomer').click();
  await waitFor(() => !sheet().classList.contains('show')); await waitFor(() => w.__quick > 0, 1500);
  r.addCustomerRan = w.__quick;

  // A second open/pay cycle must not run twice or get stuck.
  await open(); d.querySelector('#mobileSheetFooterSlot #processPaymentBtn').click();
  await waitFor(() => !sheet().classList.contains('show')); await waitFor(() => w.__paid > 1, 1500);
  r.secondPayRanOnce = w.__paid === 2;

  // Pay on the normal page (sheet closed) is untouched.
  d.querySelector('#pos-container #processPaymentBtn').click(); await sleep(50);
  r.desktopPayRuns = w.__paid === 3;
  console.log(JSON.stringify(r));
  process.exit(0);
})();
