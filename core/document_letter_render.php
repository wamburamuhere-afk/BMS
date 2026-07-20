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
     *   string[] sender_lines        fallback: one line per filled-in company
     *                                 field, used only when sender_html is empty
     *   ?string  sender_html         this letter's own freely-written sender
     *                                 block (Summernote HTML), takes priority
     *                                 over sender_lines when non-empty
     *   string   document_code
     *   string   letter_date         already formatted, e.g. "18 Jul 2026"
     *   bool     use_letterhead
     *   string   recipient           freely-written recipient block (Summernote
     *                                 HTML) — name, address, whatever the user
     *                                 typed, however they positioned it
     *   string   subject
     *   string   body_html           already merge-token-resolved
     *   string   signature_align     left|center|right
     *   ?string  signature_image_path absolute filesystem path, or null
     *   bool     signature_is_preview true = stamp a "PREVIEW" watermark label
     *   bool     suppress_signature_box true = skip the whole signature block
     *                                 (image, box, name, role) — used when a
     *                                 real signature is about to be embedded
     *                                 on top client-side (see create_document.php's
     *                                 one-click Save & Sign)
     *   string   signer_name
     *   string   signer_role
     * }
     *
     * Note: the shared audit footer ("Printed by ... / Powered By BJP
     * Technologies") is NOT part of this HTML — it's rendered separately by
     * BmsLetterPdf::Footer() (core/document_letter_pdf.php) via TCPDF's
     * native per-page footer callback, since TCPDF's writeHTML() doesn't
     * support the CSS `position:fixed` the browser-print version relies on.
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
            // Both recipient and sender are now freely-written, per-letter
            // rich-text (Summernote HTML) — passed through as-is, same trust
            // level as body_html below, so the user's own positioning/
            // alignment/formatting choices survive into the real PDF instead
            // of the PDF silently falling back to plain escaped lines.
            $html .= '<table cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:6mm;"><tr>';
            $html .= '<td width="50%" style="font-size:11pt; vertical-align:top;">';
            if (trim((string)$d['recipient']) !== '') {
                $html .= $d['recipient'];
            }
            $html .= '</td>';
            $html .= '<td width="50%" style="font-size:10pt; text-align:right; vertical-align:top;">';
            if (!empty(trim((string)($d['sender_html'] ?? '')))) {
                $html .= $d['sender_html'];
            } else {
                foreach ($d['sender_lines'] as $line) {
                    $html .= '<div>' . nl2br($esc($line)) . '</div>';
                }
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

        // Signature block — same three alignments as the live editor, and now
        // visually matched to the editor's already-approved design
        // (.letter-signature-box in create_document.php): a bordered box
        // around the signature image with the PREVIEW caption inside it, and
        // the signer's name sitting on a signature line. TCPDF's HTML parser
        // only reliably renders table-based layouts (same constraint the
        // recipient/sender table above already works around), so the box is
        // a bordered table cell rather than the editor's flexbox/absolute-
        // position CSS, which TCPDF can't reproduce.
        //
        // Skipped entirely when suppress_signature_box is set — used by the
        // one-click "Save & Sign" path (create_document.php), which fetches
        // THIS generated PDF and embeds the creator's REAL signature into it
        // client-side (pdf-lib) right afterwards. Without this flag every
        // signed letter would carry both TCPDF's own watermarked "PREVIEW —
        // NOT LEGALLY APPLIED" stamp AND the real signature on the same page.
        if (empty($d['suppress_signature_box'])) {
            $align = in_array($d['signature_align'] ?? 'left', ['left', 'center', 'right'], true)
                ? $d['signature_align'] : 'left';
            $cellAlign = ['left' => 'left', 'center' => 'center', 'right' => 'right'][$align];
            $boxMargin = ['left' => 'margin-right:auto;', 'center' => 'margin-left:auto; margin-right:auto;', 'right' => 'margin-left:auto;'][$align];

            $html .= '<div style="margin-top:14mm; text-align:' . $cellAlign . ';">';
            $html .= '<table cellpadding="4" cellspacing="0" style="' . $boxMargin . '"><tr>'
                . '<td style="border:1px dashed #adb5bd; background-color:#fbfbfb; width:65mm; height:24mm; text-align:center; vertical-align:middle;">';
            if (!empty($d['signature_image_path']) && is_file($d['signature_image_path'])) {
                $html .= '<img src="' . $esc($d['signature_image_path']) . '" height="15mm">';
                if (!empty($d['signature_is_preview'])) {
                    $html .= '<br><span style="font-size:6.5pt; font-weight:bold; letter-spacing:0.5px; color:#c00;">PREVIEW &mdash; NOT LEGALLY APPLIED</span>';
                }
            }
            $html .= '</td></tr></table>';
            $html .= '<div style="margin-top:2mm; border-top:1px solid #333333; padding-top:2mm; display:inline-block; min-width:55mm;">'
                . $esc($d['signer_name'] ?: 'Signed by') . '</div>';
            if (!empty($d['signer_role'])) {
                $html .= '<div style="font-size:9pt; color:#555555;">' . $esc($d['signer_role']) . '</div>';
            }
            $html .= '</div>';
        }

        return $html;
    }
}
