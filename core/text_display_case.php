<?php
/**
 * Global "Text Display Case" setting (system_settings.text_display_case) —
 * admin-configurable on System Settings > General (2026-09-18 request,
 * visible for both Simple POS and normal tenants — this is not a
 * Simple-POS-only feature like most other work in this codebase).
 *
 * READ-ONLY DISPLAY FORMATTING ONLY. Raw stored data is never rewritten, so:
 *   - toggling the setting instantly re-formats every EXISTING record too,
 *     with zero data migration;
 *   - switching back is always lossless — the raw value never changed.
 *
 * Deliberately a SEPARATE function from safe_output() (helpers.php), and
 * must NEVER be used to pre-fill an editable form's value="..." attribute.
 * Doing that would let a plain re-save silently bake the transformed casing
 * back in as the new "raw" value, corrupting data a little more on every
 * edit. Use caseFormat() only in genuine read-only render contexts (list
 * tables, view/detail pages, dropdown option labels, print/export output,
 * reports) — keep using safe_output() for every editable input's value.
 */

if (!function_exists('textDisplayCaseMode')) {
    /**
     * The 6 modes mirror Microsoft Word's own "Aa" case-menu order and
     * labels exactly (the reference screenshot this feature was built from)
     * — 'as_typed' is the one extra, default-safe option Word has no
     * equivalent for (it has no neutral "off" state), so an unconfigured or
     * fresh tenant sees zero behaviour change until an admin opts in.
     */
    function textDisplayCaseMode(): string
    {
        $mode = get_setting('text_display_case', 'as_typed');
        $valid = ['as_typed', 'sentence', 'lower', 'upper', 'title', 'toggle'];
        return in_array($mode, $valid, true) ? $mode : 'as_typed';
    }
}

if (!function_exists('sentenceCaseText')) {
    /**
     * Real multi-sentence support (a Notes/Description field can hold more
     * than one sentence) — capitalizes the first letter of the string AND
     * the first letter after each sentence-ending punctuation + whitespace,
     * not just a blanket ucfirst() of the whole string.
     */
    function sentenceCaseText(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');
        return preg_replace_callback(
            '/(^\s*[a-z]|[.!?]\s+[a-z])/u',
            function ($m) { return mb_strtoupper($m[0], 'UTF-8'); },
            $lower
        );
    }
}

if (!function_exists('toggleCaseText')) {
    /** Inverts the case of every letter individually ("tOGGLE cASE" in Word's own menu). */
    function toggleCaseText(string $text): string
    {
        $out = '';
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            $upper = mb_strtoupper($ch, 'UTF-8');
            $out .= ($ch === $upper) ? mb_strtolower($ch, 'UTF-8') : $upper;
        }
        return $out;
    }
}

if (!function_exists('applyCaseMode')) {
    /**
     * Pure string transform — no escaping, no defaults, no mb_ dependency on
     * caller context. Kept separate from caseFormat() so a caller that
     * already has raw text (building a CSV/export row, or a value going
     * into a non-HTML context like an SMS/email body) can reuse the exact
     * same rule without double-escaping.
     */
    function applyCaseMode(string $text, ?string $mode = null): string
    {
        if ($text === '') return $text;
        $mode = $mode ?? textDisplayCaseMode();

        switch ($mode) {
            case 'lower':    return mb_strtolower($text, 'UTF-8');
            case 'upper':    return mb_strtoupper($text, 'UTF-8');
            case 'title':    return mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');
            case 'sentence': return sentenceCaseText($text);
            case 'toggle':   return toggleCaseText($text);
            case 'as_typed':
            default:         return $text;
        }
    }
}

if (!function_exists('caseFormat')) {
    /**
     * The function pages should actually call for READ-ONLY display —
     * combines the case transform with the exact same HTML-escaping
     * safe_output() already does, in the correct order: transform the RAW
     * text first, THEN escape. Escaping first and transforming after would
     * corrupt entities — e.g. "Tom & Jerry" -> escaped "Tom &amp; Jerry" ->
     * uppercased would wrongly become "TOM &AMP; JERRY", an entity no
     * browser recognizes, instead of the correct "TOM &amp; JERRY".
     *
     * Same signature as safe_output($value, $default) by design, so a
     * display-only call site can be converted with a single find/replace.
     * The optional $mode overrides the stored setting (mirrors
     * applyCaseMode()'s own override param) — mainly for deterministic
     * tests and any future caller that needs a specific mode regardless of
     * what's currently configured; normal call sites omit it.
     */
    function caseFormat($value, string $default = 'N/A', ?string $mode = null): string
    {
        if ($value === null || $value === '') return $default;
        return htmlspecialchars(applyCaseMode((string)$value, $mode));
    }
}
