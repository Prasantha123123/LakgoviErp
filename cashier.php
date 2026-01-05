<?php
// cashier.php - Complete Billing/Sales System with Split Payment Support
include 'header.php';
require_once 'payment_functions.php';

$success = '';
$error = '';

// Get next invoice number
try {
    $stmt = $db->query("SELECT invoice_no FROM sales_invoices ORDER BY id DESC LIMIT 1");
    $last_invoice = $stmt->fetch();
    if ($last_invoice) {
        $last_number = intval(substr($last_invoice['invoice_no'], 3));
        $next_invoice_no = 'INV' . str_pad($last_number + 1, 5, '0', STR_PAD_LEFT);
    } else {
        $next_invoice_no = 'INV00001';
    }
} catch(PDOException $e) {
    $next_invoice_no = 'INV00001';
}

// Fetch active customers
try {
    $stmt = $db->query("
        SELECT c.*, pl.price_list_name, pl.currency, pl.id as price_list_id
        FROM customers c
        LEFT JOIN price_lists pl ON c.price_list_id = pl.id
        WHERE c.is_active = 1
        ORDER BY c.customer_name
    ");
    $customers = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching customers: " . $e->getMessage();
    $customers = [];
}

// Fetch finished goods with total stock (all locations)
try {
    $stmt = $db->query("
        SELECT 
            i.id,
            i.code,
            i.name,
            i.type,
            u.symbol as unit,
            COALESCE((
                SELECT SUM(quantity_in - quantity_out)
                FROM stock_ledger sl
                WHERE sl.item_id = i.id
            ), 0) as available_stock
        FROM items i
        JOIN units u ON i.unit_id = u.id
        WHERE i.type = 'finished'
        ORDER BY i.name
    ");
    $finished_goods = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
    $finished_goods = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'create_invoice') {
            $db->beginTransaction();
            
            // Validate items
            if (empty($_POST['items']) || !is_array($_POST['items'])) {
                throw new Exception("Please add at least one item to the invoice");
            }
            
            // Get customer's price list
            $stmt = $db->prepare("
                SELECT c.price_list_id, pl.currency 
                FROM customers c 
                LEFT JOIN price_lists pl ON c.price_list_id = pl.id 
                WHERE c.id = ?
            ");
            $stmt->execute([$_POST['customer_id']]);
            $customer_data = $stmt->fetch();
            
            if (!$customer_data || !$customer_data['price_list_id']) {
                throw new Exception("Customer does not have a price list assigned");
            }
            
            $subtotal = 0;
            $total_discount = 0;
            
            // Calculate totals
            foreach ($_POST['items'] as $item_data) {
                if (empty($item_data['item_id']) || $item_data['quantity'] <= 0) {
                    continue;
                }
                
                $quantity = floatval($item_data['quantity']);
                $unit_price = floatval($item_data['unit_price']);
                $discount_pct = floatval($item_data['discount_percentage'] ?? 0);
                
                $line_subtotal = $quantity * $unit_price;
                $line_discount = $line_subtotal * ($discount_pct / 100);
                
                $subtotal += $line_subtotal;
                $total_discount += $line_discount;
            }
            
            $tax_amount = floatval($_POST['tax_amount'] ?? 0);
            $total_amount = $subtotal - $total_discount + $tax_amount;
            
            // Create invoice
            $stmt = $db->prepare("
                INSERT INTO sales_invoices (
                    invoice_no, customer_id, invoice_date, due_date, price_list_id,
                    subtotal, discount_amount, tax_amount, total_amount, 
                    paid_amount, balance_amount, payment_status, status, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?)
            ");
            
            $paid_amount = floatval($_POST['paid_amount'] ?? 0);
            $balance_amount = $total_amount - $paid_amount;
            
            // If customer paid more than total, balance should be 0 (we give change)
            if ($balance_amount < 0) {
                $balance_amount = 0;
            }
            
            $payment_status = $paid_amount >= $total_amount ? 'paid' : ($paid_amount > 0 ? 'partial' : 'unpaid');
            
            $stmt->execute([
                $_POST['invoice_no'],
                $_POST['customer_id'],
                $_POST['invoice_date'],
                $_POST['due_date'] ?? null,
                $customer_data['price_list_id'],
                $subtotal,
                $total_discount,
                $tax_amount,
                $total_amount,
                $paid_amount,
                $balance_amount,
                $payment_status,
                $_POST['notes'] ?? null,
                $_SESSION['user_id']
            ]);
            
            $invoice_id = $db->lastInsertId();
            
            // Insert invoice items and update stock
            foreach ($_POST['items'] as $item_data) {
                if (empty($item_data['item_id']) || $item_data['quantity'] <= 0) {
                    continue;
                }
                
                $item_id = intval($item_data['item_id']);
                $quantity = floatval($item_data['quantity']);
                $unit_price = floatval($item_data['unit_price']);
                $discount_pct = floatval($item_data['discount_percentage'] ?? 0);
                
                $line_subtotal = $quantity * $unit_price;
                $line_discount = $line_subtotal * ($discount_pct / 100);
                $line_total = $line_subtotal - $line_discount;
                
                // Check total stock availability (across all locations)
                $stmt = $db->prepare("
                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as available_stock
                    FROM stock_ledger
                    WHERE item_id = ?
                ");
                $stmt->execute([$item_id]);
                $stock_result = $stmt->fetch();
                
                if ($stock_result['available_stock'] < $quantity) {
                    throw new Exception("Insufficient stock for item. Available: " . number_format($stock_result['available_stock'], 3));
                }
                
                // Insert invoice item (location_id set to default store - location 1)
                $stmt = $db->prepare("
                    INSERT INTO sales_invoice_items (
                        invoice_id, item_id, quantity, unit_price, 
                        discount_percentage, discount_amount, line_total, location_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ");
                
                $stmt->execute([
                    $invoice_id,
                    $item_id,
                    $quantity,
                    $unit_price,
                    $discount_pct,
                    $line_discount,
                    $line_total
                ]);
                
                // Deduct from finished goods stock - prioritize locations with stock
                // Get locations with stock for this item
                $stmt = $db->prepare("
                    SELECT location_id, SUM(quantity_in - quantity_out) as balance
                    FROM stock_ledger
                    WHERE item_id = ?
                    GROUP BY location_id
                    HAVING balance > 0
                    ORDER BY balance DESC
                ");
                $stmt->execute([$item_id]);
                $locations_with_stock = $stmt->fetchAll();
                
                $remaining_qty = $quantity;
                
                // Deduct from each location until quantity is fulfilled
                foreach ($locations_with_stock as $loc) {
                    if ($remaining_qty <= 0) break;
                    
                    $deduct_qty = min($remaining_qty, $loc['balance']);
                    
                    // Get current balance for this location
                    $stmt = $db->prepare("
                        SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance
                        FROM stock_ledger
                        WHERE item_id = ? AND location_id = ?
                    ");
                    $stmt->execute([$item_id, $loc['location_id']]);
                    $balance_result = $stmt->fetch();
                    $new_balance = $balance_result['current_balance'] - $deduct_qty;
                    
                    // Record stock deduction
                    $stmt = $db->prepare("
                        INSERT INTO stock_ledger (
                            item_id, location_id, transaction_type, reference_id, 
                            reference_no, transaction_date, quantity_out, balance
                        ) VALUES (?, ?, 'sales', ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $item_id,
                        $loc['location_id'],
                        $invoice_id,
                        $_POST['invoice_no'],
                        $_POST['invoice_date'],
                        $deduct_qty,
                        $new_balance
                    ]);
                    
                    $remaining_qty -= $deduct_qty;
                }
                
                // Update item current_stock
                $stmt = $db->prepare("UPDATE items SET current_stock = current_stock - ? WHERE id = ?");
                $stmt->execute([$quantity, $item_id]);
            }
            
            // Create payment records for each payment line (SPLIT PAYMENT SUPPORT)
            if (!empty($_POST['payments']) && is_array($_POST['payments'])) {
                // Insert each payment line into sales_payments
                insertPaymentLines($db, $invoice_id, $_POST['payments'], 'initial', $_SESSION['user_id']);
                
                // Recompute invoice totals based on actual payments
                recomputeInvoiceTotals($db, $invoice_id);
            }
            
            $db->commit();
            $success = "Invoice created successfully! Invoice No: " . $_POST['invoice_no'];
            
            // Redirect to print page
            echo "<script>window.location.href='print_invoice.php?id={$invoice_id}';</script>";
            exit;
        }
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">💰 Cashier / Point of Sale</h1>
            <p class="text-gray-600">Create sales invoices and process payments</p>
        </div>
        <a href="sales_list.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
            View All Sales
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

    <!-- Billing Form -->
    <form method="POST" id="billingForm" class="space-y-6">
        <input type="hidden" name="action" value="create_invoice">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left Column - Invoice Details -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Customer & Invoice Info Card -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Invoice Information</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Invoice No *</label>
                            <input type="text" name="invoice_no" value="<?php echo $next_invoice_no; ?>" 
                                   required readonly class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Invoice Date *</label>
                            <input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" 
                                   required class="w-full px-3 py-2 border border-gray-300 rounded-md">
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Customer *</label>
                            <select name="customer_id" id="customer_select" required 
                                    onchange="loadCustomerPriceList()" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md">
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>" 
                                            data-pricelist="<?php echo $customer['price_list_id']; ?>"
                                            data-currency="<?php echo $customer['currency']; ?>">
                                        <?php echo htmlspecialchars($customer['customer_code'] . ' - ' . $customer['customer_name']); ?>
                                        <?php if ($customer['city']): ?>
                                            (<?php echo htmlspecialchars($customer['city']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="mt-1 text-sm text-gray-500">
                                Price List: <span id="customer_pricelist_name" class="font-medium">-</span>
                            </p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                            <input type="date" name="due_date" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <input type="text" name="notes" placeholder="Optional notes" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                        </div>
                    </div>
                </div>

                <!-- Items Card -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold text-gray-900">Items</h2>
                        <button type="button" onclick="addItemRow()" 
                                class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600">
                            + Add Item
                        </button>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200" id="itemsTable">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                                    <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Stock</th>
                                    <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Qty</th>
                                    <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                                    <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Disc %</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                    <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="itemsBody">
                                <!-- Items will be added here dynamically -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-4 text-sm text-gray-500">
                        <p>💡 <strong>Tip:</strong> Select a customer first to load their price list, then add items</p>
                    </div>
                </div>
            </div>

            <!-- Right Column - Summary -->
            <div class="space-y-6">
                <!-- Summary Card -->
                <div class="bg-white rounded-lg shadow p-6 sticky top-4">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Summary</h2>
                    
                    <div class="space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Subtotal:</span>
                            <span id="subtotal_display" class="font-medium">0.00</span>
                        </div>
                        
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Discount:</span>
                            <span id="discount_display" class="font-medium text-red-600">0.00</span>
                        </div>
                        
                        <div class="flex justify-between text-sm items-center">
                            <span class="text-gray-600">Tax:</span>
                            <input type="number" name="tax_amount" id="tax_amount" step="0.01" min="0" value="0" 
                                   onchange="calculateTotals()"
                                   class="w-24 px-2 py-1 text-right border border-gray-300 rounded text-sm">
                        </div>
                        
                        <div class="border-t pt-3">
                            <div class="flex justify-between">
                                <span class="text-lg font-semibold">Total:</span>
                                <span id="total_display" class="text-lg font-bold text-primary">0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-6 pt-6 border-t">
                        <div class="flex justify-between items-center mb-3">
                            <h3 class="font-semibold text-gray-900">Payment Lines</h3>
                            <button type="button" onclick="addPaymentLine()" 
                                    class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">
                                + Add Payment
                            </button>
                        </div>
                        
                        <div id="paymentLinesContainer" class="space-y-3">
                            <!-- Payment lines will be added here dynamically -->
                        </div>
                        
                        <div class="mt-4 space-y-2 pt-3 border-t">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">Total Payments:</span>
                                <span id="total_payments_display" class="font-medium text-green-600">0.00</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">Balance Due:</span>
                                <span id="balance_display" class="font-semibold text-red-600">0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-6">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Additional Notes</label>
                        <textarea name="invoice_notes" rows="3" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"
                                  placeholder="Additional notes..."></textarea>
                    </div>
                    
                    <div class="mt-6 space-y-2">
                        <button type="submit" 
                                class="w-full bg-primary text-white px-4 py-3 rounded-md hover:bg-blue-600 font-semibold">
                            💰 Complete Sale & Print
                        </button>
                        <button type="button" onclick="resetForm()"
                                class="w-full bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600">
                            Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
// Store items and price lists data
const finishedGoods = <?php echo json_encode($finished_goods); ?>;
const customersData = <?php echo json_encode($customers); ?>;
let currentPriceListId = null;
let priceListItems = {};
let itemRowCounter = 0;

// Load customer's price list
function loadCustomerPriceList() {
    const select = document.getElementById('customer_select');
    const selectedOption = select.options[select.selectedIndex];
    
    if (!selectedOption.value) {
        currentPriceListId = null;
        document.getElementById('customer_pricelist_name').textContent = '-';
        return;
    }
    
    currentPriceListId = selectedOption.dataset.pricelist;
    const currency = selectedOption.dataset.currency || '';
    
    // Find customer data
    const customer = customersData.find(c => c.id == selectedOption.value);
    document.getElementById('customer_pricelist_name').textContent = 
        customer && customer.price_list_name ? customer.price_list_name : 'Not assigned';
    
    // Fetch price list items
    if (currentPriceListId) {
        fetch(`get_pricelist_items.php?price_list_id=${currentPriceListId}`)
            .then(response => response.json())
            .then(data => {
                priceListItems = {};
                data.forEach(item => {
                    priceListItems[item.item_id] = {
                        unit_price: parseFloat(item.unit_price),
                        discount_percentage: parseFloat(item.discount_percentage)
                    };
                });
            })
            .catch(error => console.error('Error loading price list:', error));
    }
}

// Add new item row
function addItemRow() {
    if (!currentPriceListId) {
        alert('Please select a customer first');
        return;
    }
    
    itemRowCounter++;
    const tbody = document.getElementById('itemsBody');
    const row = document.createElement('tr');
    row.id = `item_row_${itemRowCounter}`;
    row.className = 'item-row';
    
    row.innerHTML = `
        <td class="px-3 py-2">
            <select name="items[${itemRowCounter}][item_id]" 
                    onchange="updateItemPrice(${itemRowCounter})" 
                    class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select" required>
                <option value="">-- Select Item --</option>
                ${finishedGoods.map(item => 
                    `<option value="${item.id}" 
                             data-stock="${item.available_stock}" 
                             data-unit="${item.unit}">
                        ${item.code} - ${item.name}
                    </option>`
                ).join('')}
            </select>
        </td>
        <td class="px-3 py-2 text-center">
            <span id="stock_${itemRowCounter}" class="text-sm font-medium text-gray-600">-</span>
            <span id="unit_${itemRowCounter}" class="text-xs text-gray-500"></span>
        </td>
        <td class="px-3 py-2">
            <input type="number" 
                   name="items[${itemRowCounter}][quantity]" 
                   id="qty_${itemRowCounter}"
                   step="0.001" min="0.001" 
                   onchange="calculateLineTotal(${itemRowCounter})" 
                   class="w-20 px-2 py-1 border border-gray-300 rounded text-sm text-center" required>
        </td>
        <td class="px-3 py-2">
            <input type="number" 
                   name="items[${itemRowCounter}][unit_price]" 
                   id="price_${itemRowCounter}"
                   step="0.01" min="0" 
                   onchange="calculateLineTotal(${itemRowCounter})" 
                   class="w-24 px-2 py-1 border border-gray-300 rounded text-sm text-right" required>
        </td>
        <td class="px-3 py-2">
            <input type="number" 
                   name="items[${itemRowCounter}][discount_percentage]" 
                   id="disc_${itemRowCounter}"
                   step="0.01" min="0" max="100" value="0"
                   onchange="calculateLineTotal(${itemRowCounter})" 
                   class="w-16 px-2 py-1 border border-gray-300 rounded text-sm text-center">
        </td>
        <td class="px-3 py-2 text-right">
            <span id="total_${itemRowCounter}" class="font-medium">0.00</span>
        </td>
        <td class="px-3 py-2 text-center">
            <button type="button" onclick="removeItemRow(${itemRowCounter})" 
                    class="text-red-600 hover:text-red-800">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>
        </td>
    `;
    
    tbody.appendChild(row);
}

// Update item price from price list
function updateItemPrice(rowId) {
    const select = document.querySelector(`#item_row_${rowId} .item-select`);
    const selectedOption = select.options[select.selectedIndex];
    
    if (!selectedOption.value) {
        return;
    }
    
    const itemId = selectedOption.value;
    const stock = selectedOption.dataset.stock;
    const unit = selectedOption.dataset.unit;
    
    // Display stock
    document.getElementById(`stock_${rowId}`).textContent = parseFloat(stock).toFixed(3);
    document.getElementById(`unit_${rowId}`).textContent = unit;
    
    // Warn if low/no stock
    if (parseFloat(stock) <= 0) {
        alert('Warning: This item has no stock available!');
    } else if (parseFloat(stock) < 10) {
        console.log(`Low stock warning: Only ${stock} units available`);
    }
    
    // Set price from price list
    if (priceListItems[itemId]) {
        document.getElementById(`price_${rowId}`).value = priceListItems[itemId].unit_price.toFixed(2);
        document.getElementById(`disc_${rowId}`).value = priceListItems[itemId].discount_percentage.toFixed(2);
    } else {
        document.getElementById(`price_${rowId}`).value = '0.00';
        document.getElementById(`disc_${rowId}`).value = '0.00';
    }
    
    calculateLineTotal(rowId);
}

// Calculate line total
function calculateLineTotal(rowId) {
    const qty = parseFloat(document.getElementById(`qty_${rowId}`).value) || 0;
    const price = parseFloat(document.getElementById(`price_${rowId}`).value) || 0;
    const disc = parseFloat(document.getElementById(`disc_${rowId}`).value) || 0;
    
    const subtotal = qty * price;
    const discount = subtotal * (disc / 100);
    const total = subtotal - discount;
    
    document.getElementById(`total_${rowId}`).textContent = total.toFixed(2);
    
    calculateTotals();
}

// Calculate totals
function calculateTotals() {
    let subtotal = 0;
    let totalDiscount = 0;
    
    document.querySelectorAll('.item-row').forEach(row => {
        const rowId = row.id.split('_')[2];
        const qty = parseFloat(document.getElementById(`qty_${rowId}`).value) || 0;
        const price = parseFloat(document.getElementById(`price_${rowId}`).value) || 0;
        const disc = parseFloat(document.getElementById(`disc_${rowId}`).value) || 0;
        
        const lineSubtotal = qty * price;
        const lineDiscount = lineSubtotal * (disc / 100);
        
        subtotal += lineSubtotal;
        totalDiscount += lineDiscount;
    });
    
    const tax = parseFloat(document.getElementById('tax_amount').value) || 0;
    const total = subtotal - totalDiscount + tax;
    
    document.getElementById('subtotal_display').textContent = subtotal.toFixed(2);
    document.getElementById('discount_display').textContent = totalDiscount.toFixed(2);
    document.getElementById('total_display').textContent = total.toFixed(2);
    
    calculateBalance();
}

// Calculate balance based on payment lines
function calculateBalance() {
    const total = parseFloat(document.getElementById('total_display').textContent) || 0;
    let totalPayments = 0;
    
    // Sum all payment line amounts
    document.querySelectorAll('.payment-line-amount').forEach(input => {
        totalPayments += parseFloat(input.value) || 0;
    });
    
    const balance = total - totalPayments;
    
    const balanceDisplay = document.getElementById('balance_display');
    const totalPaymentsDisplay = document.getElementById('total_payments_display');
    
    totalPaymentsDisplay.textContent = totalPayments.toFixed(2);
    
    if (balance > 0) {
        balanceDisplay.textContent = balance.toFixed(2);
        balanceDisplay.className = 'font-semibold text-red-600';
    } else if (balance < 0) {
        balanceDisplay.textContent = 'Change: ' + Math.abs(balance).toFixed(2);
        balanceDisplay.className = 'font-semibold text-green-600';
    } else {
        balanceDisplay.textContent = '0.00';
        balanceDisplay.className = 'font-semibold text-gray-600';
    }
}

// Payment line counter
let paymentLineCounter = 0;

// Add new payment line
function addPaymentLine() {
    paymentLineCounter++;
    const container = document.getElementById('paymentLinesContainer');
    const total = parseFloat(document.getElementById('total_display').textContent) || 0;
    
    // Calculate remaining balance
    let currentPayments = 0;
    document.querySelectorAll('.payment-line-amount').forEach(input => {
        currentPayments += parseFloat(input.value) || 0;
    });
    const remainingBalance = Math.max(0, total - currentPayments);
    
    const lineDiv = document.createElement('div');
    lineDiv.id = `payment_line_${paymentLineCounter}`;
    lineDiv.className = 'payment-line bg-gray-50 p-3 rounded border';
    
    lineDiv.innerHTML = `
        <div class="flex justify-between items-center mb-2">
            <span class="text-xs font-semibold text-gray-600">Payment #${paymentLineCounter}</span>
            <button type="button" onclick="removePaymentLine(${paymentLineCounter})" 
                    class="text-red-500 hover:text-red-700 text-sm">✕ Remove</button>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-xs font-medium text-gray-600">Method</label>
                <select name="payments[${paymentLineCounter}][method]" 
                        onchange="togglePaymentChequeFields(${paymentLineCounter})"
                        class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600">Amount</label>
                <input type="number" name="payments[${paymentLineCounter}][amount]" 
                       step="0.01" min="0" value="${remainingBalance.toFixed(2)}"
                       onchange="calculateBalance()"
                       class="w-full px-2 py-1 border border-gray-300 rounded text-sm text-right payment-line-amount">
            </div>
        </div>
        <div class="mt-2">
            <label class="block text-xs font-medium text-gray-600">Reference No</label>
            <input type="text" name="payments[${paymentLineCounter}][reference_no]" 
                   placeholder="Card TXN / Receipt No"
                   class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
        </div>
        <div id="bank_fields_${paymentLineCounter}" class="hidden mt-2 bg-green-50 p-2 rounded border border-green-200">
            <p class="text-xs font-semibold text-green-800 mb-2">Bank Transfer Details</p>
            <div>
                <label class="block text-xs text-gray-600">Bank Name</label>
                <input type="text" name="payments[${paymentLineCounter}][bank_name]" 
                       placeholder="e.g. Commercial Bank"
                       class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
            </div>
        </div>
        <div id="cheque_fields_${paymentLineCounter}" class="hidden mt-2 bg-blue-50 p-2 rounded border border-blue-200">
            <p class="text-xs font-semibold text-blue-800 mb-2">Cheque Details</p>
            <div class="grid grid-cols-1 gap-2">
                <div>
                    <label class="block text-xs text-gray-600">Cheque Number</label>
                    <input type="text" name="payments[${paymentLineCounter}][cheque_number]" 
                           class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600">Cheque Date</label>
                    <input type="date" name="payments[${paymentLineCounter}][cheque_date]" 
                           class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600">Bank Name</label>
                    <input type="text" name="payments[${paymentLineCounter}][cheque_bank_name]" 
                           placeholder="e.g. Commercial Bank"
                           class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                </div>
            </div>
            <p class="text-xs text-blue-600 mt-1">⏳ Status: Pending</p>
            <input type="hidden" name="payments[${paymentLineCounter}][cheque_status]" value="pending">
        </div>
    `;
    
    container.appendChild(lineDiv);
    calculateBalance();
}

// Toggle cheque fields for a payment line
function togglePaymentChequeFields(lineId) {
    const select = document.querySelector(`#payment_line_${lineId} select`);
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

// Remove payment line
function removePaymentLine(lineId) {
    const line = document.getElementById(`payment_line_${lineId}`);
    if (line) {
        line.remove();
        calculateBalance();
    }
}

// Remove item row
function removeItemRow(rowId) {
    const row = document.getElementById(`item_row_${rowId}`);
    if (row) {
        row.remove();
        calculateTotals();
    }
}

// Reset form
function resetForm() {
    if (confirm('Are you sure you want to reset the form?')) {
        document.getElementById('billingForm').reset();
        document.getElementById('itemsBody').innerHTML = '';
        document.getElementById('paymentLinesContainer').innerHTML = '';
        currentPriceListId = null;
        priceListItems = {};
        itemRowCounter = 0;
        paymentLineCounter = 0;
        calculateTotals();
    }
}

// Auto-add first payment line when form loads with items
document.addEventListener('DOMContentLoaded', function() {
    // Initialize with empty state
    calculateBalance();
});
</script>

<?php include 'footer.php'; ?>