<?php
// payment_history.php - Payment History grouped by payment_no
include 'header.php';
require_once 'payment_functions.php';

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$payment_method = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';

// Build query - Group by payment_no to show one row per batch
$sql = "
    SELECT 
        sp.payment_no,
        MIN(sp.payment_date) as payment_date,
        SUM(sp.amount) as total_amount,
        COUNT(sp.id) as payment_count,
        GROUP_CONCAT(DISTINCT sp.payment_method) as payment_methods,
        GROUP_CONCAT(DISTINCT si.invoice_no) as invoice_numbers,
        GROUP_CONCAT(DISTINCT c.customer_name) as customer_names,
        GROUP_CONCAT(sp.id) as payment_ids,
        MIN(sp.id) as first_payment_id,
        MIN(sp.created_at) as created_at
    FROM sales_payments sp
    JOIN sales_invoices si ON sp.invoice_id = si.id
    JOIN customers c ON si.customer_id = c.id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $sql .= " AND (sp.payment_no LIKE ? OR si.invoice_no LIKE ? OR c.customer_name LIKE ? OR c.customer_code LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($date_from)) {
    $sql .= " AND sp.payment_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $sql .= " AND sp.payment_date <= ?";
    $params[] = $date_to;
}

if (!empty($payment_method)) {
    $sql .= " AND sp.payment_method = ?";
    $params[] = $payment_method;
}

$sql .= " GROUP BY sp.payment_no ORDER BY MIN(sp.created_at) DESC, sp.payment_no DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$grand_total = 0;
foreach ($payments as $payment) {
    $grand_total += floatval($payment['total_amount']);
}

// Payment method labels
$method_labels = [
    'cash' => 'Cash',
    'card' => 'Card',
    'bank_transfer' => 'Bank Transfer',
    'cheque' => 'Cheque',
];
?>

<div class="bg-white rounded-lg shadow-lg p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">📊 Payment History</h1>
            <p class="text-gray-600 mt-1">View all payment transactions grouped by payment batch</p>
        </div>
        <a href="sales_payments.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Sales & Payments
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-gray-50 rounded-lg p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Payment No, Invoice, Customer..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                <select name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Methods</option>
                    <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="card" <?php echo $payment_method === 'card' ? 'selected' : ''; ?>>Card</option>
                    <option value="bank_transfer" <?php echo $payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                    <option value="cheque" <?php echo $payment_method === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Filter
                </button>
                <a href="payment_history.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-lg">
                    Clear
                </a>
            </div>
        </div>
    </form>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="text-sm text-green-600 font-medium">Total Payments</div>
            <div class="text-2xl font-bold text-green-700">Rs. <?php echo number_format($grand_total, 2); ?></div>
        </div>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="text-sm text-blue-600 font-medium">Payment Batches</div>
            <div class="text-2xl font-bold text-blue-700"><?php echo count($payments); ?></div>
        </div>
        <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
            <div class="text-sm text-purple-600 font-medium">Average per Batch</div>
            <div class="text-2xl font-bold text-purple-700">Rs. <?php echo count($payments) > 0 ? number_format($grand_total / count($payments), 2) : '0.00'; ?></div>
        </div>
    </div>

    <!-- Payment Table -->
    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead>
                <tr class="bg-gray-100">
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider border-b">Payment No</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider border-b">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider border-b">Customer(s)</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider border-b">Invoice(s)</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider border-b">Method(s)</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider border-b">Lines</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider border-b">Total Amount</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider border-b">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php if (empty($payments)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                        <svg class="w-12 h-12 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        No payment records found
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($payments as $payment): ?>
                <?php
                    // Format payment methods
                    $methods = explode(',', $payment['payment_methods']);
                    $formatted_methods = array_map(function($m) use ($method_labels) {
                        return $method_labels[trim($m)] ?? ucfirst(trim($m));
                    }, $methods);
                    
                    // Truncate long customer/invoice lists
                    $customers = $payment['customer_names'];
                    if (strlen($customers) > 30) {
                        $customers = substr($customers, 0, 27) . '...';
                    }
                    
                    $invoices = $payment['invoice_numbers'];
                    if (strlen($invoices) > 25) {
                        $invoices = substr($invoices, 0, 22) . '...';
                    }
                ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3">
                        <span class="font-semibold text-blue-600"><?php echo htmlspecialchars($payment['payment_no']); ?></span>
                    </td>
                    <td class="px-4 py-3 text-gray-700">
                        <?php echo date('d M Y', strtotime($payment['payment_date'])); ?>
                    </td>
                    <td class="px-4 py-3 text-gray-700" title="<?php echo htmlspecialchars($payment['customer_names']); ?>">
                        <?php echo htmlspecialchars($customers); ?>
                    </td>
                    <td class="px-4 py-3" title="<?php echo htmlspecialchars($payment['invoice_numbers']); ?>">
                        <span class="text-gray-600"><?php echo htmlspecialchars($invoices); ?></span>
                    </td>
                    <td class="px-4 py-3">
                        <?php foreach ($formatted_methods as $method): ?>
                        <span class="inline-block px-2 py-1 text-xs rounded-full 
                            <?php 
                                $m = strtolower(trim($method));
                                if ($m === 'cash') echo 'bg-green-100 text-green-700';
                                elseif ($m === 'card') echo 'bg-blue-100 text-blue-700';
                                elseif ($m === 'bank transfer') echo 'bg-purple-100 text-purple-700';
                                elseif ($m === 'cheque') echo 'bg-yellow-100 text-yellow-700';
                                else echo 'bg-gray-100 text-gray-700';
                            ?>">
                            <?php echo htmlspecialchars($method); ?>
                        </span>
                        <?php endforeach; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
                            <?php echo $payment['payment_count']; ?> line<?php echo $payment['payment_count'] > 1 ? 's' : ''; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-gray-900">
                        Rs. <?php echo number_format($payment['total_amount'], 2); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="print_payment_receipt.php?id=<?php echo $payment['first_payment_id']; ?>&ids=<?php echo $payment['payment_ids']; ?>" 
                           target="_blank"
                           class="inline-flex items-center px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm rounded-lg transition-colors"
                           title="Print Receipt">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            Print
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($payments)): ?>
            <tfoot>
                <tr class="bg-gray-100 font-semibold">
                    <td colspan="6" class="px-4 py-3 text-right text-gray-700">Grand Total:</td>
                    <td class="px-4 py-3 text-right text-gray-900">Rs. <?php echo number_format($grand_total, 2); ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
