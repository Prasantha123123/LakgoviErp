<?php
// payment_management.php - Manage all payments including cheques
include 'header.php';

$success = '';
$error = '';

// Handle payment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $db->beginTransaction();
        
        if ($_POST['action'] === 'update_cheque_status') {
            $payment_id = intval($_POST['payment_id']);
            $new_status = $_POST['cheque_status'];
            $clearance_date = !empty($_POST['clearance_date']) ? $_POST['clearance_date'] : null;
            $bounce_reason = !empty($_POST['bounce_reason']) ? $_POST['bounce_reason'] : null;
            $bounce_charges = floatval($_POST['bounce_charges'] ?? 0);
            
            // Get payment and invoice details
            $stmt = $db->prepare("
                SELECT sp.*, si.id as invoice_id, si.paid_amount, si.balance_amount, si.total_amount
                FROM sales_payments sp
                JOIN sales_invoices si ON sp.invoice_id = si.id
                WHERE sp.id = ?
            ");
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
            
            // If cheque bounced, reverse the payment from invoice
            if ($new_status === 'bounced' && $payment['cheque_status'] !== 'bounced') {
                $new_paid = $payment['paid_amount'] - $payment['amount'];
                $new_balance = $payment['balance_amount'] + $payment['amount'];
                
                // Update invoice payment status
                $payment_status = 'unpaid';
                if ($new_paid >= $payment['total_amount']) {
                    $payment_status = 'paid';
                } elseif ($new_paid > 0) {
                    $payment_status = 'partial';
                }
                
                $stmt = $db->prepare("
                    UPDATE sales_invoices 
                    SET paid_amount = ?, 
                        balance_amount = ?,
                        payment_status = ?
                    WHERE id = ?
                ");
                $stmt->execute([$new_paid, $new_balance, $payment_status, $payment['invoice_id']]);
            }
            
            $db->commit();
            $success = "Cheque status updated successfully!";
            
        } elseif ($_POST['action'] === 'add_payment') {
            $invoice_id = intval($_POST['invoice_id']);
            $payment_date = $_POST['payment_date'];
            $amount = floatval($_POST['amount']);
            $payment_method = $_POST['payment_method'];
            
            // Get invoice details
            $stmt = $db->prepare("SELECT * FROM sales_invoices WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $invoice = $stmt->fetch();
            
            if (!$invoice) {
                throw new Exception("Invoice not found");
            }
            
            if ($amount > $invoice['balance_amount']) {
                throw new Exception("Payment amount cannot exceed balance amount");
            }
            
            // Generate payment number
            $stmt = $db->query("SELECT payment_no FROM sales_payments ORDER BY id DESC LIMIT 1");
            $last_payment = $stmt->fetch();
            if ($last_payment) {
                $last_num = intval(substr($last_payment['payment_no'], 3));
                $payment_no = 'PAY' . str_pad($last_num + 1, 5, '0', STR_PAD_LEFT);
            } else {
                $payment_no = 'PAY00001';
            }
            
            // Insert payment
            $stmt = $db->prepare("
                INSERT INTO sales_payments (
                    payment_no, invoice_id, payment_date, amount, 
                    payment_method, payment_type, reference_no, 
                    cheque_number, cheque_date, bank_name, cheque_status,
                    notes, created_by
                ) VALUES (?, ?, ?, ?, ?, 'additional', ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $cheque_status = ($payment_method === 'cheque') ? 'pending' : null;
            
            $stmt->execute([
                $payment_no,
                $invoice_id,
                $payment_date,
                $amount,
                $payment_method,
                $_POST['reference_no'] ?? null,
                $_POST['cheque_number'] ?? null,
                $_POST['cheque_date'] ?? null,
                $_POST['bank_name'] ?? null,
                $cheque_status,
                $_POST['payment_notes'] ?? null,
                $_SESSION['user_id']
            ]);
            
            // Update invoice
            $new_paid = $invoice['paid_amount'] + $amount;
            $new_balance = $invoice['balance_amount'] - $amount;
            
            $payment_status = 'partial';
            if ($new_paid >= $invoice['total_amount']) {
                $payment_status = 'paid';
                $new_balance = 0;
            } elseif ($new_paid == 0) {
                $payment_status = 'unpaid';
            }
            
            $stmt = $db->prepare("
                UPDATE sales_invoices 
                SET paid_amount = ?, 
                    balance_amount = ?,
                    payment_status = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_paid, $new_balance, $payment_status, $invoice_id]);
            
            $db->commit();
            $success = "Payment added successfully!";
        }
        
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch pending cheques
try {
    $stmt = $db->query("SELECT * FROM v_pending_cheques ORDER BY cheque_date ASC");
    $pending_cheques = $stmt->fetchAll();
} catch(PDOException $e) {
    $pending_cheques = [];
}

// Fetch bounced cheques
try {
    $stmt = $db->query("SELECT * FROM v_bounced_cheques LIMIT 20");
    $bounced_cheques = $stmt->fetchAll();
} catch(PDOException $e) {
    $bounced_cheques = [];
}

// Fetch invoices with outstanding balance
try {
    $stmt = $db->query("
        SELECT si.*, c.customer_code, c.customer_name, c.phone
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        WHERE si.balance_amount > 0
        ORDER BY si.invoice_date DESC
        LIMIT 50
    ");
    $outstanding_invoices = $stmt->fetchAll();
} catch(PDOException $e) {
    $outstanding_invoices = [];
}

// Calculate summary stats
$total_pending_cheques = array_sum(array_column($pending_cheques, 'amount'));
$total_bounced_cheques = array_sum(array_column($bounced_cheques, 'amount'));
$total_outstanding = array_sum(array_column($outstanding_invoices, 'balance_amount'));
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">💳 Payment Management</h1>
            <p class="text-gray-600">Track payments, cheques, and outstanding balances</p>
        </div>
        <div class="flex space-x-2">
            <a href="sales_list.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
                📊 Sales List
            </a>
            <a href="cashier.php" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600">
                💰 New Sale
            </a>
        </div>
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
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gradient-to-r from-yellow-500 to-yellow-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Pending Cheques</div>
                    <div class="text-2xl font-bold mt-2">Rs. <?php echo number_format($total_pending_cheques, 2); ?></div>
                    <div class="text-sm opacity-75 mt-1"><?php echo count($pending_cheques); ?> cheques</div>
                </div>
                <div class="text-4xl opacity-75">⏳</div>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-red-500 to-red-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Bounced Cheques</div>
                    <div class="text-2xl font-bold mt-2">Rs. <?php echo number_format($total_bounced_cheques, 2); ?></div>
                    <div class="text-sm opacity-75 mt-1"><?php echo count($bounced_cheques); ?> bounced</div>
                </div>
                <div class="text-4xl opacity-75">❌</div>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-orange-500 to-orange-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Total Outstanding</div>
                    <div class="text-2xl font-bold mt-2">Rs. <?php echo number_format($total_outstanding, 2); ?></div>
                    <div class="text-sm opacity-75 mt-1"><?php echo count($outstanding_invoices); ?> invoices</div>
                </div>
                <div class="text-4xl opacity-75">💸</div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="bg-white rounded-lg shadow">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex">
                <button onclick="showTab('pending')" id="tab-pending" class="tab-button active border-b-2 border-primary text-primary py-4 px-6 font-medium">
                    Pending Cheques (<?php echo count($pending_cheques); ?>)
                </button>
                <button onclick="showTab('bounced')" id="tab-bounced" class="tab-button border-b-2 border-transparent text-gray-500 hover:text-gray-700 py-4 px-6 font-medium">
                    Bounced Cheques (<?php echo count($bounced_cheques); ?>)
                </button>
                <button onclick="showTab('outstanding')" id="tab-outstanding" class="tab-button border-b-2 border-transparent text-gray-500 hover:text-gray-700 py-4 px-6 font-medium">
                    Outstanding Invoices (<?php echo count($outstanding_invoices); ?>)
                </button>
            </nav>
        </div>

        <!-- Pending Cheques Tab -->
        <div id="content-pending" class="tab-content p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cheque No</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bank</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cheque Date</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Days Pending</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($pending_cheques)): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                    No pending cheques
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pending_cheques as $cheque): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium"><?php echo htmlspecialchars($cheque['cheque_number']); ?></td>
                                    <td class="px-4 py-3">
                                        <a href="print_invoice.php?id=<?php echo $cheque['invoice_no']; ?>" class="text-primary hover:underline">
                                            <?php echo htmlspecialchars($cheque['invoice_no']); ?>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm font-medium"><?php echo htmlspecialchars($cheque['customer_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($cheque['customer_code']); ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($cheque['bank_name'] ?? '-'); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo date('d M Y', strtotime($cheque['cheque_date'])); ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $cheque['days_pending'] > 7 ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                            <?php echo $cheque['days_pending']; ?> days
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium">Rs. <?php echo number_format($cheque['amount'], 2); ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <button onclick="updateChequeStatus(<?php echo $cheque['id']; ?>, 'cleared')" 
                                                class="text-green-600 hover:text-green-800 mx-1" title="Mark as Cleared">
                                            ✅
                                        </button>
                                        <button onclick="updateChequeStatus(<?php echo $cheque['id']; ?>, 'bounced')" 
                                                class="text-red-600 hover:text-red-800 mx-1" title="Mark as Bounced">
                                            ❌
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Bounced Cheques Tab -->
        <div id="content-bounced" class="tab-content hidden p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cheque No</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bank</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bounce Reason</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Charges</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($bounced_cheques)): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                    No bounced cheques
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bounced_cheques as $cheque): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium"><?php echo htmlspecialchars($cheque['cheque_number']); ?></td>
                                    <td class="px-4 py-3">
                                        <a href="print_invoice.php?id=<?php echo $cheque['invoice_id']; ?>" class="text-primary hover:underline">
                                            <?php echo htmlspecialchars($cheque['invoice_no']); ?>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm font-medium"><?php echo htmlspecialchars($cheque['customer_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($cheque['phone']); ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($cheque['bank_name'] ?? '-'); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($cheque['bounce_reason'] ?? '-'); ?></td>
                                    <td class="px-4 py-3 text-right font-medium text-red-600">Rs. <?php echo number_format($cheque['amount'], 2); ?></td>
                                    <td class="px-4 py-3 text-right font-medium">Rs. <?php echo number_format($cheque['bounce_charges'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Outstanding Invoices Tab -->
        <div id="content-outstanding" class="tab-content hidden p-6">
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
                                    No outstanding invoices
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($outstanding_invoices as $invoice): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" class="text-primary hover:underline font-medium">
                                            <?php echo htmlspecialchars($invoice['invoice_no']); ?>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-sm"><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm font-medium"><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($invoice['customer_code']); ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-right">Rs. <?php echo number_format($invoice['total_amount'], 2); ?></td>
                                    <td class="px-4 py-3 text-right text-green-600">Rs. <?php echo number_format($invoice['paid_amount'], 2); ?></td>
                                    <td class="px-4 py-3 text-right text-red-600 font-bold">Rs. <?php echo number_format($invoice['balance_amount'], 2); ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <button onclick="addPayment(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['invoice_no']); ?>', <?php echo $invoice['balance_amount']; ?>)" 
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
    </div>
</div>

<!-- Update Cheque Status Modal -->
<div id="chequeStatusModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <h3 class="text-lg font-semibold mb-4">Update Cheque Status</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_cheque_status">
            <input type="hidden" name="payment_id" id="modal_payment_id">
            <input type="hidden" name="cheque_status" id="modal_cheque_status">
            
            <div class="space-y-4">
                <div id="cleared_fields" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Clearance Date *</label>
                    <input type="date" name="clearance_date" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>
                
                <div id="bounced_fields" class="hidden">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bounce Reason *</label>
                        <textarea name="bounce_reason" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md" placeholder="e.g., Insufficient funds, Account closed, etc."></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bounce Charges</label>
                        <input type="number" name="bounce_charges" step="0.01" min="0" value="0" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" onclick="closeModal('chequeStatusModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                    Update Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Payment Modal -->
<div id="addPaymentModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <h3 class="text-lg font-semibold mb-4">Add Payment</h3>
        <form method="POST" id="addPaymentForm">
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="invoice_id" id="payment_invoice_id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Invoice No</label>
                    <input type="text" id="payment_invoice_no" readonly class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100">
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Outstanding Balance</label>
                    <div class="text-2xl font-bold text-red-600" id="payment_balance">Rs. 0.00</div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                    <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                    <input type="number" name="amount" id="payment_amount" step="0.01" min="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                    <select name="payment_method" id="payment_method_select" onchange="togglePaymentFields()" required class="w-full px-3 py-2 border border-gray-300 rounded-md">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference No</label>
                    <input type="text" name="reference_no" placeholder="Optional" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>
                
                <!-- Cheque Fields (shown only when cheque is selected) -->
                <div id="cheque_fields" class="md:col-span-2 hidden">
                    <div class="bg-blue-50 p-4 rounded-md border border-blue-200">
                        <h4 class="font-semibold text-blue-900 mb-3">Cheque Details</h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Cheque Number *</label>
                                <input type="text" name="cheque_number" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Cheque Date *</label>
                                <input type="date" name="cheque_date" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Bank Name *</label>
                                <input type="text" name="bank_name" placeholder="e.g., Commercial Bank" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            </div>
                        </div>
                        <p class="text-xs text-blue-700 mt-2">⚠️ Cheque will be marked as "Pending" until cleared</p>
                    </div>
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="payment_notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md" placeholder="Optional payment notes"></textarea>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" onclick="closeModal('addPaymentModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                    Add Payment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Tab functionality
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active', 'border-primary', 'text-primary');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected tab content
    document.getElementById('content-' + tabName).classList.remove('hidden');
    
    // Add active class to selected tab
    const activeTab = document.getElementById('tab-' + tabName);
    activeTab.classList.add('active', 'border-primary', 'text-primary');
    activeTab.classList.remove('border-transparent', 'text-gray-500');
}

// Update cheque status
function updateChequeStatus(paymentId, status) {
    document.getElementById('modal_payment_id').value = paymentId;
    document.getElementById('modal_cheque_status').value = status;
    
    // Show/hide relevant fields
    document.getElementById('cleared_fields').classList.add('hidden');
    document.getElementById('bounced_fields').classList.add('hidden');
    
    if (status === 'cleared') {
        document.getElementById('cleared_fields').classList.remove('hidden');
    } else if (status === 'bounced') {
        document.getElementById('bounced_fields').classList.remove('hidden');
    }
    
    openModal('chequeStatusModal');
}

// Add payment
function addPayment(invoiceId, invoiceNo, balance) {
    document.getElementById('payment_invoice_id').value = invoiceId;
    document.getElementById('payment_invoice_no').value = invoiceNo;
    document.getElementById('payment_balance').textContent = 'Rs. ' + parseFloat(balance).toFixed(2);
    document.getElementById('payment_amount').max = balance;
    document.getElementById('payment_amount').value = '';
    openModal('addPaymentModal');
}

// Toggle payment fields based on method
function togglePaymentFields() {
    const method = document.getElementById('payment_method_select').value;
    const chequeFields = document.getElementById('cheque_fields');
    
    if (method === 'cheque') {
        chequeFields.classList.remove('hidden');
        // Make cheque fields required
        chequeFields.querySelectorAll('input').forEach(input => {
            if (input.name === 'cheque_number' || input.name === 'cheque_date' || input.name === 'bank_name') {
                input.required = true;
            }
        });
    } else {
        chequeFields.classList.add('hidden');
        // Remove required from cheque fields
        chequeFields.querySelectorAll('input').forEach(input => {
            input.required = false;
        });
    }
}

// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('fixed')) {
        event.target.classList.add('hidden');
    }
}
</script>

<?php include 'footer.php'; ?>