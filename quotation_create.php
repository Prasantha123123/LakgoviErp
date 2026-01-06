<?php
// quotation_create.php - Create New Quotations

// Process POST before any output (to allow redirect)
require_once 'database.php';
require_once 'config/simple_auth.php';

session_start();

$database = new Database();
$db = $database->getConnection();
$auth = new SimpleAuth($db);

if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Configuration
$allow_manual_price = false;

$success = '';
$error = '';
$redirect_url = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        
        // Validate common fields
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $price_list_id = intval($_POST['price_list_id'] ?? 0);
        $quotation_date = $_POST['quotation_date'] ?? date('Y-m-d');
        $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $terms_conditions = trim($_POST['terms_conditions'] ?? '');
        $internal_notes = trim($_POST['internal_notes'] ?? '');
        $discount_type = $_POST['discount_type'] ?? 'fixed';
        $discount_value = floatval($_POST['discount_value'] ?? 0);
        $tax_percentage = floatval($_POST['tax_percentage'] ?? 0);
        
        // Validate customer
        if (!$customer_id) {
            throw new Exception("Please select a customer.");
        }
        
        // Get price list currency (or default to LKR if no price list)
        $currency = 'LKR';
        if ($price_list_id) {
            $stmt = $db->prepare("SELECT id, currency FROM price_lists WHERE id = ? AND is_active = 1");
            $stmt->execute([$price_list_id]);
            $price_list = $stmt->fetch();
            if ($price_list) {
                $currency = $price_list['currency'];
            } else {
                $price_list_id = null; // Reset if price list not found
            }
        }
        
        // Parse items from JSON
        $items_json = $_POST['items_json'] ?? '[]';
        $items = json_decode($items_json, true);
        
        if (empty($items)) {
            throw new Exception("Please add at least one item to the quotation.");
        }
        
        // Calculate totals
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += floatval($item['line_total']);
        }
        
        // Calculate discount
        $discount_total = 0;
        if ($discount_type === 'percentage') {
            $discount_total = ($subtotal * $discount_value) / 100;
        } else {
            $discount_total = $discount_value;
        }
        
        // Calculate tax
        $taxable_amount = $subtotal - $discount_total;
        $tax_total = ($taxable_amount * $tax_percentage) / 100;
        
        // Grand total
        $grand_total = $taxable_amount + $tax_total;
        
        // Determine status (always new quotation)
        $status = ($action === 'save_sent') ? 'sent' : 'draft';
        
        $db->beginTransaction();
        
        // Generate quotation number using stored procedure
        $stmt = $db->prepare("CALL sp_get_next_quotation_no(@next_no)");
        $stmt->execute();
        $stmt = $db->query("SELECT @next_no as quotation_no");
        $result = $stmt->fetch();
        $quotation_no = $result['quotation_no'];
        
        // Insert new quotation
        $stmt = $db->prepare("
            INSERT INTO quotations (
                quotation_no, quotation_date, valid_until, customer_id, price_list_id,
                currency, status, subtotal, discount_type, discount_value, discount_total,
                tax_percentage, tax_total, grand_total, notes, terms_conditions, internal_notes,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $quotation_no, $quotation_date, $valid_until, $customer_id, $price_list_id,
            $currency, $status, $subtotal, $discount_type, $discount_value, $discount_total,
            $tax_percentage, $tax_total, $grand_total, $notes, $terms_conditions, $internal_notes,
            $_SESSION['user_id']
        ]);
        
        $current_quotation_id = $db->lastInsertId();
        
        // Insert items
        $stmt = $db->prepare("
            INSERT INTO quotation_items (
                quotation_id, item_id, item_code, item_name, description, quantity,
                unit_symbol, unit_price, discount_percentage, discount_amount, line_total,
                sort_order, is_manual_price, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $sort_order = 0;
        foreach ($items as $item) {
            $line_discount_amount = (floatval($item['unit_price']) * floatval($item['quantity']) * floatval($item['discount_percentage'] ?? 0)) / 100;
            $stmt->execute([
                $current_quotation_id,
                $item['item_id'],
                $item['item_code'],
                $item['item_name'],
                $item['description'] ?? null,
                $item['quantity'],
                $item['unit_symbol'],
                $item['unit_price'],
                $item['discount_percentage'] ?? 0,
                $line_discount_amount,
                $item['line_total'],
                $sort_order++,
                $item['is_manual_price'] ?? 0,
                $item['notes'] ?? null
            ]);
        }
        
        $db->commit();
        
        // Redirect to quotation view
        header("Location: quotation_view.php?id=" . $current_quotation_id . "&success=created");
        exit;
        
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Fetch all active customers for dropdown
try {
    $stmt = $db->query("SELECT id, customer_code, customer_name, city FROM customers WHERE is_active = 1 ORDER BY customer_name");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $customers = [];
}

// Now include header (after all redirects are handled)
include 'header.php';
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div class="flex items-center space-x-3">
            <a href="quotation_list.php" class="text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Create New Quotation</h1>
                <p class="text-gray-600">Generate a new quotation for a customer</p>
            </div>
        </div>
    </div>

    <!-- Error Messages -->
    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Main Form -->
    <form id="quotationForm" method="POST" class="space-y-6">
        <input type="hidden" name="action" id="form_action" value="save_draft">
        <input type="hidden" name="items_json" id="items_json" value="">
        <input type="hidden" name="price_list_id" id="price_list_id" value="">

        <!-- Customer Selection Section -->
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Customer Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Customer Search/Select -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Customer *</label>
                    <div class="relative">
                        <input type="text" id="customer_search" 
                               placeholder="Search by name, code, or city..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"
                               value="">
                        <input type="hidden" name="customer_id" id="customer_id" value="">
                        <div id="customer_dropdown" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-60 overflow-y-auto">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                </div>
                
                <!-- Quotation Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Quotation Date *</label>
                    <input type="date" name="quotation_date" id="quotation_date" required
                           value="<?php echo date('Y-m-d'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <!-- Customer Details (Read-only display) -->
            <div id="customer_details" class="mt-4 p-4 bg-gray-50 rounded-md hidden">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <span class="font-medium text-gray-700">Contact:</span>
                        <span id="customer_contact" class="text-gray-600 ml-1"></span>
                    </div>
                    <div>
                        <span class="font-medium text-gray-700">Phone:</span>
                        <span id="customer_phone" class="text-gray-600 ml-1"></span>
                    </div>
                    <div>
                        <span class="font-medium text-gray-700">Email:</span>
                        <span id="customer_email" class="text-gray-600 ml-1"></span>
                    </div>
                    <div class="md:col-span-3">
                        <span class="font-medium text-gray-700">Address:</span>
                        <span id="customer_address" class="text-gray-600 ml-1"></span>
                    </div>
                </div>
            </div>

            <!-- Price List Info -->
            <div id="price_list_info" class="mt-4 flex items-center space-x-4 hidden">
                <div class="flex items-center px-3 py-2 bg-blue-50 rounded-md">
                    <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="text-sm">
                        <span class="font-medium">Price List:</span>
                        <span id="price_list_name" class="text-blue-700 ml-1"></span>
                        <span id="price_list_code" class="text-gray-500 ml-1"></span>
                    </span>
                </div>
                <div class="flex items-center px-3 py-2 bg-green-50 rounded-md">
                    <span class="text-sm">
                        <span class="font-medium">Currency:</span>
                        <span id="currency_display" class="text-green-700 ml-1"></span>
                    </span>
                </div>
                <div id="price_list_source_badge" class="px-3 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-600">
                    <!-- Customer/Default badge -->
                </div>
            </div>
        </div>

        <!-- Items Section -->
        <div class="bg-white shadow rounded-lg p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Quotation Items</h2>
                <button type="button" onclick="openAddItemModal()" id="add_item_btn" 
                        class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors disabled:bg-gray-400 disabled:cursor-not-allowed"
                        disabled>
                    <svg class="w-5 h-5 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add Item
                </button>
            </div>

            <!-- Items Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item Code</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item Name</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Unit</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Disc %</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Line Total</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="items_tbody" class="bg-white divide-y divide-gray-200">
                        <tr id="no_items_row">
                            <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                <svg class="w-12 h-12 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                                </svg>
                                No items added yet. Select a customer first, then click "Add Item" to begin.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Totals Section -->
            <div class="mt-6 flex justify-end">
                <div class="w-full md:w-96 space-y-3">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-600">Subtotal:</span>
                        <span id="subtotal_display" class="font-medium">0.00</span>
                    </div>
                    
                    <div class="flex justify-between items-center text-sm">
                        <div class="flex items-center space-x-2">
                            <span class="text-gray-600">Discount:</span>
                            <select name="discount_type" id="discount_type" class="text-xs border-gray-300 rounded py-1">
                                <option value="fixed">Fixed</option>
                                <option value="percentage">%</option>
                            </select>
                            <input type="number" name="discount_value" id="discount_value" step="0.01" min="0"
                                   value="0"
                                   class="w-20 px-2 py-1 text-sm border border-gray-300 rounded text-right"
                                   onchange="calculateTotals()">
                        </div>
                        <span id="discount_display" class="font-medium text-red-600">-0.00</span>
                    </div>
                    
                    <div class="flex justify-between items-center text-sm">
                        <div class="flex items-center space-x-2">
                            <span class="text-gray-600">Tax:</span>
                            <input type="number" name="tax_percentage" id="tax_percentage" step="0.01" min="0" max="100"
                                   value="0"
                                   class="w-16 px-2 py-1 text-sm border border-gray-300 rounded text-right"
                                   onchange="calculateTotals()">
                            <span class="text-gray-500">%</span>
                        </div>
                        <span id="tax_display" class="font-medium">+0.00</span>
                    </div>
                    
                    <div class="border-t pt-3 flex justify-between items-center">
                        <span class="text-lg font-semibold text-gray-900">Grand Total:</span>
                        <span id="grand_total_display" class="text-xl font-bold text-primary">0.00</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Information -->
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Additional Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid Until</label>
                    <input type="date" name="valid_until" id="valid_until"
                           value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex justify-end space-x-3">
            <a href="quotation_list.php" class="px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="button" onclick="saveQuotation('save_draft')"
                    class="px-6 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                <svg class="w-5 h-5 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Save Quotation
            </button>
        </div>
    </form>
</div>

<!-- Add Item Modal -->
<div id="addItemModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Add Item to Quotation</h3>
            <button onclick="closeModal('addItemModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <div class="space-y-4">
            <!-- Item Search -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Search Item *</label>
                <div class="relative">
                    <input type="text" id="item_search" placeholder="Search by code or name..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                    <div id="item_dropdown" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-60 overflow-y-auto">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>
            
            <!-- Selected Item Info -->
            <div id="selected_item_info" class="hidden p-4 bg-gray-50 rounded-md">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="font-medium text-gray-700">Code:</span>
                        <span id="modal_item_code" class="ml-1"></span>
                    </div>
                    <div>
                        <span class="font-medium text-gray-700">Unit:</span>
                        <span id="modal_item_unit" class="ml-1"></span>
                    </div>
                    <div class="col-span-2">
                        <span class="font-medium text-gray-700">Name:</span>
                        <span id="modal_item_name" class="ml-1"></span>
                    </div>
                </div>
            </div>
            
            <!-- Quantity and Price -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Quantity *</label>
                    <input type="number" id="modal_quantity" step="0.001" min="0.001" value="1"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"
                           onchange="calculateModalLineTotal()">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Unit Price *</label>
                    <input type="number" id="modal_unit_price" step="0.01" min="0"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"
                           onchange="calculateModalLineTotal()">
                    <p id="no_price_warning" class="hidden text-sm text-yellow-600 mt-1">
                        No price in price list. Enter price manually.
                    </p>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Discount %</label>
                    <input type="number" id="modal_discount" step="0.01" min="0" max="100" value="0"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"
                           onchange="calculateModalLineTotal()">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Line Total</label>
                    <input type="text" id="modal_line_total" readonly
                           class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 font-medium">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                <input type="text" id="modal_description" placeholder="Additional item description..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            
            <input type="hidden" id="modal_item_id">
            <input type="hidden" id="modal_editing_index" value="-1">
        </div>
        
        <div class="flex justify-end space-x-3 mt-6 pt-4 border-t">
            <button type="button" onclick="closeModal('addItemModal')"
                    class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                Cancel
            </button>
            <button type="button" onclick="addItemToQuotation()" id="modal_add_btn"
                    class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                Add Item
            </button>
        </div>
    </div>
</div>

<script>
// Global variables
let quotationItems = [];
let selectedPriceListId = null;
let selectedCurrency = 'LKR';
const allowManualPrice = <?php echo $allow_manual_price ? 'true' : 'false'; ?>;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Setup customer search
    setupCustomerSearch();
    
    // Setup item search
    setupItemSearch();
});

// Customer search functionality
function setupCustomerSearch() {
    const searchInput = document.getElementById('customer_search');
    const dropdown = document.getElementById('customer_dropdown');
    let debounceTimer;
    
    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const query = this.value.trim();
        
        if (query.length < 2) {
            dropdown.classList.add('hidden');
            return;
        }
        
        debounceTimer = setTimeout(() => {
            fetch(`api/ajax_search_customers.php?search=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.customers.length > 0) {
                        dropdown.innerHTML = data.customers.map(c => `
                            <div class="px-4 py-2 hover:bg-gray-100 cursor-pointer" 
                                 onclick="selectCustomer(${c.id}, '${escapeHtml(c.customer_code)} - ${escapeHtml(c.customer_name)}')">
                                <div class="font-medium">${escapeHtml(c.customer_name)}</div>
                                <div class="text-sm text-gray-500">${escapeHtml(c.customer_code)} | ${escapeHtml(c.city || '')}</div>
                            </div>
                        `).join('');
                        dropdown.classList.remove('hidden');
                    } else {
                        dropdown.innerHTML = '<div class="px-4 py-2 text-gray-500">No customers found</div>';
                        dropdown.classList.remove('hidden');
                    }
                });
        }, 300);
    });
    
    // Hide dropdown on click outside
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });
}

function selectCustomer(customerId, displayText) {
    document.getElementById('customer_search').value = displayText;
    document.getElementById('customer_id').value = customerId;
    document.getElementById('customer_dropdown').classList.add('hidden');
    
    // Fetch customer details and price list
    fetch(`api/ajax_get_customer.php?customer_id=${customerId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const customer = data.customer;
                
                // Update customer details display
                document.getElementById('customer_contact').textContent = customer.contact_person || '-';
                document.getElementById('customer_phone').textContent = customer.phone || customer.mobile || '-';
                document.getElementById('customer_email').textContent = customer.email || '-';
                
                const addressParts = [
                    customer.address_line1,
                    customer.address_line2,
                    customer.city,
                    customer.state,
                    customer.postal_code
                ].filter(Boolean);
                document.getElementById('customer_address').textContent = addressParts.join(', ') || '-';
                
                document.getElementById('customer_details').classList.remove('hidden');
                
                // Update price list info
                if (data.price_list) {
                    selectedPriceListId = data.price_list.id;
                    selectedCurrency = data.price_list.currency;
                    
                    document.getElementById('price_list_id').value = data.price_list.id;
                    document.getElementById('price_list_name').textContent = data.price_list.name;
                    document.getElementById('price_list_code').textContent = '(' + data.price_list.code + ')';
                    document.getElementById('currency_display').textContent = data.price_list.currency;
                    
                    const sourceBadge = document.getElementById('price_list_source_badge');
                    if (data.price_list_source === 'customer') {
                        sourceBadge.textContent = 'Customer Price List';
                        sourceBadge.className = 'px-3 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-700';
                    } else {
                        sourceBadge.textContent = 'Default Price List';
                        sourceBadge.className = 'px-3 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-700';
                    }
                    
                    document.getElementById('price_list_info').classList.remove('hidden');
                } else {
                    // No price list - still allow adding items with manual prices
                    selectedPriceListId = 0;
                    selectedCurrency = 'LKR';
                    document.getElementById('price_list_id').value = '';
                    document.getElementById('price_list_name').textContent = 'No Price List';
                    document.getElementById('price_list_code').textContent = '';
                    document.getElementById('currency_display').textContent = 'LKR';
                    
                    const sourceBadge = document.getElementById('price_list_source_badge');
                    sourceBadge.textContent = 'Manual Pricing';
                    sourceBadge.className = 'px-3 py-1 text-xs font-medium rounded-full bg-orange-100 text-orange-700';
                    
                    document.getElementById('price_list_info').classList.remove('hidden');
                }
                
                // Always enable add item button after customer selection
                document.getElementById('add_item_btn').disabled = false;
                
                // Clear existing items if customer changed
                if (quotationItems.length > 0) {
                    if (confirm('Changing customer will clear existing items. Continue?')) {
                        quotationItems = [];
                        renderItemsTable();
                        calculateTotals();
                    }
                }
            } else {
                alert('Error: ' + data.error);
            }
        });
}

// Item search functionality
function setupItemSearch() {
    const searchInput = document.getElementById('item_search');
    const dropdown = document.getElementById('item_dropdown');
    let debounceTimer;
    
    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const query = this.value.trim();
        
        if (query.length < 2) {
            dropdown.classList.add('hidden');
            return;
        }
        
        debounceTimer = setTimeout(() => {
            const priceListParam = selectedPriceListId ? `&price_list_id=${selectedPriceListId}` : '';
            fetch(`api/ajax_search_items.php?search=${encodeURIComponent(query)}${priceListParam}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.items.length > 0) {
                        dropdown.innerHTML = data.items.map(item => `
                            <div class="px-4 py-2 hover:bg-gray-100 cursor-pointer" 
                                 onclick="selectItem(${item.id})">
                                <div class="flex justify-between">
                                    <span class="font-medium">${escapeHtml(item.code)}</span>
                                    ${item.has_price 
                                        ? `<span class="text-green-600">${selectedCurrency} ${item.unit_price.toFixed(2)}</span>`
                                        : '<span class="text-yellow-600 text-sm">Enter price</span>'
                                    }
                                </div>
                                <div class="text-sm text-gray-600">${escapeHtml(item.name)}</div>
                            </div>
                        `).join('');
                        dropdown.classList.remove('hidden');
                    } else {
                        dropdown.innerHTML = '<div class="px-4 py-2 text-gray-500">No items found</div>';
                        dropdown.classList.remove('hidden');
                    }
                });
        }, 300);
    });
    
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });
}

function selectItem(itemId) {
    document.getElementById('item_dropdown').classList.add('hidden');
    
    const priceListParam = selectedPriceListId ? `&price_list_id=${selectedPriceListId}` : '&price_list_id=0';
    fetch(`api/ajax_get_item_price.php?item_id=${itemId}${priceListParam}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const item = data.item;
                
                document.getElementById('modal_item_id').value = item.id;
                document.getElementById('modal_item_code').textContent = item.code;
                document.getElementById('modal_item_name').textContent = item.name;
                document.getElementById('modal_item_unit').textContent = item.unit_symbol;
                document.getElementById('item_search').value = item.code + ' - ' + item.name;
                document.getElementById('selected_item_info').classList.remove('hidden');
                
                const priceInput = document.getElementById('modal_unit_price');
                const warningEl = document.getElementById('no_price_warning');
                const addBtn = document.getElementById('modal_add_btn');
                
                // Always allow editing price
                if (data.has_price) {
                    priceInput.value = data.price_info.unit_price.toFixed(2);
                    document.getElementById('modal_discount').value = data.price_info.discount_percentage || 0;
                    warningEl.classList.add('hidden');
                } else {
                    priceInput.value = '';
                    warningEl.classList.remove('hidden');
                }
                
                // Always enable adding
                addBtn.disabled = false;
                
                calculateModalLineTotal();
            } else {
                alert('Error: ' + data.error);
            }
        });
}

function openAddItemModal() {
    // Reset modal
    document.getElementById('item_search').value = '';
    document.getElementById('modal_item_id').value = '';
    document.getElementById('modal_quantity').value = '1';
    document.getElementById('modal_unit_price').value = '';
    document.getElementById('modal_discount').value = '0';
    document.getElementById('modal_line_total').value = '';
    document.getElementById('modal_description').value = '';
    document.getElementById('modal_editing_index').value = '-1';
    document.getElementById('selected_item_info').classList.add('hidden');
    document.getElementById('no_price_warning').classList.add('hidden');
    document.getElementById('modal_add_btn').textContent = 'Add Item';
    document.getElementById('modal_add_btn').disabled = false;
    
    openModal('addItemModal');
}

function calculateModalLineTotal() {
    const qty = parseFloat(document.getElementById('modal_quantity').value) || 0;
    const price = parseFloat(document.getElementById('modal_unit_price').value) || 0;
    const discount = parseFloat(document.getElementById('modal_discount').value) || 0;
    
    const subtotal = qty * price;
    const discountAmount = (subtotal * discount) / 100;
    const lineTotal = subtotal - discountAmount;
    
    document.getElementById('modal_line_total').value = selectedCurrency + ' ' + lineTotal.toFixed(2);
}

function addItemToQuotation() {
    const itemId = document.getElementById('modal_item_id').value;
    const quantity = parseFloat(document.getElementById('modal_quantity').value);
    const unitPrice = parseFloat(document.getElementById('modal_unit_price').value);
    const discount = parseFloat(document.getElementById('modal_discount').value) || 0;
    const description = document.getElementById('modal_description').value;
    const editingIndex = parseInt(document.getElementById('modal_editing_index').value);
    
    if (!itemId || !quantity || quantity <= 0) {
        alert('Please select an item and enter a valid quantity.');
        return;
    }
    
    if (!unitPrice || unitPrice <= 0) {
        alert('Please enter a valid unit price.');
        return;
    }
    
    const subtotal = quantity * unitPrice;
    const discountAmount = (subtotal * discount) / 100;
    const lineTotal = subtotal - discountAmount;
    
    const itemData = {
        item_id: itemId,
        item_code: document.getElementById('modal_item_code').textContent,
        item_name: document.getElementById('modal_item_name').textContent,
        unit_symbol: document.getElementById('modal_item_unit').textContent,
        quantity: quantity,
        unit_price: unitPrice,
        discount_percentage: discount,
        line_total: lineTotal,
        description: description,
        is_manual_price: 1  // Always manual since user can edit
    };
    
    if (editingIndex >= 0) {
        quotationItems[editingIndex] = itemData;
    } else {
        // Check for duplicate
        const existingIndex = quotationItems.findIndex(item => item.item_id == itemId);
        if (existingIndex >= 0) {
            if (confirm('This item already exists. Do you want to update it?')) {
                quotationItems[existingIndex] = itemData;
            } else {
                return;
            }
        } else {
            quotationItems.push(itemData);
        }
    }
    
    renderItemsTable();
    calculateTotals();
    closeModal('addItemModal');
}

function renderItemsTable() {
    const tbody = document.getElementById('items_tbody');
    
    if (quotationItems.length === 0) {
        tbody.innerHTML = `
            <tr id="no_items_row">
                <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                    <svg class="w-12 h-12 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                    </svg>
                    No items added yet. Click "Add Item" to begin.
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = quotationItems.map((item, index) => `
        <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 text-sm text-gray-500">${index + 1}</td>
            <td class="px-4 py-3 text-sm font-medium text-gray-900">${escapeHtml(item.item_code)}</td>
            <td class="px-4 py-3 text-sm text-gray-700">
                ${escapeHtml(item.item_name)}
                ${item.description ? `<br><span class="text-xs text-gray-500">${escapeHtml(item.description)}</span>` : ''}
            </td>
            <td class="px-4 py-3 text-sm text-right">${parseFloat(item.quantity).toFixed(3)}</td>
            <td class="px-4 py-3 text-sm text-center">${escapeHtml(item.unit_symbol)}</td>
            <td class="px-4 py-3 text-sm text-right">${parseFloat(item.unit_price).toFixed(2)}</td>
            <td class="px-4 py-3 text-sm text-right">${parseFloat(item.discount_percentage || 0).toFixed(2)}%</td>
            <td class="px-4 py-3 text-sm text-right font-medium">${parseFloat(item.line_total).toFixed(2)}</td>
            <td class="px-4 py-3 text-sm text-center space-x-2">
                <button type="button" onclick="editItem(${index})" class="text-indigo-600 hover:text-indigo-900">Edit</button>
                <button type="button" onclick="removeItem(${index})" class="text-red-600 hover:text-red-900">Remove</button>
            </td>
        </tr>
    `).join('');
}

function editItem(index) {
    const item = quotationItems[index];
    
    document.getElementById('item_search').value = item.item_code + ' - ' + item.item_name;
    document.getElementById('modal_item_id').value = item.item_id;
    document.getElementById('modal_item_code').textContent = item.item_code;
    document.getElementById('modal_item_name').textContent = item.item_name;
    document.getElementById('modal_item_unit').textContent = item.unit_symbol;
    document.getElementById('modal_quantity').value = item.quantity;
    document.getElementById('modal_unit_price').value = item.unit_price;
    document.getElementById('modal_discount').value = item.discount_percentage || 0;
    document.getElementById('modal_description').value = item.description || '';
    document.getElementById('modal_editing_index').value = index;
    document.getElementById('selected_item_info').classList.remove('hidden');
    document.getElementById('modal_add_btn').textContent = 'Update Item';
    
    calculateModalLineTotal();
    openModal('addItemModal');
}

function removeItem(index) {
    if (confirm('Are you sure you want to remove this item?')) {
        quotationItems.splice(index, 1);
        renderItemsTable();
        calculateTotals();
    }
}

function calculateTotals() {
    let subtotal = 0;
    quotationItems.forEach(item => {
        subtotal += parseFloat(item.line_total);
    });
    
    const discountType = document.getElementById('discount_type').value;
    const discountValue = parseFloat(document.getElementById('discount_value').value) || 0;
    const taxPercentage = parseFloat(document.getElementById('tax_percentage').value) || 0;
    
    let discountTotal = 0;
    if (discountType === 'percentage') {
        discountTotal = (subtotal * discountValue) / 100;
    } else {
        discountTotal = discountValue;
    }
    
    const taxableAmount = subtotal - discountTotal;
    const taxTotal = (taxableAmount * taxPercentage) / 100;
    const grandTotal = taxableAmount + taxTotal;
    
    document.getElementById('subtotal_display').textContent = selectedCurrency + ' ' + subtotal.toFixed(2);
    document.getElementById('discount_display').textContent = '-' + selectedCurrency + ' ' + discountTotal.toFixed(2);
    document.getElementById('tax_display').textContent = '+' + selectedCurrency + ' ' + taxTotal.toFixed(2);
    document.getElementById('grand_total_display').textContent = selectedCurrency + ' ' + grandTotal.toFixed(2);
}

function saveQuotation(action) {
    if (!document.getElementById('customer_id').value) {
        alert('Please select a customer.');
        return;
    }
    
    if (quotationItems.length === 0) {
        alert('Please add at least one item to the quotation.');
        return;
    }
    
    // Prepare items JSON
    document.getElementById('items_json').value = JSON.stringify(quotationItems);
    document.getElementById('form_action').value = action;
    
    document.getElementById('quotationForm').submit();
}

// Utility functions
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

// Close modal on outside click
window.onclick = function(event) {
    if (event.target.classList.contains('fixed') && event.target.classList.contains('bg-gray-600')) {
        event.target.classList.add('hidden');
    }
}
</script>

<?php include 'footer.php'; ?>
