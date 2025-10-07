<?php
// sales_list.php - View all sales invoices
include 'header.php';

$error = '';
$success = '';

// Handle filters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$customer_filter = $_GET['customer'] ?? '';
$payment_status_filter = $_GET['payment_status'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Fetch sales invoices with filters
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
    
    foreach ($invoices as $invoice) {
        $total_sales += $invoice['total_amount'];
        $total_paid += $invoice['paid_amount'];
        $total_balance += $invoice['balance_amount'];
    }
    
} catch(PDOException $e) {
    $error = "Error fetching invoices: " . $e->getMessage();
    $invoices = [];
    $total_sales = 0;
    $total_paid = 0;
    $total_balance = 0;
}

// Fetch customers for filter
try {
    $stmt = $db->query("SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name");
    $customers = $stmt->fetchAll();
} catch(PDOException $e) {
    $customers = [];
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">📊 Sales Invoices</h1>
            <p class="text-gray-600">View and manage all sales transactions</p>
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
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Total Sales</div>
                    <div class="text-2xl font-bold mt-2">Rs. <?php echo number_format($total_sales, 2); ?></div>
                    <div class="text-sm opacity-75 mt-1"><?php echo count($invoices); ?> invoices</div>
                </div>
                <div class="text-4xl opacity-75">
                    💰
                </div>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Total Paid</div>
                    <div class="text-2xl font-bold mt-2">Rs. <?php echo number_format($total_paid, 2); ?></div>
                    <div class="text-sm opacity-75 mt-1">
                        <?php echo $total_sales > 0 ? round(($total_paid / $total_sales) * 100, 1) : 0; ?>% collected
                    </div>
                </div>
                <div class="text-4xl opacity-75">
                    ✅
                </div>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-red-500 to-red-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Outstanding</div>
                    <div class="text-2xl font-bold mt-2">Rs. <?php echo number_format($total_balance, 2); ?></div>
                    <div class="text-sm opacity-75 mt-1">Pending collection</div>
                </div>
                <div class="text-4xl opacity-75">
                    ⏳
                </div>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Avg Invoice</div>
                    <div class="text-2xl font-bold mt-2">
                        Rs. <?php echo count($invoices) > 0 ? number_format($total_sales / count($invoices), 2) : '0.00'; ?>
                    </div>
                    <div class="text-sm opacity-75 mt-1">Per transaction</div>
                </div>
                <div class="text-4xl opacity-75">
                    📈
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
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
                    <?php foreach ($customers as $customer): ?>
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
                <a href="sales_list.php" class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 text-sm">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Invoices Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice No</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Items</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Payment</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($invoices)): ?>
                        <tr>
                            <td colspan="10" class="px-4 py-8 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <svg class="w-16 h-16 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    <p class="text-lg font-medium">No invoices found</p>
                                    <p class="text-sm text-gray-400 mt-1">Try adjusting your filters or create a new sale</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $invoice): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
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
                                        <?php if ($invoice['city']): ?>
                                            • <?php echo htmlspecialchars($invoice['city']); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        <?php echo $invoice['total_items']; ?> items
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-medium">
                                    <span class="text-gray-900">Rs. <?php echo number_format($invoice['total_amount'], 2); ?></span>
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
                                    $badgeIcon = '❌';
                                    
                                    if ($invoice['payment_status'] === 'paid') {
                                        $badgeClass = 'bg-green-100 text-green-800';
                                        $badgeText = 'Paid';
                                        $badgeIcon = '✅';
                                    } elseif ($invoice['payment_status'] === 'partial') {
                                        $badgeClass = 'bg-yellow-100 text-yellow-800';
                                        $badgeText = 'Partial';
                                        $badgeIcon = '⏳';
                                    }
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $badgeClass; ?>">
                                        <?php echo $badgeIcon; ?> <?php echo $badgeText; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php
                                    $statusBadgeClass = 'bg-blue-100 text-blue-800';
                                    $statusText = 'Confirmed';
                                    
                                    if ($invoice['status'] === 'draft') {
                                        $statusBadgeClass = 'bg-gray-100 text-gray-800';
                                        $statusText = 'Draft';
                                    } elseif ($invoice['status'] === 'cancelled') {
                                        $statusBadgeClass = 'bg-red-100 text-red-800';
                                        $statusText = 'Cancelled';
                                    }
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $statusBadgeClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex justify-center space-x-2">
                                        <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" 
                                           class="text-blue-600 hover:text-blue-800 transition-colors" title="View/Print">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </a>
                                        <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" 
                                           class="text-green-600 hover:text-green-800 transition-colors" title="Print">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                                      d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($invoices)): ?>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-semibold text-gray-700">
                                TOTALS:
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-gray-900">
                                Rs. <?php echo number_format($total_sales, 2); ?>
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-green-600">
                                Rs. <?php echo number_format($total_paid, 2); ?>
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-red-600">
                                Rs. <?php echo number_format($total_balance, 2); ?>
                            </td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- Quick Stats -->
    <?php if (!empty($invoices)): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Statistics</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center">
                    <div class="text-2xl font-bold text-blue-600">
                        <?php 
                        $paid_count = count(array_filter($invoices, function($inv) { 
                            return $inv['payment_status'] === 'paid'; 
                        }));
                        echo $paid_count;
                        ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1">Fully Paid</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-yellow-600">
                        <?php 
                        $partial_count = count(array_filter($invoices, function($inv) { 
                            return $inv['payment_status'] === 'partial'; 
                        }));
                        echo $partial_count;
                        ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1">Partial Paid</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-red-600">
                        <?php 
                        $unpaid_count = count(array_filter($invoices, function($inv) { 
                            return $inv['payment_status'] === 'unpaid'; 
                        }));
                        echo $unpaid_count;
                        ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1">Unpaid</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-purple-600">
                        <?php 
                        $total_qty = array_sum(array_column($invoices, 'total_quantity'));
                        echo number_format($total_qty, 0);
                        ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1">Total Units Sold</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>