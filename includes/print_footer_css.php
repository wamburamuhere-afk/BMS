<style>
        /* ── PRINT FOOTER (shared — includes/print_footer_css.php) ── */
        .print-footer {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #fff;
            border-top: 1px solid #dee2e6;
            padding: 3px 22px;
            text-align: center;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .print-footer p { margin: 0; font-size: 12px; color: #2c3e50; line-height: 1.2; }
        .print-footer .brand { font-size: 12px; color: #3498db; font-weight: 600; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .footer-spacer { height: 50px; }

        @media print {
            /* Body padding-bottom = footer height (~36px / 9.5mm) + 0.2cm gap.
               Forces content to page-break before reaching the footer on every page. */
            body { padding-bottom: 14mm !important; }
            .footer-spacer { display: none !important; }
        }
</style>
