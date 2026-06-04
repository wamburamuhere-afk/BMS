<?php
/**
 * core/gl_source.php
 *
 * Resolves a journal entry's SOURCE DOCUMENT (journal_entries.entity_type +
 * entity_id, set by the auto-posting engine in core/auto_post_hook.php) into a
 * human label and a drill-down URL for the General Ledger.
 *
 * The GL previously showed only the journal's own reference_number, so you
 * couldn't see that a line came from Invoice #123 or Payroll #45. The data has
 * always been there (entity_type/entity_id) — this just surfaces it.
 *
 * Extracted as a pure helper so it can be unit-tested apart from the ledger
 * page. No DB, no side effects. Safe to require multiple times.
 */

if (!function_exists('gl_source_link')) {

    /**
     * Map of auto-posted entity_type → [route slug, human label]. Only types
     * with a real detail page are linkable; others render as a plain label
     * (no broken links). Route slugs resolve via getUrl(); the id param is the
     * BMS-wide `?id=` convention used by invoice_view / grn_view / etc.
     *
     * @return array<string,array{0:string,1:string}>
     */
    function gl_source_routes(): array {
        return [
            'invoice' => ['invoice_view',    'Invoice'],   // approve_invoice → invoice_id
            'grn'     => ['grn_view',        'GRN'],        // approve_grn → receipt_id
            'payroll' => ['payroll_details', 'Payroll'],   // update_payroll_status → payroll_id
            'expense' => ['expenses/view',   'Expense'],    // update_expense_status → expense_id
        ];
    }

    /**
     * Human label for entity_types that have no standalone detail page (so the
     * GL still names the source, just without a link).
     *
     * @return array<string,string>
     */
    function gl_source_labels(): array {
        return [
            'payment'          => 'Customer Payment',   // record_payment → payment_id
            'supplier_payment' => 'Supplier Payment',   // add_supplier_payment → payment_id
        ];
    }

    /**
     * Resolve a source document to a label + (optional) drill-down URL.
     *
     *   gl_source_link('invoice', 123)
     *     → ['label' => 'Invoice #123', 'url' => '.../invoice_view.php?id=123']
     *   gl_source_link('supplier_payment', 9)
     *     → ['label' => 'Supplier Payment #9', 'url' => null]   (no detail page)
     *   gl_source_link('', 0)
     *     → ['label' => '', 'url' => null]                      (manual journal)
     *
     * @param  string|null $entity_type  journal_entries.entity_type
     * @param  int         $entity_id    journal_entries.entity_id
     * @return array{label:string,url:?string}
     */
    function gl_source_link(?string $entity_type, int $entity_id): array {
        $type = $entity_type ? strtolower(trim($entity_type)) : '';
        if ($type === '' || $entity_id <= 0) {
            return ['label' => '', 'url' => null];   // manual / unposted — no source
        }

        $routes = gl_source_routes();
        if (isset($routes[$type])) {
            [$slug, $label] = $routes[$type];
            $url = function_exists('getUrl') ? getUrl($slug) . '?id=' . $entity_id : null;
            return ['label' => "$label #$entity_id", 'url' => $url];
        }

        // Known-but-unlinkable, else humanise the raw type ("stock_adjustment"
        // → "Stock Adjustment") so nothing renders as an opaque slug.
        $label = gl_source_labels()[$type] ?? ucwords(str_replace('_', ' ', $type));
        return ['label' => "$label #$entity_id", 'url' => null];
    }
}
