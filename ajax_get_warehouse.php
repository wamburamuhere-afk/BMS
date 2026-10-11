<?php
// ajax_get_warehouse.php
require_once __DIR__ . '/roots.php';

// This endpoint never includes header.php, so load the caller's saved
// language here or every label below would always come back in English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit(t('Unauthorized'));
}

// This endpoint renders the warehouse EDIT form — gate it on the edit verb.
if (!canEdit('warehouses')) {
    http_response_code(403);
    exit(t('Permission denied'));
}

$warehouse_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($warehouse_id <= 0) {
    http_response_code(400);
    exit(wLabel('Invalid warehouse ID', 'Invalid shop ID'));
}

// Row-level gate: a warehouse tied to a project outside the caller's scope is
// not theirs to open. Plain-text 403 (this endpoint returns HTML, not JSON).
assertScopeForRecordHtml('warehouses', 'warehouse_id', $warehouse_id);

try {
    $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE warehouse_id = ?");
    $stmt->execute([$warehouse_id]);
    $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch active projects for selection — admins see all; non-admins see only
    // their assigned projects. Mirrors app/bms/stock/warehouses.php. Skipped
    // entirely (and the field below hidden) when the Projects module is off.
    $show_projects = projectsModuleActive();
    // POS Mode only matters to Restaurant POS; a retail-only tenant never sees it.
    $show_pos_mode = tenantFeatureEnabled('pos') && tenantFeatureEnabled('restaurant_pos');
    $all_projects = [];
    if (!$show_projects) {
        // nothing to load
    } elseif (isAdmin()) {
        $all_projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $assigned = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
        if (empty($assigned)) {
            $all_projects = [];
        } else {
            $ph = implode(',', array_fill(0, count($assigned), '?'));
            $pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($ph) ORDER BY project_name ASC");
            $pstmt->execute($assigned);
            $all_projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (!$warehouse) {
        http_response_code(404);
        exit(wLabel('Warehouse not found', 'Shop not found'));
    }

    // Since address might be combined in the database, we handle it
    // The main app seems to combine them, but the edit form might need individual fields
    // For now, let's output the form fields with the data we have
    ?>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="edit_warehouse_name" class="form-label"><?= htmlspecialchars(wLabel('Warehouse Name', 'Shop Name')) ?> *</label>
            <input type="text" class="form-control" id="edit_warehouse_name" name="warehouse_name" value="<?= htmlspecialchars($warehouse['warehouse_name']) ?>" required>
        </div>
        <div class="col-md-6 mb-3">
            <label for="edit_warehouse_code" class="form-label"><?= htmlspecialchars(wLabel('Warehouse Code', 'Shop Code')) ?> <span class="text-muted">(<?= htmlspecialchars(t('Auto-generated')) ?>)</span></label>
            <input type="text" class="form-control bg-light" id="edit_warehouse_code" name="warehouse_code" value="<?= htmlspecialchars($warehouse['warehouse_code']) ?>" readonly required>
        </div>
        <?php if ($show_projects): ?>
        <div class="col-md-6 mb-3">
            <label for="edit_project_id" class="form-label"><?= htmlspecialchars(t('Project (Optional)')) ?></label>
            <select class="form-select" id="edit_project_id" name="project_id">
                <option value=""><?= htmlspecialchars(t('-- No Specific Project --')) ?></option>
                <?php foreach ($all_projects as $project): ?>
                    <option value="<?= $project['project_id'] ?>" <?= ($warehouse['project_id'] == $project['project_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($project['project_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted"><?= htmlspecialchars(wLabel('Link this warehouse to a specific project', 'Link this shop to a specific project')) ?></small>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="edit_address" class="form-label"><?= htmlspecialchars(t('Address')) ?></label>
            <input type="text" class="form-control" id="edit_address" name="address" value="<?= htmlspecialchars($warehouse['address'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label for="edit_city" class="form-label"><?= htmlspecialchars(t('City')) ?></label>
            <input type="text" class="form-control" id="edit_city" name="city" value="<?= htmlspecialchars($warehouse['city'] ?? '') ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-4 mb-3">
            <label for="edit_country" class="form-label"><?= htmlspecialchars(t('Country')) ?></label>
            <input type="text" class="form-control" id="edit_country" name="country" value="<?= htmlspecialchars($warehouse['country'] ?? 'Tanzania') ?>">
        </div>
        <div class="col-md-4 mb-3">
            <label for="edit_state" class="form-label"><?= htmlspecialchars(t('Region')) ?></label>
            <input type="text" class="form-control" id="edit_state" name="state" value="<?= htmlspecialchars($warehouse['state'] ?? '') ?>">
        </div>
        <div class="col-md-4 mb-3">
            <label for="edit_postal_code" class="form-label"><?= htmlspecialchars(t('Postal Code')) ?></label>
            <input type="text" class="form-control" id="edit_postal_code" name="postal_code" value="<?= htmlspecialchars($warehouse['postal_code'] ?? '') ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="edit_phone" class="form-label"><?= htmlspecialchars(t('Phone')) ?></label>
            <input type="tel" class="form-control" id="edit_phone" name="phone" value="<?= htmlspecialchars($warehouse['phone'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label for="edit_email" class="form-label"><?= htmlspecialchars(t('Email')) ?></label>
            <input type="email" class="form-control" id="edit_email" name="email" value="<?= htmlspecialchars($warehouse['email'] ?? '') ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="edit_manager_name" class="form-label"><?= htmlspecialchars(t('Manager Name')) ?></label>
            <input type="text" class="form-control" id="edit_manager_name" name="manager_name" value="<?= htmlspecialchars($warehouse['contact_person'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label for="edit_manager_phone" class="form-label"><?= htmlspecialchars(t('Manager Phone')) ?></label>
            <input type="tel" class="form-control" id="edit_manager_phone" name="manager_phone" value="<?= htmlspecialchars($warehouse['phone'] ?? '') ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-4 mb-3">
            <label for="edit_capacity" class="form-label"><?= htmlspecialchars(t('Capacity (units)')) ?></label>
            <input type="number" class="form-control" id="edit_capacity" name="capacity" value="<?= htmlspecialchars($warehouse['capacity'] ?? '') ?>" min="0" step="1">
        </div>
        <div class="col-md-4 mb-3">
            <label for="edit_status" class="form-label"><?= htmlspecialchars(t('Status')) ?></label>
            <select class="form-select" id="edit_status" name="status" required>
                <option value="active" <?= $warehouse['status'] == 'active' ? 'selected' : '' ?>><?= htmlspecialchars(warehouseStatusLabel('active')) ?></option>
                <option value="inactive" <?= $warehouse['status'] == 'inactive' ? 'selected' : '' ?>><?= htmlspecialchars(warehouseStatusLabel('inactive')) ?></option>
                <option value="maintenance" <?= $warehouse['status'] == 'maintenance' ? 'selected' : '' ?>><?= htmlspecialchars(warehouseStatusLabel('maintenance')) ?></option>
            </select>
        </div>
        <div class="col-md-4 mb-3">
            <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" id="edit_is_primary" name="is_primary" <?= $warehouse['is_primary'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="edit_is_primary">
                    <?= htmlspecialchars(wLabel('Set as Primary Warehouse', 'Set as Primary Shop')) ?>
                </label>
            </div>
        </div>
    </div>

    <?php if ($show_pos_mode): ?>
    <div class="row">
        <div class="col-md-4 mb-3">
            <label for="edit_pos_mode" class="form-label"><?= htmlspecialchars(t('POS Mode')) ?></label>
            <?php $pos_mode = $warehouse['pos_mode'] ?? 'retail'; ?>
            <select class="form-select" id="edit_pos_mode" name="pos_mode">
                <option value="retail" <?= $pos_mode === 'retail' ? 'selected' : '' ?>><?= htmlspecialchars(t('Retail')) ?></option>
                <option value="restaurant" <?= $pos_mode === 'restaurant' ? 'selected' : '' ?>><?= htmlspecialchars(t('Restaurant')) ?></option>
                <option value="hybrid" <?= $pos_mode === 'hybrid' ? 'selected' : '' ?>><?= htmlspecialchars(t('Hybrid (Retail + Restaurant)')) ?></option>
            </select>
            <small class="text-muted"><?= htmlspecialchars(wLabel('Restaurant/Hybrid unlocks Floors & Tables, Kitchen Display, Reservations for this warehouse in POS.', 'Restaurant/Hybrid unlocks Floors & Tables, Kitchen Display, Reservations for this shop in POS.')) ?></small>
        </div>
    </div>
    <?php endif; ?>

    <div class="mb-3">
        <label for="edit_notes" class="form-label"><?= htmlspecialchars(t('Notes')) ?></label>
        <textarea class="form-control" id="edit_notes" name="notes" rows="3"><?= htmlspecialchars($warehouse['notes'] ?? '') ?></textarea>
    </div>
    <?php
} catch (PDOException $e) {
    http_response_code(500);
    exit(t('Database error'));
}
