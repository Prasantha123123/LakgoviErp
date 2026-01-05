<?php
// invoice_payment_history.php - View complete payment history for an invoice

// Check if AJAX request for modal
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

if ($is_ajax) {
    // For AJAX, just need database connection
    require_once 'database.php';
    require_once 'payment_functions.php';
    session_start();
    
    $database = new Database();
    $db = $database->getConnection();
} else {
    // For full page, include header which sets up everything
    include 'header.php';
    require_once 'payment_functions.php';
}

$success = '';
$error = '';
$invoice = null;
$payment_history = null;

// Get invoice ID
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

if ($invoice_id > 0) {
    try {
        // Get invoice details with customer info
        $stmt = $db->prepare("
            SELECT si.*, 
                   c.customer_code, c.customer_name, c.phone, c.city,
                   pl.price_list_name, pl.currency
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            LEFT JOIN price_lists pl ON si.price_list_id = pl.id
            WHERE si.id = ?
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch();
        
        if ($invoice) {
            // Get full payment history with method breakdown
            $payment_history = getInvoicePaymentHistory($db, $invoice_id);
            
            // Get invoice items
            $stmt = $db->prepare("
                SELECT sii.*, i.code as item_code, i.name as item_name, u.symbol as unit
                FROM sales_invoice_items sii
                JOIN items i ON sii.item_id = i.id
                JOIN units u ON i.unit_id = u.id
                WHERE sii.invoice_id = ?
            ");
            $stmt->execute([$invoice_id]);
            $invoice_items = $stmt->fetchAll();
        }
    } catch(PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Search outstanding invoices for dropdown
try {
    $stmt = $db->query("
        SELECT si.id, si.invoice_no, c.customer_name, si.total_amount, si.balance_amount
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        WHERE si.status = 'confirmed'
        ORDER BY si.invoice_date DESC
        LIMIT 200
    ");
    $all_invoices = $stmt->fetchAll();
} catch(PDOException $e) {
    $all_invoices = [];
}

// If AJAX request, output simplified HTML for modal
if ($is_ajax) {
    if ($invoice):
        // Calculate running balance for each payment
        $running_balance = floatval($invoice['total_amount']);
        $cumulative_paid = 0;
?>
<div class="space-y-4">
    <!-- Invoice Summary -->
    <div class="bg-blue-50 p-3 rounded-md">
        <div class="flex justify-between items-center">
            <div>
                <p class="font-semibold text-lg"><?php echo htmlspecialchars($invoice['invoice_no']); ?></p>
                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['customer_name']); ?> (<?php echo htmlspecialchars($invoice['customer_code']); ?>)</p>
                <p class="text-xs text-gray-500">Date: <?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></p>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-500">Invoice Total</p>
                <p class="text-xl font-bold">Rs. <?php echo number_format($invoice['total_amount'], 2); ?></p>
            </div>
        </div>
    </div>

    <!-- Payment Progress Bar -->
    <div class="bg-gray-100 p-3 rounded-md">
        <?php 
        $paid_percent = $invoice['total_amount'] > 0 ? round(($invoice['paid_amount'] / $invoice['total_amount']) * 100, 1) : 0;
        ?>
        <div class="flex justify-between text-sm mb-1">
            <span class="text-green-600 font-medium">Paid: Rs. <?php echo number_format($invoice['paid_amount'], 2); ?></span>
            <span class="text-red-600 font-medium">Balance: Rs. <?php echo number_format($invoice['balance_amount'], 2); ?></span>
        </div>
        <div class="w-full bg-gray-300 rounded-full h-3">
            <div class="bg-green-500 h-3 rounded-full transition-all" style="width: <?php echo $paid_percent; ?>%"></div>
        </div>
        <p class="text-center text-xs text-gray-500 mt-1"><?php echo $paid_percent; ?>% Collected</p>
    </div>
    
    <?php if ($payment_history && !empty($payment_history['payments'])): ?>
    <!-- Payment Timeline -->
    <div class="border rounded-md overflow-hidden">
        <div class="bg-gray-50 px-3 py-2 border-b">
            <p class="font-semibold text-sm text-gray-700">📜 Payment Timeline (<?php echo count($payment_history['payments']); ?> payments)</p>
        </div>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">#</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Date</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Method</th>
                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Amount</th>
                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Running Balance</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Type</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php 
                $pmt_num = 0;
                foreach ($payment_history['payments'] as $pmt): 
                    $pmt_num++;
                    $amount = floatval($pmt['amount']);
                    
                    // Count all payments towards running balance
                    $cumulative_paid += $amount;
                    $running_balance = floatval($invoice['total_amount']) - $cumulative_paid;
                    
                    $icons = ['cash' => '💵', 'card' => '💳', 'bank_transfer' => '🏦', 'cheque' => '📝', 'other' => '📦'];
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-400 text-xs"><?php echo $pmt_num; ?></td>
                    <td class="px-3 py-2">
                        <div><?php echo date('d M Y', strtotime($pmt['payment_date'])); ?></div>
                        <div class="text-xs text-gray-400"><?php echo date('h:i A', strtotime($pmt['created_at'])); ?></div>
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex items-center">
                            <span class="mr-1"><?php echo $icons[$pmt['payment_method']] ?? '💰'; ?></span>
                            <span class="capitalize"><?php echo str_replace('_', ' ', $pmt['payment_method']); ?></span>
                        </div>
                        <?php if ($pmt['reference_no']): ?>
                            <div class="text-xs text-gray-500">Ref: <?php echo htmlspecialchars($pmt['reference_no']); ?></div>
                        <?php endif; ?>
                        <?php if ($pmt['bank_name']): ?>
                            <div class="text-xs text-gray-500">Bank: <?php echo htmlspecialchars($pmt['bank_name']); ?></div>
                        <?php endif; ?>
                        <?php if ($pmt['payment_method'] === 'cheque' && $pmt['cheque_number']): ?>
                            <div class="text-xs text-blue-600">
                                Cheque #<?php echo htmlspecialchars($pmt['cheque_number']); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-right font-semibold text-green-600">
                        +Rs. <?php echo number_format($amount, 2); ?>
                    </td>
                    <td class="px-3 py-2 text-right <?php echo $running_balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                        Rs. <?php echo number_format($running_balance, 2); ?>
                    </td>
                    <td class="px-3 py-2">
                        <?php if ($pmt['payment_type'] === 'initial'): ?>
                            <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs rounded-full">Initial</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 bg-purple-100 text-purple-700 text-xs rounded-full">Additional</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-green-50 border-t-2 border-green-200">
                <tr>
                    <td colspan="3" class="px-3 py-2 text-right font-semibold text-gray-700">Total Paid:</td>
                    <td class="px-3 py-2 text-right font-bold text-green-700 text-lg">Rs. <?php echo number_format($invoice['paid_amount'], 2); ?></td>
                    <td class="px-3 py-2 text-right font-bold <?php echo $invoice['balance_amount'] > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                        Rs. <?php echo number_format($invoice['balance_amount'], 2); ?>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    
    <!-- Method Breakdown Summary -->
    <?php if (!empty($payment_history['method_breakdown'])): ?>
    <div class="grid grid-cols-<?php echo count($payment_history['method_breakdown']); ?> gap-2">
        <?php foreach ($payment_history['method_breakdown'] as $mb): ?>
        <div class="bg-gray-50 p-2 rounded text-center border">
            <div class="text-lg"><?php echo $icons[$mb['method']] ?? '💰'; ?></div>
            <div class="text-xs text-gray-500 capitalize"><?php echo str_replace('_', ' ', $mb['method']); ?></div>
            <div class="font-semibold text-green-600">Rs. <?php echo number_format($mb['counted_paid'], 2); ?></div>
            <div class="text-xs text-gray-400"><?php echo $mb['payment_count']; ?> payment(s)</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php else: ?>
    <div class="text-center py-6 text-gray-500 bg-gray-50 rounded-md">
        <div class="text-4xl mb-2">📭</div>
        <p>No payments recorded yet</p>
        <p class="text-sm text-red-500 mt-1">Full amount outstanding: Rs. <?php echo number_format($invoice['total_amount'], 2); ?></p>
    </div>
    <?php endif; ?>
</div>
<?php
    else:
        echo '<p class="text-center text-red-500">Invoice not found (ID: ' . $invoice_id . ')</p>';
    endif;
    exit; // Stop execution for AJAX requests
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">📊 Invoice Payment History</h1>
            <p class="text-gray-600">Complete payment breakdown for an invoice</p>
        </div>
        <div class="flex space-x-2">
            <a href="customer_pending_bills.php" class="bg-orange-600 text-white px-4 py-2 rounded-md hover:bg-orange-700">
                📋 Pending Bills
            </a>
            <a href="payment_management.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
                📊 Payment Management
            </a>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Invoice Search -->
    <div class="bg-white rounded-lg shadow p-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Invoice</label>
                <select onchange="loadInvoice(this.value)" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="">-- Select Invoice --</option>
                    <?php foreach ($all_invoices as $inv): ?>
                        <option value="<?php echo $inv['id']; ?>" <?php echo $invoice_id == $inv['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($inv['invoice_no']); ?> - 
                            <?php echo htmlspecialchars($inv['customer_name']); ?>
                            (Rs. <?php echo number_format($inv['total_amount'], 2); ?>)
                            <?php if ($inv['balance_amount'] > 0): ?>
                                [Due: Rs. <?php echo number_format($inv['balance_amount'], 2); ?>]
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quick Actions</label>
                <div class="flex space-x-2">
                    <?php if ($invoice): ?>
                        <a href="print_invoice.php?id=<?php echo $invoice_id; ?>" target="_blank"
                           class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
                            🖨️ Print Invoice
                        </a>
                        <?php if ($invoice['balance_amount'] > 0): ?>
                            <a href="add_invoice_payment.php?invoice_id=<?php echo $invoice_id; ?>"
                               class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                                💳 Add Payment
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($invoice): ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Invoice Header Card -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($invoice['invoice_no']); ?></h2>
                        <p class="text-gray-500">Invoice Date: <?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></p>
                        <?php if ($invoice['due_date']): ?>
                            <p class="text-gray-500">Due Date: <?php echo date('d M Y', strtotime($invoice['due_date'])); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <span class="px-3 py-1 rounded-full text-sm font-semibold 
                            <?php 
                            echo $invoice['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 
                                ($invoice['payment_status'] === 'partial' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); 
                            ?>">
                            <?php echo strtoupper($invoice['payment_status']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm text-gray-500">Customer</p>
                        <p class="font-semibold"><?php echo htmlspecialchars($invoice['customer_name']); ?></p>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['customer_code']); ?></p>
                        <?php if ($invoice['phone']): ?>
                            <p class="text-sm text-gray-500"><?php echo htmlspecialchars($invoice['phone']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Invoice Total</p>
                        <p class="text-2xl font-bold">Rs. <?php echo number_format($invoice['total_amount'], 2); ?></p>
                    </div>
                </div>
            </div>

            <!-- Payment Method Breakdown -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">💳 Payment Method Breakdown</h3>
                
                <?php if (!empty($payment_history['method_breakdown'])): ?>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <?php foreach ($payment_history['method_breakdown'] as $breakdown): ?>
                            <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-lg border">
                                <div class="flex items-center mb-2">
                                    <span class="text-2xl mr-2">
                                        <?php 
                                        $icons = ['cash' => '💵', 'card' => '💳', 'bank_transfer' => '🏦', 'cheque' => '📝', 'other' => '📦'];
                                        echo $icons[$breakdown['method']] ?? '💰';
                                        ?>
                                    </span>
                                    <span class="font-medium capitalize"><?php echo $breakdown['method']; ?></span>
                                </div>
                                <p class="text-xl font-bold text-green-600">
                                    Rs. <?php echo number_format($breakdown['counted_paid'], 2); ?>
                                </p>
                                <?php if ($breakdown['pending_cheque'] > 0): ?>
                                    <p class="text-sm text-yellow-600 mt-1">
                                        ⏳ Pending: Rs. <?php echo number_format($breakdown['pending_cheque'], 2); ?>
                                    </p>
                                <?php endif; ?>
                                <p class="text-xs text-gray-500"><?php echo $breakdown['payment_count']; ?> payment(s)</p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-6 text-gray-500">
                        <p>No payments recorded yet</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Payment History Table -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">📜 Payment History</h3>
                
                <?php if (!empty($payment_history['payments'])): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment No</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference / Cheque Details</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Type</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($payment_history['payments'] as $pmt): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm">
                                            <?php echo date('d M Y', strtotime($pmt['payment_date'])); ?>
                                            <br><span class="text-xs text-gray-400"><?php echo date('H:i', strtotime($pmt['created_at'])); ?></span>
                                        </td>
                                        <td class="px-4 py-3 font-medium text-sm">
                                            <?php echo htmlspecialchars($pmt['payment_no']); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center">
                                                <span class="mr-1">
                                                    <?php 
                                                    $icons = ['cash' => '💵', 'card' => '💳', 'bank_transfer' => '🏦', 'cheque' => '📝', 'other' => '📦'];
                                                    echo $icons[$pmt['payment_method']] ?? '💰';
                                                    ?>
                                                </span>
                                                <span class="capitalize"><?php echo $pmt['payment_method']; ?></span>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <?php if ($pmt['payment_method'] === 'cheque'): ?>
                                                <div class="text-gray-800">
                                                    <strong>CHQ:</strong> <?php echo htmlspecialchars($pmt['cheque_number'] ?? '-'); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php if ($pmt['cheque_date']): ?>
                                                        Date: <?php echo date('d M Y', strtotime($pmt['cheque_date'])); ?>
                                                    <?php endif; ?>
                                                    <?php if ($pmt['bank_name']): ?>
                                                        | <?php echo htmlspecialchars($pmt['bank_name']); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($pmt['cheque_status'] === 'cleared' && $pmt['clearance_date']): ?>
                                                    <div class="text-xs text-green-600">
                                                        Cleared: <?php echo date('d M Y', strtotime($pmt['clearance_date'])); ?>
                                                    </div>
                                                <?php elseif ($pmt['cheque_status'] === 'bounced' && $pmt['bounce_reason']): ?>
                                                    <div class="text-xs text-red-600">
                                                        Reason: <?php echo htmlspecialchars($pmt['bounce_reason']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($pmt['reference_no'] ?? '-'); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold">
                                            Rs. <?php echo number_format($pmt['amount'], 2); ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <?php if ($pmt['payment_method'] === 'cheque'): ?>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold 
                                                    <?php 
                                                    $status_classes = [
                                                        'cleared' => 'bg-green-100 text-green-700',
                                                        'pending' => 'bg-yellow-100 text-yellow-700',
                                                        'bounced' => 'bg-red-100 text-red-700',
                                                        'cancelled' => 'bg-gray-100 text-gray-700'
                                                    ];
                                                    echo $status_classes[$pmt['cheque_status']] ?? 'bg-gray-100 text-gray-700';
                                                    ?>">
                                                    <?php echo ucfirst($pmt['cheque_status'] ?? 'unknown'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                                                    Received
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="px-2 py-1 rounded text-xs 
                                                <?php echo $pmt['payment_type'] === 'initial' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'; ?>">
                                                <?php echo ucfirst($pmt['payment_type'] ?? 'initial'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8 text-gray-500">
                        <div class="text-4xl mb-2">💸</div>
                        <p>No payments recorded for this invoice</p>
                        <a href="add_invoice_payment.php?invoice_id=<?php echo $invoice_id; ?>" 
                           class="mt-4 inline-block bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                            Add First Payment
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Payment Summary Card -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Payment Summary</h3>
                
                <div class="space-y-4">
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-gray-600">Subtotal</span>
                        <span class="font-medium">Rs. <?php echo number_format($invoice['subtotal'], 2); ?></span>
                    </div>
                    
                    <?php if ($invoice['discount_amount'] > 0): ?>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-gray-600">Discount</span>
                        <span class="font-medium text-red-600">- Rs. <?php echo number_format($invoice['discount_amount'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($invoice['tax_amount'] > 0): ?>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-gray-600">Tax</span>
                        <span class="font-medium">Rs. <?php echo number_format($invoice['tax_amount'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="font-semibold">Total Amount</span>
                        <span class="font-bold text-lg">Rs. <?php echo number_format($invoice['total_amount'], 2); ?></span>
                    </div>
                    
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-green-600">Total Paid</span>
                        <span class="font-bold text-lg text-green-600">Rs. <?php echo number_format($invoice['paid_amount'], 2); ?></span>
                    </div>
                    
                    <?php 
                    // Calculate pending cheque amount
                    $pending_cheque_total = 0;
                    if (!empty($payment_history['method_breakdown'])) {
                        foreach ($payment_history['method_breakdown'] as $b) {
                            $pending_cheque_total += $b['pending_cheque'];
                        }
                    }
                    ?>
                    
                    <?php if ($pending_cheque_total > 0): ?>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-yellow-600">Pending Cheques</span>
                        <span class="font-bold text-yellow-600">Rs. <?php echo number_format($pending_cheque_total, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="flex justify-between items-center pt-2">
                        <span class="text-red-600 font-semibold">Balance Due</span>
                        <span class="font-bold text-xl text-red-600">Rs. <?php echo number_format($invoice['balance_amount'], 2); ?></span>
                    </div>
                </div>
                
                <?php if ($invoice['balance_amount'] > 0): ?>
                <div class="mt-6">
                    <a href="add_invoice_payment.php?invoice_id=<?php echo $invoice_id; ?>" 
                       class="block w-full bg-green-600 text-white text-center px-4 py-3 rounded-md hover:bg-green-700 font-semibold">
                        💳 Add Payment
                    </a>
                </div>
                <?php else: ?>
                <div class="mt-6 text-center p-4 bg-green-50 rounded-lg">
                    <div class="text-3xl mb-2">✅</div>
                    <p class="font-semibold text-green-700">Fully Paid</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Invoice Items -->
            <?php if (!empty($invoice_items)): ?>
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Invoice Items</h3>
                
                <div class="space-y-3">
                    <?php foreach ($invoice_items as $item): ?>
                        <div class="flex justify-between items-start text-sm pb-2 border-b last:border-0">
                            <div>
                                <p class="font-medium"><?php echo htmlspecialchars($item['item_name']); ?></p>
                                <p class="text-xs text-gray-500">
                                    <?php echo number_format($item['quantity'], 3); ?> <?php echo $item['unit']; ?> 
                                    × Rs. <?php echo number_format($item['unit_price'], 2); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium">Rs. <?php echo number_format($item['line_total'], 2); ?></p>
                                <?php if ($item['discount_amount'] > 0): ?>
                                    <p class="text-xs text-red-500">-<?php echo $item['discount_percentage']; ?>%</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-lg shadow p-12 text-center">
        <div class="text-6xl mb-4">📄</div>
        <h2 class="text-2xl font-semibold text-gray-700 mb-2">Select an Invoice</h2>
        <p class="text-gray-500">Choose an invoice from the dropdown above to view its payment history</p>
    </div>
    <?php endif; ?>
</div>

<script>
function loadInvoice(invoiceId) {
    if (invoiceId) {
        window.location.href = 'invoice_payment_history.php?invoice_id=' + invoiceId;
    }
}
</script>

<?php include 'footer.php'; ?>
