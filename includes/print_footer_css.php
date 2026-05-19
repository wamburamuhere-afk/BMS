<style>
        /* ── PRINT FOOTER (shared — includes/print_footer_css.php) ── */
        .print-footer {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #fff;
            border-top: 1px solid #dee2e6;
            padding: 1px 22px;
            text-align: center;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .print-footer p { margin: 0; font-size: 7px; color: #2c3e50; line-height: 1.2; }
        .print-footer .brand { font-size: 7px; color: #3498db; font-weight: 600; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .footer-spacer { height: 25px; }

        @media print {
            /* Body padding-bottom = footer height (~18px / 5mm) + small gap. */
            body { padding-bottom: 7mm !important; }
            .footer-spacer { display: none !important; }
        }
</style>
