<?php
/**
 * core/tender_documents.php — auto-drafts the "Form of Tender" covering
 * letter from a tender's own data (Tender Details tab + Phase A's BOQ grand
 * total). Pure string building, no DB writes — callers decide when to
 * persist the result (first load vs. the explicit "Re-draft" action).
 *
 * Recipient and subject are deliberately NOT part of the editable draft —
 * they're always deterministic from tender_no/tender_description/
 * procuring_entity_name, which already live in the Tender Details tab as the
 * canonical copy. Only the body paragraphs are user-editable, stored in
 * tenders.form_of_tender_html.
 */

if (!function_exists('draftFormOfTenderBodyHtml')) {
    function draftFormOfTenderBodyHtml(array $tender): string
    {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $tenderNo = $tender['tender_no'] ?: '[Tender No.]';
        $grandTotal = (float)($tender['boq_grand_total'] ?? 0);
        $sumText = $grandTotal > 0
            ? 'TZS ' . number_format($grandTotal, 2)
            : 'TZS 0.00 (to be priced in the Bills of Quantities)';
        $validityDays = (int)($tender['bid_validity_days'] ?? 90) ?: 90;

        $html = '<p>FORM OF TENDER &mdash; ' . $esc($tenderNo) . ': ' . $esc($tender['tender_description'] ?: '[Tender Title]') . '</p>';
        $html .= '<p>Having examined the bidding documents, we, the undersigned, offer to supply and deliver / execute '
            . 'the above-named works in full conformity with the said bidding documents for the sum of '
            . $esc($sumText) . ', or such other sums as may be ascertained in accordance with the conditions of contract.</p>';
        $html .= '<p>We undertake, if our tender is accepted, to commence and complete delivery in accordance with the '
            . 'delivery schedule / work programme submitted.</p>';
        $html .= '<p>Our tender shall remain valid and binding upon us for a period of ' . $validityDays
            . ' days from the date fixed for tender opening.<br>'
            . 'We declare that we have not been debarred by the Public Procurement Regulatory Authority and that the '
            . 'information provided herein is true and correct.</p>';
        $html .= '<p>Dated this _____ day of __________ ' . date('Y') . '.</p>';

        return $html;
    }
}

if (!function_exists('tenderFormOfTenderRecipientHtml')) {
    function tenderFormOfTenderRecipientHtml(array $tender): string
    {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        return 'To: The Secretary,<br>Tender Board,<br>' . $esc($tender['procuring_entity_name'] ?: '[Procuring Entity]');
    }
}

if (!function_exists('tenderFormOfTenderSubject')) {
    function tenderFormOfTenderSubject(array $tender): string
    {
        return 'FORM OF TENDER — ' . ($tender['tender_no'] ?: '[Tender No.]');
    }
}

/**
 * Phase F — Print & Preview. TCPDF's writeHTML() only reliably renders a
 * table-based layout (same constraint core/document_letter_render.php
 * already works around), so these build a plain HTML table per document
 * rather than reusing the on-screen Bootstrap markup. Fed as 'content' into
 * the same generateLetterPdf() the Form of Tender uses (core/tender_award.php's
 * sibling reuse-the-engine decision, tender.md §3) — no second PDF pipeline.
 */
if (!function_exists('buildTenderBoqPrintHtml')) {
    function buildTenderBoqPrintHtml(array $tender, array $bills, array $itemsByBill): string
    {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $html = '<h4>Bills of Quantities</h4>';
        foreach ($bills as $bill) {
            $items = $itemsByBill[$bill['bill_id']] ?? [];
            $html .= '<p><strong>' . $esc($bill['bill_title']) . '</strong></p>';
            $html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
            $html .= '<tr style="background-color:#f0f0f0;"><th width="5%">#</th><th width="40%">Description</th><th width="10%">Unit</th><th width="12%">Qty</th><th width="15%">Rate</th><th width="18%">Amount</th></tr>';
            foreach ($items as $i => $item) {
                $html .= '<tr><td>' . ($i + 1) . '</td><td>' . $esc($item['description']) . '</td><td>' . $esc($item['unit']) . '</td>'
                    . '<td align="right">' . number_format((float)$item['qty'], 3) . '</td><td align="right">' . number_format((float)$item['rate'], 2) . '</td>'
                    . '<td align="right">' . number_format((float)$item['amount'], 2) . '</td></tr>';
            }
            $billTotal = array_sum(array_column($items, 'amount'));
            $html .= '<tr><td colspan="5" align="right"><strong>Total — ' . $esc($bill['bill_title']) . '</strong></td><td align="right"><strong>' . number_format($billTotal, 2) . '</strong></td></tr>';
            $html .= '</table><br>';
        }
        $subtotal = (float)($tender['boq_grand_total'] ?? 0);
        $html .= '<table border="0" cellpadding="4" width="60%" align="right">';
        $html .= '<tr><td>Contingency</td><td align="right">' . number_format((float)$tender['boq_contingency_percent'], 2) . '%</td></tr>';
        $html .= '<tr><td>VAT</td><td align="right">' . number_format((float)$tender['boq_vat_percent'], 2) . '%</td></tr>';
        $html .= '<tr><td><strong>GRAND TOTAL</strong></td><td align="right"><strong>TZS ' . number_format($subtotal, 2) . '</strong></td></tr>';
        $html .= '</table>';
        return $html;
    }
}

if (!function_exists('buildTenderMaterialsPrintHtml')) {
    function buildTenderMaterialsPrintHtml(array $materials): string
    {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $html = '<h4>Materials Schedule</h4>';
        $html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
        $html .= '<tr style="background-color:#f0f0f0;"><th width="5%">#</th><th width="30%">Material</th><th width="20%">Specification</th><th width="10%">Unit</th><th width="10%">Qty</th><th width="10%">Rate</th><th width="15%">Amount</th></tr>';
        foreach ($materials as $i => $m) {
            $html .= '<tr><td>' . ($i + 1) . '</td><td>' . $esc($m['material']) . '</td><td>' . $esc($m['specification']) . '</td><td>' . $esc($m['unit']) . '</td>'
                . '<td align="right">' . number_format((float)$m['qty'], 3) . '</td><td align="right">' . number_format((float)$m['rate'], 2) . '</td>'
                . '<td align="right">' . number_format((float)$m['amount'], 2) . '</td></tr>';
        }
        $total = array_sum(array_column($materials, 'amount'));
        $html .= '<tr><td colspan="6" align="right"><strong>TOTAL</strong></td><td align="right"><strong>' . number_format($total, 2) . '</strong></td></tr>';
        $html .= '</table>';
        return $html;
    }
}

if (!function_exists('buildTenderChecklistPrintHtml')) {
    function buildTenderChecklistPrintHtml(array $items): string
    {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $ready = count(array_filter($items, fn($i) => (int)$i['is_ready'] === 1));
        $html = '<h4>Tender Compliance Checklist (' . $ready . ' / ' . count($items) . ' ready)</h4>';
        $html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
        $html .= '<tr style="background-color:#f0f0f0;"><th width="10%">Ready?</th><th width="90%">Requirement</th></tr>';
        foreach ($items as $item) {
            $mark = $item['is_ready'] ? '&#9745;' : '&#9744;';
            $html .= '<tr><td align="center">' . $mark . '</td><td>' . $esc($item['item_text']) . '</td></tr>';
        }
        $html .= '</table>';
        return $html;
    }
}
