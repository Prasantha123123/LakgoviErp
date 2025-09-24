<?php
// grn.php - Goods Receipt Note management with PO integration
include 'header.php';

// Get PO ID if coming from PO page
$selected_po_id = $_GET['po_id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    $db->beginTransaction();
                    
                    // Insert GRN header
                    $stmt = $db->prepare("INSERT INTO grn (grn_no, supplier_id, po_id, grn_date, total_amount) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['grn_no'], 
                        $_POST['supplier_id'], 
                        $_POST['po_id'] ?: null, 
                        $_POST['grn_date'], 
                        $_POST['total_amount']
                    ]);
                    $grn_id = $db->lastInsertId();
                    
                    // Insert GRN items and update stock ledger
                    foreach ($_POST['items'] as $item) {
                        if (!empty($item['item_id']) && !empty($item['quantity'])) {
                            // Insert GRN item
                            $stmt = $db->prepare("INSERT INTO grn_items (grn_id, po_item_id, item_id, location_id, quantity, rate, amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $grn_id, 
                                $item['po_item_id'] ?: null,
                                $item['item_id'], 
                                $item['location_id'], 
                                $item['quantity'], 
                                $item['rate'], 
                                $item['amount']
                            ]);
                            
                            // Update PO item received quantity if linked to PO
                            if (!empty($item['po_item_id'])) {
                                $stmt = $db->prepare("UPDATE po_items SET received_quantity = received_quantity + ? WHERE id = ?");
                                $stmt->execute([$item['quantity'], $item['po_item_id']]);
                            }
                            
                            // Get current balance for stock ledger
                            $stmt = $db->prepare("
                                SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                FROM stock_ledger 
                                WHERE item_id = ? AND location_id = ?
                            ");
                            $stmt->execute([$item['item_id'], $item['location_id']]);
                            $current_balance = $stmt->fetch()['current_balance'];
                            $new_balance = $current_balance + $item['quantity'];
                            
                            // Update stock ledger
                            $stmt = $db->prepare("
                                INSERT INTO stock_ledger (item_id, location_id, transaction_type, reference_id, reference_no, transaction_date, quantity_in, balance)
                                VALUES (?, ?, 'grn', ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$item['item_id'], $item['location_id'], $grn_id, $_POST['grn_no'], $_POST['grn_date'], $item['quantity'], $new_balance]);
                            
                            // Update item current stock
                            $stmt = $db->prepare("UPDATE items SET current_stock = current_stock + ? WHERE id = ?");
                            $stmt->execute([$item['quantity'], $item['item_id']]);
                        }
                    }
                    
                    // Update PO status if all items are fully received
                    if ($_POST['po_id']) {
                        $stmt = $db->prepare("
                            SELECT 
                                SUM(quantity) as total_ordered,
                                SUM(received_quantity) as total_received
                            FROM po_items 
                            WHERE po_id = ?
                        ");
                        $stmt->execute([$_POST['po_id']]);
                        $po_totals = $stmt->fetch();
                        
                        if ($po_totals['total_received'] >= $po_totals['total_ordered']) {
                            $stmt = $db->prepare("UPDATE purchase_orders SET status = 'completed' WHERE id = ?");
                            $stmt->execute([$_POST['po_id']]);
                        } else if ($po_totals['total_received'] > 0) {
                            $stmt = $db->prepare("UPDATE purchase_orders SET status = 'partial_received' WHERE id = ?");
                            $stmt->execute([$_POST['po_id']]);
                        }
                    }
                    
                    $db->commit();
                    $success = "GRN created successfully!";
                    break;
                    
                case 'complete':
                    $stmt = $db->prepare("UPDATE grn SET status = 'completed' WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $success = "GRN marked as completed!";
                    break;
                    
                case 'delete':
                    $db->beginTransaction();
                    
                    // Get GRN items to reverse stock and PO quantities
                    $stmt = $db->prepare("SELECT * FROM grn_items WHERE grn_id = ?");
                    $stmt->execute([$_POST['id']]);
                    $grn_items = $stmt->fetchAll();
                    
                    foreach ($grn_items as $item) {
                        // Update item current stock (reverse)
                        $stmt = $db->prepare("UPDATE items SET current_stock = current_stock - ? WHERE id = ?");
                        $stmt->execute([$item['quantity'], $item['item_id']]);
                        
                        // Reverse PO item received quantity if linked
                        if ($item['po_item_id']) {
                            $stmt = $db->prepare("UPDATE po_items SET received_quantity = received_quantity - ? WHERE id = ?");
                            $stmt->execute([$item['quantity'], $item['po_item_id']]);
                        }
                    }
                    
                    // Delete stock ledger entries
                    $stmt = $db->prepare("DELETE FROM stock_ledger WHERE transaction_type = 'grn' AND reference_id = ?");
                    $stmt->execute([$_POST['id']]);
                    
                    // Delete GRN
                    $stmt = $db->prepare("DELETE FROM grn WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    
                    $db->commit();
                    $success = "GRN deleted successfully!";
                    break;
            }
        }
    } catch(PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch GRNs with supplier and PO details
try {
    $stmt = $db->query("
        SELECT g.*, s.name as supplier_name, s.code as supplier_code,
               po.po_no, po.status as po_status,
               COUNT(gi.id) as item_count
        FROM grn g 
        LEFT JOIN suppliers s ON g.supplier_id = s.id 
        LEFT JOIN purchase_orders po ON g.po_id = po.id
        LEFT JOIN grn_items gi ON g.id = gi.grn_id
        GROUP BY g.id
        ORDER BY g.grn_date DESC, g.id DESC
    ");
    $grns = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching GRNs: " . $e->getMessage();
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
    $stmt = $db->query("SELECT i.*, u.symbol FROM items i JOIN units u ON i.unit_id = u.id ORDER BY i.name");
    $items = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

// Fetch locations for dropdown
try {
    $stmt = $db->query("SELECT * FROM locations ORDER BY name");
    $locations = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching locations: " . $e->getMessage();
}

// Fetch pending/acknowledged POs for dropdown
try {
    $stmt = $db->query("
        SELECT po.id, po.po_no, po.supplier_id, s.name as supplier_name, po.status,
               COUNT(poi.id) as total_items,
               SUM(CASE WHEN poi.quantity > poi.received_quantity THEN 1 ELSE 0 END) as pending_items
        FROM purchase_orders po 
        LEFT JOIN suppliers s ON po.supplier_id = s.id 
        LEFT JOIN po_items poi ON po.id = poi.po_id
        WHERE po.status IN ('acknowledged', 'partial_received') 
        GROUP BY po.id, po.po_no, po.supplier_id, s.name, po.status
        HAVING pending_items > 0
        ORDER BY po.po_date DESC
    ");
    $pending_pos = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching purchase orders: " . $e->getMessage();
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Goods Receipt Note (GRN)</h1>
            <p class="text-gray-600">Manage incoming goods and materials</p>
        </div>
        <button onclick="openModal('createGrnModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            Create New GRN
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

    <?php if ($selected_po_id): ?>
        <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded">
            <div class="flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Creating GRN for Purchase Order. The system will automatically load pending items from the selected PO.
            </div>
        </div>
    <?php endif; ?>

    <!-- GRN Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">GRN No</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO No</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($grns as $grn): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($grn['grn_no']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php if ($grn['po_no']): ?>
                            <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2 py-1 rounded">
                                <?php echo htmlspecialchars($grn['po_no']); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-gray-400">Direct GRN</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <div>
                            <div class="font-medium"><?php echo htmlspecialchars($grn['supplier_name']); ?></div>
                            <div class="text-gray-500"><?php echo htmlspecialchars($grn['supplier_code']); ?></div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('M d, Y', strtotime($grn['grn_date'])); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                            <?php echo $grn['item_count']; ?> items
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">රු.<?php echo number_format($grn['total_amount'], 2); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="<?php echo $grn['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?> text-xs font-medium px-2.5 py-0.5 rounded capitalize">
                            <?php echo $grn['status']; ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <button onclick="viewGrnDetails(<?php echo $grn['id']; ?>)" class="text-blue-600 hover:text-blue-900">View</button>
                        <?php if ($grn['status'] === 'pending'): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="id" value="<?php echo $grn['id']; ?>">
                                <button type="submit" class="text-green-600 hover:text-green-900">Complete</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this GRN?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $grn['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($grns)): ?>
                <tr>
                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">No GRNs found. Create your first GRN!</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create GRN Modal -->
<div id="createGrnModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Create New GRN</h3>
            <button onclick="closeModal('createGrnModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-6" onsubmit="return validateGrnForm()">
            <input type="hidden" name="action" value="create">
            
            <!-- GRN Header -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">GRN Number</label>
                    <input type="text" name="grn_no" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="GRN001" value="GRN<?php echo str_pad(count($grns) + 1, 3, '0', STR_PAD_LEFT); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purchase Order (Optional)</label>
                    <select name="po_id" id="po_select" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" onchange="loadPoItems()">
                        <option value="">Select PO (Optional)</option>
                        <?php foreach ($pending_pos as $po): ?>
                            <option value="<?php echo $po['id']; ?>" <?php echo $selected_po_id == $po['id'] ? 'selected' : ''; ?> data-supplier="<?php echo $po['supplier_id']; ?>">
                                <?php echo htmlspecialchars($po['po_no'] . ' - ' . $po['supplier_name'] . ' (' . $po['pending_items'] . ' pending items)'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                    <select name="supplier_id" id="supplier_select" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['name'] . ' (' . $supplier['code'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">GRN Date</label>
                    <input type="date" name="grn_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <!-- Items Section -->
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h4 class="text-md font-semibold text-gray-900">Items Received</h4>
                    <button type="button" onclick="addGrnItem()" class="bg-green-500 text-white px-3 py-1 rounded text-sm hover:bg-green-600">
                        Add Item
                    </button>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Item</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Location</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Quantity</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Rate</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Amount</th>
                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody id="grnItemsTable">
                            <tr class="grn-item-row">
                                <td class="px-4 py-2 border-r">
                                    <select name="items[0][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select" onchange="updateUnit(this)" required>
                                        <option value="">Select Item</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>" data-unit="<?php echo $item['symbol']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="items[0][po_item_id]" class="po-item-id">
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <select name="items[0][location_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" required>
                                        <option value="">Select Location</option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?php echo $location['id']; ?>"><?php echo htmlspecialchars($location['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <div class="flex items-center">
                                        <input type="number" name="items[0][quantity]" step="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="calculateRowAmount(this)" required>
                                        <span class="ml-1 text-xs text-gray-500 unit-display"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <input type="number" name="items[0][rate]" step="0.01" class="w-full px-2 py-1 border border-gray-300 rounded text-sm rate-input" placeholder="0.00" onchange="calculateRowAmount(this)" required>
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <input type="number" name="items[0][amount]" step="0.01" class="w-full px-2 py-1 border border-gray-300 rounded text-sm amount-input" placeholder="0.00" readonly>
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <button type="button" onclick="removeGrnItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
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
                    <input type="number" name="total_amount" step="0.01" id="totalAmount" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent text-right font-medium" placeholder="0.00" readonly>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createGrnModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Create GRN</button>
            </div>
        </form>
    </div>
</div>

<!-- View GRN Details Modal -->
<div id="viewGrnModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">GRN Details</h3>
            <button onclick="closeModal('viewGrnModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="grnDetailsContent">
            <!-- Content will be loaded via AJAX -->
        </div>
    </div>
</div>

<script>
// Enhanced GRN JavaScript with better error handling and debugging

let itemRowCount = 1;

// Pre-select PO if coming from PO page
document.addEventListener('DOMContentLoaded', function() {
    console.log('GRN page loaded');
    
    // Check if PO is pre-selected
    const poSelect = document.getElementById('po_select');
    if (poSelect && poSelect.value) {
        console.log('Pre-selected PO found:', poSelect.value);
        loadPoItems();
    }
});

function loadPoItems() {
    const poSelect = document.getElementById('po_select');
    const supplierSelect = document.getElementById('supplier_select');
    
    console.log('loadPoItems called, PO value:', poSelect.value);
    
    if (poSelect.value) {
        // Auto-select supplier based on PO
        const selectedOption = poSelect.options[poSelect.selectedIndex];
        const supplierId = selectedOption.getAttribute('data-supplier');
        console.log('Auto-selecting supplier:', supplierId);
        supplierSelect.value = supplierId;
        
        // Show loading message
        const table = document.getElementById('grnItemsTable');
        table.innerHTML = '<tr><td colspan="6" class="px-4 py-2 text-center text-blue-500">Loading PO items...</td></tr>';
        
        // Build the URL
        const url = `get_po_items.php?po_id=${poSelect.value}`;
        console.log('Fetching from URL:', url);
        
        // Fetch PO items via AJAX
        fetch(url)
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                return response.text(); // Get as text first to see raw response
            })
            .then(text => {
                console.log('Raw response:', text);
                
                try {
                    const data = JSON.parse(text);
                    console.log('Parsed JSON:', data);
                    
                    if (data.success) {
                        if (data.items && data.items.length > 0) {
                            console.log('Populating', data.items.length, 'items');
                            populateGrnItemsFromPo(data.items);
                        } else {
                            console.log('No pending items found');
                            table.innerHTML = '<tr><td colspan="6" class="px-4 py-2 text-center text-yellow-600">All items from this PO have been fully received.</td></tr>';
                            // Add one empty row for manual entry after 2 seconds
                            setTimeout(() => {
                                table.innerHTML = '';
                                addGrnItem();
                            }, 2000);
                        }
                    } else {
                        console.error('Server returned error:', data.message);
                        showError(`Server Error: ${data.message}`);
                        resetToManualEntry();
                    }
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    console.error('Raw response was:', text);
                    showError('Invalid response from server. Check console for details.');
                    resetToManualEntry();
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                showError(`Network Error: ${error.message}`);
                resetToManualEntry();
            });
    } else {
        // Clear supplier selection if no PO selected
        supplierSelect.value = '';
        // Reset items table
        const table = document.getElementById('grnItemsTable');
        table.innerHTML = '';
        addGrnItem();
    }
}

function showError(message) {
    // Show error in the table
    const table = document.getElementById('grnItemsTable');
    table.innerHTML = `<tr><td colspan="6" class="px-4 py-2 text-center text-red-600">${message}</td></tr>`;
    
    // Also show as alert
    alert(message);
}

function resetToManualEntry() {
    // Add empty row for manual entry
    const table = document.getElementById('grnItemsTable');
    setTimeout(() => {
        table.innerHTML = '';
        addGrnItem();
    }, 3000);
}

function populateGrnItemsFromPo(poItems) {
    const table = document.getElementById('grnItemsTable');
    table.innerHTML = ''; // Clear existing rows
    
    console.log('Populating items:', poItems);
    
    poItems.forEach((item, index) => {
        console.log(`Creating row ${index} for item:`, item);
        const row = createGrnItemRow(index, item);
        table.appendChild(row);
        
        // Auto-calculate amount for this row
        const quantityInput = row.querySelector('.quantity-input');
        const rateInput = row.querySelector('.rate-input');
        
        if (quantityInput.value && rateInput.value) {
            calculateRowAmount(quantityInput);
        }
    });
    
    itemRowCount = poItems.length;
    calculateTotalAmount();
    
    console.log('Items populated successfully');
}

function createGrnItemRow(index, poItem = null) {
    const row = document.createElement('tr');
    row.className = 'grn-item-row';
    
    const pendingQty = poItem ? parseFloat(poItem.pending_quantity || 0) : 0;
    const receivedQty = poItem ? parseFloat(poItem.received_quantity || 0) : 0;
    
    // Get items and locations from PHP (make sure these are available)
    const itemsData = window.itemsData || [];
    const locationsData = window.locationsData || [];
    
    let itemOptions = '<option value="">Select Item</option>';
    itemsData.forEach(item => {
        const selected = poItem && item.id == poItem.item_id ? 'selected' : '';
        itemOptions += `<option value="${item.id}" data-unit="${item.symbol}" ${selected}>${item.name} (${item.code})</option>`;
    });
    
    let locationOptions = '<option value="">Select Location</option>';
    locationsData.forEach(location => {
        locationOptions += `<option value="${location.id}">${location.name}</option>`;
    });
    
    row.innerHTML = `
        <td class="px-4 py-2 border-r">
            <select name="items[${index}][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select" onchange="updateUnit(this)" required>
                ${itemOptions}
            </select>
            <input type="hidden" name="items[${index}][po_item_id]" class="po-item-id" value="${poItem ? poItem.id : ''}">
        </td>
        <td class="px-4 py-2 border-r">
            <select name="items[${index}][location_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" required>
                ${locationOptions}
            </select>
        </td>
        <td class="px-4 py-2 border-r">
            <div class="flex items-center">
                <input type="number" name="items[${index}][quantity]" step="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="calculateRowAmount(this)" required value="${pendingQty > 0 ? pendingQty.toFixed(3) : ''}">
                <span class="ml-1 text-xs text-gray-500 unit-display">${poItem ? poItem.unit_symbol : ''}</span>
            </div>
            ${pendingQty > 0 ? `<div class="text-xs text-blue-600 font-medium">Pending: ${pendingQty.toFixed(3)} ${poItem.unit_symbol}</div>` : ''}
            ${receivedQty > 0 ? `<div class="text-xs text-gray-500">Already received: ${receivedQty.toFixed(3)} ${poItem.unit_symbol}</div>` : ''}
        </td>
        <td class="px-4 py-2 border-r">
            <input type="number" name="items[${index}][rate]" step="0.01" class="w-full px-2 py-1 border border-gray-300 rounded text-sm rate-input" placeholder="0.00" onchange="calculateRowAmount(this)" required value="${poItem ? parseFloat(poItem.rate).toFixed(2) : ''}">
        </td>
        <td class="px-4 py-2 border-r">
            <input type="number" name="items[${index}][amount]" step="0.01" class="w-full px-2 py-1 border border-gray-300 rounded text-sm amount-input" placeholder="0.00" readonly>
        </td>
        <td class="px-4 py-2 text-center">
            <button type="button" onclick="removeGrnItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
        </td>
    `;
    
    return row;
}

function addGrnItem() {
    const table = document.getElementById('grnItemsTable');
    const newRow = createGrnItemRow(itemRowCount);
    table.appendChild(newRow);
    itemRowCount++;
}

function removeGrnItem(button) {
    const table = document.getElementById('grnItemsTable');
    if (table.rows.length > 1) {
        button.closest('tr').remove();
        calculateTotalAmount();
    } else {
        alert('At least one item is required');
    }
}

function updateUnit(select) {
    const selectedOption = select.options[select.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || '';
    const row = select.closest('tr');
    row.querySelector('.unit-display').textContent = unit;
}

function calculateRowAmount(input) {
    const row = input.closest('tr');
    const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
    const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
    const amount = quantity * rate;
    
    row.querySelector('.amount-input').value = amount.toFixed(2);
    calculateTotalAmount();
}

function calculateTotalAmount() {
    let total = 0;
    document.querySelectorAll('.amount-input').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    document.getElementById('totalAmount').value = total.toFixed(2);
}

function validateGrnForm() {
    // Check if at least one item has been filled
    let hasValidItem = false;
    const rows = document.querySelectorAll('.grn-item-row');
    
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
    const totalAmount = parseFloat(document.getElementById('totalAmount').value) || 0;
    if (totalAmount <= 0) {
        alert('Total amount must be greater than 0');
        return false;
    }
    
    return true;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Make items and locations data available globally for JavaScript
    window.itemsData = <?php echo json_encode($items ?? []); ?>;
    window.locationsData = <?php echo json_encode($locations ?? []); ?>;
    
    // Auto-calculate amount when quantity or rate changes
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('quantity-input') || e.target.classList.contains('rate-input')) {
            calculateRowAmount(e.target);
        }
    });
    
    // Auto-update unit when item is selected
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('item-select')) {
            updateUnit(e.target);
        }
    });
    
    console.log('GRN JavaScript initialized');
});


// Improved displayGrnDetails function - replace your existing one with this

function displayGrnDetails(data) {
    const { grn, items, summary, stock_movements } = data;
    const content = document.getElementById('grnDetailsContent');
    
    // Format currency
    const formatCurrency = (amount) => `රු.${parseFloat(amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
    
    // Format date
    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('en-IN', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    };
    
    // Get status badge HTML
    const getStatusBadge = (status) => {
        const statusClasses = {
            'pending': 'bg-yellow-100 text-yellow-800 border-yellow-200',
            'completed': 'bg-green-100 text-green-800 border-green-200',
            'cancelled': 'bg-red-100 text-red-800 border-red-200'
        };
        
        return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${statusClasses[status] || 'bg-gray-100 text-gray-800 border-gray-200'}">${status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown'}</span>`;
    };
    
    content.innerHTML = `
        <!-- GRN Header Information -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg p-6 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- GRN Details -->
                <div class="info-card">
                    <h4 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        GRN Information
                    </h4>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">GRN Number:</dt>
                            <dd class="text-sm font-semibold text-gray-900">${grn.grn_no}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Date:</dt>
                            <dd class="text-sm text-gray-900">${grn.formatted_date || formatDate(grn.grn_date)}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Status:</dt>
                            <dd class="text-sm">${getStatusBadge(grn.status)}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Created By:</dt>
                            <dd class="text-sm text-gray-900">${grn.created_by_name || 'N/A'}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Created At:</dt>
                            <dd class="text-sm text-gray-900">${grn.formatted_created_at || formatDate(grn.created_at)}</dd>
                        </div>
                    </dl>
                </div>
                
                <!-- Supplier Details -->
                <div class="info-card">
                    <h4 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                        Supplier Information
                    </h4>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Name:</dt>
                            <dd class="text-sm font-medium text-gray-900">${grn.supplier_name || 'N/A'}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Code:</dt>
                            <dd class="text-sm text-gray-900">${grn.supplier_code || 'N/A'}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Contact:</dt>
                            <dd class="text-sm text-gray-900">${grn.supplier_contact || 'N/A'}</dd>
                        </div>
                        ${grn.supplier_address ? `
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Address:</dt>
                            <dd class="text-sm text-gray-900">${grn.supplier_address}</dd>
                        </div>
                        ` : ''}
                    </dl>
                </div>
                
                <!-- PO Information -->
                <div class="info-card">
                    <h4 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                        </svg>
                        Purchase Order
                    </h4>
                    <dl class="space-y-2">
                        ${grn.po_no ? `
                            <div class="flex justify-between">
                                <dt class="text-sm font-medium text-gray-600">PO Number:</dt>
                                <dd class="text-sm font-medium text-blue-600">${grn.po_no}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-sm font-medium text-gray-600">PO Date:</dt>
                                <dd class="text-sm text-gray-900">${formatDate(grn.po_date)}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-sm font-medium text-gray-600">PO Status:</dt>
                                <dd class="text-sm">${getStatusBadge(grn.po_status)}</dd>
                            </div>
                        ` : `
                            <div class="text-sm text-gray-500 italic">Direct GRN (No PO)</div>
                        `}
                    </dl>
                </div>
            </div>
        </div>
        
        <!-- Summary Statistics -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white p-4 rounded-lg border border-gray-200 text-center">
                <div class="text-2xl font-bold text-blue-600">${summary.total_items || 0}</div>
                <div class="text-sm text-gray-600">Total Items</div>
            </div>
            <div class="bg-white p-4 rounded-lg border border-gray-200 text-center">
                <div class="text-2xl font-bold text-green-600">${parseFloat(summary.total_quantity || 0).toFixed(3)}</div>
                <div class="text-sm text-gray-600">Total Quantity</div>
            </div>
            <div class="bg-white p-4 rounded-lg border border-gray-200 text-center">
                <div class="text-2xl font-bold text-purple-600">${formatCurrency(summary.total_amount || 0)}</div>
                <div class="text-sm text-gray-600">Total Amount</div>
            </div>
            <div class="bg-white p-4 rounded-lg border border-gray-200 text-center">
                <div class="text-2xl font-bold text-orange-600">${summary.unique_locations || 0}</div>
                <div class="text-sm text-gray-600">Locations</div>
            </div>
        </div>
        
        <!-- Items Table -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                    Received Items (${items.length})
                </h4>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Rate</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            ${grn.po_no ? '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO Status</th>' : ''}
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        ${items.map(item => `
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">${item.item_name}</div>
                                        <div class="text-sm text-gray-500">${item.item_code} | ${item.item_type}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        ${item.location_name}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="text-sm font-medium text-gray-900">${parseFloat(item.quantity).toFixed(3)}</div>
                                    <div class="text-xs text-gray-500">${item.unit_symbol}</div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="text-sm text-gray-900">${formatCurrency(item.rate)}</div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="text-sm font-medium text-gray-900">${formatCurrency(item.line_total)}</div>
                                </td>
                                ${grn.po_no ? `
                                <td class="px-6 py-4">
                                    ${item.po_quantity ? `
                                        <div class="text-xs text-gray-600">
                                            <div>Ordered: ${parseFloat(item.po_quantity).toFixed(3)}</div>
                                            <div>Received: ${parseFloat(item.po_received_quantity || 0).toFixed(3)}</div>
                                        </div>
                                    ` : '<span class="text-xs text-gray-400">Direct item</span>'}
                                </td>
                                ` : ''}
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
            <button onclick="printGrnDetails(${grn.id})" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Print
            </button>
            <button onclick="closeModal('viewGrnModal')" class="px-6 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 transition-colors">
                Close
            </button>
        </div>
    `;
}

// Print functionality for GRN
function printGrnDetails(grnId) {
    const printWindow = window.open(`print_grn.php?grn_id=${grnId}`, '_blank', 'width=900,height=700,scrollbars=yes,resizable=yes');
    if (printWindow) {
        printWindow.focus();
        printWindow.onload = function() {
            setTimeout(function() {
                printWindow.print();
            }, 500);
        };
    } else {
        alert('Please allow popups to print GRN details');
    }
}


// Export to PDF functionality
function exportGrnToPdf(grnId) {
    const downloadLink = document.createElement('a');
    downloadLink.href = `export_grn_pdf.php?grn_id=${grnId}`;
    downloadLink.download = `GRN_${grnId}.pdf`;
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

function viewGrnDetails(grnId) {
    console.log('Loading GRN details for ID:', grnId);
    
    // Open the modal
    openModal('viewGrnModal');
    
    // Show loading state
    showGrnLoading();
    
    // Fetch GRN details
    fetch(`get_grn_details.php?grn_id=${grnId}`)
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('GRN details response:', data);
            if (data.success) {
                displayGrnDetails(data);
            } else {
                showGrnError(data.message || 'Failed to load GRN details');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            showGrnError(`Network error: ${error.message}`);
        });
}

function showGrnLoading() {
    const content = document.getElementById('grnDetailsContent');
    content.innerHTML = `
        <div class="flex items-center justify-center py-12">
            <div class="text-center">
                <svg class="animate-spin h-8 w-8 text-blue-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="text-gray-600">Loading GRN details...</p>
            </div>
        </div>
    `;
}

function showGrnError(message) {
    const content = document.getElementById('grnDetailsContent');
    content.innerHTML = `
        <div class="text-center py-12">
            <svg class="w-12 h-12 text-red-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Error Loading GRN Details</h3>
            <p class="text-gray-600 mb-4">${message}</p>
            <button onclick="closeModal('viewGrnModal')" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Close
            </button>
        </div>
    `;
}

// Also add these modal helper functions if they don't exist
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
}


</script>

<?php include 'footer.php'; ?>