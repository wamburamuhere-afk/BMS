<?php
// pages/login.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard');
    exit;
}

// Get company branding from settings
$company_logo = get_setting('company_logo', '');
$company_name = get_setting('company_name', 'Business Management System');

// ── Multi-tenancy: "New company? Register here" ────────────────────────────
// All staff — including tenant admins — sign in through this SAME page and get
// their credentials assigned by their own admin (Settings > Users). This link
// is for a DIFFERENT action entirely: creating a brand-new company/tenant. It
// must read unambiguously as that, never as "create a user account here" —
// hence the explicit "New company?" wording rather than a generic
// "Don't have an account?".
//
// The href cannot always be a bare relative 'register.php'. register.php
// deliberately 404s when visited from a TENANT's own subdomain (a company's
// own staff must not be able to spin up a rival tenant from inside their own
// login page) — it only renders on the platform's root/marketing domain. So
// when this login page is itself being viewed on a tenant's subdomain, the
// link has to point at the root domain explicitly, or it would 404 on click.
// Guarded by is_file()/function_exists() so this degrades to the old relative
// link if the multi-tenancy layer is ever reverted.
$registerUrl = 'register.php';
$tenantResolverFile = __DIR__ . '/core/tenant_resolver.php';
if (is_file($tenantResolverFile)) {
    require_once $tenantResolverFile;
    if (function_exists('resolveTenantFromRequest')) {
        $__r = resolveTenantFromRequest();
        if (($__r['status'] ?? '') === 'found') {
            $base = function_exists('tenantBaseDomain') ? tenantBaseDomain() : null;
            if ($base) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $registerUrl = $scheme . '://' . $base . '/register.php';
            }
        }
    }
}

// Build proper logo URL
if ($company_logo && strpos($company_logo, 'http') !== 0) {
    $company_logo = '/' . ltrim($company_logo, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Business Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --accent-color: #e74c3c;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
        }
        
        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            max-width: 450px;
            margin: 5% auto;
            padding: 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }
        
        .company-logo {
            width: 70px;
            height: 70px;
            object-fit: contain;
            margin-bottom: 0.5rem;
            border-radius: 8px;
        }

        .logo-placeholder {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.5rem;
            box-shadow: 0 4px 12px rgba(52,152,219,0.3);
        }

        .logo-placeholder i {
            font-size: 2rem;
            color: white;
        }

        .company-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark-text);
            margin-bottom: 0.2rem;
            letter-spacing: 0.3px;
        }
        
        .form-control {
            padding: 12px 15px;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }
        
        .btn-login {
            background-color: var(--primary-color);
            border: none;
            padding: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            background-color: var(--secondary-color);
            transform: translateY(-2px);
        }
        
        .input-group-text {
            background-color: #e9ecef;
            border-right: none;
        }
        
        .input-group .form-control {
            border-left: none;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 1.5rem 0;
        }
        
        .divider::before, .divider::after {
            content: "";
            flex: 1;
            border-bottom: 1px solid #dee2e6;
        }
        
        .divider-text {
            padding: 0 10px;
            color: #6c757d;
            font-size: 0.875rem;
        }
        
        .footer-links {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9rem;
        }
        
        .footer-links a {
            color: var(--dark-text);
            text-decoration: none;
            margin: 0 10px;
        }
        
        .footer-links a:hover {
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="logo-container">
                <?php if ($company_logo): ?>
                    <img src="<?= htmlspecialchars($company_logo) ?>" alt="<?= htmlspecialchars($company_name) ?>" class="company-logo">
                <?php else: ?>
                    <div class="logo-placeholder">
                        <i class="fas fa-building"></i>
                    </div>
                <?php endif; ?>
                <h5 class="company-name"><?= htmlspecialchars($company_name) ?></h5>
                <p class="text-muted mb-0" style="font-size: 0.85rem;">Please sign in to continue</p>
            </div>

            <form id="loginForm">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" id="username" name="username" placeholder="Enter your username" required>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="rememberMe">
                    <label class="form-check-label" for="rememberMe">Remember me</label>
                    <a href="forgot-password.php" class="float-end">Forgot password?</a>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 btn-login">Login</button>

                <div class="divider">
                    <span class="divider-text">OR</span>
                </div>

                <!--
                  Staff of an EXISTING company never use this — they sign in
                  above with credentials their own admin assigned under
                  Settings > Users. This is for creating a BRAND-NEW company,
                  which is why the wording says "New company?" rather than
                  "Don't have an account?" — the two must never read as the
                  same action. $registerUrl is computed above: a bare relative
                  link on the root domain / single-tenant mode, or an absolute
                  link to the root domain when this page is being viewed on a
                  tenant's own subdomain (register.php 404s there by design).
                -->
                <p class="text-center mb-0">New company?
                    <a href="<?= safe_output($registerUrl, 'register.php') ?>" style="color: var(--primary-color);">Register here</a>
                </p>
            </form>
            
            <div class="footer-links">
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
                <a href="#">Help Center</a>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Same SweetAlert2 defaults used across the rest of the system —
        // green confirm button everywhere, including error alerts.
        const originalSwalFire = Swal.fire.bind(Swal);
        Swal.fire = function(...args) {
            if (args.length === 1 && typeof args[0] === 'object') {
                const options = { ...args[0] };
                if (!options.confirmButtonColor) options.confirmButtonColor = '#28a745';
                if (!options.confirmButtonText) options.confirmButtonText = 'OK';
                return originalSwalFire(options);
            }
            if (typeof args[0] === 'string') {
                const options = { title: args[0] };
                if (args[1]) options.text = args[1];
                if (args[2]) options.icon = args[2];
                options.confirmButtonColor = '#28a745';
                options.confirmButtonText = 'OK';
                return originalSwalFire(options);
            }
            return originalSwalFire(...args);
        };

        $(document).ready(function() {
            // Toggle password visibility
            $('#togglePassword').click(function() {
                const password = $('#password');
                const type = password.attr('type') === 'password' ? 'text' : 'password';
                password.attr('type', type);
                $(this).find('i').toggleClass('fa-eye fa-eye-slash');
            });
            
            $('#loginForm').on('submit', function(e) {
                e.preventDefault();
                $('.btn-login').html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Logging in...');
                $('.btn-login').prop('disabled', true);
                
                $.ajax({
                    url: 'actions/login.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            window.location.href = 'dashboard';
                        } else {
                            Swal.fire('Login Failed', response.message || 'Invalid username or password.', 'error');
                            $('.btn-login').html('Login');
                            $('.btn-login').prop('disabled', false);
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Unable to connect to the server. Please try again.', 'error');
                        $('.btn-login').html('Login');
                        $('.btn-login').prop('disabled', false);
                    }
                });
            });
        });
    </script>
</body>
</html>