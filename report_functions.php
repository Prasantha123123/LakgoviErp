<?php
/**
 * Report Functions - Reusable functions for generating sales reports
 * Used by both web view and export functionality
 */

/**
 * Get Total Sales Report Data
 * @param PDO $db Database connection
 * @param array $filters Array with keys: date_from, date_to, customer_id, status, payment_status
 * @return array ['columns' => [], 'rows' => [], 'summary' => []]
 */
function getTotalSalesReport($db, $filters) {
    $columns = [
        'invoice_no' => 'Invoice No',
        'invoice_date' => 'Date',
        'customer_name' => 'Customer',
        'total_amount' => 'Total Amount',
        'paid_amount' => 'Paid Amount',
        'balance_amount' => 'Balance',
        'payment_status' => 'Payment Status'
    ];
    
    // Build query
    $sql = "SELECT si.id, si.invoice_no, si.invoice_date, 
                   COALESCE(c.customer_name, 'Walk-in Customer') as customer_name,
                   si.total_amount, si.paid_amount, si.balance_amount, si.payment_status, si.status
            FROM sales_invoices si
            LEFT JOIN customers c ON c.id = si.customer_id
            WHERE si.invoice_date BETWEEN :date_from AND :date_to";
    
    $params = [
        ':date_from' => $filters['date_from'],
        ':date_to' => $filters['date_to']
    ];
    
    // Status filter (default to confirmed)
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $sql .= " AND si.status = :status";
        $params[':status'] = $filters['status'];
    } else if (empty($filters['status'])) {
        $sql .= " AND si.status = 'confirmed'";
    }
    
    // Customer filter
    if (!empty($filters['customer_id'])) {
        $sql .= " AND si.customer_id = :customer_id";
        $params[':customer_id'] = $filters['customer_id'];
    }
    
    // Payment status filter
    if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
        $sql .= " AND si.payment_status = :payment_status";
        $params[':payment_status'] = $filters['payment_status'];
    }
    
    $sql .= " ORDER BY si.invoice_date DESC, si.id DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary using PHP aggregation
    $summary = [
        'total_invoices' => 0,
        'total_sales' => 0,
        'total_paid' => 0,
        'total_balance' => 0,
        'by_payment_status' => [
            'paid' => ['count' => 0, 'amount' => 0],
            'partial' => ['count' => 0, 'amount' => 0],
            'unpaid' => ['count' => 0, 'amount' => 0]
        ]
    ];
    
    foreach ($rows as $row) {
        $summary['total_invoices']++;
        $summary['total_sales'] += floatval($row['total_amount']);
        $summary['total_paid'] += floatval($row['paid_amount']);
        $summary['total_balance'] += floatval($row['balance_amount']);
        
        $ps = $row['payment_status'];
        if (isset($summary['by_payment_status'][$ps])) {
            $summary['by_payment_status'][$ps]['count']++;
            $summary['by_payment_status'][$ps]['amount'] += floatval($row['total_amount']);
        }
    }
    
    return [
        'columns' => $columns,
        'rows' => $rows,
        'summary' => $summary
    ];
}

/**
 * Get Customer-wise Sales Report Data
 * @param PDO $db Database connection
 * @param array $filters Array with keys: date_from, date_to, customer_id, status, payment_status
 * @return array ['columns' => [], 'rows' => [], 'totals' => []]
 */
function getCustomerWiseSalesReport($db, $filters) {
    $columns = [
        'customer_code' => 'Customer Code',
        'customer_name' => 'Customer Name',
        'invoices_count' => 'No. of Invoices',
        'total_sales' => 'Total Sales',
        'total_paid' => 'Total Paid',
        'total_balance' => 'Total Balance'
    ];
    
    // First get all invoices matching filters
    $sql = "SELECT si.customer_id, c.customer_code, c.customer_name,
                   si.total_amount, si.paid_amount, si.balance_amount
            FROM sales_invoices si
            JOIN customers c ON c.id = si.customer_id
            WHERE si.invoice_date BETWEEN :date_from AND :date_to";
    
    $params = [
        ':date_from' => $filters['date_from'],
        ':date_to' => $filters['date_to']
    ];
    
    // Status filter
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $sql .= " AND si.status = :status";
        $params[':status'] = $filters['status'];
    } else if (empty($filters['status'])) {
        $sql .= " AND si.status = 'confirmed'";
    }
    
    // Customer filter
    if (!empty($filters['customer_id'])) {
        $sql .= " AND si.customer_id = :customer_id";
        $params[':customer_id'] = $filters['customer_id'];
    }
    
    // Payment status filter
    if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
        $sql .= " AND si.payment_status = :payment_status";
        $params[':payment_status'] = $filters['payment_status'];
    }
    
    $sql .= " ORDER BY c.customer_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Aggregate by customer using PHP
    $customerData = [];
    foreach ($invoices as $inv) {
        $cid = $inv['customer_id'];
        if (!isset($customerData[$cid])) {
            $customerData[$cid] = [
                'customer_id' => $cid,
                'customer_code' => $inv['customer_code'],
                'customer_name' => $inv['customer_name'],
                'invoices_count' => 0,
                'total_sales' => 0,
                'total_paid' => 0,
                'total_balance' => 0
            ];
        }
        $customerData[$cid]['invoices_count']++;
        $customerData[$cid]['total_sales'] += floatval($inv['total_amount']);
        $customerData[$cid]['total_paid'] += floatval($inv['paid_amount']);
        $customerData[$cid]['total_balance'] += floatval($inv['balance_amount']);
    }
    
    // Sort by total_sales descending
    usort($customerData, function($a, $b) {
        return $b['total_sales'] <=> $a['total_sales'];
    });
    
    $rows = array_values($customerData);
    
    // Calculate totals
    $totals = [
        'invoices_count' => 0,
        'total_sales' => 0,
        'total_paid' => 0,
        'total_balance' => 0
    ];
    
    foreach ($rows as $row) {
        $totals['invoices_count'] += $row['invoices_count'];
        $totals['total_sales'] += $row['total_sales'];
        $totals['total_paid'] += $row['total_paid'];
        $totals['total_balance'] += $row['total_balance'];
    }
    
    return [
        'columns' => $columns,
        'rows' => $rows,
        'totals' => $totals
    ];
}

/**
 * Get Item-wise Sales Report Data
 * @param PDO $db Database connection
 * @param array $filters Array with keys: date_from, date_to, customer_id, status, payment_status
 * @return array ['columns' => [], 'rows' => [], 'totals' => []]
 */
function getItemWiseSalesReport($db, $filters) {
    $columns = [
        'item_code' => 'Item Code',
        'item_name' => 'Item Name',
        'total_qty' => 'Total Qty',
        'total_sales' => 'Total Sales',
        'avg_price' => 'Avg. Selling Price',
        'invoices_count' => 'No. of Invoices'
    ];
    
    // Get all invoice items matching filters
    $sql = "SELECT sii.item_id, sii.invoice_id, i.code as item_code, i.name as item_name,
                   sii.quantity, sii.line_total
            FROM sales_invoice_items sii
            JOIN sales_invoices si ON si.id = sii.invoice_id
            JOIN items i ON i.id = sii.item_id
            WHERE si.invoice_date BETWEEN :date_from AND :date_to";
    
    $params = [
        ':date_from' => $filters['date_from'],
        ':date_to' => $filters['date_to']
    ];
    
    // Status filter
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $sql .= " AND si.status = :status";
        $params[':status'] = $filters['status'];
    } else if (empty($filters['status'])) {
        $sql .= " AND si.status = 'confirmed'";
    }
    
    // Customer filter
    if (!empty($filters['customer_id'])) {
        $sql .= " AND si.customer_id = :customer_id";
        $params[':customer_id'] = $filters['customer_id'];
    }
    
    // Payment status filter
    if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
        $sql .= " AND si.payment_status = :payment_status";
        $params[':payment_status'] = $filters['payment_status'];
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Aggregate by item using PHP
    $itemData = [];
    foreach ($items as $item) {
        $iid = $item['item_id'];
        if (!isset($itemData[$iid])) {
            $itemData[$iid] = [
                'item_id' => $iid,
                'item_code' => $item['item_code'],
                'item_name' => $item['item_name'],
                'total_qty' => 0,
                'total_sales' => 0,
                'invoices' => [] // Track unique invoices
            ];
        }
        $itemData[$iid]['total_qty'] += floatval($item['quantity']);
        $itemData[$iid]['total_sales'] += floatval($item['line_total']);
        $itemData[$iid]['invoices'][$item['invoice_id']] = true;
    }
    
    // Calculate avg_price and invoices_count
    foreach ($itemData as &$row) {
        $row['invoices_count'] = count($row['invoices']);
        $row['avg_price'] = $row['total_qty'] > 0 ? $row['total_sales'] / $row['total_qty'] : 0;
        unset($row['invoices']); // Remove temp array
    }
    unset($row);
    
    // Sort by total_sales descending
    usort($itemData, function($a, $b) {
        return $b['total_sales'] <=> $a['total_sales'];
    });
    
    $rows = array_values($itemData);
    
    // Calculate totals
    $totals = [
        'total_qty' => 0,
        'total_sales' => 0,
        'invoices_count' => 0
    ];
    
    foreach ($rows as $row) {
        $totals['total_qty'] += $row['total_qty'];
        $totals['total_sales'] += $row['total_sales'];
    }
    
    // Count unique invoices for total
    $uniqueInvoices = [];
    foreach ($items as $item) {
        $uniqueInvoices[$item['invoice_id']] = true;
    }
    $totals['invoices_count'] = count($uniqueInvoices);
    
    return [
        'columns' => $columns,
        'rows' => $rows,
        'totals' => $totals
    ];
}

/**
 * Get Customer Invoices for Detail View
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @param array $filters Date range and other filters
 * @return array Invoice list
 */
function getCustomerInvoices($db, $customerId, $filters) {
    $sql = "SELECT si.invoice_no, si.invoice_date, si.total_amount, 
                   si.paid_amount, si.balance_amount, si.payment_status
            FROM sales_invoices si
            WHERE si.customer_id = :customer_id
            AND si.invoice_date BETWEEN :date_from AND :date_to";
    
    $params = [
        ':customer_id' => $customerId,
        ':date_from' => $filters['date_from'],
        ':date_to' => $filters['date_to']
    ];
    
    // Status filter
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $sql .= " AND si.status = :status";
        $params[':status'] = $filters['status'];
    } else if (empty($filters['status'])) {
        $sql .= " AND si.status = 'confirmed'";
    }
    
    // Payment status filter
    if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
        $sql .= " AND si.payment_status = :payment_status";
        $params[':payment_status'] = $filters['payment_status'];
    }
    
    $sql .= " ORDER BY si.invoice_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get Item Invoice Details for Detail View
 * @param PDO $db Database connection
 * @param int $itemId Item ID
 * @param array $filters Date range and other filters
 * @return array Invoice items list
 */
function getItemInvoices($db, $itemId, $filters) {
    $sql = "SELECT si.invoice_no, si.invoice_date, 
                   COALESCE(c.customer_name, 'Walk-in Customer') as customer_name,
                   sii.quantity, sii.unit_price, sii.line_total
            FROM sales_invoice_items sii
            JOIN sales_invoices si ON si.id = sii.invoice_id
            LEFT JOIN customers c ON c.id = si.customer_id
            WHERE sii.item_id = :item_id
            AND si.invoice_date BETWEEN :date_from AND :date_to";
    
    $params = [
        ':item_id' => $itemId,
        ':date_from' => $filters['date_from'],
        ':date_to' => $filters['date_to']
    ];
    
    // Status filter
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $sql .= " AND si.status = :status";
        $params[':status'] = $filters['status'];
    } else if (empty($filters['status'])) {
        $sql .= " AND si.status = 'confirmed'";
    }
    
    // Payment status filter
    if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
        $sql .= " AND si.payment_status = :payment_status";
        $params[':payment_status'] = $filters['payment_status'];
    }
    
    $sql .= " ORDER BY si.invoice_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Format currency value
 * @param float $value
 * @return string
 */
function formatCurrency($value) {
    return number_format(floatval($value), 2);
}

/**
 * Format quantity value
 * @param float $value
 * @return string
 */
function formatQuantity($value) {
    return number_format(floatval($value), 2);
}

/**
 * Get filter summary text for reports
 * @param array $filters
 * @param PDO $db
 * @return string
 */
function getFilterSummary($filters, $db = null) {
    $parts = [];
    
    $parts[] = "Period: " . date('d/m/Y', strtotime($filters['date_from'])) . 
               " to " . date('d/m/Y', strtotime($filters['date_to']));
    
    if (!empty($filters['customer_id']) && $db) {
        $stmt = $db->prepare("SELECT customer_name FROM customers WHERE id = ?");
        $stmt->execute([$filters['customer_id']]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            $parts[] = "Customer: " . $customer['customer_name'];
        }
    }
    
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $parts[] = "Status: " . ucfirst($filters['status']);
    } else {
        $parts[] = "Status: Confirmed";
    }
    
    if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
        $parts[] = "Payment: " . ucfirst($filters['payment_status']);
    }
    
    return implode(' | ', $parts);
}

/**
 * Get all customers for dropdown
 * @param PDO $db
 * @return array
 */
function getCustomersForDropdown($db) {
    $stmt = $db->query("SELECT id, customer_code, customer_name FROM customers ORDER BY customer_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
