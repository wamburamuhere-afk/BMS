<?php
/**
 * core/tender_boq.php — pure BOQ math, shared by api/tender_boq.php and
 * (Phase E) the AWARDED -> Project BOQ carry-over. Kept here, not inline in
 * the API file, so it can be unit-tested without simulating an HTTP request.
 */

if (!function_exists('recomputeTenderBoqTotal')) {
    /**
     * Recomputes bill subtotals + the BOQ grand total for a tender and
     * persists boq_contingency_percent / boq_vat_percent / boq_grand_total
     * onto `tenders`. This is the ONLY place the BOQ grand-total math happens
     * — callers never trust a client-submitted total.
     *
     * Order of operations (matches the Collection/Summary shown on the BOQ
     * page): subtotal -> + contingency% of subtotal -> + VAT% of
     * (subtotal + contingency).
     */
    function recomputeTenderBoqTotal(PDO $pdo, int $tenderId, ?float $contingencyPercent = null, ?float $vatPercent = null): float
    {
        if ($contingencyPercent === null || $vatPercent === null) {
            $row = $pdo->prepare("SELECT boq_contingency_percent, boq_vat_percent FROM tenders WHERE tender_id = ?");
            $row->execute([$tenderId]);
            $existing = $row->fetch(PDO::FETCH_ASSOC) ?: [];
            $contingencyPercent = $contingencyPercent ?? (float)($existing['boq_contingency_percent'] ?? 0);
            $vatPercent = $vatPercent ?? (float)($existing['boq_vat_percent'] ?? 18);
        }

        $sumStmt = $pdo->prepare("
            SELECT COALESCE(SUM(i.amount), 0) AS subtotal
            FROM tender_boq_items i
            JOIN tender_boq_bills b ON b.bill_id = i.bill_id
            WHERE b.tender_id = ?
        ");
        $sumStmt->execute([$tenderId]);
        $subtotal = (float)$sumStmt->fetchColumn();

        $contingencyAmount = $subtotal * ($contingencyPercent / 100);
        $vatAmount = ($subtotal + $contingencyAmount) * ($vatPercent / 100);
        $grandTotal = $subtotal + $contingencyAmount + $vatAmount;

        $pdo->prepare("
            UPDATE tenders
            SET boq_contingency_percent = ?, boq_vat_percent = ?, boq_grand_total = ?, updated_at = NOW()
            WHERE tender_id = ?
        ")->execute([$contingencyPercent, $vatPercent, $grandTotal, $tenderId]);

        return $grandTotal;
    }
}
