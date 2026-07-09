<?php
/**
 * BMS — Leave Type input validation.
 *
 * Shared by api/add_leave_type.php and api/update_leave_type.php so the create
 * and update paths cannot drift. Throws InvalidArgumentException with a message
 * fit to show the user; the caller turns that into a JSON error.
 */

if (!function_exists('validateLeaveTypeInput')) {
    /**
     * @param int|null $type_id  Present on update — excluded from the duplicate check.
     * @return array Clean, typed values ready to bind.
     */
    function validateLeaveTypeInput(PDO $pdo, array $post, ?int $type_id): array
    {
        $type_name = trim($post['type_name'] ?? '');
        if ($type_name === '') {
            throw new InvalidArgumentException('Leave type name is required.');
        }
        if (mb_strlen($type_name) > 50) {
            throw new InvalidArgumentException('Leave type name must be 50 characters or fewer.');
        }

        // Names must be unique — the leave form shows them and reports group by them.
        $sql = "SELECT COUNT(*) FROM leave_types WHERE LOWER(type_name) = LOWER(?)";
        $params = [$type_name];
        if ($type_id !== null) {
            $sql .= " AND type_id != ?";
            $params[] = $type_id;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        if ((int)$st->fetchColumn() > 0) {
            throw new InvalidArgumentException("A leave type named '$type_name' already exists.");
        }

        $max_days = filter_var($post['max_days_per_year'] ?? null, FILTER_VALIDATE_INT);
        if ($max_days === false || $max_days < 1) {
            throw new InvalidArgumentException('Maximum days per year must be a whole number of at least 1.');
        }
        if ($max_days > 366) {
            throw new InvalidArgumentException('Maximum days per year cannot exceed 366.');
        }

        $max_consecutive = filter_var($post['max_consecutive_days'] ?? null, FILTER_VALIDATE_INT);
        if ($max_consecutive === false || $max_consecutive < 1) {
            $max_consecutive = $max_days;   // sensible default: a single unbroken block
        }
        if ($max_consecutive > $max_days) {
            throw new InvalidArgumentException('Maximum consecutive days cannot exceed maximum days per year.');
        }

        $min_before = filter_var($post['min_days_before_apply'] ?? 0, FILTER_VALIDATE_INT);
        if ($min_before === false || $min_before < 0) $min_before = 0;

        $carry_over = filter_var($post['carry_over_days'] ?? 0, FILTER_VALIDATE_INT);
        if ($carry_over === false || $carry_over < 0) $carry_over = 0;
        if ($carry_over > $max_days) {
            throw new InvalidArgumentException('Carry-over days cannot exceed maximum days per year.');
        }

        // Checkboxes: absent means unchecked. is_paid is a radio and must be explicit.
        if (!isset($post['is_paid']) || !in_array((string)$post['is_paid'], ['0', '1'], true)) {
            throw new InvalidArgumentException('Please state whether this leave type is paid or unpaid.');
        }
        $is_paid = (int)$post['is_paid'];
        $requires_document = !empty($post['requires_document']) ? 1 : 0;

        $color = trim($post['color'] ?? '#0d6efd');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#0d6efd';
        }

        $status = ($post['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        return [
            'type_name'             => $type_name,
            'description'           => trim($post['description'] ?? '') ?: null,
            'max_days_per_year'     => $max_days,
            'min_days_before_apply' => $min_before,
            'max_consecutive_days'  => $max_consecutive,
            'requires_document'     => $requires_document,
            'is_paid'               => $is_paid,
            'carry_over_days'       => $carry_over,
            'color'                 => $color,
            'status'                => $status,
        ];
    }
}

if (!function_exists('leaveTypeUsageCount')) {
    /** How many leave records reference this type. Drives hard- vs soft-delete. */
    function leaveTypeUsageCount(PDO $pdo, int $type_id): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM leaves WHERE leave_type_id = ?");
        $st->execute([$type_id]);
        return (int)$st->fetchColumn();
    }
}
