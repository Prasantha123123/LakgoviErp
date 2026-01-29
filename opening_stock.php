<?php
// opening_stock.php - Opening Stock Management with NULL Handling Fix + Separate Raw/Finished Dropdowns
include 'header.php';

// 🔧 NULL CLEANUP HANDLER
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'cleanup_nulls') {
    try {
        $db->beginTransaction();
        
        // Fix NULL values in items table
        $stmt = $db->prepare("UPDATE items SET current_stock = 0.000 WHERE current_stock IS NULL");
        $stmt->execute();
        $updated_stock = $stmt->rowCount();
        
        $stmt = $db->prepare("UPDATE items SET cost_price = 0.00 WHERE cost_price IS NULL");
        $stmt->execute();
        $updated_cost = $stmt->rowCount();
        
        $db->commit();
        $success = "Database cleanup completed! Updated {$updated_stock} items with NULL stock and {$updated_cost} items with NULL cost price.";
        
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = "Cleanup failed: " . $e->getMessage();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    $db->beginTransaction();
                    
                    // ✅ Validate exactly one of raw_item_id or finished_item_id
                    $raw_item_id = isset($_POST['raw_item_id']) ? trim($_POST['raw_item_id']) : '';
                    $finished_item_id = isset($_POST['finished_item_id']) ? trim($_POST['finished_item_id']) : '';

                    if (($raw_item_id === '' && $finished_item_id === '') || ($raw_item_id !== '' && $finished_item_id !== '')) {
                        throw new Exception("Select exactly one: Raw Material OR Finished Item");
                    }
                    if (empty($_POST['location_id']) || empty($_POST['quantity'])) {
                        throw new Exception("Location and Quantity are required");
                    }

                    $item_id = $raw_item_id !== '' ? $raw_item_id : $finished_item_id;
                    $location_id = $_POST['location_id'];
                    $quantity = floatval($_POST['quantity']);
                    
                    // Handle rate vs total value calculation
                    $rate = 0;
                    $value = 0;
                    
                    if (!empty($_POST['rate']) && floatval($_POST['rate']) > 0) {
                        // User entered unit rate - calculate total value
                        $rate = floatval($_POST['rate']);
                        $value = $quantity * $rate;
                    } elseif (!empty($_POST['total_value']) && floatval($_POST['total_value']) > 0) {
                        // User entered total value - calculate unit rate
                        $value = floatval($_POST['total_value']);
                        $rate = $value / $quantity;
                    } else {
                        throw new Exception("Either Unit Rate or Total Value must be provided");
                    }
                    
                    $opening_date = $_POST['opening_date'];
                    
                    if ($quantity <= 0) {
                        throw new Exception("Quantity must be greater than 0");
                    }
                    
                    if ($rate <= 0) {
                        throw new Exception("Rate must be greater than 0 for cost price calculation");
                    }
                    
                    // Check if opening stock already exists for this item-location
                    $stmt = $db->prepare("
                        SELECT id FROM opening_stock 
                        WHERE item_id = ? AND location_id = ?
                    ");
                    $stmt->execute([$item_id, $location_id]);
                    
                    if ($stmt->fetch()) {
                        throw new Exception("Opening stock already exists for this item at this location. Use Edit to modify.");
                    }
                    
                    // Get current balance from stock ledger
                    $stmt = $db->prepare("
                        SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                        FROM stock_ledger 
                        WHERE item_id = ? AND location_id = ?
                    ");
                    $stmt->execute([$item_id, $location_id]);
                    $result = $stmt->fetch();
                    $current_balance = $result ? floatval($result['current_balance']) : 0;
                    $new_balance = $current_balance + $quantity;
                    
                    // Generate opening stock reference number
                    $stmt = $db->query("SELECT COUNT(*) as count FROM stock_ledger WHERE transaction_type = 'opening_stock'");
                    $count = $stmt->fetch()['count'] + 1;
                    $reference_no = 'OS' . str_pad($count, 6, '0', STR_PAD_LEFT);
                    
                    // Insert into opening_stock table
                    $stmt = $db->prepare("
                        INSERT INTO opening_stock (item_id, location_id, quantity, rate, value, opening_date, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$item_id, $location_id, $quantity, $rate, $value, $opening_date]);
                    
                    // Insert opening stock entry in stock_ledger
                    $stmt = $db->prepare("
                        INSERT INTO stock_ledger (
                            item_id, location_id, transaction_type, reference_id, reference_no,
                            transaction_date, quantity_in, balance, created_at
                        ) VALUES (?, ?, 'opening_stock', 0, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$item_id, $location_id, $reference_no, $opening_date, $quantity, $new_balance]);
                    
                    // 🔧 FIXED: Update item current_stock AND cost price if this is for the main store location
                    if ($location_id == 1) {
                        // Handle NULL current_stock values properly
                        $stmt = $db->prepare("UPDATE items SET current_stock = COALESCE(current_stock, 0) + ? WHERE id = ?");
                        $stmt->execute([$quantity, $item_id]);
                        
                        // Calculate weighted average cost price with NULL handling
                        $stmt = $db->prepare("SELECT COALESCE(current_stock, 0) as current_stock, COALESCE(cost_price, 0) as cost_price FROM items WHERE id = ?");
                        $stmt->execute([$item_id]);
                        $item_data = $stmt->fetch();
                        
                        $new_stock = floatval($item_data['current_stock']);
                        $old_cost = floatval($item_data['cost_price']);
                        $old_stock = $new_stock - $quantity; // Stock before this addition
                        
                        if ($old_stock > 0) {
                            $total_old_value = $old_stock * $old_cost;
                            $new_value_calc = $quantity * $rate;
                            $total_new_stock = $old_stock + $quantity;
                            $new_weighted_cost = ($total_old_value + $new_value_calc) / $total_new_stock;
                        } else {
                            $new_weighted_cost = $rate;
                        }
                        
                        $stmt = $db->prepare("UPDATE items SET cost_price = ? WHERE id = ?");
                        $stmt->execute([$new_weighted_cost, $item_id]);
                    }
                    
                    $db->commit();
                    $success = "Opening stock added successfully! Reference: {$reference_no} (Unit Rate: Rs. " . number_format($rate, 2) . ", Total Value: Rs. " . number_format($value, 2) . ")";
                    break;
                    
                case 'delete':
                    $db->beginTransaction();
                    
                    $opening_stock_id = $_POST['id'];
                    
                    // Get opening stock details before deletion
                    $stmt = $db->prepare("
                        SELECT * FROM opening_stock WHERE id = ?
                    ");
                    $stmt->execute([$opening_stock_id]);
                    $opening_stock = $stmt->fetch();
                    
                    if (!$opening_stock) {
                        throw new Exception("Opening stock record not found");
                    }
                    
                    $quantity = floatval($opening_stock['quantity']);
                    
                    // Delete the opening stock entry from both tables
                    $stmt = $db->prepare("DELETE FROM opening_stock WHERE id = ?");
                    $stmt->execute([$opening_stock_id]);
                    
                    $stmt = $db->prepare("
                        DELETE FROM stock_ledger 
                        WHERE item_id = ? AND location_id = ? AND transaction_type = 'opening_stock'
                    ");
                    $stmt->execute([$opening_stock['item_id'], $opening_stock['location_id']]);
                    
                    // 🔧 FIXED: Update item current_stock if this was for the main store location
                    if ($opening_stock['location_id'] == 1) {
                        // Handle NULL current_stock values properly
                        $stmt = $db->prepare("UPDATE items SET current_stock = GREATEST(COALESCE(current_stock, 0) - ?, 0) WHERE id = ?");
                        $stmt->execute([$quantity, $opening_stock['item_id']]);
                        
                        // Recalculate cost price after deletion with NULL handling
                        $stmt = $db->prepare("
                            SELECT 
                                COALESCE(SUM(gi.quantity * gi.rate), 0) as grn_value,
                                COALESCE(SUM(gi.quantity), 0) as grn_qty
                            FROM grn_items gi
                            WHERE gi.item_id = ? AND gi.location_id = 1
                        ");
                        $stmt->execute([$opening_stock['item_id']]);
                        $cost_data = $stmt->fetch();
                        
                        $total_value = floatval($cost_data['grn_value']);
                        $total_qty = floatval($cost_data['grn_qty']);
                        
                        if ($total_qty > 0) {
                            $weighted_cost = $total_value / $total_qty;
                            $stmt = $db->prepare("UPDATE items SET cost_price = ? WHERE id = ?");
                            $stmt->execute([$weighted_cost, $opening_stock['item_id']]);
                        } else {
                            $stmt = $db->prepare("UPDATE items SET cost_price = 0 WHERE id = ?");
                            $stmt->execute([$opening_stock['item_id']]);
                        }
                    }
                    
                    $db->commit();
                    $success = "Opening stock deleted successfully!";
                    break;
                    
                case 'manual_update':
                    $db->beginTransaction();
                    
                    // Validate required fields
                    $location_id = $_POST['manual_location_id'] ?? '';
                    $update_date = $_POST['manual_update_date'] ?? date('Y-m-d');
                    
                    if (empty($location_id)) {
                        throw new Exception("Location is required");
                    }
                    
                    $raw_items_posted = $_POST['raw_items'] ?? [];
                    $finished_items_posted = $_POST['finished_items'] ?? [];
                    
                    if (empty($raw_items_posted) && empty($finished_items_posted)) {
                        throw new Exception("At least one item must be added for stock update");
                    }
                    
                    $updated_count = 0;
                    $updated_items = [];
                    
                    // Process raw materials
                    foreach ($raw_items_posted as $item) {
                        if (!empty($item['item_id']) && isset($item['quantity']) && floatval($item['quantity']) != 0) {
                            $item_id = intval($item['item_id']);
                            $quantity = floatval($item['quantity']);
                            
                            // Get item details
                            $stmt = $db->prepare("SELECT name, code FROM items WHERE id = ?");
                            $stmt->execute([$item_id]);
                            $item_details = $stmt->fetch();
                            
                            if (!$item_details) {
                                throw new Exception("Item with ID {$item_id} not found");
                            }
                            
                            // Update current_stock in items table using helper function
                            updateItemStock($db, $item_id, $quantity);
                            
                            // Get updated balance from stock ledger for this location
                            $stmt = $db->prepare("
                                SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                FROM stock_ledger 
                                WHERE item_id = ? AND location_id = ?
                            ");
                            $stmt->execute([$item_id, $location_id]);
                            $result = $stmt->fetch();
                            $current_balance = $result ? floatval($result['current_balance']) : 0;
                            $new_balance = $current_balance + $quantity;
                            
                            // Generate reference number for manual stock update
                            $stmt = $db->query("SELECT COUNT(*) as count FROM stock_ledger WHERE transaction_type = 'manual_stock'");
                            $count = $stmt->fetch()['count'] + 1;
                            $reference_no = 'MS' . str_pad($count, 6, '0', STR_PAD_LEFT);
                            
                            // Insert into stock_ledger
                            if ($quantity > 0) {
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'manual_stock', 0, ?, ?, ?, 0, ?, NOW())
                                ");
                                $stmt->execute([$item_id, $location_id, $reference_no, $update_date, $quantity, $new_balance]);
                            } else {
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'manual_stock', 0, ?, ?, 0, ?, ?, NOW())
                                ");
                                $stmt->execute([$item_id, $location_id, $reference_no, $update_date, abs($quantity), $new_balance]);
                            }
                            
                            $updated_count++;
                            $updated_items[] = $item_details['code'] . ' (' . ($quantity > 0 ? '+' : '') . number_format($quantity, 3) . ')';
                        }
                    }
                    
                    // Process finished items
                    foreach ($finished_items_posted as $item) {
                        if (!empty($item['item_id']) && isset($item['quantity']) && floatval($item['quantity']) != 0) {
                            $item_id = intval($item['item_id']);
                            $quantity = floatval($item['quantity']);
                            
                            // Get item details
                            $stmt = $db->prepare("SELECT name, code FROM items WHERE id = ?");
                            $stmt->execute([$item_id]);
                            $item_details = $stmt->fetch();
                            
                            if (!$item_details) {
                                throw new Exception("Item with ID {$item_id} not found");
                            }
                            
                            // Update current_stock in items table using helper function
                            updateItemStock($db, $item_id, $quantity);
                            
                            // Get updated balance from stock ledger for this location
                            $stmt = $db->prepare("
                                SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                FROM stock_ledger 
                                WHERE item_id = ? AND location_id = ?
                            ");
                            $stmt->execute([$item_id, $location_id]);
                            $result = $stmt->fetch();
                            $current_balance = $result ? floatval($result['current_balance']) : 0;
                            $new_balance = $current_balance + $quantity;
                            
                            // Generate reference number for manual stock update
                            $stmt = $db->query("SELECT COUNT(*) as count FROM stock_ledger WHERE transaction_type = 'manual_stock'");
                            $count = $stmt->fetch()['count'] + 1;
                            $reference_no = 'MS' . str_pad($count, 6, '0', STR_PAD_LEFT);
                            
                            // Insert into stock_ledger
                            if ($quantity > 0) {
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'manual_stock', 0, ?, ?, ?, 0, ?, NOW())
                                ");
                                $stmt->execute([$item_id, $location_id, $reference_no, $update_date, $quantity, $new_balance]);
                            } else {
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'manual_stock', 0, ?, ?, 0, ?, ?, NOW())
                                ");
                                $stmt->execute([$item_id, $location_id, $reference_no, $update_date, abs($quantity), $new_balance]);
                            }
                            
                            $updated_count++;
                            $updated_items[] = $item_details['code'] . ' (' . ($quantity > 0 ? '+' : '') . number_format($quantity, 3) . ')';
                        }
                    }
                    
                    if ($updated_count == 0) {
                        throw new Exception("No valid items to update");
                    }
                    
                    $db->commit();
                    $success = "Manual stock update completed! Updated {$updated_count} item(s): " . implode(', ', $updated_items);
                    break;
            }
        }
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Fetch opening stock data
try {
    $stmt = $db->query("
        SELECT 
            os.id,
            os.quantity,
            os.rate,
            os.value,
            os.opening_date,
            i.code as item_code,
            i.name as item_name,
            COALESCE(i.current_stock, 0) as current_stock,
            COALESCE(i.cost_price, 0) as cost_price,
            l.name as location_name,
            u.symbol as unit_symbol,
            CONCAT('OS-', LPAD(os.id, 6, '0')) as reference_no
        FROM opening_stock os
        JOIN items i ON os.item_id = i.id
        JOIN locations l ON os.location_id = l.id
        JOIN units u ON i.unit_id = u.id
        ORDER BY os.opening_date DESC, os.id DESC
    ");
    $opening_stocks = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching opening stocks: " . $e->getMessage();
    $opening_stocks = [];
}

// ✅ Fetch items for dropdowns separately (raw vs finished)
try {
    // Raw materials
    $stmt = $db->query("
        SELECT i.id, i.code, i.name, u.symbol 
        FROM items i 
        JOIN units u ON i.unit_id = u.id 
        WHERE i.type = 'raw'
        ORDER BY i.name
    ");
    $raw_items = $stmt->fetchAll();

    // Finished items
    $stmt = $db->query("
        SELECT i.id, i.code, i.name, u.symbol 
        FROM items i 
        JOIN units u ON i.unit_id = u.id 
        WHERE i.type = 'finished'
        ORDER BY i.name
    ");
    $finished_items = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
    $raw_items = [];
    $finished_items = [];
}

// Fetch locations for dropdown
try {
    $stmt = $db->query("SELECT * FROM locations ORDER BY name");
    $locations = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching locations: " . $e->getMessage();
    $locations = [];
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Opening Stock Management</h1>
            <p class="text-gray-600">Set initial stock quantities with cost prices</p>
        </div>

        
<button
    onclick="openModal('manualStockUpdateModal')"
    class="bg-sky-500 text-white px-4 py-2 rounded-md hover:bg-sky-600 transition-colors duration-300"
>
    Manual Stock Update
</button>

        <button onclick="openModal('createOpeningStockModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            Create New Opening Stock
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

    <!-- Opening Stock Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Rate</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Value</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Stock</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($opening_stocks as $stock): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="text-sm font-medium text-blue-600"><?php echo htmlspecialchars($stock['reference_no']); ?></span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo date('M d, Y', strtotime($stock['opening_date'])); ?>
                    </td>
                    <td class="px-6 py-4">
                        <div>
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($stock['item_name']); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($stock['item_code']); ?></div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo htmlspecialchars($stock['location_name']); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo number_format($stock['quantity'], 3); ?> <?php echo htmlspecialchars($stock['unit_symbol']); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        Rs. <?php echo number_format($stock['rate'], 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        Rs. <?php echo number_format($stock['value'], 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="<?php echo $stock['current_stock'] > 0 ? 'text-green-600' : 'text-red-600'; ?> font-medium">
                            <?php echo number_format($stock['current_stock'], 3); ?> <?php echo htmlspecialchars($stock['unit_symbol']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this opening stock?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $stock['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($opening_stocks)): ?>
                <tr>
                    <td colspan="9" class="px-6 py-4 text-center text-gray-500">No opening stock found. Create your first opening stock!</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Opening Stock Modal -->
<div id="createOpeningStockModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Create New Opening Stock</h3>
            <button onclick="closeModal('createOpeningStockModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-6" id="openingStockForm">
            <input type="hidden" name="action" value="create">
            
            <!-- Opening Stock Header -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Raw Material Select -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Raw Material</label>
                    <select name="raw_item_id" id="raw_item_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                            onchange="onRawSelectChange()">
                        <option value="">Select Raw Material</option>
                        <?php foreach ($raw_items as $item): ?>
                            <option value="<?php echo $item['id']; ?>">
                                <?php echo htmlspecialchars($item['code'] . ' - ' . $item['name'] . ' (' . $item['symbol'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Pick this OR Finished Item below</p>
                </div>

                <!-- Finished Item Select -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Finished Item</label>
                    <select name="finished_item_id" id="finished_item_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                            onchange="onFinishedSelectChange()">
                        <option value="">Select Finished Item</option>
                        <?php foreach ($finished_items as $item): ?>
                            <option value="<?php echo $item['id']; ?>">
                                <?php echo htmlspecialchars($item['code'] . ' - ' . $item['name'] . ' (' . $item['symbol'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Pick this OR Raw Material above</p>
                </div>

                <!-- Location -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                    <select name="location_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                        <option value="">Select Location</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>">
                                <?php echo htmlspecialchars($location['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Quantity -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Quantity <span class="text-red-500">*</span></label>
                    <input type="number" 
                           name="quantity" 
                           id="quantity" 
                           step="0.001" 
                           min="0.001" 
                           required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" 
                           placeholder="0.000"
                           oninput="calculateRateOrValue()">
                </div>

                <!-- Opening Date -->
                <div class="md:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Opening Date</label>
                    <input type="date" name="opening_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <!-- Rate/Value Section with Smart Calculation -->
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="text-lg font-medium text-gray-900 mb-4">💡 Smart Rate Calculation</h4>
                <p class="text-sm text-gray-600 mb-4">Enter either Unit Rate OR Total Value - the other will be calculated automatically!</p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Unit Rate (Rs.) 
                            <span class="text-blue-500 font-normal">- Enter this if you know rate per unit</span>
                        </label>
                        <input type="number" 
                               name="rate" 
                               id="unit_rate" 
                               step="0.01" 
                               min="0" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" 
                               placeholder="0.00"
                               oninput="calculateTotalValue()">
                        <div class="mt-1 text-xs text-gray-500">Rate per unit</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Total Value (Rs.) 
                            <span class="text-green-500 font-normal">- Enter this if you know total amount</span>
                        </label>
                        <input type="number" 
                               name="total_value" 
                               id="total_value" 
                               step="0.01" 
                               min="0" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" 
                               placeholder="0.00"
                               oninput="calculateUnitRate()">
                        <div class="mt-1 text-xs text-gray-500">Total amount for all quantity</div>
                    </div>
                </div>

                <!-- Calculation Display -->
                <div id="calculationDisplay" class="mt-4 p-3 bg-white border-l-4 border-blue-500 rounded hidden">
                    <div class="flex items-center">
                        <div class="text-blue-500 mr-2">📊</div>
                        <div class="text-sm">
                            <span class="font-medium">Calculation:</span>
                            <span id="calculationText" class="text-gray-700"></span>
                        </div>
                    </div>
                </div>

                <!-- Validation Warning -->
                <div id="validationWarning" class="mt-4 p-3 bg-yellow-50 border-l-4 border-yellow-400 rounded hidden">
                    <div class="flex items-center">
                        <div class="text-yellow-500 mr-2">⚠️</div>
                        <div class="text-sm text-yellow-700">
                            <span class="font-medium">Note:</span>
                            <span id="warningText"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createOpeningStockModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" id="submitBtn" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600" disabled>Create Opening Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- Manual Stock Update Modal -->
<div id="manualStockUpdateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-11/12 max-w-6xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Manual Stock Update</h3>
            <button onclick="closeModal('manualStockUpdateModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-6" id="manualStockUpdateForm">
            <input type="hidden" name="action" value="manual_update">
            
            <!-- Header Info -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-50 p-4 rounded-lg">
                <!-- Location -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location <span class="text-red-500">*</span></label>
                    <select name="manual_location_id" id="manual_location_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent">
                        <option value="">Select Location</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>">
                                <?php echo htmlspecialchars($location['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Update Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Update Date <span class="text-red-500">*</span></label>
                    <input type="date" name="manual_update_date" id="manual_update_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <!-- Raw Materials Table -->
            <div class="bg-white border rounded-lg overflow-hidden">
                <div class="bg-sky-50 px-4 py-3 border-b flex justify-between items-center">
                    <h4 class="text-md font-medium text-gray-900">📦 Raw Materials</h4>
                    <button type="button" onclick="addManualStockRow('raw')" class="bg-sky-500 text-white px-3 py-1 rounded-md hover:bg-sky-600 text-sm flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add Row
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-8">#</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Raw Material <span class="text-red-500">*</span></th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Quantity <span class="text-red-500">*</span></th>
                                
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Action</th>
                            </tr>
                        </thead>
                        <tbody id="manualRawItemsBody" class="bg-white divide-y divide-gray-200">
                            <!-- Dynamic rows will be added here -->
                        </tbody>
                      
                    </table>
                </div>
            </div>

            <!-- Finished Items Table -->
            <div class="bg-white border rounded-lg overflow-hidden">
                <div class="bg-green-50 px-4 py-3 border-b flex justify-between items-center">
                    <h4 class="text-md font-medium text-gray-900">🏭 Finished Items</h4>
                    <button type="button" onclick="addManualStockRow('finished')" class="bg-green-500 text-white px-3 py-1 rounded-md hover:bg-green-600 text-sm flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add Row
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-8">#</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Finished Item <span class="text-red-500">*</span></th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Quantity <span class="text-red-500">*</span></th>
                             
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Action</th>
                            </tr>
                        </thead>
                        <tbody id="manualFinishedItemsBody" class="bg-white divide-y divide-gray-200">
                            <!-- Dynamic rows will be added here -->
                        </tbody>
                       
                    </table>
                </div>
            </div>

           

            <!-- Validation Warning -->
            <div id="manualValidationWarning" class="p-3 bg-yellow-50 border-l-4 border-yellow-400 rounded hidden">
                <div class="flex items-center">
                    <div class="text-yellow-500 mr-2">⚠️</div>
                    <div class="text-sm text-yellow-700">
                        <span class="font-medium">Note:</span>
                        <span id="manualWarningText"></span>
                    </div>
                </div>
            </div>

            <div class="flex justify-between items-center pt-4">
                <div class="text-sm text-gray-500">
                    <span id="manualRawCount">0</span> raw material(s), <span id="manualFinishedCount">0</span> finished item(s) added
                </div>
                <div class="flex space-x-3">
                    <button type="button" onclick="closeModal('manualStockUpdateModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" id="manualSubmitBtn" class="px-4 py-2 bg-sky-500 text-white rounded-md hover:bg-sky-600 disabled:opacity-50 disabled:cursor-not-allowed" disabled>Update Stock</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function calculateTotalValue() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unitRate = parseFloat(document.getElementById('unit_rate').value) || 0;
    
    if (unitRate > 0 && quantity > 0) {
        const totalValue = quantity * unitRate;
        document.getElementById('total_value').value = totalValue.toFixed(2);
        
        document.getElementById('total_value').classList.add('bg-blue-50');
        document.getElementById('unit_rate').classList.remove('bg-green-50');
        
        showCalculation(`${quantity} × Rs.${unitRate} = Rs.${totalValue.toFixed(2)}`);
        validateForm();
    } else if (unitRate === 0) {
        document.getElementById('total_value').value = '';
        document.getElementById('total_value').classList.remove('bg-blue-50');
        hideCalculation();
    }
}

function calculateUnitRate() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const totalValue = parseFloat(document.getElementById('total_value').value) || 0;
    
    if (totalValue > 0 && quantity > 0) {
        const unitRate = totalValue / quantity;
        document.getElementById('unit_rate').value = unitRate.toFixed(2);
        
        document.getElementById('unit_rate').classList.add('bg-green-50');
        document.getElementById('total_value').classList.remove('bg-blue-50');
        
        showCalculation(`Rs.${totalValue} ÷ ${quantity} = Rs.${unitRate.toFixed(2)} per unit`);
        validateForm();
    } else if (totalValue === 0) {
        document.getElementById('unit_rate').value = '';
        document.getElementById('unit_rate').classList.remove('bg-green-50');
        hideCalculation();
    }
}

function calculateRateOrValue() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unitRate = parseFloat(document.getElementById('unit_rate').value) || 0;
    const totalValue = parseFloat(document.getElementById('total_value').value) || 0;
    
    if (quantity > 0) {
        if (unitRate > 0) {
            calculateTotalValue();
        } else if (totalValue > 0) {
            calculateUnitRate();
        }
    }
    validateForm();
}

function showCalculation(text) {
    document.getElementById('calculationText').textContent = text;
    document.getElementById('calculationDisplay').classList.remove('hidden');
}

function hideCalculation() {
    document.getElementById('calculationDisplay').classList.add('hidden');
}

function showWarning(text) {
    document.getElementById('warningText').textContent = text;
    document.getElementById('validationWarning').classList.remove('hidden');
}

function hideWarning() {
    document.getElementById('validationWarning').classList.add('hidden');
}

function validateForm() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unitRate = parseFloat(document.getElementById('unit_rate').value) || 0;
    const totalValue = parseFloat(document.getElementById('total_value').value) || 0;
    const submitBtn = document.getElementById('submitBtn');

    // Ensure one of the item selects has a value
    const rawSel = document.getElementById('raw_item_id');
    const finSel = document.getElementById('finished_item_id');
    const oneItemSelected = (!!rawSel.value ^ !!finSel.value); // XOR

    if (oneItemSelected && quantity > 0 && (unitRate > 0 || totalValue > 0)) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        hideWarning();
    } else {
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        
        if (!oneItemSelected) {
            showWarning('Select exactly one item: Raw Material OR Finished Item');
        } else if (quantity > 0 && unitRate === 0 && totalValue === 0) {
            showWarning('Please enter either Unit Rate or Total Value');
        } else if (quantity === 0) {
            showWarning('Please enter quantity first');
        } else {
            hideWarning();
        }
    }
}

// ✅ Mutual exclusivity handlers
function onRawSelectChange() {
    const rawSel = document.getElementById('raw_item_id');
    const finSel = document.getElementById('finished_item_id');
    if (rawSel.value) {
        finSel.value = '';
        finSel.disabled = true;
    } else {
        finSel.disabled = false;
    }
    validateForm();
}

function onFinishedSelectChange() {
    const rawSel = document.getElementById('raw_item_id');
    const finSel = document.getElementById('finished_item_id');
    if (finSel.value) {
        rawSel.value = '';
        rawSel.disabled = true;
    } else {
        rawSel.disabled = false;
    }
    validateForm();
}

// ensure clean state when opening/closing modal
function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
    document.getElementById('openingStockForm').reset();
    document.getElementById('unit_rate').classList.remove('bg-green-50', 'bg-blue-50');
    document.getElementById('total_value').classList.remove('bg-green-50', 'bg-blue-50');
    hideCalculation();
    hideWarning();
    document.getElementById('submitBtn').disabled = true;

    const rawSel = document.getElementById('raw_item_id');
    const finSel = document.getElementById('finished_item_id');
    if (rawSel) rawSel.disabled = false;
    if (finSel) finSel.disabled = false;
}

function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
    // sync exclusivity in case form state restored by browser
    onRawSelectChange();
    onFinishedSelectChange();
}

document.addEventListener('DOMContentLoaded', function() {
    validateForm();
    onRawSelectChange();
    onFinishedSelectChange();
});

// ========== Manual Stock Update Modal Functions (Multiple Items) ==========
const rawItemsData = <?php echo json_encode($raw_items); ?>;
const finishedItemsData = <?php echo json_encode($finished_items); ?>;
let manualRawRowCounter = 0;
let manualFinishedRowCounter = 0;

function addManualStockRow(type) {
    if (type === 'raw') {
        manualRawRowCounter++;
        const tbody = document.getElementById('manualRawItemsBody');
        
        let rawOptions = '<option value="">Select Raw Material</option>';
        rawItemsData.forEach(item => {
            rawOptions += `<option value="${item.id}" data-symbol="${item.symbol}">${item.code} - ${item.name} (${item.symbol})</option>`;
        });
        
        const row = document.createElement('tr');
        row.id = `manualRawRow_${manualRawRowCounter}`;
        row.className = 'hover:bg-gray-50';
        row.innerHTML = `
            <td class="px-3 py-2 text-sm text-gray-500">${tbody.children.length + 1}</td>
            <td class="px-3 py-2">
                <select name="raw_items[${manualRawRowCounter}][item_id]" 
                        id="manualRawItem_${manualRawRowCounter}"
                        class="w-full px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-sky-500 text-sm"
                        onchange="validateManualForm()"
                        required>
                    ${rawOptions}
                </select>
            </td>
            <td class="px-3 py-2">
                <input type="number" 
                       name="raw_items[${manualRawRowCounter}][quantity]" 
                       id="manualRawQty_${manualRawRowCounter}"
                       step="0.001" 
                       class="w-full px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-sky-500 text-sm" 
                       placeholder="0.000"
                       oninput="validateManualForm()"
                       required>
            </td>
           
            <td class="px-3 py-2 text-center">
                <button type="button" onclick="removeManualStockRow('raw', ${manualRawRowCounter})" class="text-red-500 hover:text-red-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </button>
            </td>
        `;
        
        tbody.appendChild(row);
    } else if (type === 'finished') {
        manualFinishedRowCounter++;
        const tbody = document.getElementById('manualFinishedItemsBody');
        
        let finishedOptions = '<option value="">Select Finished Item</option>';
        finishedItemsData.forEach(item => {
            finishedOptions += `<option value="${item.id}" data-symbol="${item.symbol}">${item.code} - ${item.name} (${item.symbol})</option>`;
        });
        
        const row = document.createElement('tr');
        row.id = `manualFinishedRow_${manualFinishedRowCounter}`;
        row.className = 'hover:bg-gray-50';
        row.innerHTML = `
            <td class="px-3 py-2 text-sm text-gray-500">${tbody.children.length + 1}</td>
            <td class="px-3 py-2">
                <select name="finished_items[${manualFinishedRowCounter}][item_id]" 
                        id="manualFinishedItem_${manualFinishedRowCounter}"
                        class="w-full px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm"
                        onchange="validateManualForm()"
                        required>
                    ${finishedOptions}
                </select>
            </td>
            <td class="px-3 py-2">
                <input type="number" 
                       name="finished_items[${manualFinishedRowCounter}][quantity]" 
                       id="manualFinishedQty_${manualFinishedRowCounter}"
                       step="0.001" 
                       class="w-full px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm" 
                       placeholder="0.000"
                       oninput="validateManualForm()"
                       required>
            </td>
           
            <td class="px-3 py-2 text-center">
                <button type="button" onclick="removeManualStockRow('finished', ${manualFinishedRowCounter})" class="text-red-500 hover:text-red-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </button>
            </td>
        `;
        
        tbody.appendChild(row);
    }
    
    updateManualItemCount();
    validateManualForm();
}

function removeManualStockRow(type, rowNum) {
    const prefix = type === 'raw' ? 'manualRawRow' : 'manualFinishedRow';
    const row = document.getElementById(`${prefix}_${rowNum}`);
    if (row) {
        row.remove();
        updateManualRowNumbers(type);
        updateManualItemCount();
        validateManualForm();
    }
}

function updateManualRowNumbers(type) {
    const tbodyId = type === 'raw' ? 'manualRawItemsBody' : 'manualFinishedItemsBody';
    const tbody = document.getElementById(tbodyId);
    const rows = tbody.querySelectorAll('tr');
    rows.forEach((row, index) => {
        row.querySelector('td:first-child').textContent = index + 1;
    });
}

function updateManualItemCount() {
    const rawCount = document.getElementById('manualRawItemsBody').querySelectorAll('tr').length;
    const finishedCount = document.getElementById('manualFinishedItemsBody').querySelectorAll('tr').length;
    document.getElementById('manualRawCount').textContent = rawCount;
    document.getElementById('manualFinishedCount').textContent = finishedCount;
}

function showManualWarning(text) {
    document.getElementById('manualWarningText').textContent = text;
    document.getElementById('manualValidationWarning').classList.remove('hidden');
}

function hideManualWarning() {
    document.getElementById('manualValidationWarning').classList.add('hidden');
}

function validateManualForm() {
    const rawTbody = document.getElementById('manualRawItemsBody');
    const finishedTbody = document.getElementById('manualFinishedItemsBody');
    const rawRows = rawTbody.querySelectorAll('tr');
    const finishedRows = finishedTbody.querySelectorAll('tr');
    const locationId = document.getElementById('manual_location_id').value;
    const submitBtn = document.getElementById('manualSubmitBtn');
    
    let isValid = true;
    let errorMsg = '';
    
    // Check if location is selected
    if (!locationId) {
        isValid = false;
        errorMsg = 'Please select a location';
    }
    
    // Check if at least one row exists in either table
    if (rawRows.length === 0 && finishedRows.length === 0) {
        isValid = false;
        errorMsg = 'Please add at least one item';
    }
    
    // Validate raw material rows
    let usedRawItems = [];
    rawRows.forEach(row => {
        const rowId = row.id.split('_')[1];
        const itemId = document.getElementById(`manualRawItem_${rowId}`)?.value;
        const qty = parseFloat(document.getElementById(`manualRawQty_${rowId}`)?.value) || 0;
        
        if (!itemId) {
            isValid = false;
            errorMsg = 'Please select an item for all raw material rows';
        } else if (usedRawItems.includes(itemId)) {
            isValid = false;
            errorMsg = 'Duplicate raw material detected';
        } else {
            usedRawItems.push(itemId);
        }
        
        if (qty == 0) {
            isValid = false;
            errorMsg = 'Please enter quantity for all raw materials';
        }
    });
    
    // Validate finished item rows
    let usedFinishedItems = [];
    finishedRows.forEach(row => {
        const rowId = row.id.split('_')[1];
        const itemId = document.getElementById(`manualFinishedItem_${rowId}`)?.value;
        const qty = parseFloat(document.getElementById(`manualFinishedQty_${rowId}`)?.value) || 0;
        
        if (!itemId) {
            isValid = false;
            errorMsg = 'Please select an item for all finished item rows';
        } else if (usedFinishedItems.includes(itemId)) {
            isValid = false;
            errorMsg = 'Duplicate finished item detected';
        } else {
            usedFinishedItems.push(itemId);
        }
        
        if (qty == 0) {
            isValid = false;
            errorMsg = 'Please enter quantity for all finished items';
        }
    });
    
    if (isValid) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        hideManualWarning();
    } else {
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        if (errorMsg) {
            showManualWarning(errorMsg);
        }
    }
}

function resetManualStockForm() {
    document.getElementById('manualStockUpdateForm').reset();
    document.getElementById('manualRawItemsBody').innerHTML = '';
    document.getElementById('manualFinishedItemsBody').innerHTML = '';
    document.getElementById('manualRawCount').textContent = '0';
    document.getElementById('manualFinishedCount').textContent = '0';
    manualRawRowCounter = 0;
    manualFinishedRowCounter = 0;
    hideManualWarning();
    document.getElementById('manualSubmitBtn').disabled = true;
}

// Update closeModal to handle manual modal
const originalCloseModal = closeModal;
closeModal = function(modalId) {
    document.getElementById(modalId).classList.add('hidden');
    
    if (modalId === 'createOpeningStockModal') {
        document.getElementById('openingStockForm').reset();
        document.getElementById('unit_rate').classList.remove('bg-green-50', 'bg-blue-50');
        document.getElementById('total_value').classList.remove('bg-green-50', 'bg-blue-50');
        hideCalculation();
        hideWarning();
        document.getElementById('submitBtn').disabled = true;
        const rawSel = document.getElementById('raw_item_id');
        const finSel = document.getElementById('finished_item_id');
        if (rawSel) rawSel.disabled = false;
        if (finSel) finSel.disabled = false;
    }
    
    if (modalId === 'manualStockUpdateModal') {
        resetManualStockForm();
    }
}

// Update openModal to handle manual modal
const originalOpenModal = openModal;
openModal = function(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
    
    if (modalId === 'createOpeningStockModal') {
        onRawSelectChange();
        onFinishedSelectChange();
    }
    
    if (modalId === 'manualStockUpdateModal') {
        // Add first row automatically for raw materials
        if (document.getElementById('manualRawItemsBody').children.length === 0) {
            addManualStockRow('raw');
        }
    }
}

// Add change listener for location
document.getElementById('manual_location_id')?.addEventListener('change', validateManualForm);
</script>

<?php include 'footer.php'; ?>
