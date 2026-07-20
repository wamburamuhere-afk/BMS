<?php
/**
 * core/document_letter_pdf.php — generates the real, server-rendered PDF for
 * a Create Document letter (app/constant/document/create_document.php).
 *
 * Used by api/document/save_created_document.php at every save. Kept as its
 * own require-able unit (rather than inline in the API endpoint) so it can
 * also be exercised standalone — e.g. from a CLI test — without pulling in
 * the endpoint's HTTP/session/CSRF handling.
 */

require_once __DIR__ . '/document_letter_render.php';
require_once ROOT_DIR . '/TCPDF/tcpdf.php';

if (!class_exists('BmsLetterPdf')) {
    /**
     * TCPDF's writeHTML() does not support CSS `position: fixed` — the shared
     * browser print footer (includes/print_footer_css.php) relies entirely on
     * that to pin itself to the page bottom, which is why embedding its HTML
     * as flowing body content (the old approach) left it wherever the letter
     * content happened to end, not at the true bottom of the page. This
     * subclass uses TCPDF's own per-page Footer() callback instead — the
     * correct, native way to pin content to the same position on every page,
     * matching how every browser-rendered print page achieves it via CSS.
     */
    class BmsLetterPdf extends TCPDF
    {
        public string $bmsFooterLine1 = '';
        public string $bmsFooterLine2 = '';

        public function Footer(): void
        {
            $this->SetY(-12);
            $this->SetFont('helvetica', '', 7);
            $this->SetTextColor(44, 62, 80);
            $this->Cell(0, 3, $this->bmsFooterLine1, 0, 1, 'C');
            $this->SetFont('helvetica', 'B', 7);
            $this->SetTextColor(52, 152, 219);
            $this->Cell(0, 3, $this->bmsFooterLine2, 0, 0, 'C');
        }
    }
}

if (!function_exists('generateLetterPdf')) {
    /**
     * Gathers the same letterhead data create_document.php's editor computes
     * (company settings, sender lines, the creator's on-file signature
     * preview), builds the letter HTML via renderLetterHtml(), and writes a
     * real PDF to $targetPath via TCPDF. Returns the file size in bytes.
     *
     * @param array $fields {
     *   string  document_code, letter_date, recipient, subject, content,
     *           signature_align
     *   bool    use_letterhead
     *   ?string custom_sender_info  this letter's own freely-written sender
     *           block (Summernote HTML); when non-empty it overrides the
     *           live-recomputed Company Profile sender_lines below
     *   bool    suppress_signature_box  true = skip the watermarked signature
     *           preview entirely — the caller is about to embed a REAL
     *           signature on top (create_document.php's one-click Save & Sign)
     *           and a "PREVIEW — NOT LEGALLY APPLIED" stamp must never end up
     *           on the same page as the real one
     * }
     */
    function generateLetterPdf(PDO $pdo, array $fields, string $targetPath): int
    {
        $company_name    = get_setting('company_name', 'Business Management System');
        $company_logo    = get_setting('company_logo');
        $company_address = get_setting('company_address', '');
        $company_phone   = get_setting('company_phone', '');
        $company_email   = get_setting('company_email', '');
        $company_tin     = get_setting('company_tin', '');
        $company_vrn     = get_setting('company_vrn', '');

        $sender_lines = [];
        if ($company_address !== '') { $sender_lines[] = $company_address; }
        if ($company_phone !== '')   { $sender_lines[] = 'Tel: ' . $company_phone; }
        if ($company_email !== '')   { $sender_lines[] = $company_email; }
        if ($company_tin !== '')     { $sender_lines[] = 'TIN: ' . $company_tin; }
        if ($company_vrn !== '')     { $sender_lines[] = 'VRN: ' . $company_vrn; }

        // Same query/intent as create_document.php's watermarked preview —
        // this is still never the legally-applied signature (that only
        // happens via select_document_add_esignature.php's own audited flow).
        $sig_stmt = $pdo->prepare("
            SELECT thumbnail_path, file_path FROM user_signatures
            WHERE user_id = ? AND status = 'active'
            ORDER BY created_at DESC LIMIT 1
        ");
        $sig_stmt->execute([$_SESSION['user_id'] ?? 0]);
        $my_signature = $sig_stmt->fetch(PDO::FETCH_ASSOC);
        $signature_rel_path = $my_signature ? ($my_signature['thumbnail_path'] ?: $my_signature['file_path']) : null;

        // Same defaulting logic as includes/print_footer_html.php, computed
        // directly (not captured via ob_start()) since the footer is now
        // rendered through TCPDF's native Footer() callback below, not
        // embedded as flowing HTML in the letter body.
        $printed_by   = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? 'System');
        $printed_role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'User';
        $printed_at   = date('d M, Y') . ' at ' . date('H:i:s');

        $letterHtml = renderLetterHtml([
            'company_name'         => $company_name,
            'company_logo_path'    => $company_logo ? (ROOT_DIR . '/' . ltrim($company_logo, '/')) : null,
            'sender_lines'         => $sender_lines,
            'document_code'        => $fields['document_code'],
            'letter_date'          => $fields['letter_date'] !== '' ? date('d M Y', strtotime($fields['letter_date'])) : date('d M Y'),
            'use_letterhead'       => $fields['use_letterhead'],
            'recipient'            => $fields['recipient'],
            'sender_html'          => $fields['custom_sender_info'] ?? null,
            'subject'              => $fields['subject'],
            'body_html'            => $fields['content'],
            'signature_align'      => $fields['signature_align'],
            'signature_image_path' => $signature_rel_path ? (ROOT_DIR . '/' . ltrim($signature_rel_path, '/')) : null,
            'signature_is_preview' => true,
            'suppress_signature_box' => !empty($fields['suppress_signature_box']),
            'signer_name'          => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
            'signer_role'          => $_SESSION['user_role'] ?? '',
        ]);

        // TCPDF's bundled config unconditionally re-defines
        // K_TCPDF_THROW_EXCEPTION_ERROR (and can emit other stray PHP
        // notices/warnings from its font subsystem); with this project's
        // global display_errors=1, any of those would be echoed straight
        // into the caller's JSON response and corrupt it. Genuine TCPDF
        // failures still surface — they're thrown as Exceptions, which this
        // handler doesn't intercept — only non-fatal diagnostics are
        // swallowed, and only for the duration of PDF generation.
        $prevErrorHandler = set_error_handler(function () { return true; });
        try {
            $pdf = new BmsLetterPdf('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('BMS');
            $pdf->SetAuthor($company_name);
            $pdf->SetTitle($fields['subject']);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(true);
            $pdf->bmsFooterLine1 = 'This document was Printed by ' . $printed_by . ' - ' . ucfirst($printed_role) . ' on ' . $printed_at;
            $pdf->bmsFooterLine2 = 'Powered By BJP Technologies (c) ' . date('Y') . ', All Rights Reserved';
            $pdf->setFooterMargin(10);
            // Canonical BMS print margins (i_e_print.md §1), same as every
            // other print page — 10/8/16/8mm top/right/bottom/left.
            $pdf->SetMargins(8, 10, 8);
            $pdf->SetAutoPageBreak(true, 16);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->AddPage();
            $pdf->writeHTML($letterHtml, true, false, true, false, '');
            $pdf->Output($targetPath, 'F');
        } finally {
            set_error_handler($prevErrorHandler);
        }

        if (!is_file($targetPath)) {
            throw new Exception('Failed to generate the document PDF');
        }

        return (int)filesize($targetPath);
    }
}
