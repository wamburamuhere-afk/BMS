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
