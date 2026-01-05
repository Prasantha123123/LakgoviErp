<?php
// customer_pending_bills.php - View all pending invoices by customer
include 'header.php';
require_once 'payment_functions.php';

$success = '';
$error = '';

// Get selected customer
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$pending_invoices = [];
$selected_customer = null;

// Fetch all customers
try {
    $stmt = $db->query("
        SELECT c.*, 
               COUNT(DISTINCT CASE WHEN si.payment_status != 'paid' AND si.status = 'confirmed' THEN si.id END) as pending_count,
               COALESCE(SUM(CASE WHEN si.payment_status != 'paid' AND si.status = 'confirmed' THEN si.balance_amount ELSE 0 END), 0) as total_pending
        FROM customers c
        LEFT JOIN sales_invoices si ON c.id = si.customer_id
        WHERE c.is_active = 1
        GROUP BY c.id
        ORDER BY total_pending DESC, c.customer_name
    ");
    $customers = $stmt->fetchAll();
} catch(PDOException $e) {
    $customers = [];
}

// If customer selected, get their pending invoices
if ($customer_id > 0) {
    try {
        // Get customer details
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $selected_customer = $stmt->fetch();
        
        // Get pending invoices for this customer
        $pending_invoices = getCustomerPendingInvoices($db, $customer_id);
        
    } catch(PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Calculate totals
$total_outstanding = 0;
$total_pending_count = 0;
foreach ($customers as $c) {
    $total_outstanding += floatval($c['total_pending']);
    $total_pending_count += intval($c['pending_count']);
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">📋 Customer Pending Bills</h1>
            <p class="text-gray-600">View and manage outstanding invoices by customer</p>
        </div>
        <div class="flex space-x-2">
            <a href="payment_management.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
                📊 Payment Management
            </a>
            <a href="add_invoice_payment.php" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                💳 Add Payment
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
        <div class="bg-gradient-to-r from-orange-500 to-orange-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Total Outstanding</div>
                    <div class="text-2xl font-bold mt-2">Rs. <?php echo number_format($total_outstanding, 2); ?></div>
                </div>
                <div class="text-4xl opacity-75">💸</div>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-red-500 to-red-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Pending Invoices</div>
                    <div class="text-2xl font-bold mt-2"><?php echo $total_pending_count; ?></div>
                </div>
                <div class="text-4xl opacity-75">📄</div>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium opacity-90">Customers with Dues</div>
                    <div class="text-2xl font-bold mt-2">
                        <?php echo count(array_filter($customers, function($c) { return $c['pending_count'] > 0; })); ?>
                    </div>
                </div>
                <div class="text-4xl opacity-75">👥</div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Customer List (Left) -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-4 border-b">
                <h2 class="text-lg font-semibold text-gray-900">Customers</h2>
                <input type="text" id="customerSearch" placeholder="Search customer..." 
                       onkeyup="filterCustomers()"
                       class="mt-2 w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            
            <div class="max-h-[600px] overflow-y-auto" id="customerList">
                <?php foreach ($customers as $c): ?>
                    <?php if ($c['pending_count'] > 0 || $c['id'] == $customer_id): ?>
                    <a href="?customer_id=<?php echo $c['id']; ?>" 
                       class="customer-item block p-4 border-b hover:bg-gray-50 <?php echo $c['id'] == $customer_id ? 'bg-blue-50 border-l-4 border-l-primary' : ''; ?>"
                       data-name="<?php echo strtolower($c['customer_name'] . ' ' . $c['customer_code']); ?>">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($c['customer_name']); ?></p>
                                <p class="text-sm text-gray-500"><?php echo htmlspecialchars($c['customer_code']); ?></p>
                                <?php if ($c['phone']): ?>
                                    <p class="text-xs text-gray-400"><?php echo htmlspecialchars($c['phone']); ?></p>
                                <?php endif; ?>
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
                <!-- Customer Header -->
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
                            <p class="text-2xl font-bold text-red-600">
                                Rs. <?php 
                                $customer_total = array_sum(array_column($pending_invoices, 'balance_amount'));
                                echo number_format($customer_total, 2); 
                                ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Invoices Table -->
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
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Due Date</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($pending_invoices as $inv): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3">
                                                <a href="print_invoice.php?id=<?php echo $inv['id']; ?>" 
                                                   class="text-primary hover:underline font-medium">
                                                    <?php echo htmlspecialchars($inv['invoice_no']); ?>
                                                </a>
                                            </td>
                                            <td class="px-4 py-3 text-sm">
                                                <?php echo date('d M Y', strtotime($inv['invoice_date'])); ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm">
                                                <?php if ($inv['due_date']): ?>
                                                    <?php 
                                                    $due = strtotime($inv['due_date']);
                                                    $today = strtotime(date('Y-m-d'));
                                                    $overdue = $due < $today;
                                                    ?>
                                                    <span class="<?php echo $overdue ? 'text-red-600 font-medium' : ''; ?>">
                                                        <?php echo date('d M Y', $due); ?>
                                                        <?php if ($overdue): ?>
                                                            <span class="ml-1 text-xs">(Overdue)</span>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                Rs. <?php echo number_format($inv['total_amount'], 2); ?>
                                            </td>
                                            <td class="px-4 py-3 text-right text-green-600">
                                                Rs. <?php echo number_format($inv['paid_amount'], 2); ?>
                                            </td>
                                            <td class="px-4 py-3 text-right font-bold text-red-600">
                                                Rs. <?php echo number_format($inv['balance_amount'], 2); ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold 
                                                    <?php echo $inv['payment_status'] === 'partial' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'; ?>">
                                                    <?php echo ucfirst($inv['payment_status']); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <div class="flex justify-center space-x-2">
                                                    <button onclick="openPaymentModal(<?php echo $inv['id']; ?>, '<?php echo htmlspecialchars($inv['invoice_no']); ?>', <?php echo $inv['balance_amount']; ?>)" 
                                                            class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">
                                                        Pay Now
                                                    </button>
                                                    <a href="invoice_payment_history.php?invoice_id=<?php echo $inv['id']; ?>" 
                                                       class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700">
                                                        History
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-gray-100">
                                    <tr>
                                        <td colspan="3" class="px-4 py-3 font-semibold">Total</td>
                                        <td class="px-4 py-3 text-right font-semibold">
                                            Rs. <?php echo number_format(array_sum(array_column($pending_invoices, 'total_amount')), 2); ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold text-green-600">
                                            Rs. <?php echo number_format(array_sum(array_column($pending_invoices, 'paid_amount')), 2); ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-bold text-red-600">
                                            Rs. <?php echo number_format($customer_total, 2); ?>
                                        </td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                <div class="text-6xl mb-4">👈</div>
                <p class="text-lg">Select a customer from the list to view their pending invoices</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Payment Modal -->
<div id="paymentModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold">Quick Payment</h3>
            <button onclick="closePaymentModal()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        
        <form action="add_invoice_payment.php" method="POST" id="quickPaymentForm">
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="invoice_id" id="modal_invoice_id">
            
            <div class="mb-4">
                <p class="text-sm text-gray-500">Invoice</p>
                <p class="font-semibold" id="modal_invoice_no"></p>
            </div>
            
            <div class="mb-4 p-3 bg-red-50 rounded">
                <p class="text-sm text-gray-600">Balance Due</p>
                <p class="text-2xl font-bold text-red-600" id="modal_balance"></p>
            </div>
            
            <div id="modalPaymentLines" class="space-y-3 mb-4">
                <!-- Payment lines will be added here -->
            </div>
            
            <div class="flex justify-between mb-4">
                <button type="button" onclick="addModalPaymentLine()" 
                        class="text-green-600 hover:text-green-800 text-sm">
                    + Add another payment method
                </button>
            </div>
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closePaymentModal()" 
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
let modalLineCounter = 0;

function filterCustomers() {
    const search = document.getElementById('customerSearch').value.toLowerCase();
    document.querySelectorAll('.customer-item').forEach(item => {
        const name = item.dataset.name;
        item.style.display = name.includes(search) ? 'block' : 'none';
    });
}

function openPaymentModal(invoiceId, invoiceNo, balance) {
    document.getElementById('modal_invoice_id').value = invoiceId;
    document.getElementById('modal_invoice_no').textContent = invoiceNo;
    document.getElementById('modal_balance').textContent = 'Rs. ' + parseFloat(balance).toFixed(2);
    modalBalance = balance;
    modalLineCounter = 0;
    
    // Clear and add first payment line
    document.getElementById('modalPaymentLines').innerHTML = '';
    addModalPaymentLine();
    
    document.getElementById('paymentModal').classList.remove('hidden');
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.add('hidden');
}

function addModalPaymentLine() {
    modalLineCounter++;
    const container = document.getElementById('modalPaymentLines');
    
    // Calculate remaining
    let currentTotal = 0;
    container.querySelectorAll('.modal-payment-amount').forEach(input => {
        currentTotal += parseFloat(input.value) || 0;
    });
    const remaining = Math.max(0, modalBalance - currentTotal);
    
    const div = document.createElement('div');
    div.className = 'bg-gray-50 p-3 rounded border';
    div.innerHTML = `
        <div class="grid grid-cols-2 gap-2 mb-2">
            <div>
                <label class="block text-xs text-gray-600">Method</label>
                <select name="payments[${modalLineCounter}][method]" 
                        onchange="toggleModalCheque(this, ${modalLineCounter})"
                        class="w-full px-2 py-1 border rounded text-sm">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600">Amount</label>
                <input type="number" name="payments[${modalLineCounter}][amount]" 
                       value="${remaining.toFixed(2)}" step="0.01" min="0"
                       class="w-full px-2 py-1 border rounded text-sm text-right modal-payment-amount">
            </div>
        </div>
        <div>
            <label class="block text-xs text-gray-600">Reference</label>
            <input type="text" name="payments[${modalLineCounter}][reference_no]" 
                   placeholder="Optional" class="w-full px-2 py-1 border rounded text-sm">
        </div>
        <div id="modal_cheque_${modalLineCounter}" class="hidden mt-2 bg-blue-50 p-2 rounded grid grid-cols-1 gap-1">
            <input type="text" name="payments[${modalLineCounter}][cheque_number]" 
                   placeholder="Cheque Number" class="w-full px-2 py-1 border rounded text-sm">
            <input type="date" name="payments[${modalLineCounter}][cheque_date]" 
                   class="w-full px-2 py-1 border rounded text-sm">
            <input type="text" name="payments[${modalLineCounter}][bank_name]" 
                   placeholder="Bank Name" class="w-full px-2 py-1 border rounded text-sm">
            <input type="hidden" name="payments[${modalLineCounter}][cheque_status]" value="pending">
        </div>
    `;
    container.appendChild(div);
}

function toggleModalCheque(select, lineId) {
    const chequeFields = document.getElementById(`modal_cheque_${lineId}`);
    if (select.value === 'cheque') {
        chequeFields.classList.remove('hidden');
    } else {
        chequeFields.classList.add('hidden');
    }
}

// Close modal on outside click
window.onclick = function(event) {
    const modal = document.getElementById('paymentModal');
    if (event.target === modal) {
        closePaymentModal();
    }
}
</script>

<?php include 'footer.php'; ?>
