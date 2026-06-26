<?php
/**
 * core/form_lookups.php
 * ---------------------
 * Shared reference-data helpers for the self-growing dropdowns
 * (supplier_type, payment_terms, currency, ...) used by Suppliers,
 * Sub-contractors and Customers. The actor row stores the chosen VALUE string;
 * the option catalogue lives in `form_lookups` and grows when a user types a
 * new value. Mirrors the proven supplier_categories "type-new-persists" pattern.
 */

if (!function_exists('formLookupOptions')) {
    /**
     * Active options for a lookup key, ordered for display.
     * @return array<int,array{value:string,label:string}>
     */
    function formLookupOptions(PDO $pdo, string $key): array
    {
        $st = $pdo->prepare("SELECT value, label FROM form_lookups
                              WHERE lookup_key = ? AND status = 'active'
                              ORDER BY sort_order, label");
        $st->execute([$key]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('renderOtherSelect')) {
    /**
     * Render a dropdown with an "Other" option that swaps to a text input.
     * Choosing "Other" hides the dropdown and reveals an input; the typed value
     * is submitted under $otherName and the server resolves it (value 'other' →
     * the typed text) and persists it so it appears next time.
     *
     * @param array  $options  list of ['value'=>..,'label'=>..]
     * @param string $selected currently-selected value (matched against option values)
     * @param string $otherName POST field name for the typed value (e.g. 'supplier_type_other')
     */
    function renderOtherSelect(string $id, string $name, array $options, string $selected = '',
                               string $otherName = '', string $placeholder = 'Select…', bool $required = false): string
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $opts = '<option value="">' . $h($placeholder) . '</option>';
        $matched = false;
        foreach ($options as $o) {
            $v = (string)($o['value'] ?? '');
            $l = (string)($o['label'] ?? $v);
            $sel = ($selected !== '' && $selected === $v) ? ' selected' : '';
            if ($sel) $matched = true;
            $opts .= '<option value="' . $h($v) . '"' . $sel . '>' . $h($l) . '</option>';
        }
        // A persisted value not in the current list (edge) — keep it visible/selected.
        if ($selected !== '' && !$matched && $selected !== 'other') {
            $opts .= '<option value="' . $h($selected) . '" selected>' . $h($selected) . '</option>';
        }
        $req = $required ? ' required' : '';
        $otherOpt = '<option value="other">➕ Other (type new)…</option>';

        return '<div class="other-field-wrap">'
             .   '<select class="form-select other-trigger" id="' . $h($id) . '" name="' . $h($name) . '"'
             .          ' data-placeholder="' . $h($placeholder) . '" data-other-name="' . $h($otherName) . '"' . $req . '>'
             .     $opts . $otherOpt
             .   '</select>'
             .   '<div class="other-input-box mt-2 d-none">'
             .     '<div class="input-group">'
             .       '<input type="text" class="form-control other-input" name="' . $h($otherName) . '"'
             .             ' placeholder="Type a new value — it will be saved for next time">'
             .       '<button type="button" class="btn btn-outline-secondary other-back" title="Back to list"><i class="bi bi-arrow-left"></i></button>'
             .     '</div>'
             .   '</div>'
             . '</div>';
    }
}

if (!function_exists('upsertFormLookup')) {
    /**
     * Ensure a chosen value exists in the catalogue so it reappears next time.
     * No-op for empty values or values already present (idempotent). The label
     * defaults to the value for free-typed entries. Best-effort: never throws.
     *
     * @return bool true if a new option was added.
     */
    function upsertFormLookup(PDO $pdo, string $key, ?string $value, ?int $userId = null, ?string $label = null): bool
    {
        $value = trim((string)$value);
        if ($key === '' || $value === '') return false;

        try {
            $exists = $pdo->prepare("SELECT 1 FROM form_lookups WHERE lookup_key = ? AND value = ? LIMIT 1");
            $exists->execute([$key, $value]);
            if ($exists->fetchColumn()) return false;

            $nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM form_lookups
                                            WHERE lookup_key = " . $pdo->quote($key))->fetchColumn();
            $ins = $pdo->prepare("INSERT IGNORE INTO form_lookups (lookup_key, value, label, sort_order, created_by)
                                  VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$key, $value, ($label !== null && $label !== '' ? $label : $value), $nextOrder, $userId]);
            return $ins->rowCount() > 0;
        } catch (Throwable $e) {
            error_log("upsertFormLookup($key): " . $e->getMessage());
            return false;
        }
    }
}
