<?php
// add_invoice_payment.php - Add additional payments to existing invoices
include 'header.php';
require_once 'payment_functions.php';

$success = '';
$error = '';
$invoice = null;
$payment_history = null;

// Get invoice details if ID provided
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : (isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0);

if ($invoice_id > 0) {
    try {
        // Get invoice details
        $stmt = $db->prepare("
            SELECT si.*, c.customer_code, c.customer_name, c.phone
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            WHERE si.id = ?
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch();
        
        if ($invoice) {
            // Get payment history
            $payment_history = getInvoicePaymentHistory($db, $invoice_id);
        }
    } catch(PDOException $e) {
        $error = "Error fetching invoice: " . $e->getMessage();
    }
}

// Handle form submission
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
            
            // Check if payment exceeds balance (optional - can allow overpayment for credit)
            if ($total_new_payment > $invoice['balance_amount']) {
                throw new Exception("Total payment (Rs. " . number_format($total_new_payment, 2) . 
                    ") exceeds invoice balance (Rs. " . number_format($invoice['balance_amount'], 2) . ")");
            }
            
            // Insert each payment line and capture inserted IDs for receipts
            $inserted_ids = insertPaymentLines($db, $invoice_id, $_POST['payments'], 'additional', $_SESSION['user_id']);
            
            // Recompute invoice totals
            $updated = recomputeInvoiceTotals($db, $invoice_id);
            
            $db->commit();
            $success = "Payment added successfully! New balance: Rs. " . number_format($updated['balance_amount'], 2);
            if (!empty($inserted_ids)) {
                $success .= " <a class='underline text-blue-700' target='_blank' href='print_payment_receipt.php?id=" . intval($inserted_ids[0]) . "'>🖨️ Print Receipt</a>";
            }
            
            // Refresh invoice data
            $stmt = $db->prepare("
                SELECT si.*, c.customer_code, c.customer_name, c.phone
                FROM sales_invoices si
                JOIN customers c ON si.customer_id = c.id
                WHERE si.id = ?
            ");
            $stmt->execute([$invoice_id]);
            $invoice = $stmt->fetch();
            $payment_history = getInvoicePaymentHistory($db, $invoice_id);
        }
        
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch outstanding invoices for search
try {
    $stmt = $db->query("
        SELECT si.id, si.invoice_no, si.invoice_date, si.total_amount, si.balance_amount,
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
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">💳 Add Invoice Payment</h1>
            <p class="text-gray-600">Add additional payments to existing invoices</p>
        </div>
        <div class="flex space-x-2">
            <a href="payment_management.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
                📊 Payment Management
            </a>
            <a href="customer_pending_bills.php" class="bg-orange-600 text-white px-4 py-2 rounded-md hover:bg-orange-700">
                📋 Pending Bills
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column - Invoice Search & Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Invoice Search -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Select Invoice</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Search Invoice</label>
                        <select id="invoice_search" onchange="loadInvoice(this.value)" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            <option value="">-- Select Outstanding Invoice --</option>
                            <?php foreach ($outstanding_invoices as $inv): ?>
                                <option value="<?php echo $inv['id']; ?>" 
                                        <?php echo $invoice_id == $inv['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($inv['invoice_no']); ?> - 
                                    <?php echo htmlspecialchars($inv['customer_name']); ?> 
                                    (Balance: Rs. <?php echo number_format($inv['balance_amount'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Or Enter Invoice No</label>
                        <div class="flex">
                            <input type="text" id="invoice_no_search" placeholder="INV00001" 
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-l-md">
                            <button type="button" onclick="searchInvoice()" 
                                    class="bg-primary text-white px-4 py-2 rounded-r-md hover:bg-blue-600">
                                Search
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($invoice): ?>
            <!-- Invoice Details -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Invoice Details</h2>
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                    <div>
                        <p class="text-sm text-gray-500">Invoice No</p>
                        <p class="font-semibold"><?php echo htmlspecialchars($invoice['invoice_no']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Date</p>
                        <p class="font-semibold"><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Customer</p>
                        <p class="font-semibold"><?php echo htmlspecialchars($invoice['customer_name']); ?></p>
                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($invoice['customer_code']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Phone</p>
                        <p class="font-semibold"><?php echo htmlspecialchars($invoice['phone'] ?? '-'); ?></p>
                    </div>
                </div>
                
                <div class="grid grid-cols-3 gap-4 p-4 bg-gray-50 rounded-lg">
                    <div class="text-center">
                        <p class="text-sm text-gray-500">Total Amount</p>
                        <p class="text-xl font-bold">Rs. <?php echo number_format($invoice['total_amount'], 2); ?></p>
                    </div>
                    <div class="text-center">
                        <p class="text-sm text-gray-500">Paid Amount</p>
                        <p class="text-xl font-bold text-green-600">Rs. <?php echo number_format($invoice['paid_amount'], 2); ?></p>
                    </div>
                    <div class="text-center">
                        <p class="text-sm text-gray-500">Balance Due</p>
                        <p class="text-xl font-bold text-red-600">Rs. <?php echo number_format($invoice['balance_amount'], 2); ?></p>
                    </div>
                </div>
                
                <div class="mt-4">
                    <span class="px-3 py-1 rounded-full text-sm font-semibold 
                        <?php 
                        echo $invoice['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 
                            ($invoice['payment_status'] === 'partial' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); 
                        ?>">
                        <?php echo ucfirst($invoice['payment_status']); ?>
                    </span>
                    <?php if ($invoice['due_date']): ?>
                        <span class="ml-2 text-sm text-gray-500">
                            Due: <?php echo date('d M Y', strtotime($invoice['due_date'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment History -->
            <?php if ($payment_history && !empty($payment_history['payments'])): ?>
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Payment History</h2>
                
                <!-- Method Breakdown -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                    <?php foreach ($payment_history['method_breakdown'] as $breakdown): ?>
                        <div class="bg-gray-50 p-3 rounded-lg text-center">
                            <p class="text-xs text-gray-500 uppercase"><?php echo ucfirst($breakdown['method']); ?></p>
                            <p class="font-semibold text-green-600">Rs. <?php echo number_format($breakdown['counted_paid'], 2); ?></p>
                            <?php if ($breakdown['pending_cheque'] > 0): ?>
                                <p class="text-xs text-yellow-600">Pending: Rs. <?php echo number_format($breakdown['pending_cheque'], 2); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Payment List -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Payment No</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($payment_history['payments'] as $pmt): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2"><?php echo date('d M Y', strtotime($pmt['payment_date'])); ?></td>
                                    <td class="px-3 py-2 font-medium"><?php echo htmlspecialchars($pmt['payment_no']); ?></td>
                                    <td class="px-3 py-2">
                                        <span class="capitalize"><?php echo $pmt['payment_method']; ?></span>
                                        <?php if ($pmt['payment_type'] === 'additional'): ?>
                                            <span class="ml-1 px-1.5 py-0.5 bg-blue-100 text-blue-700 text-xs rounded">Add</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600">
                                        <?php 
                                        if ($pmt['payment_method'] === 'cheque') {
                                            echo htmlspecialchars($pmt['cheque_number'] ?? '-');
                                            if ($pmt['bank_name']) echo ' / ' . htmlspecialchars($pmt['bank_name']);
                                        } else {
                                            echo htmlspecialchars($pmt['reference_no'] ?? '-');
                                        }
                                        ?>
                                    </td>
                                    <td class="px-3 py-2 text-right font-medium">Rs. <?php echo number_format($pmt['amount'], 2); ?></td>
                                    <td class="px-3 py-2 text-center">
                                        <?php if ($pmt['payment_method'] === 'cheque'): ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold 
                                                <?php 
                                                echo $pmt['cheque_status'] === 'cleared' ? 'bg-green-100 text-green-700' : 
                                                    ($pmt['cheque_status'] === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'); 
                                                ?>">
                                                <?php echo ucfirst($pmt['cheque_status']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">Received</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Right Column - Add Payment Form -->
        <div>
            <?php if ($invoice && $invoice['balance_amount'] > 0): ?>
            <div class="bg-white rounded-lg shadow p-6 sticky top-4">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Add Payment</h2>
                
                <form method="POST" id="addPaymentForm">
                    <input type="hidden" name="action" value="add_payment">
                    <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                    
                    <div class="mb-4 p-3 bg-red-50 rounded-lg">
                        <p class="text-sm text-gray-600">Balance Due</p>
                        <p class="text-2xl font-bold text-red-600" id="current_balance">
                            Rs. <?php echo number_format($invoice['balance_amount'], 2); ?>
                        </p>
                    </div>
                    
                    <div class="mb-4">
                        <div class="flex justify-between items-center mb-2">
                            <label class="text-sm font-medium text-gray-700">Payment Lines</label>
                            <button type="button" onclick="addPaymentLine()" 
                                    class="bg-green-600 text-white px-2 py-1 rounded text-xs hover:bg-green-700">
                                + Add Line
                            </button>
                        </div>
                        <div id="paymentLinesContainer" class="space-y-3">
                            <!-- Payment lines will be added here -->
                        </div>
                    </div>
                    
                    <div class="border-t pt-4 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Total Payment:</span>
                            <span id="total_payment_display" class="font-semibold text-green-600">Rs. 0.00</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Remaining Balance:</span>
                            <span id="remaining_balance_display" class="font-semibold text-red-600">
                                Rs. <?php echo number_format($invoice['balance_amount'], 2); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="mt-6">
                        <button type="submit" 
                                class="w-full bg-primary text-white px-4 py-3 rounded-md hover:bg-blue-600 font-semibold">
                            💰 Record Payment
                        </button>
                    </div>
                </form>
            </div>
            <?php elseif ($invoice && $invoice['balance_amount'] <= 0): ?>
            <div class="bg-green-50 rounded-lg shadow p-6">
                <div class="text-center">
                    <div class="text-4xl mb-2">✅</div>
                    <h3 class="text-lg font-semibold text-green-800">Invoice Fully Paid</h3>
                    <p class="text-sm text-green-600 mt-1">No balance remaining</p>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-gray-50 rounded-lg shadow p-6">
                <div class="text-center text-gray-500">
                    <div class="text-4xl mb-2">📄</div>
                    <p>Select an invoice to add payment</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const invoiceBalance = <?php echo $invoice ? $invoice['balance_amount'] : 0; ?>;
let paymentLineCounter = 0;

function loadInvoice(invoiceId) {
    if (invoiceId) {
        window.location.href = 'add_invoice_payment.php?invoice_id=' + invoiceId;
    }
}

function searchInvoice() {
    const invoiceNo = document.getElementById('invoice_no_search').value.trim();
    if (invoiceNo) {
        // Search by invoice number (would need AJAX endpoint, for now redirect)
        alert('Please select invoice from the dropdown or implement AJAX search');
    }
}

function addPaymentLine() {
    paymentLineCounter++;
    const container = document.getElementById('paymentLinesContainer');
    
    // Calculate remaining
    let currentTotal = 0;
    document.querySelectorAll('.payment-amount-input').forEach(input => {
        currentTotal += parseFloat(input.value) || 0;
    });
    const remaining = Math.max(0, invoiceBalance - currentTotal);
    
    const lineDiv = document.createElement('div');
    lineDiv.id = `payment_line_${paymentLineCounter}`;
    lineDiv.className = 'bg-gray-50 p-3 rounded border';
    
    lineDiv.innerHTML = `
        <div class="flex justify-between items-center mb-2">
            <span class="text-xs font-semibold text-gray-500">Payment #${paymentLineCounter}</span>
            <button type="button" onclick="removePaymentLine(${paymentLineCounter})" 
                    class="text-red-500 hover:text-red-700 text-xs">✕</button>
        </div>
        <div class="grid grid-cols-2 gap-2 mb-2">
            <div>
                <label class="block text-xs text-gray-600">Method</label>
                <select name="payments[${paymentLineCounter}][method]" 
                        onchange="toggleChequeFields(${paymentLineCounter})"
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
                       step="0.01" min="0" value="${remaining.toFixed(2)}"
                       onchange="updateTotals()"
                       class="w-full px-2 py-1 border rounded text-sm text-right payment-amount-input">
            </div>
        </div>
        <div>
            <label class="block text-xs text-gray-600">Reference</label>
            <input type="text" name="payments[${paymentLineCounter}][reference_no]" 
                   placeholder="TXN / Receipt No"
                   class="w-full px-2 py-1 border rounded text-sm">
        </div>
        <div id="cheque_fields_${paymentLineCounter}" class="hidden mt-2 bg-blue-50 p-2 rounded">
            <div class="grid grid-cols-1 gap-1">
                <input type="text" name="payments[${paymentLineCounter}][cheque_number]" 
                       placeholder="Cheque Number" class="w-full px-2 py-1 border rounded text-sm">
                <input type="date" name="payments[${paymentLineCounter}][cheque_date]" 
                       class="w-full px-2 py-1 border rounded text-sm">
                <input type="text" name="payments[${paymentLineCounter}][bank_name]" 
                       placeholder="Bank Name" class="w-full px-2 py-1 border rounded text-sm">
            </div>
            <input type="hidden" name="payments[${paymentLineCounter}][cheque_status]" value="pending">
        </div>
    `;
    
    container.appendChild(lineDiv);
    updateTotals();
}

function toggleChequeFields(lineId) {
    const select = document.querySelector(`#payment_line_${lineId} select`);
    const chequeFields = document.getElementById(`cheque_fields_${lineId}`);
    
    if (select.value === 'cheque') {
        chequeFields.classList.remove('hidden');
    } else {
        chequeFields.classList.add('hidden');
    }
}

function removePaymentLine(lineId) {
    const line = document.getElementById(`payment_line_${lineId}`);
    if (line) {
        line.remove();
        updateTotals();
    }
}

function updateTotals() {
    let total = 0;
    document.querySelectorAll('.payment-amount-input').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    
    const remaining = invoiceBalance - total;
    
    document.getElementById('total_payment_display').textContent = 'Rs. ' + total.toFixed(2);
    document.getElementById('remaining_balance_display').textContent = 'Rs. ' + Math.max(0, remaining).toFixed(2);
    
    if (remaining < 0) {
        document.getElementById('remaining_balance_display').className = 'font-semibold text-orange-600';
    } else if (remaining === 0) {
        document.getElementById('remaining_balance_display').className = 'font-semibold text-green-600';
    } else {
        document.getElementById('remaining_balance_display').className = 'font-semibold text-red-600';
    }
}

// Auto-add first payment line if invoice loaded
document.addEventListener('DOMContentLoaded', function() {
    if (invoiceBalance > 0) {
        addPaymentLine();
    }
});
</script>

<?php include 'footer.php'; ?>
