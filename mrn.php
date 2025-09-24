<?php
// mrn.php - Material Request Note management (COMPLETE WORKING VERSION)
include 'header.php';

// Initialize database connection using your Database class
require_once 'database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
case 'create':
    $db->beginTransaction();
    
    try {
        // Insert MRN header
        $stmt = $db->prepare("INSERT INTO mrn (mrn_no, mrn_date, purpose, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
        $stmt->execute([$_POST['mrn_no'], $_POST['mrn_date'], $_POST['purpose']]);
        $mrn_id = $db->lastInsertId();
        
        $items_inserted = 0;
        
        // FIXED: Always move from Store to Production
        $source_location_id = 1;      // Store (always FROM here)
        $destination_location_id = 2; // Production Floor (always TO here)
        
        // Process each MRN item
        foreach ($_POST['items'] as $item) {
            if (!empty($item['item_id']) && !empty($item['quantity'])) {
                
                $requested_qty = floatval($item['quantity']);
                
                // Get current stock balance in STORE (source)
                $stmt = $db->prepare("
                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                    FROM stock_ledger 
                    WHERE item_id = ? AND location_id = ?
                ");
                $stmt->execute([$item['item_id'], $source_location_id]);
                $result = $stmt->fetch();
                $source_balance = $result ? floatval($result['current_balance']) : 0;
                
                // Validate sufficient stock in STORE
                if ($source_balance < $requested_qty) {
                    $stmt = $db->prepare("SELECT name FROM items WHERE id = ?");
                    $stmt->execute([$item['item_id']]);
                    $item_name = $stmt->fetchColumn();
                    
                    throw new Exception("Insufficient stock in Store for {$item_name}. Available: {$source_balance}, Requested: {$requested_qty}");
                }
                
                // Get current stock balance in PRODUCTION (destination)
                $stmt = $db->prepare("
                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                    FROM stock_ledger 
                    WHERE item_id = ? AND location_id = ?
                ");
                $stmt->execute([$item['item_id'], $destination_location_id]);
                $result = $stmt->fetch();
                $dest_balance = $result ? floatval($result['current_balance']) : 0;
                
                // Calculate new balances
                $new_source_balance = $source_balance - $requested_qty;
                $new_dest_balance = $dest_balance + $requested_qty;
                
                // Insert MRN item record (use destination location for compatibility)
                $stmt = $db->prepare("INSERT INTO mrn_items (mrn_id, item_id, location_id, quantity) VALUES (?, ?, ?, ?)");
                $stmt->execute([$mrn_id, $item['item_id'], $destination_location_id, $requested_qty]);
                $items_inserted++;
                
                // === TWO STOCK LEDGER ENTRIES ===
                
                // 1. Record stock movement OUT from STORE
                $stmt = $db->prepare("
                    INSERT INTO stock_ledger (
                        item_id, 
                        location_id, 
                        transaction_type, 
                        reference_id, 
                        reference_no, 
                        transaction_date, 
                        quantity_in,
                        quantity_out, 
                        balance,
                        created_at
                    ) VALUES (?, ?, 'mrn', ?, ?, ?, 0, ?, ?, NOW())
                ");
                $stmt->execute([
                    $item['item_id'], 
                    $source_location_id,       // Store (location_id = 1)
                    $mrn_id, 
                    $_POST['mrn_no'], 
                    $_POST['mrn_date'], 
                    $requested_qty,            // quantity_out
                    $new_source_balance
                ]);
                
                // 2. Record stock movement IN to PRODUCTION
                $stmt = $db->prepare("
                    INSERT INTO stock_ledger (
                        item_id, 
                        location_id, 
                        transaction_type, 
                        reference_id, 
                        reference_no, 
                        transaction_date, 
                        quantity_in,
                        quantity_out, 
                        balance,
                        created_at
                    ) VALUES (?, ?, 'mrn', ?, ?, ?, ?, 0, ?, NOW())
                ");
                $stmt->execute([
                    $item['item_id'], 
                    $destination_location_id,  // Production Floor (location_id = 2)
                    $mrn_id, 
                    $_POST['mrn_no'], 
                    $_POST['mrn_date'], 
                    $requested_qty,            // quantity_in
                    $new_dest_balance
                ]);
            }
        }
        
        if ($items_inserted == 0) {
            throw new Exception("No items were processed. Please add items to the MRN.");
        }
        
        $db->commit();
        $success = "MRN created successfully! {$items_inserted} items transferred from Store to Production Floor.";
        
    } catch(Exception $e) {
        $db->rollback();
        throw $e;
    }
    break;
                    
                case 'complete':
                    $stmt = $db->prepare("UPDATE mrn SET status = 'completed' WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $success = "MRN marked as completed!";
                    break;
                    
                case 'delete':
                    $db->beginTransaction();
                    
                    try {
                        // Get MRN items to reverse stock changes
                        $stmt = $db->prepare("
                            SELECT mi.*, i.name as item_name 
                            FROM mrn_items mi 
                            JOIN items i ON mi.item_id = i.id 
                            WHERE mi.mrn_id = ?
                        ");
                        $stmt->execute([$_POST['id']]);
                        $mrn_items = $stmt->fetchAll();
                        
                        // Get MRN details for reference_no
                        $stmt = $db->prepare("SELECT mrn_no FROM mrn WHERE id = ?");
                        $stmt->execute([$_POST['id']]);
                        $mrn = $stmt->fetch();
                        
                        foreach ($mrn_items as $item) {
                            // Get current balance before reversal
                            $stmt = $db->prepare("
                                SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                FROM stock_ledger 
                                WHERE item_id = ? AND location_id = ?
                            ");
                            $stmt->execute([$item['item_id'], $item['location_id']]);
                            $result = $stmt->fetch();
                            $current_balance = $result ? floatval($result['current_balance']) : 0;
                            
                            // Calculate new balance after adding back the stock
                            $new_balance = $current_balance + floatval($item['quantity']);
                            
                            // Add reversal entry to stock ledger
                            $stmt = $db->prepare("
                                INSERT INTO stock_ledger (
                                    item_id, 
                                    location_id, 
                                    transaction_type, 
                                    reference_id, 
                                    reference_no, 
                                    transaction_date, 
                                    quantity_in,
                                    quantity_out, 
                                    balance
                                ) VALUES (?, ?, 'mrn_reversal', ?, ?, CURDATE(), ?, 0, ?)
                            ");
                            $stmt->execute([
                                $item['item_id'], 
                                $item['location_id'], 
                                $_POST['id'], 
                                'REV-' . $mrn['mrn_no'], 
                                $item['quantity'],
                                $new_balance
                            ]);
                            
                            // Update item current stock (add back)
                            $stmt = $db->prepare("UPDATE items SET current_stock = current_stock + ? WHERE id = ?");
                            $stmt->execute([$item['quantity'], $item['item_id']]);
                        }
                        
                        // Delete original stock ledger entries
                        $stmt = $db->prepare("DELETE FROM stock_ledger WHERE transaction_type = 'mrn' AND reference_id = ?");
                        $stmt->execute([$_POST['id']]);
                        
                        // Delete MRN items
                        $stmt = $db->prepare("DELETE FROM mrn_items WHERE mrn_id = ?");
                        $stmt->execute([$_POST['id']]);
                        
                        // Delete MRN
                        $stmt = $db->prepare("DELETE FROM mrn WHERE id = ?");
                        $stmt->execute([$_POST['id']]);
                        
                        $db->commit();
                        $success = "MRN deleted successfully and stock restored!";
                        
                    } catch(Exception $e) {
                        $db->rollback();
                        throw $e;
                    }
                    break;
            }
        }
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    } catch(PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Database Error: " . $e->getMessage();
    }
}

// Fetch MRNs with enhanced information
try {
    $stmt = $db->query("
        SELECT m.*, 
               COUNT(mi.id) as item_count,
               COALESCE(SUM(mi.quantity), 0) as total_quantity,
               DATE_FORMAT(m.mrn_date, '%d %b %Y') as formatted_date
        FROM mrn m 
        LEFT JOIN mrn_items mi ON m.id = mi.mrn_id
        GROUP BY m.id
        ORDER BY m.mrn_date DESC, m.id DESC
    ");
    $mrns = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching MRNs: " . $e->getMessage();
}

// Fetch items with current stock information
try {
    $stmt = $db->query("
        SELECT i.*, u.symbol,
               COALESCE(SUM(sl.quantity_in - sl.quantity_out), 0) as ledger_stock
        FROM items i 
        JOIN units u ON i.unit_id = u.id 
        LEFT JOIN stock_ledger sl ON i.id = sl.item_id
        GROUP BY i.id, u.symbol
        HAVING ledger_stock > 0
        ORDER BY i.name
    ");
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
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Material Request Note (MRN)</h1>
            <p class="text-gray-600">Manage material issues and requests</p>
        </div>
        <button onclick="openModal('createMrnModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            Create New MRN
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

    <!-- Stock Alert -->
    <?php if (empty($items)): ?>
        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded">
            <div class="flex">
                <svg class="w-5 h-5 text-yellow-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <strong>No items in stock!</strong> You need to create GRNs (Goods Receipt Notes) first to receive items before you can issue them via MRN.
                    <a href="grn.php" class="underline ml-2">Create GRN →</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- MRN Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">MRN No</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purpose</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Qty</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($mrns as $mrn): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900 font-mono"><?php echo htmlspecialchars($mrn['mrn_no']); ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($mrn['purpose']); ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900"><?php echo $mrn['formatted_date']; ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                            <?php echo $mrn['item_count']; ?> items
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900 font-medium"><?php echo number_format($mrn['total_quantity'], 3); ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $mrn['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                            <?php echo ucfirst($mrn['status']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <button onclick="viewMrnDetails(<?php echo $mrn['id']; ?>)" class="text-blue-600 hover:text-blue-900">View</button>
                        <?php if ($mrn['status'] === 'pending'): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="id" value="<?php echo $mrn['id']; ?>">
                                <button type="submit" class="text-green-600 hover:text-green-900">Complete</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this MRN? This will restore the stock.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $mrn['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($mrns)): ?>
                <tr>
                    <td colspan="7" class="px-6 py-4 text-center text-gray-500">No MRNs found. Create your first MRN!</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create MRN Modal -->
<div id="createMrnModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Create New MRN</h3>
            <button onclick="closeModal('createMrnModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-6" onsubmit="return validateMrnForm()">
            <input type="hidden" name="action" value="create">
            
            <!-- MRN Header -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">MRN Number</label>
                    <input type="text" name="mrn_no" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="MRN001" value="MRN<?php echo str_pad(count($mrns) + 1, 3, '0', STR_PAD_LEFT); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purpose</label>
                    <input type="text" name="purpose" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="e.g., Production, Maintenance, Sample">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">MRN Date</label>
                    <input type="date" name="mrn_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <!-- Items Section -->
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h4 class="text-md font-semibold text-gray-900">Items to Issue</h4>
                    <button type="button" onclick="addMrnItem()" class="bg-green-500 text-white px-3 py-1 rounded text-sm hover:bg-green-600">
                        Add Item
                    </button>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Item</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Location</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Available Stock</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Request Quantity</th>
                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody id="mrnItemsTable">
                            <tr class="mrn-item-row">
                                <td class="px-4 py-2 border-r">
                                    <select name="items[0][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select" onchange="updateStock(this)" required>
                                        <option value="">Select Item</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>" data-stock="<?php echo $item['ledger_stock']; ?>" data-unit="<?php echo $item['symbol']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <select name="items[0][location_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm location-select" onchange="updateStockForLocation(this)" required>
                                        <option value="">Select Location</option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?php echo $location['id']; ?>"><?php echo htmlspecialchars($location['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <div class="flex items-center">
                                        <span class="stock-display text-sm font-medium">-</span>
                                        <span class="ml-1 text-xs text-gray-500 unit-display"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 border-r">
                                    <input type="number" name="items[0][quantity]" step="0.001" min="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateQuantity(this)" required>
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <button type="button" onclick="removeMrnItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createMrnModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Create MRN</button>
            </div>
        </form>
    </div>
</div>

<!-- View MRN Details Modal -->
<div id="viewMrnModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-4 mx-auto p-5 border w-11/12 max-w-7xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-gray-900 flex items-center">
                <svg class="w-6 h-6 mr-2 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                MRN Details
            </h3>
            <button onclick="closeModal('viewMrnModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <!-- Content Container -->
        <div id="mrnDetailsContent" class="max-h-[calc(100vh-150px)] overflow-y-auto">
            <!-- Default placeholder content -->
            <div class="text-center py-8">
                <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <h3 class="text-lg font-medium text-gray-900 mb-2">MRN Details</h3>
                <p class="text-gray-600">Click "View" on any MRN to see detailed information.</p>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div class="flex justify-end mt-6 pt-4 border-t border-gray-200">
            <button onclick="closeModal('viewMrnModal')" class="px-6 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 transition-colors">
                Close
            </button>
        </div>
    </div>
</div>


<script>
let mrnItemCount = 1; // Make sure this is properly initialized

function addMrnItem() {
    const table = document.getElementById('mrnItemsTable');
    const row = document.createElement('tr');
    row.className = 'mrn-item-row';
    
    row.innerHTML = `
        <td class="px-4 py-2 border-r">
            <select name="items[${mrnItemCount}][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select" onchange="updateStock(this)" required>
                <option value="">Select Item</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="px-4 py-2 border-r">
            <select name="items[${mrnItemCount}][location_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm location-select" onchange="updateStock(this)" required>
                <option value="">Select Location</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?php echo $location['id']; ?>"><?php echo htmlspecialchars($location['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="px-4 py-2 border-r">
            <div class="flex items-center">
                <span class="stock-display text-sm font-medium">-</span>
                <span class="ml-1 text-xs text-gray-500 unit-display"></span>
            </div>
        </td>
        <td class="px-4 py-2 border-r">
            <input type="number" name="items[${mrnItemCount}][quantity]" step="0.001" min="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateQuantity(this)" required>
        </td>
        <td class="px-4 py-2 text-center">
            <button type="button" onclick="removeMrnItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
        </td>
    `;
    
    table.appendChild(row);
    mrnItemCount++;
}
function removeMrnItem(button) {
    const table = document.getElementById('mrnItemsTable');
    if (table.rows.length > 1) {
        button.closest('tr').remove();
    }
}


function updateStock(select) {
    // This function handles both item and location changes
    const row = select.closest('tr');
    const itemSelect = row.querySelector('.item-select');
    const locationSelect = row.querySelector('.location-select');
    
    // If both item and location are selected, fetch real-time stock
    if (itemSelect.value && locationSelect.value) {
        fetchLocationSpecificStock(itemSelect.value, locationSelect.value, row);
    } else {
        // Reset stock display if either is not selected
        resetStockDisplay(row);
    }
}

function updateStockForLocation(select) {
    // This is the same as updateStock - just call it
    updateStock(select);
}

function fetchLocationSpecificStock(itemId, locationId, row) {
    // Show loading state
    const stockDisplay = row.querySelector('.stock-display');
    stockDisplay.textContent = 'Loading...';
    stockDisplay.className = 'stock-display text-sm font-medium text-blue-600';
    
    // Fetch real-time stock for this item-location combination
    fetch(`get_current_stock.php?item_id=${itemId}&location_id=${locationId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const currentStock = parseFloat(data.current_stock || 0);
                const quantityInput = row.querySelector('.quantity-input');
                const unitDisplay = row.querySelector('.unit-display');
                
                // Update stock display with proper color coding
                stockDisplay.textContent = currentStock.toFixed(3);
                stockDisplay.className = 'stock-display text-sm font-medium ' + 
                    (currentStock === 0 ? 'text-red-600' : 
                     currentStock < 50 ? 'text-yellow-600' : 'text-green-600');
                
                // Update unit display
                if (unitDisplay) {
                    unitDisplay.textContent = data.unit || '';
                }
                
                // Set max quantity and validate current input
                quantityInput.setAttribute('max', currentStock);
                
                // Validate current quantity if user has already entered something
                const requestedQty = parseFloat(quantityInput.value || 0);
                if (requestedQty > currentStock) {
                    quantityInput.style.borderColor = '#ef4444';
                    quantityInput.title = `Insufficient stock! Available: ${currentStock.toFixed(3)}`;
                    
                    // Show alert if user has entered quantity
                    if (quantityInput.value) {
                        alert(`Insufficient stock! Available: ${currentStock.toFixed(3)}, Requested: ${requestedQty.toFixed(3)}`);
                        quantityInput.focus();
                    }
                } else {
                    quantityInput.style.borderColor = '#d1d5db';
                    quantityInput.title = '';
                }
            } else {
                stockDisplay.textContent = 'Error';
                stockDisplay.className = 'stock-display text-sm font-medium text-red-600';
                console.error('Error fetching stock:', data.message);
            }
        })
        .catch(error => {
            stockDisplay.textContent = 'Error';
            stockDisplay.className = 'stock-display text-sm font-medium text-red-600';
            console.error('Error fetching stock:', error);
        });
}

function resetStockDisplay(row) {
    const stockDisplay = row.querySelector('.stock-display');
    const unitDisplay = row.querySelector('.unit-display');
    const quantityInput = row.querySelector('.quantity-input');
    
    stockDisplay.textContent = '-';
    stockDisplay.className = 'stock-display text-sm font-medium';
    
    if (unitDisplay) {
        unitDisplay.textContent = '';
    }
    
    quantityInput.removeAttribute('max');
    quantityInput.style.borderColor = '#d1d5db';
    quantityInput.title = '';
}


function validateQuantity(input) {
    const row = input.closest('tr');
    const itemSelect = row.querySelector('.item-select');
    const locationSelect = row.querySelector('.location-select');
    
    if (!itemSelect.value || !locationSelect.value) {
        alert('Please select both item and location first');
        input.value = '';
        return;
    }
    
    const maxStock = parseFloat(input.getAttribute('max') || 0);
    const requestedQty = parseFloat(input.value || 0);
    
    if (requestedQty > maxStock) {
        input.style.borderColor = '#ef4444';
        input.title = `Insufficient stock! Available: ${maxStock.toFixed(3)}`;
        alert(`Insufficient stock! Available: ${maxStock.toFixed(3)}, Requested: ${requestedQty.toFixed(3)}`);
        input.focus();
        return false;
    } else {
        input.style.borderColor = '#d1d5db';
        input.title = '';
        return true;
    }
}

function viewMrnDetails(mrnId) {
    console.log('Loading MRN details for ID:', mrnId);
    
    openModal('viewMrnModal');
    showMrnLoading();
    
    fetch(`get_mrn_details.php?mrn_id=${mrnId}`)
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers.get('content-type'));
            return response.text(); // Change to .text() temporarily to see raw output
        })
        .then(data => {
            console.log('Raw response:', data); // This will show you what's actually returned
            // Try to parse manually
            try {
                const jsonData = JSON.parse(data);
                if (jsonData.success) {
                    displayMrnDetails(jsonData);
                } else {
                    showMrnError(jsonData.message || 'Unknown error occurred');
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response was:', data);
                showMrnError('Invalid response from server: ' + data.substring(0, 200));
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            showMrnError(`Network error: ${error.message}`);
        });
}

function displayMrnDetails(data) {
    const { mrn, items, summary, stock_movements } = data;
    const content = document.getElementById('mrnDetailsContent');
    
    // Utility functions
    const formatDate = (dateString) => {
        return new Date(dateString).toLocaleDateString('en-IN', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    };
    
    const getStatusBadge = (status) => {
        const statusClasses = {
            'pending': 'bg-yellow-100 text-yellow-800 border-yellow-200',
            'completed': 'bg-green-100 text-green-800 border-green-200',
            'cancelled': 'bg-red-100 text-red-800 border-red-200'
        };
        
        const iconMap = {
            'pending': '⏳',
            'completed': '✅',
            'cancelled': '❌'
        };
        
        return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${statusClasses[status] || 'bg-gray-100 text-gray-800 border-gray-200'}">${iconMap[status] || '📋'} ${status.charAt(0).toUpperCase() + status.slice(1)}</span>`;
    };
    
    const getItemTypeBadge = (type) => {
        const typeClasses = {
            'raw': 'bg-blue-100 text-blue-800',
            'semi_finished': 'bg-yellow-100 text-yellow-800',
            'finished': 'bg-green-100 text-green-800'
        };
        
        return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${typeClasses[type] || 'bg-gray-100 text-gray-800'}">${type.replace('_', ' ').toUpperCase()}</span>`;
    };
    
    content.innerHTML = `
        <!-- MRN Header Information -->
        <div class="bg-gradient-to-r from-red-50 to-orange-50 rounded-lg p-6 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- MRN Details -->
                <div class="info-card">
                    <h4 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        MRN Information
                    </h4>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">MRN Number:</dt>
                            <dd class="text-sm font-semibold text-gray-900 font-mono">${mrn.mrn_no}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Date:</dt>
                            <dd class="text-sm text-gray-900">${mrn.formatted_date}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Purpose:</dt>
                            <dd class="text-sm text-gray-900">${mrn.purpose}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Status:</dt>
                            <dd class="text-sm">${getStatusBadge(mrn.status)}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Created By:</dt>
                            <dd class="text-sm text-gray-900">${mrn.created_by_name || 'N/A'}</dd>
                        </div>
                    </dl>
                </div>
                
                <!-- Request Summary -->
                <div class="info-card">
                    <h4 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        Request Summary
                    </h4>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Total Items:</dt>
                            <dd class="text-sm font-semibold text-gray-900">${summary.total_items}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Total Quantity:</dt>
                            <dd class="text-sm font-semibold text-gray-900">${summary.total_quantity.toFixed(3)}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Locations:</dt>
                            <dd class="text-sm text-gray-900">${summary.unique_locations}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-600">Request Type:</dt>
                            <dd class="text-sm text-orange-600 font-medium">Material Issue</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
        
        <!-- Summary Statistics -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white border border-gray-200 rounded-lg p-4 text-center info-card hover:shadow-md transition-all">
                <div class="text-2xl font-bold text-red-600 mb-1">${summary.total_items}</div>
                <div class="text-sm text-gray-600">Items Issued</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4 text-center info-card hover:shadow-md transition-all">
                <div class="text-2xl font-bold text-orange-600 mb-1">${summary.total_quantity.toFixed(3)}</div>
                <div class="text-sm text-gray-600">Total Quantity</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4 text-center info-card hover:shadow-md transition-all">
                <div class="text-2xl font-bold text-purple-600 mb-1">${summary.unique_locations}</div>
                <div class="text-sm text-gray-600">Locations</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4 text-center info-card hover:shadow-md transition-all">
                <div class="text-2xl font-bold text-indigo-600 mb-1">${mrn.status === 'completed' ? '✅' : '⏳'}</div>
                <div class="text-sm text-gray-600">Status</div>
            </div>
        </div>
        
        <!-- Items Details -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden mb-6">
            <div class="px-6 py-4 bg-gradient-to-r from-gray-50 to-gray-100 border-b border-gray-200">
                <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    Items Issued (${items.length} items)
                </h4>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Details</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Issued Qty</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Stock Before</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Current Stock</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Impact</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        ${items.map((item, index) => {
                            const stockBefore = parseFloat(item.stock_before_mrn || 0);
                            const currentStock = parseFloat(item.current_stock || 0);
                            const issuedQty = parseFloat(item.quantity);
                            const stockImpact = ((issuedQty / Math.max(stockBefore, 1)) * 100).toFixed(1);
                            
                            return `
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 w-2 h-2 bg-red-600 rounded-full mr-3"></div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">${item.item_name}</div>
                                            <div class="text-sm text-gray-500 font-mono">${item.item_code}</div>
                                            <div class="mt-1">${getItemTypeBadge(item.item_type)}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">${item.location_name}</div>
                                    <div class="text-sm text-gray-500 capitalize">${item.location_type}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <div class="text-sm font-medium text-red-600">-${parseFloat(item.quantity).toFixed(3)}</div>
                                    <div class="text-sm text-gray-500">${item.unit_symbol}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <div class="text-sm text-gray-900">${stockBefore.toFixed(3)}</div>
                                    <div class="text-sm text-gray-500">${item.unit_symbol}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <div class="text-sm font-medium ${currentStock < 50 ? 'text-red-600' : currentStock < 100 ? 'text-yellow-600' : 'text-green-600'}">
                                        ${currentStock.toFixed(3)}
                                    </div>
                                    <div class="text-sm text-gray-500">${item.unit_symbol}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="text-sm font-medium ${stockImpact > 75 ? 'text-red-600' : stockImpact > 50 ? 'text-yellow-600' : 'text-green-600'}">
                                        ${stockImpact}%
                                    </div>
                                    <div class="text-xs text-gray-500">of stock</div>
                                </td>
                            </tr>
                        `}).join('')}
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Item Type Breakdown -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">${summary.raw_materials}</div>
                <div class="text-sm text-blue-700">Raw Materials</div>
            </div>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-yellow-600">${summary.semi_finished}</div>
                <div class="text-sm text-yellow-700">Semi-Finished</div>
            </div>
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-green-600">${summary.finished_goods}</div>
                <div class="text-sm text-green-700">Finished Goods</div>
            </div>
        </div>
        
        <!-- Stock Movements -->
        ${stock_movements.length > 0 ? `
            <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                    <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        Stock Ledger Entries
                    </h4>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Quantity Out</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance After</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            ${stock_movements.map(movement => `
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">${movement.item_name}</div>
                                        <div class="text-sm text-gray-500">${movement.item_code}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${movement.location_name}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-red-600">
                                        -${parseFloat(movement.quantity_out).toFixed(3)} ${movement.unit_symbol}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                                        ${parseFloat(movement.balance).toFixed(3)} ${movement.unit_symbol}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        ${formatDate(movement.transaction_date)}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        ` : ''}
        
        <!-- Action Buttons -->
        <div class="flex justify-between items-center mt-8 pt-6 border-t border-gray-200">
            <div class="text-sm text-gray-500">
                <span class="flex items-center">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Created on ${mrn.formatted_created_at} by ${mrn.created_by_name || 'Unknown'}
                </span>
            </div>
            <div class="flex space-x-3">
                <button onclick="printMrnDetails(${mrn.id})" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-all">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    Print MRN
                </button>
                <button onclick="exportMrnToPdf(${mrn.id})" class="inline-flex items-center px-4 py-2 border border-red-300 rounded-md shadow-sm text-sm font-medium text-red-700 bg-red-50 hover:bg-red-100 transition-all">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Export PDF
                </button>
            </div>
        </div>
    `;
}

// Loading and error states
function showMrnLoading() {
    const content = document.getElementById('mrnDetailsContent');
    content.innerHTML = `
        <div class="min-h-[300px] flex items-center justify-center">
            <div class="text-center">
                <div class="relative">
                    <div class="w-16 h-16 border-4 border-red-200 border-t-red-600 rounded-full animate-spin mx-auto mb-4"></div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Loading MRN Details</h3>
                <p class="text-gray-600">Please wait while we fetch the information...</p>
            </div>
        </div>
    `;
}

function showMrnError(message) {
    const content = document.getElementById('mrnDetailsContent');
    content.innerHTML = `
        <div class="min-h-[200px] flex items-center justify-center">
            <div class="text-center">
                <svg class="w-16 h-16 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Error Loading MRN Details</h3>
                <p class="text-gray-600 mb-4">${message}</p>
                <button onclick="closeModal('viewMrnModal')" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Close
                </button>
            </div>
        </div>
    `;
}

// Print functionality
function printMrnDetails(mrnId) {
    const printWindow = window.open(`print_mrn.php?mrn_id=${mrnId}`, '_blank', 'width=900,height=700,scrollbars=yes,resizable=yes');
    if (printWindow) {
        printWindow.focus();
        printWindow.onload = function() {
            setTimeout(function() {
                printWindow.print();
            }, 500);
        };
    } else {
        alert('❌ Please allow popups to print MRN details');
    }
}

// Export to PDF functionality
function exportMrnToPdf(mrnId) {
    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.innerHTML = `
        <svg class="w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Generating PDF...
    `;
    button.disabled = true;
    
    const downloadLink = document.createElement('a');
    downloadLink.href = `export_mrn_pdf.php?mrn_id=${mrnId}`;
    downloadLink.download = `MRN_${mrnId}.pdf`;
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    
    downloadLink.click();
    
    setTimeout(() => {
        document.body.removeChild(downloadLink);
        button.innerHTML = originalText;
        button.disabled = false;
    }, 2000);
}

// CSS for enhanced styling
const mrnDetailsCSS = `
<style>
.info-card {
    transition: all 0.2s ease-in-out;
}

.info-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.animate-spin {
    animation: spin 1s linear infinite;
}

.modal-backdrop {
    backdrop-filter: blur(2px);
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
}

.table-striped tr:nth-child(even) {
    background-color: #f8fafc;
}

.table-striped tr:nth-child(odd) {
    background-color: #ffffff;
}

#mrnDetailsContent::-webkit-scrollbar {
    width: 8px;
}

#mrnDetailsContent::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

#mrnDetailsContent::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}

#mrnDetailsContent::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>
`;

// Inject CSS
document.head.insertAdjacentHTML('beforeend', mrnDetailsCSS);

function validateMrnStock(itemSelect, locationSelect, quantityInput) {
    if (!itemSelect.value || !locationSelect.value) {
        return;
    }
    
    // Get current stock for the selected item-location combination
    fetch(`get_current_stock.php?item_id=${itemSelect.value}&location_id=${locationSelect.value}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const currentStock = parseFloat(data.current_stock || 0);
                const requestedQty = parseFloat(quantityInput.value || 0);
                
                // Update max attribute
                quantityInput.setAttribute('max', currentStock);
                
                // Update stock display
                const stockDisplay = quantityInput.closest('tr').querySelector('.stock-display');
                const unitDisplay = quantityInput.closest('tr').querySelector('.unit-display');
                
                if (stockDisplay) {
                    stockDisplay.textContent = currentStock.toFixed(3);
                    stockDisplay.className = 'stock-display text-sm font-medium ' + 
                        (currentStock === 0 ? 'text-red-600' : 
                         currentStock < 50 ? 'text-yellow-600' : 'text-green-600');
                }
                
                if (unitDisplay) {
                    unitDisplay.textContent = data.unit || '';
                }
                
                // Validate current quantity
                if (requestedQty > currentStock) {
                    quantityInput.style.borderColor = '#ef4444';
                    quantityInput.title = `Insufficient stock! Available: ${currentStock.toFixed(3)}`;
                } else {
                    quantityInput.style.borderColor = '#d1d5db';
                    quantityInput.title = '';
                }
            }
        })
        .catch(error => {
            console.error('Error fetching stock:', error);
        });
}

// Add event listeners for stock validation
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('item-select') || e.target.classList.contains('location-select')) {
            const row = e.target.closest('tr');
            const itemSelect = row.querySelector('.item-select');
            const locationSelect = row.querySelector('.location-select');
            const quantityInput = row.querySelector('.quantity-input');
            
            if (itemSelect && locationSelect && quantityInput) {
                validateMrnStock(itemSelect, locationSelect, quantityInput);
            }
        }
    });
    
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('quantity-input')) {
            const row = e.target.closest('tr');
            const itemSelect = row.querySelector('.item-select');
            const locationSelect = row.querySelector('.location-select');
            
            if (itemSelect && locationSelect) {
                validateMrnStock(itemSelect, locationSelect, e.target);
            }
        }
    });
});

</script>

<?php include 'footer.php'; ?>