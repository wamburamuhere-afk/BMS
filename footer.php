<?php
echo '<footer>';
// Close the container and add footer
echo '</div>'; // Close container

echo '<footer class="text-center py-3 text-muted d-print-none">';
echo '<p>© '.date('Y').' Business Management System. All Rights Reserved.</p>';
echo '</footer>';

// ── Global Print Footer ──────────────────────────────────────────
// Hidden on screen (via responsive.css .bms-print-footer { display:none })
// Fixed at the bottom of every printed page via @media print rules.
$_print_username = htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$_print_role     = htmlspecialchars($_SESSION['user_role'] ?? 'User');
$_print_date     = date('d M Y \a\t h:i:s A');
$_print_year     = date('Y');

echo '
<div class="bms-print-footer">
    <span class="bpf-line1">
        This document was <strong>Printed </strong> by <strong>' . $_print_username . ' - ' . $_print_role . '</strong> on ' . $_print_date . '
    </span>
    <span class="bpf-line2">
        Powered by BJP Technologies &copy; ' . $_print_year . ', All Rights Reserved.
    </span>
</div>';





?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Global fix: keep Select2 (and other plugin) dropdowns from being closed by the
     Bootstrap modal focus-trap. Sets focus:false as the DEFAULT for every modal —
     present and future, however created — so click-and-search dropdowns stay open.
     No per-modal markup needed. This is the system-wide standard going forward. -->
<script>
    try {
        if (window.bootstrap && bootstrap.Modal && bootstrap.Modal.Default) {
            bootstrap.Modal.Default.focus = false;
        }
    } catch (e) { /* no-op */ }
    // Belt-and-suspenders: also stamp the attribute on any modals already in the DOM.
    document.querySelectorAll('.modal').forEach(function (m) {
        if (!m.hasAttribute('data-bs-focus')) m.setAttribute('data-bs-focus', 'false');
    });
</script>

<!-- Global Modal Close on Success -->
<script>
$(document).ajaxSuccess(function(event, xhr, settings, data) {
    // If the response indicates success, try to close any open modals
    // EXCLUSIONS:
    // 1. Only close modals if the request was NOT a GET request
    // 2. Do NOT close for activity logging or heartbeat requests
    const isLogRequest = settings.url.includes('log_activity') || settings.url.includes('log_audit');

    if (data && data.success === true && settings.type !== 'GET' && !isLogRequest) {
        const openModal = document.querySelector('.modal.show');
        // Opt-out for modals with their own multi-step flow (e.g. "Generate with
        // AI": generate -> review suggestion -> Use this), where a success:true
        // response is only an intermediate step, not "the record was saved,
        // close the dialog" — this global rule would otherwise slam the modal
        // shut before the user ever sees the result.
        if (openModal && openModal.getAttribute('data-no-autoclose') !== 'true') {
            const modalInstance = bootstrap.Modal.getInstance(openModal);
            if (modalInstance) {
                modalInstance.hide();
            }
        }
    }
});
</script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>