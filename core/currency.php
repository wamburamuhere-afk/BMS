<?php
/**
 * core/currency.php — the company's ONE operating currency (per tenant).
 *
 * Supported operating currencies (product decision 2026-11-01):
 *   TZS  Tanzania   (DEFAULT — every existing tenant)
 *   KES  Kenya
 *   BIF  Burundi
 *   RWF  Rwanda
 *   SSP  South Sudan
 *   USD  (any country)
 *
 * SAFETY RULE — a TZS company must look and behave exactly as before this file
 * existed. Every helper below returns the old hard-coded value when the company
 * currency is TZS; only a company that has chosen another currency sees a change.
 *
 * Source of truth: system_settings `currency` (written by Settings → System
 * Settings, read directly by ~50 pages). `company_currency` (Company Profile /
 * mobile app) is kept in sync on every save, but is NOT used for reading, so a
 * tenant whose two keys disagree today sees no change on the web.
 *
 * There is no foreign-exchange conversion anywhere in the ledger. That is why a
 * non-TZS company may only issue documents in its own currency (see
 * bmsEnforceDocCurrency()). TZS companies keep today's document currency list.
 */

if (!function_exists('bmsSupportedCurrencies')) {
    /**
     * @return array<string, array{name:string, symbol:string, country:?string, unit:string}>
     *         symbol includes its trailing space where the old code had one ('TSh ').
     */
    function bmsSupportedCurrencies(): array
    {
        return [
            'TZS' => ['name' => 'Tanzanian Shilling',   'symbol' => 'TSh ', 'country' => 'Tanzania',    'unit' => 'Shillings'],
            'KES' => ['name' => 'Kenyan Shilling',      'symbol' => 'KSh ', 'country' => 'Kenya',       'unit' => 'Shillings'],
            'BIF' => ['name' => 'Burundian Franc',      'symbol' => 'FBu ', 'country' => 'Burundi',     'unit' => 'Francs'],
            'RWF' => ['name' => 'Rwandan Franc',        'symbol' => 'FRw ', 'country' => 'Rwanda',      'unit' => 'Francs'],
            'SSP' => ['name' => 'South Sudanese Pound', 'symbol' => 'SSP ', 'country' => 'South Sudan', 'unit' => 'Pounds'],
            'USD' => ['name' => 'US Dollar',            'symbol' => '$',    'country' => null,          'unit' => 'Dollars'],
        ];
    }
}

if (!function_exists('bmsCompanyCurrency')) {
    /**
     * The company's operating currency code. Same value every existing page already
     * reads via get_setting('currency', 'TZS'), normalised to upper case; anything
     * that is not a 3-letter code (or no tenant DB, e.g. the superadmin panel) → 'TZS'.
     */
    function bmsCompanyCurrency(): string
    {
        $raw = function_exists('get_setting') ? (string)get_setting('currency', 'TZS') : 'TZS';
        $code = strtoupper(trim($raw));
        return preg_match('/^[A-Z]{3}$/', $code) ? $code : 'TZS';
    }
}

if (!function_exists('bmsIsDefaultCurrency')) {
    /** True for a TZS company — the "change nothing" path. */
    function bmsIsDefaultCurrency(): bool
    {
        return bmsCompanyCurrency() === 'TZS';
    }
}

if (!function_exists('bmsCurrencySymbol')) {
    /**
     * Display prefix for a code. Keeps the old format_currency() symbols (TSh, $, €,
     * £, KSh) and adds FBu / FRw / SSP. An unknown code falls back to 'TSh ' for a
     * TZS company (exactly the old behaviour) and to "CODE " otherwise.
     */
    function bmsCurrencySymbol(?string $code = null): string
    {
        $code = strtoupper(trim((string)($code ?? bmsCompanyCurrency())));
        $legacy = ['EUR' => '€', 'GBP' => '£'];   // still shown on old documents
        $all = bmsSupportedCurrencies();
        if (isset($all[$code]))    return $all[$code]['symbol'];
        if (isset($legacy[$code])) return $legacy[$code];
        return bmsIsDefaultCurrency() ? 'TSh ' : ($code !== '' ? $code . ' ' : bmsCompanyCurrency() . ' ');
    }
}

if (!function_exists('bmsCurrencyUnitWord')) {
    /** "Shillings" / "Francs" / "Pounds" / "Dollars" — for amount-in-words lines. */
    function bmsCurrencyUnitWord(?string $code = null): string
    {
        $code = strtoupper(trim((string)($code ?? bmsCompanyCurrency())));
        $all = bmsSupportedCurrencies();
        return $all[$code]['unit'] ?? 'Shillings';
    }
}

if (!function_exists('bmsCurrencyForCountry')) {
    /** Registration: country name → operating currency. Unknown/empty → 'TZS'. */
    function bmsCurrencyForCountry(?string $country): string
    {
        $c = strtolower(trim((string)$country));
        foreach (bmsSupportedCurrencies() as $code => $meta) {
            if ($meta['country'] !== null && strtolower($meta['country']) === $c) return $code;
        }
        return 'TZS';
    }
}

if (!function_exists('bmsRegistrationCountries')) {
    /** Countries offered at registration, Tanzania first (the default). */
    function bmsRegistrationCountries(): array
    {
        $out = [];
        foreach (bmsSupportedCurrencies() as $meta) {
            if ($meta['country'] !== null) $out[] = $meta['country'];
        }
        return $out;   // Tanzania, Kenya, Burundi, Rwanda, South Sudan
    }
}

if (!function_exists('bmsDocumentCurrencies')) {
    /**
     * Currencies a document form (customer, supplier, quotation, sales order, LPO,
     * PO, invoice, payment …) may offer, as code => name.
     *  - TZS company: EXACTLY the list those forms hard-coded before (unchanged).
     *  - Any other company: only its own currency (no FX in the ledger).
     */
    function bmsDocumentCurrencies(): array
    {
        if (bmsIsDefaultCurrency()) {
            return ['TZS' => 'Tanzanian Shilling', 'USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'KES' => 'Kenyan Shilling'];
        }
        $code = bmsCompanyCurrency();
        $all = bmsSupportedCurrencies();
        return [$code => $all[$code]['name'] ?? $code];
    }
}

if (!function_exists('bmsEnforceDocCurrency')) {
    /**
     * Server-side guard for a document's currency.
     *  - TZS company: returns $posted untouched (today's behaviour).
     *  - Any other company: always its own currency, whatever was posted, so no
     *    foreign-currency amount can reach the ledger unconverted.
     */
    function bmsEnforceDocCurrency($posted)
    {
        return bmsIsDefaultCurrency() ? $posted : bmsCompanyCurrency();
    }
}

if (!function_exists('bmsCompanyCurrencyOptionHtml')) {
    /** The single <option> a non-TZS company's document currency dropdown shows. */
    function bmsCompanyCurrencyOptionHtml(): string
    {
        $code = bmsCompanyCurrency();
        $all  = bmsSupportedCurrencies();
        $label = isset($all[$code]) ? (function_exists('t') ? t($all[$code]['name']) : $all[$code]['name']) . ' (' . $code . ')' : $code;
        return '<option value="' . htmlspecialchars($code, ENT_QUOTES) . '" selected>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    }
}
