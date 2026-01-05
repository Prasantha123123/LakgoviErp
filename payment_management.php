<?php
// payment_management.php - Manage payments and outstanding invoices
include 'header.php';
require_once 'payment_functions.php';

$success = '';
$error = '';

// Handle payment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $db->beginTransaction();
        
        if ($_POST['action'] === 'add_payment') {
            $invoice_id = intval($_POST['invoice_id']);
            
            // Get invoice details
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
            
            if ($total_new_payment > $invoice['balance_amount']) {
                throw new Exception("Payment amount cannot exceed balance amount");
            }
            
            // Insert payment lines
            insertPaymentLines($db, $invoice_id, $_POST['payments'], 'additional', $_SESSION['user_id']);
            
            // Recompute invoice totals
            $updated = recomputeInvoiceTotals($db, $invoice_id);
            
            $db->commit();
            $success = "Payment added successfully! New balance: Rs. " . number_format($updated['balance_amount'], 2);
        }
        
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch invoices with outstanding balance
try {
    $stmt = $db->query("
        SELECT si.*, c.customer_code, c.customer_name, c.phone
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        WHERE si.balance_amount > 0 AND si.status = 'confirmed'
        ORDER BY si.invoice_date DESC
        LIMIT 50
    ");
    $outstanding_invoices = $stmt->fetchAll();
} catch(PDOException $e) {
    $outstanding_invoices = [];
}

// Calculate summary stats
$total_outstanding = 0;
foreach ($outstanding_invoices as $inv) {
    $total_outstanding += floatval($inv['balance_amount']);
}
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
    <div class="grid grid-cols-1 md:grid-cols-1 gap-4">
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

    <!-- Outstanding Invoices -->
    <div class="bg-white rounded-lg shadow">
        <div class="p-4 border-b">
            <h2 class="text-lg font-semibold text-gray-900">Outstanding Invoices</h2>
        </div>
        <div class="p-6">
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

<!-- Add Payment Modal -->
<div id="addPaymentModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold">Add Payment</h3>
            <button onclick="closeModal('addPaymentModal')" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <form action="add_invoice_payment.php" method="POST" id="addPaymentForm">
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="invoice_id" id="payment_invoice_id">
            
            <div class="mb-4">
                <p class="text-sm text-gray-500">Invoice</p>
                <p class="font-semibold" id="payment_invoice_no"></p>
            </div>
            
            <div class="mb-4 p-3 bg-red-50 rounded">
                <p class="text-sm text-gray-600">Balance Due</p>
                <p class="text-2xl font-bold text-red-600" id="payment_balance"></p>
            </div>
            
            <div id="paymentLinesContainer" class="space-y-3 mb-4">
                <!-- Payment lines will be added here -->
            </div>
            
            <div class="flex justify-between mb-4">
                <button type="button" onclick="addPaymentLine()" 
                        class="text-green-600 hover:text-green-800 text-sm">
                    + Add another payment method
                </button>
            </div>
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeModal('addPaymentModal')" 
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                    Record Payment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let modalBalance = 0;
let paymentLineCounter = 0;

// Add payment
function addPayment(invoiceId, invoiceNo, balance) {
    document.getElementById('payment_invoice_id').value = invoiceId;
    document.getElementById('payment_invoice_no').textContent = invoiceNo;
    document.getElementById('payment_balance').textContent = 'Rs. ' + parseFloat(balance).toFixed(2);
    modalBalance = balance;
    paymentLineCounter = 0;
    
    // Clear and add first payment line
    document.getElementById('paymentLinesContainer').innerHTML = '';
    addPaymentLine();
    
    openModal('addPaymentModal');
}

function addPaymentLine() {
    paymentLineCounter++;
    const container = document.getElementById('paymentLinesContainer');
    
    // Calculate remaining
    let currentTotal = 0;
    container.querySelectorAll('.payment-amount-input').forEach(input => {
        currentTotal += parseFloat(input.value) || 0;
    });
    const remaining = Math.max(0, modalBalance - currentTotal);
    
    const div = document.createElement('div');
    div.className = 'bg-gray-50 p-3 rounded border';
    div.innerHTML = `
        <div class="grid grid-cols-2 gap-2 mb-2">
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
                       class="w-full px-2 py-1 border rounded text-sm text-right payment-amount-input">
            </div>
        </div>
        <div>
            <label class="block text-xs text-gray-600">Reference</label>
            <input type="text" name="payments[${paymentLineCounter}][reference_no]" 
                   placeholder="Optional" class="w-full px-2 py-1 border rounded text-sm">
        </div>
        <div id="cheque_fields_${paymentLineCounter}" class="hidden mt-2 bg-blue-50 p-2 rounded grid grid-cols-1 gap-1">
            <input type="text" name="payments[${paymentLineCounter}][cheque_number]" 
                   placeholder="Cheque Number" class="w-full px-2 py-1 border rounded text-sm">
            <input type="date" name="payments[${paymentLineCounter}][cheque_date]" 
                   class="w-full px-2 py-1 border rounded text-sm">
            <input type="text" name="payments[${paymentLineCounter}][bank_name]" 
                   placeholder="Bank Name" class="w-full px-2 py-1 border rounded text-sm">
            <input type="hidden" name="payments[${paymentLineCounter}][cheque_status]" value="pending">
        </div>
    `;
    container.appendChild(div);
}

function toggleChequeFields(select, lineId) {
    const chequeFields = document.getElementById(`cheque_fields_${lineId}`);
    if (select.value === 'cheque') {
        chequeFields.classList.remove('hidden');
    } else {
        chequeFields.classList.add('hidden');
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