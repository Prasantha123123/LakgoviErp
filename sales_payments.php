<?php
// sales_payments.php - Unified Sales & Payments Management
include 'header.php';
require_once 'payment_functions.php';

$success = '';
$error = '';
$receipt_id = null; // For receipt modal popup

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'invoices';

// ===== HANDLE POST ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $db->beginTransaction();
        
        if ($_POST['action'] === 'add_payment') {
            $invoice_id = intval($_POST['invoice_id']);
            
            // Get current invoice details
            $stmt = $db->prepare("SELECT * FROM sales_invoices WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $invoice = $stmt->fetch();
            
            if (!$invoice) {
                throw new Exception("Invoice not found");
            }
            
            // Validate payments array
            if (empty($_POST['payments']) || !is_array($_POST['payments'])) {
                throw new Exception("Please add at least one payment line");
            }
            
            // Calculate total of new payments
            $total_new_payment = 0;
            foreach ($_POST['payments'] as $payment) {
                $amount = floatval($payment['amount'] ?? 0);
                if ($amount > 0) {
                    $total_new_payment += $amount;
                }
            }
            
            if ($total_new_payment <= 0) {
                throw new Exception("Total payment amount must be greater than 0");
            }
            
            // Check if payment exceeds balance
            if ($total_new_payment > $invoice['balance_amount']) {
                throw new Exception("Total payment (Rs. " . number_format($total_new_payment, 2) . 
                    ") exceeds invoice balance (Rs. " . number_format($invoice['balance_amount'], 2) . ")");
            }
            
            // Insert each payment line and capture inserted IDs for receipts
            $inserted_ids = insertPaymentLines($db, $invoice_id, $_POST['payments'], 'additional', $_SESSION['user_id']);
            
            // Recompute invoice totals
            $updated = recomputeInvoiceTotals($db, $invoice_id);
            
            $db->commit();
            // Set receipt_id to show modal popup
            if (!empty($inserted_ids)) {
                $receipt_id = intval($inserted_ids[0]);
            }
        }
        
        // ===== OVERALL CUSTOMER PAYMENT - FIFO DISTRIBUTION =====
        if ($_POST['action'] === 'add_customer_payment') {
            $customer_id = intval($_POST['customer_id']);
            $payment_amount = floatval($_POST['payment_amount']);
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $payment_date = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
            $reference_no = !empty($_POST['reference_no']) ? $_POST['reference_no'] : null;
            
            // Cheque fields
            $cheque_number = null;
            $cheque_date = null;
            $bank_name = null;
            $cheque_status = null;
            
            if ($payment_method === 'cheque') {
                $cheque_number = !empty($_POST['cheque_number']) ? $_POST['cheque_number'] : null;
                $cheque_date = !empty($_POST['cheque_date']) ? $_POST['cheque_date'] : null;
                $bank_name = !empty($_POST['cheque_bank_name']) ? $_POST['cheque_bank_name'] : null;
                $cheque_status = 'pending';
            } elseif ($payment_method === 'bank_transfer') {
                $bank_name = !empty($_POST['bank_name']) ? $_POST['bank_name'] : null;
            }
            
            if ($payment_amount <= 0) {
                throw new Exception("Payment amount must be greater than 0");
            }
            
            // Get all pending invoices for this customer (FIFO = oldest first by invoice_date)
            $stmt = $db->prepare("
                SELECT id, invoice_no, balance_amount, invoice_date 
                FROM sales_invoices 
                WHERE customer_id = ? AND status = 'confirmed' AND balance_amount > 0
                ORDER BY invoice_date ASC, id ASC
            ");
            $stmt->execute([$customer_id]);
            $pending_invoices = $stmt->fetchAll();
            
            if (empty($pending_invoices)) {
                throw new Exception("No pending invoices found for this customer");
            }
            
            // Calculate total outstanding
            $total_outstanding = 0;
            foreach ($pending_invoices as $inv) {
                $total_outstanding += floatval($inv['balance_amount']);
            }
            
            // Validate payment doesn't exceed total outstanding
            if ($payment_amount > $total_outstanding) {
                throw new Exception("Payment amount (Rs. " . number_format($payment_amount, 2) . 
                    ") exceeds total outstanding (Rs. " . number_format($total_outstanding, 2) . ")");
            }
            
            // Distribute payment using FIFO (oldest invoices first)
            $first_payment_id = null;
            $remaining_payment = $payment_amount;
            $allocation_details = [];
            
            foreach ($pending_invoices as $inv) {
                if ($remaining_payment <= 0) {
                    break; // No more payment to allocate
                }
                
                $invoice_balance = floatval($inv['balance_amount']);
                
                // Allocate payment to this invoice (min of remaining payment and invoice balance)
                $payment_for_this_invoice = min($remaining_payment, $invoice_balance);
                
                // Create payment record for this invoice
                $payment_no = getNextPaymentNo($db);
                
                $stmt = $db->prepare("
                    INSERT INTO sales_payments (
                        payment_no, invoice_id, payment_date, amount, 
                        payment_method, payment_type, reference_no, 
                        cheque_number, cheque_date, bank_name, cheque_status,
                        created_by
                    ) VALUES (?, ?, ?, ?, ?, 'customer_overall', ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $payment_no,
                    $inv['id'],
                    $payment_date,
                    $payment_for_this_invoice,
                    $payment_method,
                    $reference_no,
                    $cheque_number,
                    $cheque_date,
                    $bank_name,
                    $cheque_status,
                    $_SESSION['user_id']
                ]);
                
                // Track receipt link for the first created payment
                if (!$first_payment_id) {
                    $first_payment_id = $db->lastInsertId();
                }

                // Recompute invoice totals
                $updated_invoice = recomputeInvoiceTotals($db, $inv['id']);
                
                // Track allocation for success message
                $allocation_details[] = [
                    'invoice_no' => $inv['invoice_no'],
                    'amount' => $payment_for_this_invoice,
                    'status' => $updated_invoice['payment_status']
                ];
                
                // Reduce remaining payment
                $remaining_payment -= $payment_for_this_invoice;
            }
            
            $db->commit();

            // Set receipt_id to show modal popup
            if ($first_payment_id) {
                $receipt_id = intval($first_payment_id);
            }
            
            // Set active tab to pending to show results
            $active_tab = 'pending';
        }
        
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// ===== INVOICES TAB DATA =====
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$customer_filter = $_GET['customer'] ?? '';
$payment_status_filter = $_GET['payment_status'] ?? '';
$status_filter = $_GET['status'] ?? '';

try {
    $sql = "SELECT * FROM v_sales_invoice_summary WHERE 1=1";
    $params = [];
    
    if ($date_from) {
        $sql .= " AND invoice_date >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $sql .= " AND invoice_date <= ?";
        $params[] = $date_to;
    }
    
    if ($customer_filter) {
        $sql .= " AND id IN (SELECT id FROM sales_invoices WHERE customer_id = ?)";
        $params[] = $customer_filter;
    }
    
    if ($payment_status_filter) {
        $sql .= " AND payment_status = ?";
        $params[] = $payment_status_filter;
    }
    
    if ($status_filter) {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    $sql .= " ORDER BY invoice_date DESC, created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
    
    // Calculate summary statistics
    $total_sales = 0;
    $total_paid = 0;
    $total_balance = 0;
    
    foreach ($invoices as $inv) {
        $total_sales += $inv['total_amount'];
        $total_paid += $inv['paid_amount'];
        $total_balance += $inv['balance_amount'];
    }
    
} catch(PDOException $e) {
    $invoices = [];
    $total_sales = 0;
    $total_paid = 0;
    $total_balance = 0;
}

// ===== PENDING BILLS TAB DATA =====
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$pending_invoices = [];
$selected_customer = null;

try {
    $stmt = $db->query("
        SELECT c.*, 
               COUNT(DISTINCT CASE WHEN si.payment_status != 'paid' AND si.status = 'confirmed' THEN si.id END) as pending_count,
               COALESCE(SUM(CASE WHEN si.payment_status != 'paid' AND si.status = 'confirmed' THEN si.balance_amount ELSE 0 END), 0) as total_pending
        FROM customers c
        LEFT JOIN sales_invoices si ON c.id = si.customer_id
        WHERE c.is_active = 1
    ");
    // Note: We'll calculate aggregates manually to avoid GROUP BY
    $customers_raw = $stmt->fetchAll();
} catch(PDOException $e) {
    $customers_raw = [];
}

// Manually aggregate customer data
$customers_map = [];
try {
    $stmt = $db->query("SELECT id, customer_code, customer_name, phone, is_active FROM customers WHERE is_active = 1 ORDER BY customer_name");
    $all_customers = $stmt->fetchAll();
    
    foreach ($all_customers as $c) {
        $customers_map[$c['id']] = [
            'id' => $c['id'],
            'customer_code' => $c['customer_code'],
            'customer_name' => $c['customer_name'],
            'phone' => $c['phone'],
            'pending_count' => 0,
            'total_pending' => 0
        ];
    }
    
    // Get pending invoices count and total per customer
    $stmt = $db->query("
        SELECT customer_id, id, balance_amount 
        FROM sales_invoices 
        WHERE payment_status != 'paid' AND status = 'confirmed' AND balance_amount > 0
    ");
    $pending_rows = $stmt->fetchAll();
    
    foreach ($pending_rows as $row) {
        if (isset($customers_map[$row['customer_id']])) {
            $customers_map[$row['customer_id']]['pending_count']++;
            $customers_map[$row['customer_id']]['total_pending'] += floatval($row['balance_amount']);
        }
    }
    
    // Sort by total_pending descending
    uasort($customers_map, function($a, $b) {
        return $b['total_pending'] <=> $a['total_pending'];
    });
    
} catch(PDOException $e) {
    $customers_map = [];
}

$customers = array_values($customers_map);

// Calculate totals for pending bills
$total_outstanding = 0;
$total_pending_count = 0;
foreach ($customers as $c) {
    $total_outstanding += floatval($c['total_pending']);
    $total_pending_count += intval($c['pending_count']);
}

// Store original values for display
$display_total_sales = $total_sales;
$display_total_paid = $total_paid;
$display_total_balance = $total_balance;
$display_invoice_count = count($invoices);

// If customer selected, get their pending invoices and update summary cards
if ($customer_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $selected_customer = $stmt->fetch();
        
        if ($selected_customer) {
            $pending_invoices = getCustomerPendingInvoices($db, $customer_id);
            
            // Override summary cards with selected customer's data
            $stmt = $db->prepare("
                SELECT 
                    COALESCE(SUM(total_amount), 0) as cust_total_sales,
                    COALESCE(SUM(paid_amount), 0) as cust_total_paid,
                    COALESCE(SUM(balance_amount), 0) as cust_total_balance,
                    COUNT(*) as cust_invoice_count
                FROM sales_invoices 
                WHERE customer_id = ? AND status = 'confirmed'
            ");
            $stmt->execute([$customer_id]);
            $cust_summary = $stmt->fetch();
            
            // Update the display variables for summary cards
            $display_total_sales = floatval($cust_summary['cust_total_sales']);
            $display_total_paid = floatval($cust_summary['cust_total_paid']);
            $display_total_balance = floatval($cust_summary['cust_total_balance']);
            $display_invoice_count = intval($cust_summary['cust_invoice_count']);
        }
    } catch(PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
    $active_tab = 'pending';
}

// ===== OUTSTANDING TAB DATA =====
try {
    $stmt = $db->query("
        SELECT si.id, si.invoice_no, si.invoice_date, si.total_amount, si.paid_amount, si.balance_amount,
               c.customer_code, c.customer_name
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        WHERE si.balance_amount > 0 AND si.status = 'confirmed'
        ORDER BY si.invoice_date DESC
        LIMIT 100
    ");
    $outstanding_invoices = $stmt->fetchAll();
} catch(PDOException $e) {
    $outstanding_invoices = [];
}

// Fetch customers for filter dropdown
try {
    $stmt = $db->query("SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name");
    $customer_list = $stmt->fetchAll();
} catch(PDOException $e) {
    $customer_list = [];
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">📊 Sales & Payments</h1>
            <p class="text-gray-600">Manage invoices, payments, and outstanding balances</p>
        </div>
        <a href="cashier.php" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600">
            + New Sale
        </a>
    </div>

    <!-- Messages -->
    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <?php if ($selected_customer): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center">
                <span class="text-2xl mr-2">👤</span>
                <div>
                    <p class="font-semibold text-blue-900"><?php echo htmlspecialchars($selected_customer['customer_name']); ?></p>
                    <p class="text-sm text-blue-600"><?php echo htmlspecialchars($selected_customer['customer_code']); ?></p>
                </div>
            </div>
            <a href="?tab=pending" class="text-sm text-blue-600 hover:underline">× Clear Filter</a>
        </div>
    </div>
    <?php endif; ?>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90"><?php echo $selected_customer ? 'Customer Sales' : 'Total Sales'; ?></div>
                    <div class="text-2xl font-bold mt-2">Rs. <?php echo number_format($display_total_sales, 2); ?></div>
                    <div class="text-sm opacity-75 mt-1"><?php echo $display_invoice_count; ?> invoices</div>
                </div>
                <div class="text-4xl opacity-75">💰</div>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90"><?php echo $selected_customer ? 'Customer Paid' : 'Total Paid'; ?></div>
                    <div class="text-2xl font-bold mt-2">Rs. <?php echo number_format($display_total_paid, 2); ?></div>
                    <div class="text-sm opacity-75 mt-1">
                        <?php echo $display_total_sales > 0 ? round(($display_total_paid / $display_total_sales) * 100, 1) : 0; ?>% collected
                    </div>
                </div>
                <div class="text-4xl opacity-75">✅</div>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-red-500 to-red-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90"><?php echo $selected_customer ? 'Customer Due' : 'Outstanding'; ?></div>
                    <div class="text-2xl font-bold mt-2">Rs. <?php echo number_format($display_total_balance, 2); ?></div>
                    <div class="text-sm opacity-75 mt-1">Pending collection</div>
                </div>
                <div class="text-4xl opacity-75">⏳</div>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Avg Invoice</div>
                    <div class="text-2xl font-bold mt-2">
                        Rs. <?php echo $display_invoice_count > 0 ? number_format($display_total_sales / $display_invoice_count, 2) : '0.00'; ?>
                    </div>
                    <div class="text-sm opacity-75 mt-1">Per transaction</div>
                </div>
                <div class="text-4xl opacity-75">📈</div>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            <a href="?tab=invoices<?php echo $date_from ? '&date_from='.$date_from : ''; ?><?php echo $date_to ? '&date_to='.$date_to : ''; ?>" 
               class="<?php echo $active_tab === 'invoices' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> 
                      whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center">
                📋 All Invoices
                <span class="ml-2 px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-600">
                    <?php echo count($invoices); ?>
                </span>
            </a>
            <a href="?tab=outstanding" 
               class="<?php echo $active_tab === 'outstanding' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> 
                      whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center">
                💳 Outstanding
                <span class="ml-2 px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-600">
                    <?php echo count($outstanding_invoices); ?>
                </span>
            </a>
            <a href="?tab=pending" 
               class="<?php echo $active_tab === 'pending' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> 
                      whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center">
                👥 By Customer
                <span class="ml-2 px-2 py-0.5 text-xs rounded-full bg-orange-100 text-orange-600">
                    <?php echo count(array_filter($customers, function($c) { return $c['pending_count'] > 0; })); ?>
                </span>
            </a>
        </nav>
    </div>

    <!-- Tab Content -->
    <?php if ($active_tab === 'invoices'): ?>
    <!-- ===== ALL INVOICES TAB ===== -->
    <div class="bg-white rounded-lg shadow p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
            <input type="hidden" name="tab" value="invoices">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                <select name="customer" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">All Customers</option>
                    <?php foreach ($customer_list as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" 
                                <?php echo $customer_filter == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['customer_code'] . ' - ' . $customer['customer_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Status</label>
                <select name="payment_status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">All</option>
                    <option value="paid" <?php echo $payment_status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="partial" <?php echo $payment_status_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="unpaid" <?php echo $payment_status_filter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">All</option>
                    <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            
            <div class="flex items-end space-x-2">
                <button type="submit" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 text-sm">
                    🔍 Filter
                </button>
                <a href="?tab=invoices" class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 text-sm">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice No</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Payment</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($invoices)): ?>
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                <div class="text-4xl mb-2">📋</div>
                                <p>No invoices found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $invoice): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" 
                                       class="text-primary hover:underline font-medium">
                                        <?php echo htmlspecialchars($invoice['invoice_no']); ?>
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($invoice['customer_name']); ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo htmlspecialchars($invoice['customer_code']); ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right font-medium">
                                    Rs. <?php echo number_format($invoice['total_amount'], 2); ?>
                                </td>
                                <td class="px-4 py-3 text-right text-green-600 font-medium">
                                    Rs. <?php echo number_format($invoice['paid_amount'], 2); ?>
                                </td>
                                <td class="px-4 py-3 text-right <?php echo $invoice['balance_amount'] > 0 ? 'text-red-600' : 'text-gray-400'; ?> font-medium">
                                    Rs. <?php echo number_format($invoice['balance_amount'], 2); ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php
                                    $badgeClass = 'bg-red-100 text-red-800';
                                    $badgeText = 'Unpaid';
                                    
                                    if ($invoice['payment_status'] === 'paid') {
                                        $badgeClass = 'bg-green-100 text-green-800';
                                        $badgeText = 'Paid';
                                    } elseif ($invoice['payment_status'] === 'partial') {
                                        $badgeClass = 'bg-yellow-100 text-yellow-800';
                                        $badgeText = 'Partial';
                                    }
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $badgeClass; ?>">
                                        <?php echo $badgeText; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex justify-center space-x-2">
                                        <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" 
                                           class="text-blue-600 hover:text-blue-800" title="View">
                                            👁️
                                        </a>
                                        <button onclick="openPaymentHistory(<?php echo $invoice['id']; ?>)" 
                                                class="text-purple-600 hover:text-purple-800" title="Payment History">
                                            📜
                                        </button>
                                        <?php if ($invoice['balance_amount'] > 0 && $invoice['status'] === 'confirmed'): ?>
                                        <button onclick="openPaymentModal(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['invoice_no']); ?>', <?php echo $invoice['balance_amount']; ?>)" 
                                                class="text-green-600 hover:text-green-800" title="Add Payment">
                                            💳
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($invoices)): ?>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="3" class="px-4 py-3 text-right font-semibold text-gray-700">TOTALS:</td>
                            <td class="px-4 py-3 text-right font-bold text-gray-900">Rs. <?php echo number_format($total_sales, 2); ?></td>
                            <td class="px-4 py-3 text-right font-bold text-green-600">Rs. <?php echo number_format($total_paid, 2); ?></td>
                            <td class="px-4 py-3 text-right font-bold text-red-600">Rs. <?php echo number_format($total_balance, 2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <?php elseif ($active_tab === 'outstanding'): ?>
    <!-- ===== OUTSTANDING TAB ===== -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="p-4 border-b">
            <h2 class="text-lg font-semibold text-gray-900">Outstanding Invoices</h2>
            <p class="text-sm text-gray-500">Invoices with pending balance - click "+ Add Payment" to receive payment</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice No</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($outstanding_invoices)): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                <div class="text-4xl mb-2">✅</div>
                                <p>No outstanding invoices! All paid up.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($outstanding_invoices as $inv): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <a href="print_invoice.php?id=<?php echo $inv['id']; ?>" class="text-primary hover:underline font-medium">
                                        <?php echo htmlspecialchars($inv['invoice_no']); ?>
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?php echo date('d M Y', strtotime($inv['invoice_date'])); ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($inv['customer_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($inv['customer_code']); ?></div>
                                </td>
                                <td class="px-4 py-3 text-right">Rs. <?php echo number_format($inv['total_amount'], 2); ?></td>
                                <td class="px-4 py-3 text-right text-green-600">Rs. <?php echo number_format($inv['paid_amount'], 2); ?></td>
                                <td class="px-4 py-3 text-right text-red-600 font-semibold">Rs. <?php echo number_format($inv['balance_amount'], 2); ?></td>
                                <td class="px-4 py-3 text-center">
                                    <button onclick="openPaymentModal(<?php echo $inv['id']; ?>, '<?php echo htmlspecialchars($inv['invoice_no']); ?>', <?php echo $inv['balance_amount']; ?>)"
                                            class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">
                                        + Add Payment
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($active_tab === 'pending'): ?>
    <!-- ===== BY CUSTOMER TAB ===== -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Customer List (Left) -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-4 border-b">
                <h2 class="text-lg font-semibold text-gray-900">Customers with Dues</h2>
                <input type="text" id="customerSearch" placeholder="Search customer..." 
                       onkeyup="filterCustomers()"
                       class="mt-2 w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            
            <div class="max-h-[500px] overflow-y-auto" id="customerList">
                <?php foreach ($customers as $c): ?>
                    <?php if ($c['pending_count'] > 0 || $c['id'] == $customer_id): ?>
                    <a href="?tab=pending&customer_id=<?php echo $c['id']; ?>" 
                       class="customer-item block p-4 border-b hover:bg-gray-50 <?php echo $c['id'] == $customer_id ? 'bg-blue-50 border-l-4 border-l-primary' : ''; ?>"
                       data-name="<?php echo strtolower($c['customer_name'] . ' ' . $c['customer_code']); ?>">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($c['customer_name']); ?></p>
                                <p class="text-sm text-gray-500"><?php echo htmlspecialchars($c['customer_code']); ?></p>
                            </div>
                            <div class="text-right">
                                <?php if ($c['pending_count'] > 0): ?>
                                    <p class="font-semibold text-red-600">Rs. <?php echo number_format($c['total_pending'], 2); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo $c['pending_count']; ?> invoice(s)</p>
                                <?php else: ?>
                                    <span class="px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full">No dues</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pending Invoices (Right) -->
        <div class="lg:col-span-2">
            <?php if ($selected_customer): ?>
            <div class="bg-white rounded-lg shadow">
                <div class="p-4 border-b bg-gray-50">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900">
                                <?php echo htmlspecialchars($selected_customer['customer_name']); ?>
                            </h2>
                            <p class="text-sm text-gray-500">
                                <?php echo htmlspecialchars($selected_customer['customer_code']); ?>
                                <?php if ($selected_customer['phone']): ?>
                                    | <?php echo htmlspecialchars($selected_customer['phone']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Total Outstanding</p>
                            <p class="text-2xl font-bold text-red-600 mb-2">
                                Rs. <?php 
                                $customer_total = 0;
                                foreach ($pending_invoices as $pinv) {
                                    $customer_total += floatval($pinv['balance_amount']);
                                }
                                echo number_format($customer_total, 2); 
                                ?>
                            </p>
                            <?php if ($customer_total > 0): ?>
                            <button onclick="openOverallPaymentModal(<?php echo $selected_customer['id']; ?>, '<?php echo htmlspecialchars($selected_customer['customer_name']); ?>', <?php echo $customer_total; ?>)"
                                    class="bg-purple-600 text-white px-4 py-2 rounded-md text-sm hover:bg-purple-700 font-medium">
                                💰 Overall Payment
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="p-4">
                    <?php if (empty($pending_invoices)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <div class="text-4xl mb-2">✅</div>
                            <p>No pending invoices for this customer</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($pending_invoices as $pinv): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3">
                                                <a href="print_invoice.php?id=<?php echo $pinv['id']; ?>" class="text-primary hover:underline font-medium">
                                                    <?php echo htmlspecialchars($pinv['invoice_no']); ?>
                                                </a>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-600">
                                                <?php echo date('d M Y', strtotime($pinv['invoice_date'])); ?>
                                            </td>
                                            <td class="px-4 py-3 text-right">Rs. <?php echo number_format($pinv['total_amount'], 2); ?></td>
                                            <td class="px-4 py-3 text-right text-green-600">Rs. <?php echo number_format($pinv['paid_amount'], 2); ?></td>
                                            <td class="px-4 py-3 text-right text-red-600 font-semibold">Rs. <?php echo number_format($pinv['balance_amount'], 2); ?></td>
                                            <td class="px-4 py-3 text-center">
                                                <button onclick="openPaymentModal(<?php echo $pinv['id']; ?>, '<?php echo htmlspecialchars($pinv['invoice_no']); ?>', <?php echo $pinv['balance_amount']; ?>)"
                                                        class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">
                                                    Pay Now
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                <div class="text-6xl mb-4">👈</div>
                <h3 class="text-lg font-medium">Select a Customer</h3>
                <p class="text-sm mt-2">Click on a customer from the list to view their pending invoices</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4" style="overflow-y: auto;">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg my-auto" style="max-height: 90vh; display: flex; flex-direction: column;">
        <div class="p-4 border-b flex justify-between items-center" style="flex-shrink: 0;">
            <h3 class="text-lg font-semibold text-gray-900">💳 Add Payment</h3>
            <button onclick="closeModal('paymentModal')" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" style="display: flex; flex-direction: column; flex: 1; overflow: hidden;">
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="invoice_id" id="modal_invoice_id">
            
            <div class="p-4 space-y-4" style="overflow-y: auto; flex: 1;">
                <div class="bg-blue-50 p-3 rounded-md">
                    <p class="text-sm text-gray-600">Invoice: <span id="modal_invoice_no" class="font-semibold"></span></p>
                    <p class="text-sm text-gray-600">Balance: <span id="modal_balance" class="font-semibold text-red-600"></span></p>
                </div>
                
                <div id="paymentLinesContainer">
                    <!-- Payment lines will be added here -->
                </div>
                
                <button type="button" onclick="addPaymentLine()" class="text-primary hover:underline text-sm">
                    + Add Another Payment Method
                </button>
            </div>
            
            <div class="p-4 border-t flex justify-end space-x-2 bg-white" style="flex-shrink: 0;">
                <button type="button" onclick="closeModal('paymentModal')" class="px-4 py-2 border rounded-md hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                    Save Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Payment History Modal -->
<div id="historyModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center overflow-y-auto">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4 my-8">
        <div class="p-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="text-lg font-semibold text-gray-900">📜 Payment History & Tracking</h3>
            <button onclick="closeModal('historyModal')" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <div class="p-4 max-h-[70vh] overflow-y-auto" id="historyContent">
            <p class="text-center text-gray-500">Loading...</p>
        </div>
        <div class="p-4 border-t flex justify-end sticky bottom-0 bg-white">
            <button onclick="closeModal('historyModal')" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Overall Customer Payment Modal -->
<div id="overallPaymentModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center overflow-y-auto py-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 my-auto max-h-[95vh] flex flex-col">
        <div class="p-4 border-b flex justify-between items-center bg-purple-50 flex-shrink-0">
            <h3 class="text-lg font-semibold text-purple-900">💰 Overall Customer Payment</h3>
            <button onclick="closeModal('overallPaymentModal')" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <form method="POST" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="add_customer_payment">
            <input type="hidden" name="customer_id" id="overall_customer_id">
            
            <div class="p-4 space-y-4 overflow-y-auto flex-1">
                <div class="bg-purple-50 p-4 rounded-md">
                    <p class="text-sm text-gray-600">Customer: <span id="overall_customer_name" class="font-semibold text-purple-800"></span></p>
                    <p class="text-sm text-gray-600 mt-1">Total Outstanding: <span id="overall_total_outstanding" class="font-semibold text-red-600"></span></p>
                </div>
                
                <div class="bg-blue-50 p-3 rounded-md text-sm text-blue-800">
                    <strong>💡 FIFO Allocation:</strong> Payment will be applied to oldest invoices first
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Amount <span class="text-red-500">*</span></label>
                    <input type="number" name="payment_amount" id="overall_payment_amount" 
                           step="0.01" min="0.01" required
                           class="w-full px-3 py-2 border rounded-md text-right text-lg font-semibold">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date</label>
                    <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>"
                           class="w-full px-3 py-2 border rounded-md">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                    <select name="payment_method" id="overall_payment_method" onchange="toggleOverallChequeFields()"
                            class="w-full px-3 py-2 border rounded-md">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference No.</label>
                    <input type="text" name="reference_no" placeholder="Optional"
                           class="w-full px-3 py-2 border rounded-md">
                </div>
                
                <div id="overall_bank_fields" class="hidden bg-green-50 p-3 rounded-md">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bank Name</label>
                    <input type="text" name="bank_name" placeholder="Enter bank name"
                           class="w-full px-3 py-2 border rounded-md">
                </div>
                
                <div id="overall_cheque_fields" class="hidden bg-blue-50 p-3 rounded-md space-y-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cheque Number</label>
                        <input type="text" name="cheque_number" placeholder="Cheque number"
                               class="w-full px-3 py-2 border rounded-md">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cheque Date</label>
                        <input type="date" name="cheque_date"
                               class="w-full px-3 py-2 border rounded-md">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bank Name</label>
                        <input type="text" name="cheque_bank_name" placeholder="Bank name"
                               class="w-full px-3 py-2 border rounded-md">
                    </div>
                </div>
            </div>
            
            <div class="p-4 border-t flex justify-end space-x-2 flex-shrink-0">
                <button type="button" onclick="closeModal('overallPaymentModal')" class="px-4 py-2 border rounded-md hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700">
                    💰 Apply Payment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let paymentLineCounter = 0;
let currentBalance = 0;

function openPaymentModal(invoiceId, invoiceNo, balance) {
    document.getElementById('modal_invoice_id').value = invoiceId;
    document.getElementById('modal_invoice_no').textContent = invoiceNo;
    document.getElementById('modal_balance').textContent = 'Rs. ' + parseFloat(balance).toFixed(2);
    currentBalance = parseFloat(balance);
    
    // Clear and add first payment line
    document.getElementById('paymentLinesContainer').innerHTML = '';
    paymentLineCounter = 0;
    addPaymentLine();
    
    document.getElementById('paymentModal').classList.remove('hidden');
}

function addPaymentLine() {
    const container = document.getElementById('paymentLinesContainer');
    
    // Calculate remaining balance by subtracting existing payment amounts
    let totalEntered = 0;
    const existingAmounts = container.querySelectorAll('input[name*="[amount]"]');
    existingAmounts.forEach(input => {
        totalEntered += parseFloat(input.value) || 0;
    });
    const remaining = Math.max(0, currentBalance - totalEntered);
    
    const div = document.createElement('div');
    div.className = 'payment-line bg-gray-50 p-3 rounded-md mb-2';
    div.id = `payment_line_${paymentLineCounter}`;
    div.innerHTML = `
        <div class="flex justify-between items-center mb-2">
            <span class="text-sm font-medium">Payment #${paymentLineCounter + 1}</span>
            ${paymentLineCounter > 0 ? `<button type="button" onclick="removePaymentLine(${paymentLineCounter})" class="text-red-500 hover:text-red-700 text-sm">Remove</button>` : ''}
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-xs text-gray-600">Method</label>
                <select name="payments[${paymentLineCounter}][method]" 
                        onchange="toggleChequeFields(this, ${paymentLineCounter})"
                        class="w-full px-2 py-1 border rounded text-sm">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600">Amount</label>
                <input type="number" name="payments[${paymentLineCounter}][amount]" 
                       value="${remaining.toFixed(2)}" step="0.01" min="0"
                       class="w-full px-2 py-1 border rounded text-sm text-right">
            </div>
        </div>
        <div class="mt-2">
            <label class="block text-xs text-gray-600">Reference</label>
            <input type="text" name="payments[${paymentLineCounter}][reference_no]" 
                   placeholder="Optional" class="w-full px-2 py-1 border rounded text-sm">
        </div>
        <div id="bank_fields_${paymentLineCounter}" class="hidden mt-2 bg-green-50 p-2 rounded">
            <input type="text" name="payments[${paymentLineCounter}][bank_name]" 
                   placeholder="Bank Name" class="w-full px-2 py-1 border rounded text-sm">
        </div>
        <div id="cheque_fields_${paymentLineCounter}" class="hidden mt-2 bg-blue-50 p-2 rounded grid grid-cols-1 gap-1">
            <input type="text" name="payments[${paymentLineCounter}][cheque_number]" 
                   placeholder="Cheque Number" class="w-full px-2 py-1 border rounded text-sm">
            <input type="date" name="payments[${paymentLineCounter}][cheque_date]" 
                   class="w-full px-2 py-1 border rounded text-sm">
            <input type="text" name="payments[${paymentLineCounter}][cheque_bank_name]" 
                   placeholder="Bank Name" class="w-full px-2 py-1 border rounded text-sm">
            <input type="hidden" name="payments[${paymentLineCounter}][cheque_status]" value="pending">
        </div>
    `;
    container.appendChild(div);
    paymentLineCounter++;
}

function removePaymentLine(lineId) {
    const element = document.getElementById(`payment_line_${lineId}`);
    if (element) {
        element.remove();
    }
}

function toggleChequeFields(select, lineId) {
    const chequeFields = document.getElementById(`cheque_fields_${lineId}`);
    const bankFields = document.getElementById(`bank_fields_${lineId}`);
    
    // Hide all extra fields first
    chequeFields.classList.add('hidden');
    bankFields.classList.add('hidden');
    
    // Show relevant fields based on selection
    if (select.value === 'cheque') {
        chequeFields.classList.remove('hidden');
    } else if (select.value === 'bank_transfer') {
        bankFields.classList.remove('hidden');
    }
}

function openPaymentHistory(invoiceId) {
    document.getElementById('historyModal').classList.remove('hidden');
    document.getElementById('historyContent').innerHTML = '<p class="text-center text-gray-500">Loading...</p>';
    
    // Fetch payment history via AJAX
    fetch(`invoice_payment_history.php?invoice_id=${invoiceId}&ajax=1`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text();
        })
        .then(html => {
            document.getElementById('historyContent').innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('historyContent').innerHTML = '<p class="text-center text-red-500">Error loading payment history: ' + error.message + '</p>';
        });
}

// ===== OVERALL CUSTOMER PAYMENT FUNCTIONS =====
let overallTotalOutstanding = 0;

function openOverallPaymentModal(customerId, customerName, totalOutstanding) {
    document.getElementById('overall_customer_id').value = customerId;
    document.getElementById('overall_customer_name').textContent = customerName;
    document.getElementById('overall_total_outstanding').textContent = 'Rs. ' + parseFloat(totalOutstanding).toFixed(2);
    overallTotalOutstanding = parseFloat(totalOutstanding);
    
    // Set max amount to total outstanding
    const amountInput = document.getElementById('overall_payment_amount');
    amountInput.value = totalOutstanding.toFixed(2);
    amountInput.max = totalOutstanding;
    
    // Reset payment method
    document.getElementById('overall_payment_method').value = 'cash';
    toggleOverallChequeFields();
    
    document.getElementById('overallPaymentModal').classList.remove('hidden');
}

function toggleOverallChequeFields() {
    const method = document.getElementById('overall_payment_method').value;
    const chequeFields = document.getElementById('overall_cheque_fields');
    const bankFields = document.getElementById('overall_bank_fields');
    
    // Hide all first
    chequeFields.classList.add('hidden');
    bankFields.classList.add('hidden');
    
    // Show relevant fields
    if (method === 'cheque') {
        chequeFields.classList.remove('hidden');
    } else if (method === 'bank_transfer') {
        bankFields.classList.remove('hidden');
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

function filterCustomers() {
    const search = document.getElementById('customerSearch').value.toLowerCase();
    const items = document.querySelectorAll('.customer-item');
    
    items.forEach(item => {
        const name = item.getAttribute('data-name');
        if (name.includes(search)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('fixed')) {
        event.target.classList.add('hidden');
    }
}

// ===== RECEIPT MODAL FUNCTIONS =====
function openReceiptModal(receiptId) {
    document.getElementById('receiptIframe').src = 'print_payment_receipt.php?id=' + receiptId;
    document.getElementById('receiptModal').classList.remove('hidden');
}

function closeReceiptModal() {
    document.getElementById('receiptModal').classList.add('hidden');
    document.getElementById('receiptIframe').src = '';
}

function printReceipt() {
    document.getElementById('receiptIframe').contentWindow.print();
}

function downloadReceipt() {
    document.getElementById('receiptIframe').contentWindow.print();
}

// Auto-open receipt modal after successful payment
<?php if ($receipt_id): ?>
document.addEventListener('DOMContentLoaded', function() {
    openReceiptModal(<?php echo $receipt_id; ?>);
});
<?php endif; ?>
</script>

<!-- Receipt Modal -->
<div id="receiptModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-6xl" style="height: 85vh; display: flex; flex-direction: column;">
        <div class="p-4 border-b flex justify-between items-center bg-green-50 flex-shrink-0">
            <h3 class="text-lg font-semibold text-green-800">✅ Payment Successful - Receipt</h3>
            <button onclick="closeReceiptModal()" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <div class="flex-1 overflow-hidden">
            <iframe id="receiptIframe" src="" class="w-full h-full border-0"></iframe>
        </div>
        <div class="p-4 border-t flex justify-between items-center bg-gray-50 flex-shrink-0">
            <span class="text-sm text-gray-600">💡 Use the buttons below to print or download the receipt</span>
            <div class="flex space-x-2">
                <button onclick="printReceipt()" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    🖨️ Print
                </button>
                <button onclick="downloadReceipt()" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                    ⬇️ Download PDF
                </button>
                <button onclick="closeReceiptModal()" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
