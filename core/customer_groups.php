<?php
// core/customer_groups.php — rules and membership for Customer Groups.
//
// A static group's members are added and removed by hand. A dynamic group has
// one rule (field + operator + value) and its members are every non-deleted
// customer matching it; customerGroupRefresh() brings the membership in line
// (on create, on edit, and from the Refresh button).
//
// Rule fields are a whitelist of SQL expressions on the current customers
// schema, so nothing from the request reaches SQL except bound values.

if (!function_exists('customerGroupRuleFields')) {
    /** field key => [label, SQL expression on customers c] */
    function customerGroupRuleFields(): array
    {
        return [
            'customer_type'       => ['Customer Type', 'c.customer_type'],
            'category'            => ['Category', '(SELECT cc.category_name FROM customer_categories cc WHERE cc.category_id = c.category_id)'],
            'occupation_business' => ['Occupation / Business', 'c.occupation_business'],
            'city'                => ['City', 'c.city'],
            'state'               => ['Region', 'c.state'],
            'country'             => ['Country', 'c.country'],
            'created_at'          => ['Registration Date (YYYY-MM-DD)', "DATE_FORMAT(c.created_at, '%Y-%m-%d')"],
        ];
    }

    /** operator key => label */
    function customerGroupRuleOperators(): array
    {
        return ['equals' => 'Equals', 'contains' => 'Contains', 'starts_with' => 'Starts With', 'ends_with' => 'Ends With'];
    }

    /**
     * Rules from a form post (rule_field / rule_operator / rule_value).
     * Returns [rules|null, error|null]; rules is null for a static group.
     */
    function customerGroupRulesFromPost(string $groupType, array $post): array
    {
        if ($groupType !== 'dynamic') return [null, null];
        $field = (string)($post['rule_field'] ?? '');
        $op    = (string)($post['rule_operator'] ?? 'equals');
        $value = trim((string)($post['rule_value'] ?? ''));
        if (!isset(customerGroupRuleFields()[$field])) return [null, 'Choose the field a dynamic group matches on'];
        if (!isset(customerGroupRuleOperators()[$op])) return [null, 'Invalid rule condition'];
        if ($value === '')                             return [null, 'Enter the value a dynamic group matches'];
        if (mb_strlen($value) > 255)                   return [null, 'Rule value is too long'];
        return [['field' => $field, 'operator' => $op, 'value' => $value], null];
    }

    /**
     * WHERE fragment + params for a stored rule, or null when the rule can't be
     * used (e.g. a field from the old customer layout).
     */
    function customerGroupRuleSql(?array $rules): ?array
    {
        if (!$rules) return null;
        $field = (string)($rules['field'] ?? '');
        if ($field === 'entity_type') $field = 'customer_type';   // old layout's name for it
        $fields = customerGroupRuleFields();
        $op = (string)($rules['operator'] ?? '');
        $value = (string)($rules['value'] ?? '');
        if (!isset($fields[$field]) || !isset(customerGroupRuleOperators()[$op]) || $value === '') return null;
        $expr = $fields[$field][1];
        if ($op === 'equals') return ["$expr = ?", [$value]];
        $like = addcslashes($value, '\\%_');
        $pattern = $op === 'contains' ? "%$like%" : ($op === 'starts_with' ? "$like%" : "%$like");
        return ["$expr LIKE ?", [$pattern]];
    }

    /**
     * Make a dynamic group's members exactly the customers matching its rule.
     * Returns ['added' => n, 'removed' => n, 'total' => n] or ['error' => msg].
     */
    function customerGroupRefresh(PDO $pdo, int $groupId, ?array $rules, int $userId): array
    {
        $rule = customerGroupRuleSql($rules);
        if ($rule === null) {
            return ['error' => 'This group\'s rule uses a field customers no longer have — edit the group and choose a field again.'];
        }
        [$where, $params] = $rule;

        $st = $pdo->prepare("SELECT c.customer_id FROM customers c WHERE c.status != 'deleted' AND $where");
        $st->execute($params);
        $match = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $st = $pdo->prepare("SELECT customer_id FROM customer_group_customers WHERE group_id = ?");
        $st->execute([$groupId]);
        $current = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $add    = array_values(array_diff($match, $current));
        $remove = array_values(array_diff($current, $match));

        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) $pdo->beginTransaction();
        try {
            if ($remove) {
                $in = implode(',', array_fill(0, count($remove), '?'));
                $pdo->prepare("DELETE FROM customer_group_customers WHERE group_id = ? AND customer_id IN ($in)")
                    ->execute(array_merge([$groupId], $remove));
            }
            if ($add) {
                $ins = $pdo->prepare("INSERT IGNORE INTO customer_group_customers (group_id, customer_id, added_by, added_at) VALUES (?, ?, ?, NOW())");
                foreach ($add as $cid) $ins->execute([$groupId, $cid, $userId]);
            }
            if ($ownTxn) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['added' => count($add), 'removed' => count($remove), 'total' => count($match)];
    }

    /** One live (not deleted) group row, or null. */
    function customerGroupFind(PDO $pdo, int $groupId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM customer_groups WHERE group_id = ? AND status != 'deleted'");
        $st->execute([$groupId]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        return $g ?: null;
    }

    /** Validated common fields from a create/edit post. Returns [data|null, error|null]. */
    function customerGroupFromPost(array $post): array
    {
        $name = trim((string)($post['group_name'] ?? ''));
        $type = (string)($post['group_type'] ?? '');
        $color = (string)($post['color'] ?? '#007bff');
        $status = ($post['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        if ($name === '')                                 return [null, 'Group name is required'];
        if (mb_strlen($name) > 255)                       return [null, 'Group name is too long'];
        if (!in_array($type, ['static', 'dynamic'], true)) return [null, 'Choose a group type'];
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color))   $color = '#007bff';
        [$rules, $err] = customerGroupRulesFromPost($type, $post);
        if ($err) return [null, $err];
        return [[
            'group_name'  => $name,
            'description' => trim((string)($post['description'] ?? '')),
            'group_type'  => $type,
            'color'       => strtolower($color),
            'status'      => $status,
            'rules'       => $rules,
        ], null];
    }
}
