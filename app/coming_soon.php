<?php
// app/coming_soon.php
require_once dirname(__DIR__) . '/roots.php';
$page_title = "Coming Soon";
include HEADER_FILE;
?>

<div class="container text-center py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm border-0 p-5">
                <div class="card-body">
                    <div class="mb-4">
                        <i class="bi bi-clock-history text-primary display-1"></i>
                    </div>
                    <h1 class="fw-bold mb-3">Feature Coming Soon</h1>
                    <p class="lead text-muted mb-4">
                        We are working hard to bring this feature to you. 
                        This page is part of our upcoming module update.
                    </p>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="dashboard" class="btn btn-primary px-4">
                            <i class="bi bi-speedometer2 me-2"></i>Back to Dashboard
                        </a>
                        <button onclick="window.history.back()" class="btn btn-outline-secondary px-4">
                            <i class="bi bi-arrow-left me-2"></i>Go Back
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="mt-5 text-muted small">
                <p>&copy; <?= date('Y') ?> Business Management System. All rights reserved.</p>
            </div>
        </div>
    </div>
</div>

<?php include FOOTER_FILE; ?>
