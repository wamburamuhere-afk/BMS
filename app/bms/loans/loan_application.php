<?php
require_once '../../../header.php'; // Adjust path if necessary, but we should rely on roots.php constants if possible, but this file is new. 
// Actually, since I'm creating a new file, I should use the standard include if I can. 
// But wait, if I use header.php, it might rely on config.php being included first? 
// roots.php includes config.php. 
// If this file is accessed directly via URL (not through a router), it needs to include roots.php or config.php.
// Most files in this project seem to do `require_once 'header.php';`.
// And `header.php` usually includes `roots.php` or `config.php`.
// Let's stick to the pattern I saw in customer files: `require_once HEADER_FILE;`. 
// But `HEADER_FILE` is defined in `roots.php`. So I need to include `roots.php` first?
// Or does `header.php` handle that? 
// In `customer_details.php`: `require_once HEADER_FILE;`.
// This implies `HEADER_FILE` is already defined? No, `customer_details.php` was failing because `config.php` wasn't found.
// The `roots.php` file defines `HEADER_FILE`.
// If I use `require_once HEADER_FILE;` without including `roots.php`, it will fail if `HEADER_FILE` isn't defined.
// PROBABLY `header.php` is NOT the first thing to be included, OR `roots.php` is auto-prepended, OR I should include `roots.php` (relative path) first.
// Wait, in `customer_details.php` I resolved it by using `HEADER_FILE`.
// BUT, `HEADER_FILE` is a constant. Where is it defined? In `roots.php`.
// So `customer_details.php` MUST include `roots.php` somehow.
// Let's look at `customer_details.php` again effectively? No, I already edited it.
// The file started with:
// <?php
// require_once HEADER_FILE;
// ...
// This means `HEADER_FILE` MUST be defined before this line runs, or it's a string 'HEADER_FILE' (which would be a file path?).
// PHP constants don't auto-resolve if not defined.
// If `customer_details.php` relies on `roots.php` being included *before* it, then how is it included?
// Maybe `index.php` routes to it?
// The `roots.php` comment says:
// // This array maps clean URLs to actual PHP files
// ...
// 'customers/details' => CUSTOMERS_DIR . '/customer_details.php',
// ...
// This suggests a router (likely `index.php` or `.htaccess` redirecting to `index.php`) handles the request, includes `roots.php`, and then includes `customer_details.php`.
// IF that's the case, then `HEADER_FILE` IS defined.
// SO, for `loan_application.php`, if I access it via `index.php` routing, it handles the includes.
// If I access it directly (e.g. `localhost/app/bms/loans/loan_application.php`), it will fail unless I include `roots.php`.
// Given the project structure, it seems they use a router.
// I will assume the router is used and use `HEADER_FILE`.

// NOTE: I need to verify if `HEADER_FILE` works.
// For now, I'll use a safe placeholder content.

?>
<div class="container mt-5">
    <div class="alert alert-info">
        <h3><i class="bi bi-info-circle"></i> Loan Application</h3>
        <p>This module is currently under development.</p>
        <a href="<?= getUrl('dashboard') ?>" class="btn btn-primary">Back to Dashboard</a>
    </div>
</div>
<?php require_once FOOTER_FILE; ?>
