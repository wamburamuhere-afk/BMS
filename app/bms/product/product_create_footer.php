<?php
// product_create_footer.php
?>
<script>
$(document).ready(function() {
    const isEdit = typeof IS_EDIT !== 'undefined' && IS_EDIT;
    if (isEdit) {
        logReportAction('Viewed Edit Product Page', 'User opened the edit page for product ID: ' + PRODUCT_ID);
    } else {
        logReportAction('Viewed Create Product Page', 'User opened the create new product page');
    }

    // Form submission
    $('#productForm').on('submit', function(e) {
        e.preventDefault();
        createProduct('active');
    });
    
    // Calculate initial markup
    calculateMarkup();
    calculateMinSellingPrice();
    updateUnitLabels();
    
    // Auto-focus on product name
    $('#product_name').focus();

    // Image upload trigger
    $('#imagePreview').click(function() {
        $('#product_image').click();
    });
});

function generateNewSKU() {
    logReportAction('Generated New SKU', 'User generated a random SKU for a new product');
    const timestamp = Math.floor(Date.now() / 1000);
    const random = Math.floor(Math.random() * 900) + 100;
    $('#sku').val('PROD' + timestamp + random).addClass('is-valid');
    setTimeout(() => $('#sku').removeClass('is-valid'), 2000);
}

function generateNewBarcode() {
    logReportAction('Generated New Barcode', 'User initiated barcode generation/scanner');
    $('#barcodeScannerModal').modal('show');
}

function startBarcodeScanner() {
    $('#scannerContainer').html(`
        <div class="text-center">
            <div class="spinner-grow text-primary" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading scanner...</span>
            </div>
            <p class="mt-3 fw-bold">Initializing Camera...</p>
            <small class="text-muted">Searching for barcode source...</small>
        </div>
    `);
    
    setTimeout(() => {
        const barcode = '69' + Math.floor(Math.random() * 100000000000);
        $('#barcode').val(barcode).addClass('is-valid');
        $('#barcodeScannerModal').modal('hide');
        setTimeout(() => $('#barcode').removeClass('is-valid'), 2000);
        
        Swal.fire({
            icon: 'success',
            title: 'Scan Successful',
            text: 'Barcode: ' + barcode,
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    }, 1500);
}

function useManualBarcode() {
    const manualBarcode = $('#manualBarcodeInput').val().trim();
    if (manualBarcode) {
        $('#barcode').val(manualBarcode).addClass('is-valid');
        $('#barcodeScannerModal').modal('hide');
        $('#manualBarcodeInput').val('');
        setTimeout(() => $('#barcode').removeClass('is-valid'), 2000);
    }
}

function previewImage(event) {
    const input = event.target;
    const preview = document.getElementById('imagePreview');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        if (file.size > 2 * 1024 * 1024) {
            Swal.fire({ icon: 'error', title: 'File Too Large', text: 'Maximum file size is 2MB.' });
            input.value = '';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `
                <div class="position-relative w-100 h-100 d-flex align-items-center justify-content-center">
                    <img src="${e.target.result}" class="img-fluid rounded shadow-sm" style="max-height: 100%; object-fit: contain;">
                    <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-2 rounded-circle" onclick="removeImage(event)">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            `;
            $(preview).removeClass('bg-light opacity-50').addClass('bg-white');
        };
        reader.readAsDataURL(file);
    }
}

function removeImage(event) {
    if (event) event.stopPropagation();
    $('#product_image').val('');
    $('#imagePreview').html(`
        <div class="text-center opacity-50">
            <i class="bi bi-image-fill display-3"></i>
            <p class="small mt-2">Drophere or Click to Upload</p>
        </div>
    `).addClass('bg-light opacity-50').removeClass('bg-white');
}

function calculateMarkup() {
    const cost = parseFloat($('#cost_price').val()) || 0;
    const selling = parseFloat($('#selling_price').val()) || 0;
    
    let markupPercentage = 0;
    let profit = 0;
    
    if (cost > 0) {
        markupPercentage = ((selling - cost) / cost) * 100;
        profit = selling - cost;
    }
    
    $('#markup_percentage').val(markupPercentage.toFixed(2));
    $('#profit_margin').val(profit.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    
    const markupInput = $('#markup_percentage');
    const profitInput = $('#profit_margin');
    
    if (markupPercentage >= 30) {
        markupInput.parent().parent().removeClass('border-danger border-warning text-danger text-warning').addClass('text-success');
    } else if (markupPercentage >= 10) {
        markupInput.parent().parent().removeClass('border-danger text-danger text-success').addClass('text-warning');
    } else {
        markupInput.parent().parent().removeClass('border-warning text-warning text-success').addClass('text-danger');
    }
}

function calculateMinSellingPrice() {
    const sellingPrice = parseFloat($('#selling_price').val()) || 0;
    const discountRate = parseFloat($('#discount_rate').val()) || 0;
    
    const minPrice = sellingPrice - (sellingPrice * discountRate / 100);
    
    $('#min_selling_price').val(minPrice.toFixed(2));
    $('#min_selling_price_display').text(minPrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
}

function updateUnitLabels() {
    const unitText = ($('#unit').val() || 'pcs').toLowerCase();
    $('.unit-label').text(unitText);
    $('.modal-unit-label').text(unitText);
}

function showQuickAddUnit() {
    const unitCode = $('#unit').val().trim();
    if (!unitCode) {
        Swal.fire({ icon: 'warning', title: 'Empty Unit', text: 'Please enter a unit code first.' });
        return;
    }
    
    Swal.fire({
        title: 'Add New Unit',
        text: `Do you want to add "${unitCode}" to the global units list?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, add it',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            return $.post('<?= getUrl("/api/save_unit.php") ?>', {
                unit_code: unitCode,
                unit_name: unitCode.charAt(0).toUpperCase() + unitCode.slice(1),
                status: 'active'
            }).then(res => {
                if (typeof res === 'string') res = JSON.parse(res);
                if (!res.success) throw new Error(res.message);
                return res;
            }).catch(error => {
                Swal.showValidationMessage(`Request failed: ${error}`);
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            // Add to datalist if not already there
            const datalist = $('#unit_list');
            if (datalist.find(`option[value="${unitCode}"]`).length === 0) {
                datalist.append(`<option value="${unitCode}">${unitCode.charAt(0).toUpperCase() + unitCode.slice(1)}</option>`);
            }
            logReportAction('Quick Added Unit', 'User quick-added measurement unit: ' + unitCode);
            Swal.fire({ 
                icon: 'success', 
                title: 'Unit Added!', 
                confirmButtonColor: '#28a745',
                confirmButtonText: 'OK'
            });
        }
    });
}

function updateDimensions() {
    const length = $('#dim_length').val() || '0';
    const width = $('#dim_width').val() || '0';
    const height = $('#dim_height').val() || '0';
    $('#dimensions').val(`${length}×${width}×${height} cm`);
}

function showQuickCategoryModal() { $('#quickCategoryModal').modal('show'); }
function showQuickBrandModal() { $('#quickBrandModal').modal('show'); }

function saveQuickCategory() {
    const name = $('#quickCategoryName').val().trim();
    if (!name) return;
    
    $.post('<?= getUrl("/api/create_category.php") ?>', {
        category_name: name,
        parent_id: $('#quickCategoryParent').val(),
        type: 'product'
    }, function(res) {
        if (res.success) {
            logReportAction('Quick Added Category', 'User quick-added category: ' + name);
            $('#category_id').append(new Option(name, res.category_id, true, true));
            $('#quickCategoryModal').modal('hide');
            $('#quickCategoryName').val('');
        }
    }, 'json');
}

function saveQuickBrand() {
    const name = $('#quickBrandName').val().trim();
    if (!name) return;
    
    $.post('<?= getUrl("/api/save_brand.php") ?>', {
        brand_name: name,
        website: $('#quickBrandWebsite').val(),
        status: 'active'
    }, function(res) {
        if (res.success) {
            logReportAction('Quick Added Brand', 'User quick-added brand: ' + name);
            $('#brand_id').append(new Option(name, res.brand_id, true, true));
            $('#quickBrandModal').modal('hide');
            $('#quickBrandName').val('');
        }
    }, 'json');
}

function validateForm() {
    let valid = true;
    const required = [
        { id: 'product_name', tab: 'general' },
        { id: 'cost_price', tab: 'pricing' },
        { id: 'selling_price', tab: 'pricing' },
        { id: 'unit', tab: 'inventory' }
    ];
    
    for (const field of required) {
        const el = $(`#${field.id}`);
        if (!el.val() || el.val() == 0) {
            $(`#${field.tab}-tab`).tab('show');
            el.addClass('is-invalid').focus();
            valid = false;
            break;
        } else {
            el.removeClass('is-invalid');
        }
    }
    return valid;
}

function createProduct(status = 'active') {
    if (!validateForm()) return;
    
    const submitBtn = $('button[type="submit"]');
    const originalText = submitBtn.html();
    const isEdit = typeof IS_EDIT !== 'undefined' && IS_EDIT;
    
    submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + (isEdit ? 'Updating...' : 'Saving...'));
    
    const formData = new FormData($('#productForm')[0]);
    formData.append('status', status);
    
    if (isEdit) {
        formData.append('product_id', PRODUCT_ID);
    }
    
    // Initial Stock data (only for create)
    if (!isEdit) {
        const initialStock = {};
        $('[name^="initial_stock"]').each(function() {
            const idMatch = $(this).attr('name').match(/\[(\d+)\]/);
            if (idMatch) {
                const val = parseFloat($(this).val()) || 0;
                if (val > 0) initialStock[idMatch[1]] = val;
            }
        });
        if (Object.keys(initialStock).length > 0) {
            formData.append('initial_stock_data', JSON.stringify(initialStock));
        }
    }
    
    $.ajax({
        url: isEdit ? '<?= getUrl("/api/update_product.php") ?>' : '<?= getUrl("/api/create_product.php") ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                if (isEdit) {
                    logReportAction('Updated Product', 'User successfully updated product: ' + $('#product_name').val() + ' (ID: ' + PRODUCT_ID + ')');
                } else {
                    logReportAction('Created Product', 'User successfully created new product: ' + $('#product_name').val());
                }
                Swal.fire({ 
                    icon: 'success', 
                    title: isEdit ? 'Product Updated!' : 'Product Created!', 
                    text: res.message, 
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'OK'
                }).then(() => window.location.href = 'products');
            } else {
                Swal.fire({ icon: 'error', title: isEdit ? 'Update Failed' : 'Creation Failed', text: res.message });
                submitBtn.prop('disabled', false).html(originalText);
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'System Error', text: 'Communication with server failed.' });
            submitBtn.prop('disabled', false).html(originalText);
        }
    });
}

function saveAsDraft() { 
    logReportAction('Saving Product as Draft', 'User initiated saving product as draft (inactive status)');
    createProduct('inactive'); 
}
</script>

<style>
/* Premium UI Overrides */
:root {
    --primary: #0d6efd;
    --soft-primary: #e7f1ff;
    --border-color: #f1f3f5;
}

.custom-tabs .nav-link {
    color: #495057;
    font-weight: 600;
    transition: all 0.3s ease;
    border-bottom: 3px solid transparent;
}

.custom-tabs .nav-link:hover {
    background-color: #f8f9fa;
    color: var(--primary);
}

.custom-tabs .nav-link.active {
    background-color: white !important;
    color: var(--primary) !important;
    border-bottom: 3px solid var(--primary) !important;
    box-shadow: 0 4px 10px rgba(13, 110, 253, 0.05);
}

.shadow-inner {
    box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.06);
}

.bg-soft-primary {
    background-color: var(--soft-primary);
}

.bg-light-primary {
    background-color: #eff6ff;
    color: #2563eb;
}

.bg-light-primary:hover {
    background-color: #dbeafe;
}

.form-control:focus, .form-select:focus {
    background-color: #fff !important;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.1);
    border-color: rgba(13, 110, 253, 0.5) !important;
}

.rounded-4 { border-radius: 1rem !important; }

/* Custom Radio Styling */
.custom-radio .form-check-input:checked {
    background-color: var(--primary);
    border-color: var(--primary);
}

.custom-radio .form-check-label {
    cursor: pointer;
}

/* Animations */
.tab-pane {
    animation: fadeIn 0.4s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

#imagePreview {
    cursor: pointer;
    transition: all 0.3s ease;
}

#imagePreview:hover {
    border-color: var(--primary) !important;
    transform: scale(1.02);
}

.uppercase { text-transform: uppercase; letter-spacing: 0.5px; }
.extra-small { font-size: 0.75rem; }

/* Pricing Visuals */
.price-updated {
    animation: pulseHighlight 1s ease;
}

@keyframes pulseHighlight {
    0% { background-color: rgba(25, 135, 84, 0.1); }
    100% { background-color: transparent; }
}

.input-group-text {
    border: none;
    background: transparent;
    color: #6c757d;
}
</style>

<?php
// Include the main footer
include("footer.php");
ob_end_flush();
?>