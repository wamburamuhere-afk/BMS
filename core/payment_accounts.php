<?php
/**
 * Company payment accounts (bank + mobile money) printed on quotations,
 * invoices and vouchers. Stored in system_settings:
 *   payment_bank_accounts    JSON [{label, number, swift, name, show}]
 *   payment_mobile_accounts  JSON [{label, number, ref, name, show}]
 *   payment_name_mode        'shared' | 'each'
 *   account_name             default registered name (legacy key, reused)
 * Before the JSON keys exist, the legacy single-account keys are read instead.
 */

const PAYMENT_ACCOUNT_SETTING_KEYS = [
    'payment_bank_accounts', 'payment_mobile_accounts', 'payment_name_mode',
    'bank_name', 'account_name', 'account_number', 'swift_code',
    'mpesa_paybill', 'mpesa_account_no', 'check_payable_to',
];

function paymentAccountsSanitize($rows, string $kind): array
{
    if (!is_array($rows)) return [];
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $row = [
            'label'  => mb_substr(trim((string)($r['label'] ?? '')), 0, 100),
            'number' => mb_substr(trim((string)($r['number'] ?? '')), 0, 60),
            'name'   => mb_substr(trim((string)($r['name'] ?? '')), 0, 150),
            'show'   => !isset($r['show']) || filter_var($r['show'], FILTER_VALIDATE_BOOLEAN),
        ];
        if ($kind === 'bank') {
            $row['swift'] = mb_substr(trim((string)($r['swift'] ?? '')), 0, 30);
        } else {
            $row['ref'] = mb_substr(trim((string)($r['ref'] ?? '')), 0, 60);
        }
        if ($row['label'] === '' || $row['number'] === '') continue;
        $out[] = $row;
    }
    return $out;
}

function getPaymentAccounts(PDO $pdo): array
{
    $s = [];
    try {
        $in = implode(',', array_fill(0, count(PAYMENT_ACCOUNT_SETTING_KEYS), '?'));
        $st = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute(PAYMENT_ACCOUNT_SETTING_KEYS);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) $s[$r['setting_key']] = (string)$r['setting_value'];
    } catch (Exception $e) {}

    if (isset($s['payment_bank_accounts'])) {
        $bank = paymentAccountsSanitize(json_decode($s['payment_bank_accounts'], true), 'bank');
    } else {
        $bank = [];
        if (trim($s['bank_name'] ?? '') !== '' && trim($s['account_number'] ?? '') !== '') {
            $bank[] = ['label' => trim($s['bank_name']), 'number' => trim($s['account_number']),
                       'swift' => trim($s['swift_code'] ?? ''), 'name' => '', 'show' => true];
        }
    }

    if (isset($s['payment_mobile_accounts'])) {
        $mobile = paymentAccountsSanitize(json_decode($s['payment_mobile_accounts'], true), 'mobile');
    } else {
        $mobile = [];
        if (trim($s['mpesa_paybill'] ?? '') !== '') {
            $mobile[] = ['label' => 'Paybill', 'number' => trim($s['mpesa_paybill']),
                         'ref' => trim($s['mpesa_account_no'] ?? ''), 'name' => '', 'show' => true];
        }
    }

    return [
        'mode'         => ($s['payment_name_mode'] ?? '') === 'each' ? 'each' : 'shared',
        'default_name' => trim($s['account_name'] ?? ''),
        'bank'         => $bank,
        'mobile'       => $mobile,
        'cheque'       => trim($s['check_payable_to'] ?? ''),
    ];
}

/** Accounts flagged to appear on documents, each with its effective holder name. */
function paymentAccountsForPrint(array $pa): array
{
    $holder = function (array $a) use ($pa): string {
        return ($pa['mode'] === 'each' && $a['name'] !== '') ? $a['name'] : $pa['default_name'];
    };
    $pick = function (array $rows) use ($holder): array {
        $out = [];
        foreach ($rows as $a) {
            if (empty($a['show'])) continue;
            $a['holder'] = $holder($a);
            $out[] = $a;
        }
        return $out;
    };
    return ['bank' => $pick($pa['bank']), 'mobile' => $pick($pa['mobile']), 'cheque' => $pa['cheque']];
}

function paymentAccountsHasAny(array $pa): bool
{
    $p = paymentAccountsForPrint($pa);
    return $p['bank'] || $p['mobile'] || $p['cheque'] !== '';
}

/**
 * Inner HTML for a template's .bank-details box ("Layout A · compact lines"):
 * the most common holder name prints once on top; any account registered
 * under a different name shows that name in brackets on its own line.
 */
function renderPaymentAccountsBlock(array $pa, string $headTag = 'strong'): string
{
    $p = paymentAccountsForPrint($pa);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $headTag = in_array($headTag, ['strong', 'h4'], true) ? $headTag : 'strong';

    $counts = [];
    foreach (array_merge($p['bank'], $p['mobile']) as $a) {
        if ($a['holder'] !== '') $counts[$a['holder']] = ($counts[$a['holder']] ?? 0) + 1;
    }
    $main = '';
    if ($counts) {
        arsort($counts);
        $main = array_key_first($counts);
        if (isset($counts[$pa['default_name']]) && $counts[$pa['default_name']] === $counts[$main]) {
            $main = $pa['default_name'];
        }
    }

    $line = function (array $a, string $extra) use ($e, $main): string {
        $h = '<p class="pa-line"><span class="pa-lbl">' . $e($a['label']) . ':</span> '
           . '<span class="pa-no">' . $e($a['number']) . '</span>' . $extra;
        if ($a['holder'] !== '' && $a['holder'] !== $main) {
            $h .= ' <span class="pa-sub">(' . $e($a['holder']) . ')</span>';
        }
        return $h . '</p>';
    };

    $html = '<style>'
        . '.bank-details{min-width:0;max-width:100%;overflow:hidden;overflow-wrap:anywhere;word-break:break-word}'
        . '.bank-details .pa-line{display:flex;flex-wrap:wrap;align-items:baseline;column-gap:.35em;min-width:0}'
        . '.bank-details .pa-line>span{min-width:0;max-width:100%;overflow-wrap:anywhere}'
        . '.bank-details .pa-no{font-weight:700;letter-spacing:.2px}'
        . '.bank-details .pa-sub{opacity:.75;font-size:.92em}'
        . '.bank-details .pa-holder{overflow-wrap:anywhere}'
        . '</style>';

    if ($main !== '') {
        $html .= '<p class="pa-holder">Account Name: <span class="pa-no">' . $e($main) . '</span></p>';
    }
    if ($p['bank']) {
        $html .= '<div class="bank-section"><' . $headTag . '>Bank Transfer</' . $headTag . '>';
        foreach ($p['bank'] as $a) {
            $html .= $line($a, $a['swift'] !== '' ? ' <span class="pa-sub">SWIFT: ' . $e($a['swift']) . '</span>' : '');
        }
        $html .= '</div>';
    }
    if ($p['mobile']) {
        $html .= '<div class="bank-section"><' . $headTag . '>Mobile Money</' . $headTag . '>';
        foreach ($p['mobile'] as $a) {
            $ref = ($a['ref'] !== '' && $a['ref'] !== $a['number']) ? ' <span class="pa-sub">Acc: ' . $e($a['ref']) . '</span>' : '';
            $html .= $line($a, $ref);
        }
        $html .= '</div>';
    }
    if ($p['cheque'] !== '') {
        $html .= '<div class="bank-section"><' . $headTag . '>Cheque</' . $headTag . '>'
               . '<p class="pa-line"><span>Payable to:</span> <span>' . $e($p['cheque']) . '</span></p></div>';
    }
    return $html;
}
