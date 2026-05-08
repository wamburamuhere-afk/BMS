<?php
/**
 * unauthorized.php
 * Professional "Access Denied" page shown when a user lacks permission
 */
ob_start();
require_once __DIR__ . '/roots.php';

// Make sure the user is logged in — if not, send to login
if (!isAuthenticated()) {
    redirectTo('login');
}

// Get role safely
$role_display = $_SESSION['role_name'] ?? ($_SESSION['role'] ?? 'Standard User');

// Include the app header
includeHeader();
?>

<style>
/* ==============================
   Access Denied Page — Premium Refined Design
   ============================== */
:root {
    --core-navy: #1e293b;
    --core-blue: #0d6efd;
    --core-blue-hover: #0a58ca;
    --core-error: #ef4444;
    --core-slate: #64748b;
    --core-bg-light: #f1f5f9;
}

.access-denied-wrapper {
    min-height: 80vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
    background: #f8fafc;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

.access-denied-card {
    max-width: 500px;
    width: 100%;
    background: #ffffff;
    border-radius: 24px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    animation: fadeInUp 0.5s ease-out;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

.access-header-section {
    background: linear-gradient(to bottom, #f0f7ff, #ffffff);
    padding: 3.5rem 2rem 1.5rem;
    text-align: center;
    border-bottom: 1px solid #f1f5f9;
}

.notice-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: #fff;
    border: 1px solid #e2e8f0;
    padding: 4px 12px;
    border-radius: 20px;
    color: var(--core-error);
    font-weight: 700;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
}

.icon-container {
    width: 80px;
    height: 80px;
    background: #fff;
    color: var(--core-error);
    border-radius: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 2.2rem;
    box-shadow: 0 10px 20px -5px rgba(239, 68, 68, 0.2);
    border: 1px solid #fee2e2;
}

.access-header-section h1 {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--core-navy);
    margin-bottom: 0;
    letter-spacing: -0.02em;
}

.access-body-section {
    padding: 2.5rem 2.5rem 3.5rem;
    text-align: center;
}

.message-detail {
    color: var(--core-slate);
    font-size: 1.05rem;
    line-height: 1.6;
    margin-bottom: 2.5rem;
}

.message-detail b {
    color: var(--core-navy);
}

.btn-blue-premium {
    padding: 1rem 2.5rem;
    border-radius: 16px;
    font-weight: 700;
    font-size: 1rem;
    text-decoration: none !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.8rem;
    background: var(--core-blue);
    color: #fff !important;
    box-shadow: 0 10px 25px -5px rgba(13, 110, 253, 0.4);
    border: none;
    width: 100%;
}

.btn-blue-premium:hover {
    background: var(--core-blue-hover);
    transform: translateY(-3px);
    box-shadow: 0 20px 30px -10px rgba(13, 110, 253, 0.5);
}

.btn-blue-premium i {
    font-size: 1.2rem;
}

.footer-branding {
    margin-top: 3rem;
    font-size: 0.8rem;
    font-weight: 700;
    color: #cbd5e1;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}
</style>

<div class="access-denied-wrapper">
    <div class="access-denied-card">
        <div class="access-header-section">
            
            <div class="icon-container">
                <i class="bi bi-shield-lock-fill"></i>
            </div>
            <h1>Access Restricted</h1>
        </div>

        <div class="access-body-section">
            <p class="message-detail">
                Your current account permissions (<b><?= htmlspecialchars($role_display) ?></b>) 
                do not allow access to this page. 
            </p>

            <div>
                <a href="<?= getUrl('dashboard') ?>" class="btn-blue-premium">
                    <i class="bi bi-house-door-fill"></i> BACK TO DASHBOARD
                </a>
            </div>

            <div class="footer-branding">
                BUSINESS MANAGEMENT SYSTEM
            </div>
        </div>
    </div>
</div>

<?php
includeFooter();
ob_end_flush();
?>

