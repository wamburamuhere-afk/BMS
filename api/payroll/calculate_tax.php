<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    /**
     * Calculate progressive tax based on configured tax brackets
     * 
     * @param float $taxable_income The income to calculate tax on
     * @param PDO $pdo Database connection
     * @return array ['tax_amount' => float, 'breakdown' => array]
     */
    function calculateProgressiveTax($taxable_income, $pdo) {
        // Fetch active tax brackets ordered by min_income
        $stmt = $pdo->query("
            SELECT * FROM tax_brackets 
            WHERE is_active = 1 
            AND (effective_to IS NULL OR effective_to >= CURDATE())
            AND effective_from <= CURDATE()
            ORDER BY min_income ASC
        ");
        $brackets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($brackets)) {
            // Fallback to default tax rate if no brackets configured
            $default_rate_stmt = $pdo->prepare("
                SELECT setting_value FROM payroll_settings 
                WHERE setting_key = 'default_tax_rate'
            ");
            $default_rate_stmt->execute();
            $default_rate = $default_rate_stmt->fetchColumn() ?: 10;
            
            return [
                'tax_amount' => $taxable_income * ($default_rate / 100),
                'breakdown' => [
                    ['bracket' => 'Default Rate', 'rate' => $default_rate, 'amount' => $taxable_income * ($default_rate / 100)]
                ]
            ];
        }
        
        $total_tax = 0;
        $breakdown = [];
        $remaining_income = $taxable_income;
        
        foreach ($brackets as $bracket) {
            if ($remaining_income <= 0) break;
            
            $min = $bracket['min_income'];
            $max = $bracket['max_income'] ?? PHP_FLOAT_MAX;
            $rate = $bracket['tax_rate'];
            
            // Calculate taxable amount in this bracket
            if ($taxable_income > $min) {
                $bracket_income = min($remaining_income, $max - $min);
                $bracket_tax = $bracket_income * ($rate / 100);
                
                $total_tax += $bracket_tax;
                $remaining_income -= $bracket_income;
                
                $breakdown[] = [
                    'bracket' => $bracket['bracket_name'],
                    'min' => $min,
                    'max' => $max == PHP_FLOAT_MAX ? null : $max,
                    'rate' => $rate,
                    'taxable_amount' => $bracket_income,
                    'tax_amount' => $bracket_tax
                ];
            }
        }
        
        return [
            'tax_amount' => $total_tax,
            'breakdown' => $breakdown
        ];
    }
    
    // Example usage
    $taxable_income = $_REQUEST['income'] ?? $_REQUEST['taxable_income'] ?? 0;
    
    if ($taxable_income > 0) {
        $result = calculateProgressiveTax($taxable_income, $pdo);
        echo json_encode([
            'success' => true,
            'taxable_income' => $taxable_income,
            'tax_amount' => round($result['tax_amount'], 2),
            'breakdown' => $result['breakdown']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Please provide income parameter'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
