<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/payment_accounts.php';

// Phase 2 of security_implementation_plan.md — page-level gate. Admin
// can now grant 'payment_settings' to other roles via /user_roles.php.
autoEnforcePermission('payment_settings');

require_once __DIR__ . '/../../../header.php';

// Check admin permissions
if (!isAdmin()) {
    header("Location: unauthorized.php");
    exit();
}

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['_csrf'] ?? ''))) {
            throw new Exception(t('Your session expired. Refresh the page and try again.'));
        }

        $bankRows   = paymentAccountsSanitize(json_decode((string)($_POST['bank_accounts_json'] ?? '[]'), true), 'bank');
        $mobileRows = paymentAccountsSanitize(json_decode((string)($_POST['mobile_accounts_json'] ?? '[]'), true), 'mobile');
        $mode       = ($_POST['payment_name_mode'] ?? '') === 'each' ? 'each' : 'shared';

        save_setting('payment_bank_accounts', json_encode($bankRows, JSON_UNESCAPED_UNICODE));
        save_setting('payment_mobile_accounts', json_encode($mobileRows, JSON_UNESCAPED_UNICODE));
        save_setting('payment_name_mode', $mode);
        save_setting('account_name', mb_substr(trim((string)($_POST['account_name'] ?? '')), 0, 150));
        save_setting('check_payable_to', mb_substr(trim((string)($_POST['check_payable_to'] ?? '')), 0, 150));
        $terms = (string)($_POST['default_payment_terms'] ?? 'Net 30');
        if (in_array($terms, ['Due on Receipt', 'Net 15', 'Net 30', 'Net 60'], true)) {
            save_setting('default_payment_terms', $terms);
        }

        // Keep the legacy single-account keys in step with the first account
        // for any older screen that still reads them.
        $b0 = $bankRows[0] ?? null;
        $m0 = $mobileRows[0] ?? null;
        save_setting('bank_name', $b0['label'] ?? '');
        save_setting('account_number', $b0['number'] ?? '');
        save_setting('swift_code', $b0['swift'] ?? '');
        save_setting('mpesa_paybill', $m0['number'] ?? '');
        save_setting('mpesa_account_no', $m0['ref'] ?? '');

        logActivity($pdo, $_SESSION['user_id'], 'Updated payment settings: ' . count($bankRows) . ' bank account(s), ' . count($mobileRows) . ' mobile money account(s)');
        $success_msg = t('Payment settings updated successfully!');
    } catch (Exception $e) {
        $error_msg = t('Error updating settings:') . ' ' . $e->getMessage();
    }
}

$pa    = getPaymentAccounts($pdo);
$terms = get_setting('default_payment_terms', 'Net 30');
if (!empty($success_msg)) {
    $terms = $_POST['default_payment_terms'] ?? $terms;
}
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>

<style>
    .pa-card { border: 1px solid #e3e7ed; border-radius: 10px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px; background: #fff; }
    .pa-card + .pa-card { margin-top: 8px; }
    .pa-card .pa-body { min-width: 0; }
    .pa-card .pa-title { font-weight: 600; overflow-wrap: anywhere; }
    .pa-card .pa-num { font-family: ui-monospace, Consolas, monospace; font-size: .95rem; overflow-wrap: anywhere; }
    .pa-card .pa-meta { font-size: .8rem; color: #6c757d; overflow-wrap: anywhere; }
    .pa-card.pa-hidden { opacity: .55; }
    .pa-card .pa-actions { display: flex; gap: 2px; flex-shrink: 0; }
    .pa-empty { border: 1px dashed #ced4da; border-radius: 10px; padding: 14px; text-align: center; color: #6c757d; font-size: .9rem; }
    .pa-mode { border: 1px solid #e3e7ed; border-radius: 10px; padding: 10px 12px; cursor: pointer; display: flex; gap: 10px; align-items: flex-start; height: 100%; }
    .pa-mode:has(input:checked) { border-color: #0d6efd; background: #f1f6ff; }
    .pa-mode input { margin-top: 4px; }
    .pa-preview { background: #f4f6f8; border-left: 4px solid #3498db; border-radius: 6px; padding: 12px 14px; font-size: 12px; color: #1a252f; }
    .pa-preview h3 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding-bottom: 6px; margin-bottom: 8px; border-bottom: 1.5px solid #3498db; }
    .pa-preview p { margin: 2px 0; }
    .pa-preview strong { display: block; font-weight: 700; margin-top: 6px; }
    .pa-preview .bank-section + .bank-section { border-top: 1px solid #e4e8ec; margin-top: 6px; padding-top: 2px; }
    [data-bs-theme="dark"] .pa-card { background: var(--bs-body-bg); border-color: var(--bs-border-color); }
    [data-bs-theme="dark"] .pa-mode:has(input:checked) { background: rgba(13,110,253,.15); }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-0"><i class="bi bi-wallet2"></i> <?= t('Payment Settings') ?></h2>
                    <p class="text-muted"><?= t('Configure payment methods and banking details for invoices') ?></p>
                </div>
            </div>

            <?php if ($success_msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <form method="POST" id="paymentSettingsForm">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="bank_accounts_json" id="bank_accounts_json">
                <input type="hidden" name="mobile_accounts_json" id="mobile_accounts_json">

                <!-- Registered account name -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white p-4 border-0">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-person-badge me-2 text-primary"></i><?= t('Registered Account Name') ?></h5>
                    </div>
                    <div class="card-body p-4 pt-0">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="pa-mode">
                                    <input type="radio" name="payment_name_mode" value="shared" id="mode_shared" <?= $pa['mode'] === 'shared' ? 'checked' : '' ?>>
                                    <span><strong><?= t('Same name for all accounts') ?></strong><br>
                                    <small class="text-muted"><?= t('Type the name once. It appears on every bank and mobile money account.') ?></small></span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="pa-mode">
                                    <input type="radio" name="payment_name_mode" value="each" id="mode_each" <?= $pa['mode'] === 'each' ? 'checked' : '' ?>>
                                    <span><strong><?= t('Some accounts have a different name') ?></strong><br>
                                    <small class="text-muted"><?= t('Each account gets a name box. Leave it blank to use the default name.') ?></small></span>
                                </label>
                            </div>
                        </div>
                        <label for="account_name" class="form-label"><?= t('Default registered name') ?></label>
                        <input type="text" class="form-control" id="account_name" name="account_name" maxlength="150" value="<?= htmlspecialchars($pa['default_name']) ?>" placeholder="<?= t('e.g. BJP TECHNOLOGIES (T) LTD') ?>">
                    </div>
                </div>

                <!-- Bank Transfer -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white p-4 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-bank me-2 text-primary"></i><?= t('Bank Transfer Details') ?></h5>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openAccountModal('bank')">
                            <i class="bi bi-plus-circle me-1"></i><?= t('Add Bank Account') ?>
                        </button>
                    </div>
                    <div class="card-body p-4 pt-0" id="bankList"></div>
                </div>

                <!-- Mobile Money -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white p-4 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-phone me-2 text-success"></i><?= t('Mobile Money') ?></h5>
                        <button type="button" class="btn btn-success btn-sm" onclick="openAccountModal('mobile')">
                            <i class="bi bi-plus-circle me-1"></i><?= t('Add Mobile Money') ?>
                        </button>
                    </div>
                    <div class="card-body p-4 pt-0" id="mobileList"></div>
                </div>

                <!-- Cheques & Terms -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white p-4 border-0">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-warning"></i><?= t('Cheques & Default Terms') ?></h5>
                    </div>
                    <div class="card-body p-4 pt-0">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="check_payable_to" class="form-label"><?= t('Checks Payable To') ?></label>
                                <input type="text" class="form-control" id="check_payable_to" name="check_payable_to" maxlength="150" value="<?= htmlspecialchars($pa['cheque']) ?>" placeholder="<?= t('Name to appear on checks') ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="default_payment_terms" class="form-label"><?= t('Payment Terms') ?></label>
                                <select class="form-select" id="default_payment_terms" name="default_payment_terms">
                                    <option value="Due on Receipt" <?= $terms == 'Due on Receipt' ? 'selected' : '' ?>><?= t('Due upon Receipt') ?></option>
                                    <option value="Net 15" <?= $terms == 'Net 15' ? 'selected' : '' ?>><?= t('Net 15 Days') ?></option>
                                    <option value="Net 30" <?= $terms == 'Net 30' ? 'selected' : '' ?>><?= t('Net 30 Days') ?></option>
                                    <option value="Net 60" <?= $terms == 'Net 60' ? 'selected' : '' ?>><?= t('Net 60 Days') ?></option>
                                </select>
                                <div class="form-text"><?= t('Default due date calculation for new invoices.') ?></div>
                            </div>
                        </div>

                        <div class="col-12 mt-4 text-end">
                            <button type="submit" class="btn btn-primary px-4 py-2">
                                <i class="bi bi-save me-2"></i><?= t('Save Payment Settings') ?>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 bg-light mb-4" style="position: sticky; top: 80px;">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-1"><?= t('Preview') ?></h5>
                    <p class="small text-muted mb-3"><?= t('How payment details will appear on quotations and invoices:') ?></p>
                    <div class="pa-preview" id="paPreview"></div>
                    <p class="small text-muted mt-2 mb-0" id="paUnsaved" style="display:none;"><i class="bi bi-exclamation-circle me-1"></i><?= t('You have unsaved changes. Press Save Payment Settings.') ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add / Edit account modal -->
<div class="modal fade" id="accountModal" tabindex="-1" aria-labelledby="accountModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header text-white" id="accountModalHeader">
                <h5 class="modal-title" id="accountModalTitle"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="am_label" class="form-label" id="am_label_text"></label> <span class="text-danger">*</span>
                    <input type="text" class="form-control" id="am_label" maxlength="100">
                </div>
                <div class="mb-3">
                    <label for="am_number" class="form-label" id="am_number_text"></label> <span class="text-danger">*</span>
                    <input type="text" class="form-control font-monospace" id="am_number" maxlength="60">
                </div>
                <div class="mb-3" id="am_swift_wrap">
                    <label for="am_swift" class="form-label"><?= t('SWIFT / BIC Code') ?> <small class="text-muted">(<?= t('optional') ?>)</small></label>
                    <input type="text" class="form-control" id="am_swift" maxlength="30">
                </div>
                <div class="mb-3" id="am_ref_wrap">
                    <label for="am_ref" class="form-label"><?= t('Account / Reference') ?> <small class="text-muted">(<?= t('optional') ?>)</small></label>
                    <input type="text" class="form-control" id="am_ref" maxlength="60" placeholder="<?= t('For paybills that need an account number') ?>">
                </div>
                <div class="mb-3" id="am_name_wrap">
                    <label for="am_name" class="form-label"><?= t('Registered name') ?> <small class="text-muted">(<?= t('optional') ?>)</small></label>
                    <input type="text" class="form-control" id="am_name" maxlength="150">
                </div>
                <p class="small text-muted mb-3" id="am_shared_note"></p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="am_show">
                    <label class="form-check-label" for="am_show"><?= t('Show on quotations and invoices') ?></label>
                </div>
                <div class="text-danger small mt-2" id="am_error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                <button type="button" class="btn btn-primary" id="am_save"><i class="bi bi-check-circle me-1"></i><span id="am_save_text"></span></button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const PA = {
        bank:   <?= json_encode(array_values($pa['bank']), $jsonFlags) ?>,
        mobile: <?= json_encode(array_values($pa['mobile']), $jsonFlags) ?>
    };
    const TXT = <?= json_encode([
        'bank_title_add'  => t('Add Bank Account'),
        'bank_title_edit' => t('Edit Bank Account'),
        'mob_title_add'   => t('Add Mobile Money'),
        'mob_title_edit'  => t('Edit Mobile Money'),
        'bank_label'      => t('Bank name'),
        'bank_number'     => t('Account number'),
        'mob_label'       => t('Network / type'),
        'mob_number'      => t('Number'),
        'bank_label_ph'   => t('e.g. CRDB Bank'),
        'mob_label_ph'    => t('e.g. M-Pesa Lipa Namba'),
        'save'            => t('Save'),
        'update'          => t('Update'),
        'required'        => t('Fill in both required fields.'),
        'no_bank'         => t('No bank accounts yet. Click Add Bank Account.'),
        'no_mobile'       => t('No mobile money accounts yet. Click Add Mobile Money.'),
        'hidden'          => t('Hidden on documents'),
        'edit'            => t('Edit'),
        'remove'          => t('Remove'),
        'shared_note'     => t('Registered name: %s (same for all accounts).'),
        'name_ph'         => t('Leave blank to use: %s'),
        'acc_name'        => t('Account Name:'),
        'bank_transfer'   => t('Bank Transfer'),
        'mobile_money'    => t('Mobile Money'),
        'cheque'          => t('Cheque'),
        'payable_to'      => t('Payable to:'),
        'bank_details'    => t('Bank Details'),
        'nothing'         => t('Nothing to show yet.'),
        'confirm_remove'  => t('Remove this account?'),
    ], $jsonFlags) ?>;

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const mode = () => document.getElementById('mode_each').checked ? 'each' : 'shared';
    const defName = () => document.getElementById('account_name').value.trim();
    const holder = a => (mode() === 'each' && (a.name || '').trim()) ? a.name.trim() : defName();
    let dirty = false;
    const markDirty = () => { dirty = true; document.getElementById('paUnsaved').style.display = ''; };

    function renderList(kind) {
        const box = document.getElementById(kind === 'bank' ? 'bankList' : 'mobileList');
        const rows = PA[kind];
        if (!rows.length) {
            box.innerHTML = `<div class="pa-empty">${esc(kind === 'bank' ? TXT.no_bank : TXT.no_mobile)}</div>`;
            return;
        }
        box.innerHTML = rows.map((a, i) => {
            const extra = kind === 'bank' ? (a.swift ? ' · SWIFT ' + esc(a.swift) : '') : (a.ref ? ' · Acc ' + esc(a.ref) : '');
            const hiddenNote = a.show === false ? ` · <i class="bi bi-eye-slash"></i> ${esc(TXT.hidden)}` : '';
            return `<div class="pa-card${a.show === false ? ' pa-hidden' : ''}">
                <div class="pa-body">
                    <div class="pa-title">${esc(a.label)}</div>
                    <div class="pa-num">${esc(a.number)}<span class="pa-meta">${extra}</span></div>
                    <div class="pa-meta">${esc(holder(a))}${hiddenNote}</div>
                </div>
                <div class="pa-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary" title="${esc(TXT.edit)}" onclick="openAccountModal('${kind}', ${i})"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger" title="${esc(TXT.remove)}" onclick="removeAccount('${kind}', ${i})"><i class="bi bi-trash"></i></button>
                </div>
            </div>`;
        }).join('');
    }

    // Mirrors renderPaymentAccountsBlock() in core/payment_accounts.php
    function renderPreview() {
        const vis = k => PA[k].filter(a => a.show !== false).map(a => Object.assign({}, a, { holder: holder(a) }));
        const bank = vis('bank'), mob = vis('mobile');
        const cheque = document.getElementById('check_payable_to').value.trim();
        const counts = {};
        [...bank, ...mob].forEach(a => { if (a.holder) counts[a.holder] = (counts[a.holder] || 0) + 1; });
        let main = '';
        Object.keys(counts).forEach(k => { if (!main || counts[k] > counts[main]) main = k; });
        if (main && counts[defName()] === counts[main]) main = defName();

        const line = (a, extra) => `<p>${esc(a.label)}: <b>${esc(a.number)}</b>${extra}${a.holder && a.holder !== main ? ` <span class="text-muted">(${esc(a.holder)})</span>` : ''}</p>`;
        let h = `<h3>${esc(TXT.bank_details)}</h3>`;
        if (main) h += `<p>${esc(TXT.acc_name)} <b>${esc(main)}</b></p>`;
        if (bank.length) h += `<div class="bank-section"><strong>${esc(TXT.bank_transfer)}</strong>` + bank.map(a => line(a, a.swift ? ` <span class="text-muted">SWIFT: ${esc(a.swift)}</span>` : '')).join('') + '</div>';
        if (mob.length) h += `<div class="bank-section"><strong>${esc(TXT.mobile_money)}</strong>` + mob.map(a => line(a, a.ref && a.ref !== a.number ? ` <span class="text-muted">Acc: ${esc(a.ref)}</span>` : '')).join('') + '</div>';
        if (cheque) h += `<div class="bank-section"><strong>${esc(TXT.cheque)}</strong><p>${esc(TXT.payable_to)} ${esc(cheque)}</p></div>`;
        if (!bank.length && !mob.length && !cheque) h += `<p class="text-muted">${esc(TXT.nothing)}</p>`;
        document.getElementById('paPreview').innerHTML = h;
    }

    function renderAll() { renderList('bank'); renderList('mobile'); renderPreview(); }

    let modal, editKind = 'bank', editIndex = null;

    window.openAccountModal = function (kind, index) {
        editKind = kind;
        editIndex = (index === undefined) ? null : index;
        const a = editIndex === null ? { label: '', number: '', swift: '', ref: '', name: '', show: true } : PA[kind][editIndex];
        const isBank = kind === 'bank';
        document.getElementById('accountModalHeader').className = 'modal-header text-white ' + (isBank ? 'bg-primary' : 'bg-success');
        document.getElementById('accountModalTitle').textContent = isBank
            ? (editIndex === null ? TXT.bank_title_add : TXT.bank_title_edit)
            : (editIndex === null ? TXT.mob_title_add : TXT.mob_title_edit);
        document.getElementById('am_label_text').textContent = isBank ? TXT.bank_label : TXT.mob_label;
        document.getElementById('am_number_text').textContent = isBank ? TXT.bank_number : TXT.mob_number;
        document.getElementById('am_label').placeholder = isBank ? TXT.bank_label_ph : TXT.mob_label_ph;
        document.getElementById('am_label').value = a.label || '';
        document.getElementById('am_number').value = a.number || '';
        document.getElementById('am_swift').value = a.swift || '';
        document.getElementById('am_ref').value = a.ref || '';
        document.getElementById('am_name').value = a.name || '';
        document.getElementById('am_name').placeholder = TXT.name_ph.replace('%s', defName());
        document.getElementById('am_show').checked = a.show !== false;
        document.getElementById('am_swift_wrap').style.display = isBank ? '' : 'none';
        document.getElementById('am_ref_wrap').style.display = isBank ? 'none' : '';
        const each = mode() === 'each';
        document.getElementById('am_name_wrap').style.display = each ? '' : 'none';
        const note = document.getElementById('am_shared_note');
        note.style.display = each ? 'none' : '';
        note.textContent = TXT.shared_note.replace('%s', defName() || '—');
        document.getElementById('am_error').textContent = '';
        const saveBtn = document.getElementById('am_save');
        saveBtn.className = 'btn ' + (isBank ? 'btn-primary' : 'btn-success');
        document.getElementById('am_save_text').textContent = editIndex === null ? TXT.save : TXT.update;
        modal = modal || new bootstrap.Modal(document.getElementById('accountModal'));
        modal.show();
    };

    window.removeAccount = function (kind, index) {
        Swal.fire({ title: TXT.confirm_remove, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: TXT.remove })
            .then(r => {
                if (!r.isConfirmed) return;
                PA[kind].splice(index, 1);
                markDirty();
                renderAll();
            });
    };

    document.getElementById('accountModal').addEventListener('shown.bs.modal', () => document.getElementById('am_label').focus());

    document.getElementById('am_save').addEventListener('click', function () {
        const rec = {
            label:  document.getElementById('am_label').value.trim(),
            number: document.getElementById('am_number').value.trim(),
            name:   document.getElementById('am_name').value.trim(),
            show:   document.getElementById('am_show').checked
        };
        if (editKind === 'bank') rec.swift = document.getElementById('am_swift').value.trim();
        else rec.ref = document.getElementById('am_ref').value.trim();
        if (!rec.label || !rec.number) {
            document.getElementById('am_error').textContent = TXT.required;
            document.getElementById(rec.label ? 'am_number' : 'am_label').focus();
            return;
        }
        if (editIndex === null) PA[editKind].push(rec); else PA[editKind][editIndex] = rec;
        markDirty();
        modal.hide();
        renderAll();
    });

    ['mode_shared', 'mode_each'].forEach(id => document.getElementById(id).addEventListener('change', () => { markDirty(); renderAll(); }));
    ['account_name', 'check_payable_to'].forEach(id => document.getElementById(id).addEventListener('input', () => { markDirty(); renderAll(); }));

    document.getElementById('paymentSettingsForm').addEventListener('submit', function () {
        document.getElementById('bank_accounts_json').value = JSON.stringify(PA.bank);
        document.getElementById('mobile_accounts_json').value = JSON.stringify(PA.mobile);
        dirty = false;
    });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    renderAll();
})();
</script>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
