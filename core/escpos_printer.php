<?php
/**
 * core/escpos_printer.php
 *
 * Phase 21 (pos_upgrade_plan.md §8) — real network (IP) thermal-printer
 * support. §3 Phase 10 correctly ruled out raw ESC/POS printing and
 * drawer-kick from a plain browser page (no WebUSB without HTTPS, no print-
 * bridge agent installed) — but a printer with its OWN Ethernet/WiFi
 * interface is not "the browser talking to hardware" at all: it is a plain
 * TCP socket target on the shop LAN, reachable directly from this PHP
 * backend. This only helps that class of hardware — a USB-only printer
 * still needs a bridge, unchanged conclusion from Phase 10.
 *
 * Standard ESC/POS control codes used here (raw byte sequences, not text):
 *   ESC @         (\x1B\x40)             — initialise printer
 *   ESC E n       (\x1B\x45\x01/\x00)    — bold on/off
 *   ESC a n       (\x1B\x61\x00/\x01/\x02) — align left/center/right
 *   GS V 0        (\x1D\x56\x00)         — full paper cut
 *   ESC p 0 25 250 (\x1B\x70\x00\x19\xFA) — drawer-kick (pin 2), rides the
 *                                            SAME socket as the print job —
 *                                            this is what makes the
 *                                            drawer-kick claim real, not
 *                                            speculative: no separate
 *                                            hardware channel is needed.
 */

const ESCPOS_INIT    = "\x1B\x40";
const ESCPOS_BOLD_ON  = "\x1B\x45\x01";
const ESCPOS_BOLD_OFF = "\x1B\x45\x00";
const ESCPOS_ALIGN_LEFT   = "\x1B\x61\x00";
const ESCPOS_ALIGN_CENTER = "\x1B\x61\x01";
const ESCPOS_ALIGN_RIGHT  = "\x1B\x61\x02";
const ESCPOS_CUT      = "\x1D\x56\x00";
const ESCPOS_DRAWER_KICK = "\x1B\x70\x00\x19\xFA";

/**
 * Build the raw ESC/POS byte stream for a POS receipt.
 *
 * @param array $data {
 *   company_name, company_address, company_phone, company_tin, company_vrn,
 *   receipt_number, cashier_name, sale_date, customer_name,
 *   items: [{product_name, quantity, unit_price, line_total}, ...],
 *   subtotal, tax_amount, discount_amount, grand_total, payment_method,
 *   amount_tendered, change_given, currency, char_width (default 42, the
 *   standard column count for an 80mm thermal printer at 12cpi font)
 * }
 * @param bool $kickDrawer  append the drawer-kick command (only meaningful
 *             when this register's drawer is wired to this printer's kick
 *             port — same "opens automatically on print" mechanism already
 *             documented in §3 Phase 10's honest cash-drawer messaging).
 */
function buildEscPosReceipt(array $data, bool $kickDrawer = true): string
{
    $w = (int)($data['char_width'] ?? 42);
    $currency = $data['currency'] ?? 'TZS';
    $line = str_repeat('-', $w) . "\n";

    $center = function (string $s) use ($w) {
        $s = trim($s);
        if ($s === '') return '';
        $pad = max(0, intdiv($w - mb_strlen($s), 2));
        return str_repeat(' ', $pad) . $s . "\n";
    };
    $twoCol = function (string $left, string $right) use ($w) {
        $left = trim($left); $right = trim($right);
        $space = max(1, $w - mb_strlen($left) - mb_strlen($right));
        return $left . str_repeat(' ', $space) . $right . "\n";
    };

    $out = ESCPOS_INIT . ESCPOS_ALIGN_CENTER . ESCPOS_BOLD_ON;
    $out .= $center($data['company_name'] ?? '');
    $out .= ESCPOS_BOLD_OFF;
    if (!empty($data['company_address'])) $out .= $center($data['company_address']);
    if (!empty($data['company_phone']))   $out .= $center($data['company_phone']);
    if (!empty($data['company_tin']))     $out .= $center('TIN: ' . $data['company_tin']);
    if (!empty($data['company_vrn']))     $out .= $center('VRN: ' . $data['company_vrn']);
    $out .= ESCPOS_ALIGN_LEFT . $line;

    $out .= $twoCol('Receipt#:', (string)($data['receipt_number'] ?? ''));
    $out .= $twoCol('Date:', (string)($data['sale_date'] ?? date('Y-m-d H:i')));
    $out .= $twoCol('Cashier:', (string)($data['cashier_name'] ?? ''));
    if (!empty($data['customer_name'])) $out .= $twoCol('Customer:', (string)$data['customer_name']);
    $out .= $line;

    foreach (($data['items'] ?? []) as $item) {
        $out .= (string)($item['product_name'] ?? '') . "\n";
        $qtyPrice = number_format((float)($item['quantity'] ?? 0), 2) . ' x ' . number_format((float)($item['unit_price'] ?? 0), 2);
        $out .= $twoCol('  ' . $qtyPrice, number_format((float)($item['line_total'] ?? 0), 2));
    }
    $out .= $line;

    $out .= $twoCol('Subtotal:', number_format((float)($data['subtotal'] ?? 0), 2));
    if (!empty($data['discount_amount'])) $out .= $twoCol('Discount:', '-' . number_format((float)$data['discount_amount'], 2));
    if (!empty($data['tax_amount']))      $out .= $twoCol('Tax:', number_format((float)$data['tax_amount'], 2));
    $out .= ESCPOS_BOLD_ON . $twoCol('TOTAL (' . $currency . '):', number_format((float)($data['grand_total'] ?? 0), 2)) . ESCPOS_BOLD_OFF;

    if (isset($data['amount_tendered'])) $out .= $twoCol('Tendered:', number_format((float)$data['amount_tendered'], 2));
    if (isset($data['change_given']))    $out .= $twoCol('Change:', number_format((float)$data['change_given'], 2));
    $out .= $twoCol('Payment:', (string)($data['payment_method'] ?? ''));
    $out .= $line;

    $out .= ESCPOS_ALIGN_CENTER . $center('*** THANK YOU ***') . "\n\n\n";
    $out .= ESCPOS_CUT;
    if ($kickDrawer) $out .= ESCPOS_DRAWER_KICK;

    return $out;
}

/**
 * Send raw bytes to a network (IP) thermal printer over a plain TCP socket.
 * Fail-open by design — the caller (print_receipt.php) falls back to the
 * browser print dialog on any failure here, never blocks the cashier from
 * getting SOME receipt.
 *
 * @return array{success: bool, error: ?string}
 */
function sendToNetworkPrinter(string $ip, int $port, string $bytes, float $timeoutSeconds = 3.0): array
{
    if ($ip === '' || $port <= 0) {
        return ['success' => false, 'error' => 'Printer IP address/port not configured.'];
    }

    $errno = 0; $errstr = '';
    $socket = @fsockopen($ip, $port, $errno, $errstr, $timeoutSeconds);
    if (!$socket) {
        return ['success' => false, 'error' => "Could not connect to printer at $ip:$port ($errstr)"];
    }

    stream_set_timeout($socket, (int)$timeoutSeconds);
    $written = @fwrite($socket, $bytes);
    fclose($socket);

    if ($written === false || $written < strlen($bytes)) {
        return ['success' => false, 'error' => 'Failed to send the full receipt to the printer.'];
    }

    return ['success' => true, 'error' => null];
}
