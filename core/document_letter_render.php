<?php
/**
 * core/document_letter_render.php — builds the TCPDF-compatible HTML for a
 * Create Document letter (app/constant/document/create_document.php).
 *
 * This is the ONE place that defines "what a finished letter looks like" for
 * the server-generated PDF — used by api/document/save_created_document.php
 * at every save. TCPDF's writeHTML() only reliably supports a subset of CSS
 * (tables and basic inline styles — no flexbox/grid), so this deliberately
 * builds a table-based layout rather than reusing create_document.php's
 * on-screen flexbox markup verbatim; the two are visually equivalent, not
 * byte-identical.
 *
 * All image paths passed in must already be resolved, absolute FILESYSTEM
 * paths (not URLs, not web-root-relative) — TCPDF embeds images by reading
 * the file directly.
 */

if (!function_exists('renderLetterHtml')) {
    /**
     * @param array $d {
     *   string   company_name
     *   ?string  company_logo_path   absolute filesystem path, or null
     *   string[] sender_lines        one line per filled-in company field
     *   string   document_code
     *   string   letter_date         already formatted, e.g. "18 Jul 2026"
     *   bool     use_letterhead
     *   string   recipient
     *   string   recipient_address
     *   string   subject
     *   string   body_html           already merge-token-resolved
     *   string   signature_align     left|center|right
     *   ?string  signature_image_path absolute filesystem path, or null
     *   bool     signature_is_preview true = stamp a "PREVIEW" watermark label
     *   string   signer_name
     *   string   signer_role
     *   string   footer_html         pre-rendered shared audit footer (see
     *                                includes/print_footer_html.php), inserted
     *                                as-is at the bottom of the last page.
     * }
     */
    function renderLetterHtml(array $d): string
    {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $html = '<style>
            body, td, div, p { font-family: helvetica, sans-serif; }
        </style>';

        if (!empty($d['use_letterhead'])) {
            $html .= '<div style="text-align:center; margin-bottom:6mm;">';
            if (!empty($d['company_logo_path']) && is_file($d['company_logo_path'])) {
                $html .= '<img src="' . $esc($d['company_logo_path']) . '" height="16mm"><br>';
            }
            $html .= '<span style="font-size:16pt; font-weight:bold; color:#0d6efd; letter-spacing:1px;">'
                . strtoupper($esc($d['company_name'])) . '</span>';
            $html .= '</div>';

            // Recipient (left) / sender + Ref + date (right) — a table is the
            // one two-column layout TCPDF's HTML parser renders reliably.
            $html .= '<table cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:6mm;"><tr>';
            $html .= '<td width="50%" style="font-size:10pt; vertical-align:top;">';
            if ($d['recipient'] !== '') {
                $html .= '<div style="font-size:11pt;">' . nl2br($esc($d['recipient'])) . '</div>';
            }
            if (!empty($d['recipient_address'])) {
                $html .= '<div style="font-size:10pt; color:#444;">' . nl2br($esc($d['recipient_address'])) . '</div>';
            }
            $html .= '</td>';
            $html .= '<td width="50%" style="font-size:10pt; text-align:right; vertical-align:top;">';
            foreach ($d['sender_lines'] as $line) {
                $html .= '<div>' . nl2br($esc($line)) . '</div>';
            }
            $html .= '<div style="font-weight:bold;">Ref: ' . $esc($d['document_code']) . '</div>';
            $html .= '<div>' . $esc($d['letter_date']) . '</div>';
            $html .= '</td>';
            $html .= '</tr></table>';

            $html .= '<div style="font-size:11pt; text-decoration:underline; margin-bottom:6mm;">'
                . '<strong>RE: ' . $esc($d['subject'] ?: '(Subject)') . '</strong></div>';
        }

        // Body — already merge-resolved rich-text HTML from the Summernote
        // editor (p/br/strong/em/ul/ol/li/table etc.), passed through as-is;
        // TCPDF's HTML parser handles this common subset directly.
        $html .= '<div style="font-size:11pt; line-height:1.6;">' . $d['body_html'] . '</div>';

        // Signature block — same three alignments as the live editor.
        $align = in_array($d['signature_align'] ?? 'left', ['left', 'center', 'right'], true)
            ? $d['signature_align'] : 'left';
        $cellAlign = ['left' => 'left', 'center' => 'center', 'right' => 'right'][$align];
        $html .= '<div style="margin-top:14mm; text-align:' . $cellAlign . ';">';
        if (!empty($d['signature_image_path']) && is_file($d['signature_image_path'])) {
            $html .= '<img src="' . $esc($d['signature_image_path']) . '" height="18mm"><br>';
            if (!empty($d['signature_is_preview'])) {
                $html .= '<span style="font-size:7pt; color:#c00; letter-spacing:1px;">PREVIEW &mdash; not a legally applied signature</span><br>';
            }
        }
        $html .= '<div style="margin-top:2mm;">' . $esc($d['signer_name'] ?: 'Signed by') . '</div>';
        if (!empty($d['signer_role'])) {
            $html .= '<div style="font-size:9pt; color:#555;">' . $esc($d['signer_role']) . '</div>';
        }
        $html .= '</div>';

        if (!empty($d['footer_html'])) {
            // print_footer_html.php's own CSS (print_footer_css.php) targets
            // browser @media print, which TCPDF's HTML parser doesn't apply —
            // restate the same small/muted styling inline so the audit line
            // still reads the same as every other BMS print page's footer.
            $html .= '<div style="margin-top:10mm; font-size:7pt; color:#2c3e50; text-align:center;">'
                . $d['footer_html'] . '</div>';
        }

        return $html;
    }
}
