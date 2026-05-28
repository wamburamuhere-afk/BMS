<style>
        /* ── PRINT FOOTER (shared — includes/print_footer_css.php) ── */
        .print-footer {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            height: 16px;
            background: #fff;
            border-top: 1px solid #dee2e6;
            padding: 0 22px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .print-footer p { margin: 0; font-size: 7px; color: #2c3e50; line-height: 1; }
        .print-footer .brand { font-size: 7px; color: #3498db; font-weight: 600; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .footer-spacer { height: 12px; }

        @media print {
            body { padding-bottom: 4mm !important; }
            .footer-spacer { display: none !important; }
        }
</style>