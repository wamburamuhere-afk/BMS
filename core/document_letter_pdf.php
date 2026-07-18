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

if (!function_exists('generateLetterPdf')) {
    /**
     * Gathers the same letterhead data create_document.php's editor computes
     * (company settings, sender lines, the creator's on-file signature
     * preview), builds the letter HTML via renderLetterHtml(), and writes a
     * real PDF to $targetPath via TCPDF. Returns the file size in bytes.
     *
     * @param array $fields {
     *   string document_code, letter_date, recipient, recipient_address,
     *          subject, content, signature_align
     *   bool   use_letterhead
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

        ob_start();
        $printed_by   = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? 'System');
        $printed_role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'User';
        require ROOT_DIR . '/includes/print_footer_html.php';
        $footer_html = ob_get_clean();

        $letterHtml = renderLetterHtml([
            'company_name'         => $company_name,
            'company_logo_path'    => $company_logo ? (ROOT_DIR . '/' . ltrim($company_logo, '/')) : null,
            'sender_lines'         => $sender_lines,
            'document_code'        => $fields['document_code'],
            'letter_date'          => $fields['letter_date'] !== '' ? date('d M Y', strtotime($fields['letter_date'])) : date('d M Y'),
            'use_letterhead'       => $fields['use_letterhead'],
            'recipient'            => $fields['recipient'],
            'recipient_address'    => $fields['recipient_address'],
            'subject'              => $fields['subject'],
            'body_html'            => $fields['content'],
            'signature_align'      => $fields['signature_align'],
            'signature_image_path' => $signature_rel_path ? (ROOT_DIR . '/' . ltrim($signature_rel_path, '/')) : null,
            'signature_is_preview' => true,
            'signer_name'          => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
            'signer_role'          => $_SESSION['user_role'] ?? '',
            'footer_html'          => $footer_html,
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
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('BMS');
            $pdf->SetAuthor($company_name);
            $pdf->SetTitle($fields['subject']);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
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
