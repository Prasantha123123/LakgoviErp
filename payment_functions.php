<?php
// payment_functions.php - Core payment functions
// This file contains reusable payment calculation functions

/**
 * Recompute invoice totals based on actual payments
 * RULE: Pending cheques do NOT count as paid
 * 
 * Paid = SUM(sales_payments.amount WHERE invoice_id = X AND (
 *   payment_method != 'cheque' OR cheque_status = 'cleared'
 * ))
 * 
 * @param PDO $db Database connection
 * @param int $invoice_id Invoice ID to recompute
 * @return array Updated invoice data (paid_amount, balance_amount, payment_status)
 */
function recomputeInvoiceTotals($db, $invoice_id) {
    // Get invoice total_amount
    $stmt = $db->prepare("SELECT total_amount FROM sales_invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) {
        throw new Exception("Invoice not found: " . $invoice_id);
    }
    
    $total_amount = floatval($invoice['total_amount']);
    
    // Calculate paid amount - without GROUP BY (as requested)
    // Count non-cheque payments + cleared cheques
    $stmt = $db->prepare("
        SELECT id, payment_method, amount, cheque_status 
        FROM sales_payments 
        WHERE invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $payments = $stmt->fetchAll();
    
    $paid_amount = 0;
    $pending_cheque_amount = 0;
    
    foreach ($payments as $payment) {
        $amount = floatval($payment['amount']);
        
        if ($payment['payment_method'] === 'cheque') {
            // Only count cleared cheques as paid
            if ($payment['cheque_status'] === 'cleared') {
                $paid_amount += $amount;
            } elseif ($payment['cheque_status'] === 'pending') {
                $pending_cheque_amount += $amount;
            }
            // bounced/cancelled cheques are not counted
        } else {
            // Non-cheque payments always count
            $paid_amount += $amount;
        }
    }
    
    // Calculate balance
    $balance_amount = $total_amount - $paid_amount;
    
    // Ensure balance is not negative (overpayment scenario)
    if ($balance_amount < 0) {
        $balance_amount = 0;
    }
    
    // Determine payment status
    if ($balance_amount <= 0) {
        $payment_status = 'paid';
    } elseif ($paid_amount > 0) {
        $payment_status = 'partial';
    } else {
        $payment_status = 'unpaid';
    }
    
    // Update invoice
    $stmt = $db->prepare("
        UPDATE sales_invoices 
        SET paid_amount = ?, 
            balance_amount = ?,
            payment_status = ?
        WHERE id = ?
    ");
    $stmt->execute([$paid_amount, $balance_amount, $payment_status, $invoice_id]);
    
    return [
        'total_amount' => $total_amount,
        'paid_amount' => $paid_amount,
        'balance_amount' => $balance_amount,
        'pending_cheque_amount' => $pending_cheque_amount,
        'payment_status' => $payment_status
    ];
}

/**
 * Get next payment number
 * 
 * @param PDO $db Database connection
 * @return string Next payment number (PAY00001 format)
 */
function getNextPaymentNo($db) {
    $stmt = $db->query("SELECT payment_no FROM sales_payments ORDER BY id DESC LIMIT 1");
    $last_payment = $stmt->fetch();
    
    if ($last_payment) {
        $last_num = intval(substr($last_payment['payment_no'], 3));
        return 'PAY' . str_pad($last_num + 1, 5, '0', STR_PAD_LEFT);
    }
    
    return 'PAY00001';
}

/**
 * Insert multiple payment lines for an invoice
 * 
 * @param PDO $db Database connection
 * @param int $invoice_id Invoice ID
 * @param array $payments Array of payment lines
 * @param string $payment_type 'initial' or 'additional'
 * @param int $created_by User ID
 * @return array Array of inserted payment IDs
 */
function insertPaymentLines($db, $invoice_id, $payments, $payment_type, $created_by) {
    $inserted_ids = [];
    
    // Get invoice date for default payment date
    $stmt = $db->prepare("SELECT invoice_date FROM sales_invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();
    $default_date = $invoice ? $invoice['invoice_date'] : date('Y-m-d');
    
    foreach ($payments as $payment) {
        $amount = floatval($payment['amount'] ?? 0);
        
        // Skip empty payments
        if ($amount <= 0) {
            continue;
        }
        
        $method = $payment['method'] ?? 'cash';
        $payment_date = !empty($payment['payment_date']) ? $payment['payment_date'] : $default_date;
        $reference_no = !empty($payment['reference_no']) ? $payment['reference_no'] : null;
        
        // Cheque fields
        $cheque_number = null;
        $cheque_date = null;
        $bank_name = null;
        $cheque_status = null;
        
        if ($method === 'cheque') {
            $cheque_number = !empty($payment['cheque_number']) ? $payment['cheque_number'] : null;
            $cheque_date = !empty($payment['cheque_date']) ? $payment['cheque_date'] : null;
            $bank_name = !empty($payment['cheque_bank_name']) ? $payment['cheque_bank_name'] : null;
            $cheque_status = !empty($payment['cheque_status']) ? $payment['cheque_status'] : 'pending';
        } elseif ($method === 'bank_transfer') {
            // Bank transfer also stores bank name
            $bank_name = !empty($payment['bank_name']) ? $payment['bank_name'] : null;
        }
        
        // Get next payment number
        $payment_no = getNextPaymentNo($db);
        
        $stmt = $db->prepare("
            INSERT INTO sales_payments (
                payment_no, invoice_id, payment_date, amount, 
                payment_method, payment_type, reference_no, 
                cheque_number, cheque_date, bank_name, cheque_status,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $payment_no,
            $invoice_id,
            $payment_date,
            $amount,
            $method,
            $payment_type,
            $reference_no,
            $cheque_number,
            $cheque_date,
            $bank_name,
            $cheque_status,
            $created_by
        ]);
        
        $inserted_ids[] = $db->lastInsertId();
    }
    
    return $inserted_ids;
}

/**
 * Get payment history for an invoice
 * 
 * @param PDO $db Database connection
 * @param int $invoice_id Invoice ID
 * @return array Array with 'payments' and 'method_breakdown'
 */
function getInvoicePaymentHistory($db, $invoice_id) {
    // Get all payments
    $stmt = $db->prepare("
        SELECT 
            id, payment_no, payment_date, payment_method, amount, 
            reference_no, cheque_number, cheque_date, bank_name, 
            cheque_status, clearance_date, bounce_reason, bounce_charges,
            payment_type, created_at
        FROM sales_payments
        WHERE invoice_id = ?
        ORDER BY payment_date, id
    ");
    $stmt->execute([$invoice_id]);
    $payments = $stmt->fetchAll();
    
    // Calculate method breakdown without GROUP BY
    $method_breakdown = [];
    
    foreach ($payments as $payment) {
        $method = $payment['payment_method'];
        $amount = floatval($payment['amount']);
        
        if (!isset($method_breakdown[$method])) {
            $method_breakdown[$method] = [
                'method' => $method,
                'total_amount' => 0,
                'counted_paid' => 0,
                'pending_cheque' => 0,
                'payment_count' => 0
            ];
        }
        
        $method_breakdown[$method]['total_amount'] += $amount;
        $method_breakdown[$method]['payment_count']++;
        
        if ($method === 'cheque') {
            if ($payment['cheque_status'] === 'cleared') {
                $method_breakdown[$method]['counted_paid'] += $amount;
            } elseif ($payment['cheque_status'] === 'pending') {
                $method_breakdown[$method]['pending_cheque'] += $amount;
            }
            // bounced/cancelled don't count
        } else {
            $method_breakdown[$method]['counted_paid'] += $amount;
        }
    }
    
    return [
        'payments' => $payments,
        'method_breakdown' => array_values($method_breakdown)
    ];
}

/**
 * Get customer pending invoices
 * 
 * @param PDO $db Database connection
 * @param int $customer_id Customer ID (optional - if null, gets all)
 * @return array Array of pending invoices
 */
function getCustomerPendingInvoices($db, $customer_id = null) {
    $sql = "
        SELECT 
            si.id, si.invoice_no, si.invoice_date, si.due_date,
            si.total_amount, si.paid_amount, si.balance_amount, si.payment_status,
            c.id as customer_id, c.customer_code, c.customer_name, c.phone
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        WHERE si.payment_status != 'paid' AND si.status = 'confirmed'
    ";
    
    $params = [];
    
    if ($customer_id) {
        $sql .= " AND si.customer_id = ?";
        $params[] = $customer_id;
    }
    
    $sql .= " ORDER BY si.invoice_date DESC, si.id DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll();
}

/**
 * Get pending cheques with all relevant info
 * 
 * @param PDO $db Database connection
 * @param string $status Filter by status ('pending', 'bounced', or null for both)
 * @return array Array of cheque records
 */
function getChequePayments($db, $status = null) {
    $sql = "
        SELECT 
            sp.id, sp.payment_no, sp.invoice_id, sp.payment_date, sp.amount,
            sp.cheque_number, sp.cheque_date, sp.bank_name, sp.cheque_status,
            sp.clearance_date, sp.bounce_reason, sp.bounce_charges,
            sp.created_at,
            si.invoice_no, si.total_amount as invoice_total, si.balance_amount as invoice_balance,
            c.id as customer_id, c.customer_code, c.customer_name, c.phone,
            DATEDIFF(CURDATE(), sp.cheque_date) as days_pending
        FROM sales_payments sp
        JOIN sales_invoices si ON sp.invoice_id = si.id
        JOIN customers c ON si.customer_id = c.id
        WHERE sp.payment_method = 'cheque'
    ";
    
    $params = [];
    
    if ($status) {
        $sql .= " AND sp.cheque_status = ?";
        $params[] = $status;
    } else {
        $sql .= " AND sp.cheque_status IN ('pending', 'bounced')";
    }
    
    $sql .= " ORDER BY sp.cheque_date ASC, sp.id ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll();
}

/**
 * Update cheque status and recompute invoice totals
 * 
 * @param PDO $db Database connection
 * @param int $payment_id Payment ID
 * @param string $new_status New cheque status
 * @param string|null $clearance_date Clearance date (for cleared cheques)
 * @param string|null $bounce_reason Bounce reason (for bounced cheques)
 * @param float $bounce_charges Bounce charges (for bounced cheques)
 * @return array Updated invoice data
 */
function updateChequeStatus($db, $payment_id, $new_status, $clearance_date = null, $bounce_reason = null, $bounce_charges = 0) {
    // Get payment info
    $stmt = $db->prepare("SELECT invoice_id FROM sales_payments WHERE id = ?");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch();
    
    if (!$payment) {
        throw new Exception("Payment not found");
    }
    
    // Update cheque status
    $stmt = $db->prepare("
        UPDATE sales_payments 
        SET cheque_status = ?, 
            clearance_date = ?, 
            bounce_reason = ?,
            bounce_charges = ?
        WHERE id = ?
    ");
    $stmt->execute([$new_status, $clearance_date, $bounce_reason, $bounce_charges, $payment_id]);
    
    // Recompute invoice totals
    return recomputeInvoiceTotals($db, $payment['invoice_id']);
}
?>
