<?php
require_once '../config.php';

class PenaltyCalculator {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Calculate penalties for all active loans with overdue payments
     */
    public function calculateAllPenalties() {
        try {
            // Get all active loans with overdue payments
            $overdueLoans = $this->getOverdueLoans();
            $results = [
                'total_loans_processed' => 0,
                'total_penalties_calculated' => 0,
                'total_penalty_amount' => 0,
                'details' => []
            ];
            
            foreach ($overdueLoans as $loan) {
                $penaltyResult = $this->calculateLoanPenalties($loan);
                $results['details'][] = $penaltyResult;
                
                if ($penaltyResult['penalties_calculated'] > 0) {
                    $results['total_loans_processed']++;
                    $results['total_penalties_calculated'] += $penaltyResult['penalties_calculated'];
                    $results['total_penalty_amount'] += $penaltyResult['total_penalty_amount'];
                }
            }
            
            return $results;
            
        } catch (Exception $e) {
            throw new Exception("Penalty calculation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate penalties for a specific loan
     */
    public function calculateLoanPenalties($loan) {
        $result = [
            'loan_id' => $loan['loan_id'],
            'reference_number' => $loan['reference_number'],
            'penalties_calculated' => 0,
            'total_penalty_amount' => 0,
            'overdue_installments' => []
        ];
        
        try {
            // Get overdue installments for this loan
            $overdueInstallments = $this->getOverdueInstallments($loan['loan_id']);
            
            foreach ($overdueInstallments as $installment) {
                $penaltyAmount = $this->calculateInstallmentPenalty($loan, $installment);
                
                if ($penaltyAmount > 0) {
                    // Update the installment with calculated penalty
                    $this->updateInstallmentPenalty($installment['id'], $penaltyAmount);
                    
                    $result['overdue_installments'][] = [
                        'installment_id' => $installment['id'],
                        'payment_number' => $installment['payment_number'],
                        'due_date' => $installment['due_date'],
                        'overdue_days' => $installment['overdue_days'],
                        'penalty_amount' => $penaltyAmount
                    ];
                    
                    $result['penalties_calculated']++;
                    $result['total_penalty_amount'] += $penaltyAmount;
                }
            }
            
            // Update loan totals if penalties were calculated
            if ($result['penalties_calculated'] > 0) {
                $this->updateLoanTotals($loan['loan_id']);
            }
            
        } catch (Exception $e) {
            throw new Exception("Failed to calculate penalties for loan {$loan['loan_id']}: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Get all loans with overdue payments
     */
    private function getOverdueLoans() {
        $sql = "
            SELECT 
                l.loan_id,
                l.reference_number,
                l.amount,
                l.penalty_interest,
                l.grace_period,
                l.loan_start_date,
                l.loan_end_date,
                l.status
            FROM loans l
            WHERE l.status IN ('Disbursed', 'active')
            AND EXISTS (
                SELECT 1 FROM loan_repayment_schedule lrs 
                WHERE lrs.loan_id = l.loan_id 
                AND lrs.status != 'paid'
                AND lrs.due_date < CURDATE()
                AND lrs.paid_amount < lrs.total_amount
            )
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get overdue installments for a specific loan
     */
    private function getOverdueInstallments($loanId) {
        $sql = "
            SELECT 
                id,
                payment_number,
                due_date,
                principal_amount,
                interest_amount,
                penalty_amount,
                total_amount,
                paid_amount,
                remaining_balance,
                DATEDIFF(CURDATE(), due_date) as overdue_days
            FROM loan_repayment_schedule 
            WHERE loan_id = ?
            AND status != 'paid'
            AND due_date < CURDATE()
            AND paid_amount < total_amount
            ORDER BY due_date ASC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$loanId]);
        $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate effective overdue days considering grace period
        foreach ($installments as &$installment) {
            $installment['effective_overdue_days'] = max(0, $installment['overdue_days'] - $installment['grace_period'] ?? 0);
        }
        
        return $installments;
    }
    
    /**
     * Calculate penalty for a specific installment
     */
    private function calculateInstallmentPenalty($loan, $installment) {
        // Skip if within grace period
        if ($installment['effective_overdue_days'] <= 0) {
            return 0;
        }
        
        $penaltyRate = $this->parsePenaltyRate($loan['penalty_interest']);
        $outstandingAmount = $installment['remaining_balance'];
        
        if ($outstandingAmount <= 0) {
            return 0;
        }
        
        // Calculate daily penalty
        $dailyPenaltyRate = $penaltyRate / 100 / 365; // Convert annual rate to daily
        $penaltyAmount = $outstandingAmount * $dailyPenaltyRate * $installment['effective_overdue_days'];
        
        // Round to 2 decimal places
        return round(max(0, $penaltyAmount), 2);
    }
    
    /**
     * Parse penalty interest rate from string
     */
    private function parsePenaltyRate($penaltyRate) {
        if (is_numeric($penaltyRate)) {
            return (float)$penaltyRate;
        }
        
        // Handle percentage strings like "5%", "5.5%", etc.
        if (preg_match('/(\d+(?:\.\d+)?)\s*%?/', (string)$penaltyRate, $matches)) {
            return (float)$matches[1];
        }
        
        // Default penalty rate if not specified
        return 5.0; // 5% annual default penalty rate
    }
    
    /**
     * Update installment with calculated penalty
     */
    private function updateInstallmentPenalty($installmentId, $penaltyAmount) {
        $sql = "
            UPDATE loan_repayment_schedule 
            SET penalty_amount = ?,
                total_amount = principal_amount + interest_amount + ?,
                remaining_balance = principal_amount + interest_amount + ? - paid_amount,
                updated_at = NOW()
            WHERE id = ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$penaltyAmount, $penaltyAmount, $penaltyAmount, $installmentId]);
    }
    
    /**
     * Update loan totals after penalty calculation
     */
    private function updateLoanTotals($loanId) {
        // Recalculate loan totals including penalties
        $sql = "
            UPDATE loans l
            SET 
                total_repayment = (
                    SELECT SUM(principal_amount + interest_amount + penalty_amount) 
                    FROM loan_repayment_schedule 
                    WHERE loan_id = l.loan_id
                ),
                balance = (
                    SELECT SUM(remaining_balance) 
                    FROM loan_repayment_schedule 
                    WHERE loan_id = l.loan_id
                ),
                updated_at = NOW()
            WHERE l.loan_id = ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$loanId]);
    }
    
    /**
     * Get penalty calculation report for a specific loan
     */
    public function getPenaltyReport($loanId) {
        $sql = "
            SELECT 
                l.loan_id,
                l.reference_number,
                l.amount as loan_amount,
                l.penalty_interest,
                l.grace_period,
                COUNT(lrs.id) as total_installments,
                SUM(CASE WHEN lrs.due_date < CURDATE() AND lrs.status != 'paid' THEN 1 ELSE 0 END) as overdue_installments,
                SUM(lrs.penalty_amount) as total_penalties,
                MAX(lrs.due_date) as last_due_date
            FROM loans l
            LEFT JOIN loan_repayment_schedule lrs ON l.loan_id = lrs.loan_id
            WHERE l.loan_id = ?
            GROUP BY l.loan_id
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$loanId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Usage example and CLI script
if (php_sapi_name() === 'cli') {
    // CLI execution for cron jobs
    try {
        $calculator = new PenaltyCalculator($pdo);
        $results = $calculator->calculateAllPenalties();
        
        echo "Penalty Calculation Completed:\n";
        echo "Loans Processed: " . $results['total_loans_processed'] . "\n";
        echo "Penalties Calculated: " . $results['total_penalties_calculated'] . "\n";
        echo "Total Penalty Amount: " . number_format($results['total_penalty_amount'], 2) . "\n";
        
        // Log the results
        error_log("Penalty calculation completed: " . json_encode($results));
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        error_log("Penalty calculation error: " . $e->getMessage());
        exit(1);
    }
}
?>