<?php
// purchase_orders.php - Purchase Orders management
include 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    $db->beginTransaction();
                    
                    // Insert PO header with created_by field
                    $stmt = $db->prepare("INSERT INTO purchase_orders (po_no, supplier_id, po_date, required_date, total_amount, notes, terms_conditions, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['po_no'], 
                        $_POST['supplier_id'], 
                        $_POST['po_date'], 
                        $_POST['required_date'], 
                        $_POST['total_amount'],
                        $_POST['notes'],
                        $_POST['terms_conditions'],
                        6  // Default to admin user ID 6 from your database
                    ]);
                    $po_id = $db->lastInsertId();
                    
                    // Insert PO items
                    foreach ($_POST['items'] as $item) {
                        if (!empty($item['item_id']) && !empty($item['quantity']) && !empty($item['rate'])) {
                            $stmt = $db->prepare("INSERT INTO po_items (po_id, item_id, quantity, rate, amount, notes) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $po_id, 
                                $item['item_id'], 
                                $item['quantity'], 
                                $item['rate'], 
                                $item['amount'],
                                $item['notes'] ?? ''
                            ]);
                        }
                    }
                    
                    $db->commit();
                    $success = "Purchase Order created successfully!";
                    break;
                    
                case 'update_status':
                    $stmt = $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
                    $stmt->execute([$_POST['new_status'], $_POST['po_id']]);
                    $success = "PO status updated successfully!";
                    break;
                    
                case 'delete':
                    $stmt = $db->prepare("DELETE FROM purchase_orders WHERE id = ?");
                    $stmt->execute([$_POST['po_id']]);
                    $success = "Purchase Order deleted successfully!";
                    break;
            }
        }
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch POs with details
try {
    $stmt = $db->query("
        SELECT po.*, s.name as supplier_name, s.code as supplier_code,
               COUNT(poi.id) as item_count,
               COALESCE(SUM(poi.quantity), 0) as total_qty,
               COALESCE(SUM(poi.received_quantity), 0) as received_qty
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN po_items poi ON po.id = poi.po_id
        GROUP BY po.id
        ORDER BY po.po_date DESC, po.id DESC
    ");
    $purchase_orders = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching purchase orders: " . $e->getMessage();
}

// Fetch ALL PO details including items for JavaScript
$po_details = [];
try {
    foreach ($purchase_orders as $po) {
        // Get PO items
        $stmt = $db->prepare("
            SELECT poi.*, i.name as item_name, i.code as item_code, 
                   u.symbol as unit_symbol
            FROM po_items poi
            JOIN items i ON poi.item_id = i.id
            JOIN units u ON i.unit_id = u.id
            WHERE poi.po_id = ?
            ORDER BY poi.id
        ");
        $stmt->execute([$po['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $po_details[$po['id']] = [
            'po' => $po,
            'items' => $items
        ];
    }
} catch(PDOException $e) {
    // Continue even if this fails
}

// Fetch suppliers for dropdown
try {
    $stmt = $db->query("SELECT * FROM suppliers ORDER BY name");
    $suppliers = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching suppliers: " . $e->getMessage();
}

// Fetch items for dropdown
try {
    $stmt = $db->query("SELECT i.*, u.symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.type IN ('raw', 'semi_finished') ORDER BY i.name");
    $items = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

// Get status counts
$status_counts = [
    'draft' => 0,
    'sent' => 0, 
    'acknowledged' => 0,
    'partial_received' => 0,
    'completed' => 0
];

foreach ($purchase_orders as $po) {
    if (isset($status_counts[$po['status']])) {
        $status_counts[$po['status']]++;
    }
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Purchase Orders</h1>
            <p class="text-gray-600">Manage purchase orders and track deliveries</p>
        </div>
        <button onclick="openModal('createPoModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            Create Purchase Order
        </button>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-gray-100">
                    <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Draft</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $status_counts['draft']; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-4 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-blue-100">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Sent</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $status_counts['sent']; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-4 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-green-100">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Acknowledged</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $status_counts['acknowledged']; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-4 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-yellow-100">
                    <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Partial</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $status_counts['partial_received']; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-4 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-purple-100">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Completed</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $status_counts['completed']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Purchase Orders Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO No</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Required Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($purchase_orders as $po): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($po['po_no']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <div>
                            <div class="font-medium"><?php echo htmlspecialchars($po['supplier_name']); ?></div>
                            <div class="text-gray-500"><?php echo htmlspecialchars($po['supplier_code']); ?></div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('M d, Y', strtotime($po['po_date'])); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php if ($po['required_date']): ?>
                            <?php echo date('M d, Y', strtotime($po['required_date'])); ?>
                        <?php else: ?>
                            <span class="text-gray-400">Not set</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                            <?php echo $po['item_count']; ?> items
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">රු.<?php echo number_format($po['total_amount'], 2); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="<?php 
                            $status_colors = [
                                'draft' => 'bg-gray-100 text-gray-800',
                                'sent' => 'bg-blue-100 text-blue-800', 
                                'acknowledged' => 'bg-green-100 text-green-800',
                                'partial_received' => 'bg-yellow-100 text-yellow-800',
                                'completed' => 'bg-purple-100 text-purple-800',
                                'cancelled' => 'bg-red-100 text-red-800'
                            ];
                            echo $status_colors[$po['status']] ?? 'bg-gray-100 text-gray-800';
                        ?> text-xs font-medium px-2.5 py-0.5 rounded capitalize">
                            <?php echo str_replace('_', ' ', $po['status']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <button onclick="viewPoDetails(<?php echo $po['id']; ?>)" class="text-blue-600 hover:text-blue-900">View</button>
                        
                        <?php if ($po['status'] === 'draft'): ?>
                            <button onclick="updatePoStatus(<?php echo $po['id']; ?>, 'sent')" class="text-green-600 hover:text-green-900">Send</button>
                        <?php endif; ?>
                        
                        <?php if ($po['status'] === 'sent'): ?>
                            <button onclick="updatePoStatus(<?php echo $po['id']; ?>, 'acknowledged')" class="text-blue-600 hover:text-blue-900">Acknowledge</button>
                        <?php endif; ?>
                        
                        <?php if (in_array($po['status'], ['acknowledged', 'partial_received'])): ?>
                            <a href="grn.php?po_id=<?php echo $po['id']; ?>" class="text-green-600 hover:text-green-900">Create GRN</a>
                        <?php endif; ?>
                        
                        <?php if ($po['status'] === 'draft'): ?>
                            <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this PO?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
                                <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($purchase_orders)): ?>
                <tr>
                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">No purchase orders found. Create your first PO!</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create PO Modal -->
<div id="createPoModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-5xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Create Purchase Order</h3>
            <button onclick="closeModal('createPoModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-6" onsubmit="return validatePoForm()">
            <input type="hidden" name="action" value="create">
            
            <!-- PO Header -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PO Number</label>
                    <input type="text" name="po_no" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="PO001" value="PO<?php echo str_pad(count($purchase_orders) + 1, 3, '0', STR_PAD_LEFT); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                    <select name="supplier_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['name'] . ' (' . $supplier['code'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PO Date</label>
                    <input type="date" name="po_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Required Date</label>
                    <input type="date" name="required_date" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                </div>
            </div>

            <!-- Notes and Terms -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Any special instructions or notes"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Terms & Conditions</label>
                    <textarea name="terms_conditions" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Payment terms, delivery conditions, etc."></textarea>
                </div>
            </div>

            <!-- Items Section -->
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h4 class="text-md font-semibold text-gray-900">Items to Order</h4>
                    <button type="button" onclick="addPoItem()" class="bg-green-500 text-white px-3 py-1 rounded text-sm hover:bg-green-600">
                        Add Item
                    </button>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Item</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Quantity</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Rate</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Amount</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Notes</th>
                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody id="poItemsTable">
                            <tr class="po-item-row">
                                <td class="px-4 py-2 border-r">
                                    <select name="items[0][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select" onchange="updateItemUnit(this)" required>
                                        <option value="">Select Item</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>" data-unit="<?php echo $item['symbol']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <div class="flex items-center">
                                        <input type="number" name="items[0][quantity]" step="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="calculatePoRowAmount(this)" required>
                                        <span class="ml-1 text-xs text-gray-500 unit-display"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <input type="number" name="items[0][rate]" step="0.01" class="w-full px-2 py-1 border border-gray-300 rounded text-sm rate-input" placeholder="0.00" onchange="calculatePoRowAmount(this)" required>
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <input type="number" name="items[0][amount]" step="0.01" class="w-full px-2 py-1 border border-gray-300 rounded text-sm amount-input" placeholder="0.00" readonly>
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <input type="text" name="items[0][notes]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" placeholder="Optional notes">
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <button type="button" onclick="removePoItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Total Amount -->
            <div class="flex justify-end">
                <div class="w-64">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Total Amount</label>
                    <input type="number" name="total_amount" step="0.01" id="poTotalAmount" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent text-right font-medium" placeholder="0.00" readonly>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createPoModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Create Purchase Order</button>
            </div>
        </form>
    </div>
</div>

<!-- View PO Details Modal -->
<div id="viewPoModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-5xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Purchase Order Details</h3>
            <button onclick="closeModal('viewPoModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="poDetailsContent">
            <!-- Content will be loaded via JavaScript -->
        </div>
    </div>
</div>

<script>
let poItemRowCount = 1;

function addPoItem() {
    const table = document.getElementById('poItemsTable');
    const newRow = table.rows[0].cloneNode(true);
    
    // Update input names with new index
    const inputs = newRow.querySelectorAll('input, select');
    inputs.forEach(input => {
        if (input.name) {
            input.name = input.name.replace('[0]', `[${poItemRowCount}]`);
            input.value = '';
        }
    });
    
    // Reset unit display
    newRow.querySelector('.unit-display').textContent = '';
    
    table.appendChild(newRow);
    poItemRowCount++;
}

function removePoItem(button) {
    const table = document.getElementById('poItemsTable');
    if (table.rows.length > 1) {
        button.closest('tr').remove();
        calculatePoTotalAmount();
    } else {
        alert('At least one item is required');
    }
}

function updateItemUnit(select) {
    const selectedOption = select.options[select.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || '';
    const row = select.closest('tr');
    row.querySelector('.unit-display').textContent = unit;
}

function calculatePoRowAmount(input) {
    const row = input.closest('tr');
    const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
    const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
    const amount = quantity * rate;
    
    row.querySelector('.amount-input').value = amount.toFixed(2);
    calculatePoTotalAmount();
}

function calculatePoTotalAmount() {
    let total = 0;
    document.querySelectorAll('.amount-input').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    document.getElementById('poTotalAmount').value = total.toFixed(2);
}

function validatePoForm() {
    // Check if at least one item has been filled
    let hasValidItem = false;
    const rows = document.querySelectorAll('.po-item-row');
    
    rows.forEach(row => {
        const itemSelect = row.querySelector('.item-select');
        const quantity = row.querySelector('.quantity-input');
        const rate = row.querySelector('.rate-input');
        
        if (itemSelect.value && quantity.value && rate.value) {
            hasValidItem = true;
        }
    });
    
    if (!hasValidItem) {
        alert('Please add at least one item with quantity and rate');
        return false;
    }
    
    // Check if total amount is greater than 0
    const totalAmount = parseFloat(document.getElementById('poTotalAmount').value) || 0;
    if (totalAmount <= 0) {
        alert('Total amount must be greater than 0');
        return false;
    }
    
    return true;
}

function updatePoStatus(poId, newStatus) {
    if (confirm(`Are you sure you want to change the status to ${newStatus.replace('_', ' ')}?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="po_id" value="${poId}">
            <input type="hidden" name="new_status" value="${newStatus}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function viewPoDetails(poId) {
    // Get data from PHP variable that's already loaded
    const poData = <?php echo json_encode($po_details); ?>;
    
    if (poData[poId]) {
        displayPoDetails(poData[poId].po, poData[poId].items);
        openModal('viewPoModal');
    } else {
        alert('PO details not found');
    }
}

function displayPoDetails(po, items) {
    let itemsHtml = '';
    let totalReceived = 0;
    let totalOrdered = 0;
    
    items.forEach(item => {
        totalOrdered += parseFloat(item.quantity);
        totalReceived += parseFloat(item.received_quantity || 0);
        
        const receivedPercentage = item.quantity > 0 ? (item.received_quantity / item.quantity) * 100 : 0;
        const statusColor = receivedPercentage === 0 ? 'text-gray-500' : 
                           (receivedPercentage < 100 ? 'text-yellow-600' : 'text-green-600');
        
        itemsHtml += `
            <tr>
                <td class="px-4 py-2 border">
                    <div>
                        <div class="font-medium">${item.item_name}</div>
                        <div class="text-sm text-gray-500">${item.item_code}</div>
                    </div>
                </td>
                <td class="px-4 py-2 border text-right">${parseFloat(item.quantity).toFixed(3)} ${item.unit_symbol}</td>
                <td class="px-4 py-2 border text-right">රු.${parseFloat(item.rate).toFixed(2)}</td>
                <td class="px-4 py-2 border text-right">රු.${parseFloat(item.amount).toFixed(2)}</td>
                <td class="px-4 py-2 border text-right ${statusColor}">${parseFloat(item.received_quantity || 0).toFixed(3)} ${item.unit_symbol}</td>
                <td class="px-4 py-2 border text-sm">${item.notes || '-'}</td>
            </tr>
        `;
    });
    
    const overallReceiptPercentage = totalOrdered > 0 ? (totalReceived / totalOrdered) * 100 : 0;
    
    document.getElementById('poDetailsContent').innerHTML = `
        <div class="space-y-6">
            <!-- PO Header -->
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-700">PO Number:</span>
                        <span class="text-gray-900">${po.po_no}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-700">Supplier:</span>
                        <span class="text-gray-900">${po.supplier_name} (${po.supplier_code})</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-700">PO Date:</span>
                        <span class="text-gray-900">${new Date(po.po_date).toLocaleDateString()}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-700">Required Date:</span>
                        <span class="text-gray-900">${po.required_date ? new Date(po.required_date).toLocaleDateString() : 'Not set'}</span>
                    </div>
                </div>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-700">Status:</span>
                        <span class="px-2 py-1 text-xs rounded bg-blue-100 text-blue-800 capitalize">${po.status.replace('_', ' ')}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-700">Total Amount:</span>
                        <span class="text-gray-900 font-bold">රු.${parseFloat(po.total_amount).toFixed(2)}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-700">Receipt Status:</span>
                        <span class="font-medium ${overallReceiptPercentage === 0 ? 'text-gray-500' : 
                                                   (overallReceiptPercentage < 100 ? 'text-yellow-600' : 'text-green-600')}">${overallReceiptPercentage.toFixed(0)}% Received</span>
                    </div>
                </div>
            </div>
            
            <!-- Notes and Terms -->
            ${po.notes || po.terms_conditions ? `
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                ${po.notes ? `
                <div>
                    <h5 class="font-medium text-gray-900 mb-2">Notes</h5>
                    <p class="text-sm text-gray-600 bg-gray-50 p-3 rounded">${po.notes}</p>
                </div>
                ` : ''}
                ${po.terms_conditions ? `
                <div>
                    <h5 class="font-medium text-gray-900 mb-2">Terms & Conditions</h5>
                    <p class="text-sm text-gray-600 bg-gray-50 p-3 rounded">${po.terms_conditions}</p>
                </div>
                ` : ''}
            </div>
            ` : ''}
            
            <!-- Items Table -->
            <div>
                <h5 class="font-medium text-gray-900 mb-3">Purchase Order Items</h5>
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Item</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Ordered Qty</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Rate</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Amount</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Received Qty</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="3" class="px-4 py-2 text-right font-medium border">Total:</td>
                                <td class="px-4 py-2 text-right font-bold border">රු.${parseFloat(po.total_amount).toFixed(2)}</td>
                                <td colspan="2" class="px-4 py-2 border"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="flex justify-end space-x-3">
                ${po.status === 'acknowledged' || po.status === 'partial_received' ? 
                    `<a href="grn.php?po_id=${po.id}" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Create GRN</a>` : ''}
                <button onclick="closeModal('viewPoModal')" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                    Close
                </button>
            </div>
        </div>
    `;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Auto-calculate amount when quantity or rate changes
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('quantity-input') || e.target.classList.contains('rate-input')) {
            calculatePoRowAmount(e.target);
        }
    });
    
    // Auto-update unit when item is selected
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('item-select')) {
            updateItemUnit(e.target);
        }
    });
});
</script>

<?php include 'footer.php'; ?>