<?php
// mrn.php - Material Request Note management with Return MRN (Production -> Store)
// Returns are stored in stock_ledger with transaction_type='mrn_return'
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

                // =========================
                // CREATE MRN (Store -> Production)
                // =========================
                case 'create':
                    $db->beginTransaction();
                    try {
                        // Insert MRN header
                        $stmt = $db->prepare("INSERT INTO mrn (mrn_no, mrn_date, purpose, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                        $stmt->execute([$_POST['mrn_no'], $_POST['mrn_date'], $_POST['purpose']]);
                        $mrn_id = $db->lastInsertId();
                        
                        $items_inserted = 0;
                        
                        // Always FROM Store (1) TO Production (2)
                        $source_location_id = 1;      // Store
                        $destination_location_id = 2; // Production
                        
                        // Process each MRN item
                        foreach ($_POST['items'] as $item) {
                            if (!empty($item['item_id']) && !empty($item['quantity'])) {
                                $requested_qty = floatval($item['quantity']);
                                
                                // CONVERT PIECES TO KG if needed
                                // Get item unit to check if conversion is needed
                                $stmt = $db->prepare("SELECT i.*, u.symbol as unit_symbol FROM items i JOIN units u ON u.id = i.unit_id WHERE i.id = ?");
                                $stmt->execute([$item['item_id']]);
                                $item_data = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if (!$item_data) {
                                    throw new Exception("Item not found");
                                }
                                
                                $requested_qty_kg = $requested_qty; // Default: assume already in kg
                                
                                // If unit is PCS/PC, convert to KG using BOM data
                                if (strtolower($item_data['unit_symbol']) == 'pcs' || strtolower($item_data['unit_symbol']) == 'pc') {
                                    $kg_per_piece = 0;
                                    
                                    // Try bom_product first
                                    $stmt = $db->prepare("SELECT product_unit_qty FROM bom_product WHERE finished_item_id = ? LIMIT 1");
                                    $stmt->execute([$item['item_id']]);
                                    $bom_product = $stmt->fetch();
                                    
                                    if ($bom_product && $bom_product['product_unit_qty'] > 0) {
                                        $kg_per_piece = floatval($bom_product['product_unit_qty']);
                                    } else {
                                        // Try bom_direct
                                        $stmt = $db->prepare("SELECT finished_unit_qty FROM bom_direct WHERE finished_item_id = ? LIMIT 1");
                                        $stmt->execute([$item['item_id']]);
                                        $bom_direct = $stmt->fetch();
                                        
                                        if ($bom_direct && $bom_direct['finished_unit_qty'] > 0) {
                                            $kg_per_piece = floatval($bom_direct['finished_unit_qty']);
                                        }
                                    }
                                    
                                    if ($kg_per_piece > 0) {
                                        $requested_qty_kg = $requested_qty * $kg_per_piece;
                                    } else {
                                        // If no BOM data, treat as 1:1
                                        $requested_qty_kg = $requested_qty;
                                    }
                                }
                                
                                // Use $requested_qty_kg for all stock operations (stock is stored in KG)
                                
                                // Get current stock in STORE (source) - from ledger
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item['item_id'], $source_location_id]);
                                $ledger_balance = floatval($stmt->fetchColumn());
                                
                                // If no ledger entry, check items.current_stock as fallback
                                $source_balance = $ledger_balance;
                                if ($source_balance == 0) {
                                    $stmt = $db->prepare("SELECT COALESCE(current_stock, 0) as stock FROM items WHERE id = ?");
                                    $stmt->execute([$item['item_id']]);
                                    $fallback_stock = floatval($stmt->fetchColumn());
                                    if ($fallback_stock > 0) {
                                        $source_balance = $fallback_stock;
                                        // Create initial_stock entry for this item in Store to bootstrap the ledger
                                        $stmt = $db->prepare("
                                            INSERT INTO stock_ledger (
                                                item_id, location_id, transaction_type, reference_id, reference_no,
                                                transaction_date, quantity_in, quantity_out, balance, created_at
                                            ) VALUES (?, ?, 'initial_stock', 0, 'FALLBACK', ?, ?, 0, ?, NOW())
                                        ");
                                        $stmt->execute([
                                            $item['item_id'],
                                            $source_location_id,
                                            $_POST['mrn_date'],
                                            $source_balance,
                                            $source_balance
                                        ]);
                                    }
                                }
                                
                                // Validate available stock in STORE (check KG amount)
                                if ($source_balance < $requested_qty_kg) {
                                    throw new Exception("Insufficient stock in Store for {$item_data['name']}. Available: " . number_format($source_balance, 3) . " kg, Requested: " . number_format($requested_qty_kg, 3) . " kg (" . number_format($requested_qty, 0) . " " . $item_data['unit_symbol'] . ")");
                                }
                                
                                // Balance in PRODUCTION (dest)
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item['item_id'], $destination_location_id]);
                                $dest_balance = floatval($stmt->fetchColumn());
                                
                                $new_source_balance = $source_balance - $requested_qty_kg;
                                $new_dest_balance   = $dest_balance + $requested_qty_kg;
                                
                                // Insert MRN item (store requested qty in pieces for display, but stock ledger uses KG)
                                $stmt = $db->prepare("INSERT INTO mrn_items (mrn_id, item_id, location_id, quantity) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$mrn_id, $item['item_id'], $destination_location_id, $requested_qty]);
                                $items_inserted++;
                                
                                // 1) OUT from STORE (use KG)
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'mrn', ?, ?, ?, 0, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $item['item_id'], 
                                    $source_location_id,
                                    $mrn_id, 
                                    $_POST['mrn_no'], 
                                    $_POST['mrn_date'], 
                                    $requested_qty_kg, 
                                    $new_source_balance
                                ]);
                                
                                // 2) IN to PRODUCTION (use KG)
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'mrn', ?, ?, ?, ?, 0, ?, NOW())
                                ");
                                $stmt->execute([
                                    $item['item_id'], 
                                    $destination_location_id,
                                    $mrn_id, 
                                    $_POST['mrn_no'], 
                                    $_POST['mrn_date'], 
                                    $requested_qty_kg, 
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

                // =========================
                // CREATE RETURN MRN (Production -> Store)
                // =========================
                case 'create_return':
                    $db->beginTransaction();
                    try {
                        // Insert Return header (using same mrn table)
                        $stmt = $db->prepare("INSERT INTO mrn (mrn_no, mrn_date, purpose, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                        $stmt->execute([$_POST['rtn_no'], $_POST['rtn_date'], $_POST['rtn_purpose']]);
                        $rtn_id = $db->lastInsertId();
                        
                        $items_inserted = 0;
                        
                        // Always FROM Production (2) TO Store (1)
                        $source_location_id = 2; // Production
                        $destination_location_id = 1; // Store
                        
                        foreach ($_POST['return_items'] as $item) {
                            if (!empty($item['item_id']) && !empty($item['quantity'])) {
                                $requested_qty = floatval($item['quantity']);
                                
                                // CONVERT PIECES TO KG if needed
                                $stmt = $db->prepare("SELECT i.*, u.symbol as unit_symbol FROM items i JOIN units u ON u.id = i.unit_id WHERE i.id = ?");
                                $stmt->execute([$item['item_id']]);
                                $item_data = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if (!$item_data) {
                                    throw new Exception("Item not found");
                                }
                                
                                $requested_qty_kg = $requested_qty; // Default: assume already in kg
                                
                                // If unit is PCS/PC, convert to KG using BOM data
                                if (strtolower($item_data['unit_symbol']) == 'pcs' || strtolower($item_data['unit_symbol']) == 'pc') {
                                    $kg_per_piece = 0;
                                    
                                    // Try bom_product first
                                    $stmt = $db->prepare("SELECT product_unit_qty FROM bom_product WHERE finished_item_id = ? LIMIT 1");
                                    $stmt->execute([$item['item_id']]);
                                    $bom_product = $stmt->fetch();
                                    
                                    if ($bom_product && $bom_product['product_unit_qty'] > 0) {
                                        $kg_per_piece = floatval($bom_product['product_unit_qty']);
                                    } else {
                                        // Try bom_direct
                                        $stmt = $db->prepare("SELECT finished_unit_qty FROM bom_direct WHERE finished_item_id = ? LIMIT 1");
                                        $stmt->execute([$item['item_id']]);
                                        $bom_direct = $stmt->fetch();
                                        
                                        if ($bom_direct && $bom_direct['finished_unit_qty'] > 0) {
                                            $kg_per_piece = floatval($bom_direct['finished_unit_qty']);
                                        }
                                    }
                                    
                                    if ($kg_per_piece > 0) {
                                        $requested_qty_kg = $requested_qty * $kg_per_piece;
                                    } else {
                                        $requested_qty_kg = $requested_qty;
                                    }
                                }
                                
                                // Stock in PRODUCTION (source) - from ledger
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item['item_id'], $source_location_id]);
                                $ledger_balance = floatval($stmt->fetchColumn());
                                
                                // If no ledger entry, check items.current_stock as fallback
                                $source_balance = $ledger_balance;
                                if ($source_balance == 0) {
                                    $stmt = $db->prepare("SELECT COALESCE(current_stock, 0) as stock FROM items WHERE id = ?");
                                    $stmt->execute([$item['item_id']]);
                                    $fallback_stock = floatval($stmt->fetchColumn());
                                    if ($fallback_stock > 0) {
                                        $source_balance = $fallback_stock;
                                        // Create initial_stock entry for this item in Production to bootstrap the ledger
                                        $stmt = $db->prepare("
                                            INSERT INTO stock_ledger (
                                                item_id, location_id, transaction_type, reference_id, reference_no,
                                                transaction_date, quantity_in, quantity_out, balance, created_at
                                            ) VALUES (?, ?, 'initial_stock', 0, 'FALLBACK', ?, ?, 0, ?, NOW())
                                        ");
                                        $stmt->execute([
                                            $item['item_id'],
                                            $source_location_id,
                                            $_POST['rtn_date'],
                                            $source_balance,
                                            $source_balance
                                        ]);
                                    }
                                }
                                
                                if ($source_balance < $requested_qty_kg) {
                                    throw new Exception("Insufficient stock in Production for {$item_data['name']}. Available: " . number_format($source_balance, 3) . " kg, Requested: " . number_format($requested_qty_kg, 3) . " kg (" . number_format($requested_qty, 0) . " " . $item_data['unit_symbol'] . ")");
                                }
                                
                                // Stock in STORE (dest)
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item['item_id'], $destination_location_id]);
                                $dest_balance = floatval($stmt->fetchColumn());
                                
                                $new_source_balance = $source_balance - $requested_qty_kg;
                                $new_dest_balance   = $dest_balance + $requested_qty_kg;
                                
                                // Insert return item (store requested qty in pieces for display)
                                $stmt = $db->prepare("INSERT INTO mrn_items (mrn_id, item_id, location_id, quantity) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$rtn_id, $item['item_id'], $destination_location_id, $requested_qty]);
                                $items_inserted++;
                                
                                // 1) OUT from PRODUCTION  (transaction_type='mrn_return', use KG)
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'mrn_return', ?, ?, ?, 0, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $item['item_id'], 
                                    $source_location_id,
                                    $rtn_id, 
                                    $_POST['rtn_no'], 
                                    $_POST['rtn_date'], 
                                    $requested_qty_kg, 
                                    $new_source_balance
                                ]);
                                
                                // 2) IN to STORE        (transaction_type='mrn_return', use KG)
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'mrn_return', ?, ?, ?, ?, 0, ?, NOW())
                                ");
                                $stmt->execute([
                                    $item['item_id'], 
                                    $destination_location_id,
                                    $rtn_id, 
                                    $_POST['rtn_no'], 
                                    $_POST['rtn_date'], 
                                    $requested_qty_kg, 
                                    $new_dest_balance
                                ]);
                            }
                        }
                        
                        if ($items_inserted == 0) {
                            throw new Exception("No items were processed. Please add items to the Return MRN.");
                        }
                        
                        $db->commit();
                        $success = "Return MRN created successfully! {$items_inserted} items transferred from Production Floor to Store.";
                    } catch(Exception $e) {
                        $db->rollback();
                        throw $e;
                    }
                    break;

                // Mark completed (works for both MRN and Return MRN)
                case 'complete':
                    $stmt = $db->prepare("UPDATE mrn SET status = 'completed' WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $success = "Record marked as completed!";
                    break;

                // Delete (support MRN or Return MRN by detecting prefix)
                case 'delete':
                    $db->beginTransaction();
                    try {
                        // Get MRN items
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
                        $mrn_no = $mrn ? $mrn['mrn_no'] : '';

                        // Detect transaction type based on prefix
                        $is_return = (strpos($mrn_no, 'RTN') === 0);
                        // Use MRN types for returns
                        $ledger_type   = $is_return ? 'mrn_return' : 'mrn';
                        $reversal_type = $is_return ? 'mrn_return_reversal' : 'mrn_reversal';
                        
                        foreach ($mrn_items as $item) {
                            // Balance at stored location_id (dest saved in items)
                            $stmt = $db->prepare("
                                SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                FROM stock_ledger 
                                WHERE item_id = ? AND location_id = ?
                            ");
                            $stmt->execute([$item['item_id'], $item['location_id']]);
                            $current_balance = floatval($stmt->fetchColumn());
                            
                            $new_balance = $current_balance + floatval($item['quantity']);
                            
                            // Add reversal entry
                            $stmt = $db->prepare("
                                INSERT INTO stock_ledger (
                                    item_id, location_id, transaction_type, reference_id, reference_no,
                                    transaction_date, quantity_in, quantity_out, balance
                                ) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 0, ?)
                            ");
                            $stmt->execute([
                                $item['item_id'], 
                                $item['location_id'], 
                                $reversal_type, 
                                $_POST['id'], 
                                'REV-' . $mrn_no, 
                                $item['quantity'],
                                $new_balance
                            ]);
                            
                            // Update items.current_stock (kept same pattern)
                            $stmt = $db->prepare("UPDATE items SET current_stock = current_stock + ? WHERE id = ?");
                            $stmt->execute([$item['quantity'], $item['item_id']]);
                        }

                        // Delete original stock ledger entries for this ref & type
                        $stmt = $db->prepare("DELETE FROM stock_ledger WHERE transaction_type = ? AND reference_id = ?");
                        $stmt->execute([$ledger_type, $_POST['id']]);

                        // Delete MRN items
                        $stmt = $db->prepare("DELETE FROM mrn_items WHERE mrn_id = ?");
                        $stmt->execute([$_POST['id']]);
                        
                        // Delete MRN header
                        $stmt = $db->prepare("DELETE FROM mrn WHERE id = ?");
                        $stmt->execute([$_POST['id']]);
                        
                        $db->commit();
                        $success = "Record deleted successfully and stock restored!";
                    } catch(Exception $e) {
                        $db->rollback();
                        throw $e;
                    }
                    break;
            }
        }
    } catch(Exception $e) {
        if ($db->inTransaction()) { $db->rollback(); }
        $error = "Error: " . $e->getMessage();
    } catch(PDOException $e) {
        if ($db->inTransaction()) { $db->rollback(); }
        $error = "Database Error: " . $e->getMessage();
    }
}

// Fetch MRNs (includes Returns since they share table; distinguish by prefix)
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

// Fetch items with any positive ledger stock (list)
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

// Counts for default numbers
try {
    $mrn_count = (int)$db->query("SELECT COUNT(*) FROM mrn WHERE mrn_no LIKE 'MRN%'")->fetchColumn();
    $rtn_count = (int)$db->query("SELECT COUNT(*) FROM mrn WHERE mrn_no LIKE 'RTN%'")->fetchColumn();
} catch(PDOException $e) {
    $mrn_count = 0; $rtn_count = 0;
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Material Request Note (MRN) & Return MRN</h1>
            <p class="text-gray-600">MRN: Store → Production | Return MRN: Production → Store</p>
        </div>
        <div class="space-x-2">
            <button onclick="openModal('createMrnModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                Create New MRN
            </button>
            <button onclick="openModal('createReturnModal')" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition-colors">
                Create Return MRN
            </button>
        </div>
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
                    <strong>No items in stock!</strong> Create GRNs first to receive items before issuing/returning.
                    <a href="grn.php" class="underline ml-2">Create GRN →</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- MRN/Return Table (shared) -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">No</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Purpose</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Items</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Qty</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($mrns as $mrn): 
                    $is_return = strpos($mrn['mrn_no'], 'RTN') === 0; ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $is_return ? 'bg-indigo-100 text-indigo-800' : 'bg-blue-100 text-blue-800'; ?>">
                            <?php echo $is_return ? 'Return MRN' : 'MRN'; ?>
                        </span>
                    </td>
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
                        <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this record? This will restore the stock.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $mrn['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($mrns)): ?>
                <tr>
                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">No records found. Create your first MRN or Return MRN!</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create MRN Modal (Store -> Production) -->
<div id="createMrnModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Create New MRN (Store → Production)</h3>
            <button onclick="closeModal('createMrnModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-6" onsubmit="return validateMrnForm()">
            <input type="hidden" name="action" value="create">
            
            <!-- MRN Header -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">MRN Number</label>
                    <input type="text" name="mrn_no" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="MRN001" value="MRN<?php echo str_pad($mrn_count + 1, 3, '0', STR_PAD_LEFT); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purpose</label>
                    <input type="text" name="purpose" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="e.g., Production Issue">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">MRN Date</label>
                    <input type="date" name="mrn_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <!-- Items Section -->
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h4 class="text-md font-semibold text-gray-900">Items to Issue (From Store)</h4>
                    <button type="button" onclick="addMrnItem()" class="bg-green-500 text-white px-3 py-1 rounded text-sm hover:bg-green-600">Add Item</button>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Item</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Available in Store</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Request Quantity</th>
                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody id="mrnItemsTable">
                            <tr class="mrn-item-row">
                                <td class="px-4 py-2 border-r">
                                    <select name="items[0][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-mrn" onchange="updateStockMrn(this)" required>
                                        <option value="">Select Item</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
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

<!-- Create Return MRN Modal (Production -> Store) -->
<div id="createReturnModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Create Return MRN (Production → Store)</h3>
            <button onclick="closeModal('createReturnModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-6" onsubmit="return validateReturnForm()">
            <input type="hidden" name="action" value="create_return">
            
            <!-- Return Header -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Return MRN Number</label>
                    <input type="text" name="rtn_no" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="RTN001" value="RTN<?php echo str_pad($rtn_count + 1, 3, '0', STR_PAD_LEFT); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purpose</label>
                    <input type="text" name="rtn_purpose" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="e.g., Excess material return">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Return Date</label>
                    <input type="date" name="rtn_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <!-- Items Section -->
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h4 class="text-md font-semibold text-gray-900">Items to Return (From Production)</h4>
                    <button type="button" onclick="addReturnItem()" class="bg-indigo-600 text-white px-3 py-1 rounded text-sm hover:bg-indigo-700">Add Item</button>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Item</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Available in Production</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r">Return Quantity</th>
                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody id="returnItemsTable">
                            <tr class="return-item-row">
                                <td class="px-4 py-2 border-r">
                                    <select name="return_items[0][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-return" onchange="updateStockReturn(this)" required>
                                        <option value="">Select Item</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
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
                                    <input type="number" name="return_items[0][quantity]" step="0.001" min="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateQuantity(this)" required>
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <button type="button" onclick="removeReturnItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createReturnModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Create Return MRN</button>
            </div>
        </form>
    </div>
</div>

<!-- View MRN Details Modal (simple placeholder; hook up to your get_mrn_details.php) -->
<div id="viewMrnModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-4 mx-auto p-5 border w-11/12 max-w-7xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-gray-900 flex items-center">
                <svg class="w-6 h-6 mr-2 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                MRN Details
            </h3>
            <button onclick="closeModal('viewMrnModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="mrnDetailsContent" class="max-h-[calc(100vh-150px)] overflow-y-auto">
            <div class="text-center py-8">
                <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                <h3 class="text-lg font-medium text-gray-900 mb-2">MRN Details</h3>
                <p class="text-gray-600">Click "View" on any MRN/Return MRN to see detailed information.</p>
            </div>
        </div>
        <div class="flex justify-end mt-6 pt-4 border-t border-gray-200">
            <button onclick="closeModal('viewMrnModal')" class="px-6 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 transition-colors">Close</button>
        </div>
    </div>
</div>

<script>
let mrnItemCount = 1;
let returnItemCount = 1;

function confirmDelete(msg) {
    return confirm(msg || 'Are you sure?');
}

// ===== Add/Remove rows (MRN)
function addMrnItem() {
    const table = document.getElementById('mrnItemsTable');
    const row = document.createElement('tr');
    row.className = 'mrn-item-row';
    row.innerHTML = `
        <td class="px-4 py-2 border-r">
            <select name="items[${mrnItemCount}][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-mrn" onchange="updateStockMrn(this)" required>
                <option value="">Select Item</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
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
    if (table.rows.length > 1) { button.closest('tr').remove(); }
}

// ===== Add/Remove rows (Return MRN)
function addReturnItem() {
    const table = document.getElementById('returnItemsTable');
    const row = document.createElement('tr');
    row.className = 'return-item-row';
    row.innerHTML = `
        <td class="px-4 py-2 border-r">
            <select name="return_items[${returnItemCount}][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-return" onchange="updateStockReturn(this)" required>
                <option value="">Select Item</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
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
            <input type="number" name="return_items[${returnItemCount}][quantity]" step="0.001" min="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateQuantity(this)" required>
        </td>
        <td class="px-4 py-2 text-center">
            <button type="button" onclick="removeReturnItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
        </td>
    `;
    table.appendChild(row);
    returnItemCount++;
}
function removeReturnItem(button) {
    const table = document.getElementById('returnItemsTable');
    if (table.rows.length > 1) { button.closest('tr').remove(); }
}

// ===== Stock helpers
function fetchLocationSpecificStock(itemId, locationId, row) {
    const stockDisplay = row.querySelector('.stock-display');
    stockDisplay.textContent = 'Loading...';
    stockDisplay.className = 'stock-display text-sm font-medium text-blue-600';
    
    fetch(`get_current_stock.php?item_id=${itemId}&location_id=${locationId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const currentStock = parseFloat(data.current_stock || 0);
                const quantityInput = row.querySelector('.quantity-input');
                const unitDisplay = row.querySelector('.unit-display');
                
                stockDisplay.textContent = currentStock.toFixed(3);
                stockDisplay.className = 'stock-display text-sm font-medium ' + 
                    (currentStock === 0 ? 'text-red-600' : currentStock < 50 ? 'text-yellow-600' : 'text-green-600');
                
                if (unitDisplay) unitDisplay.textContent = data.unit || '';
                
                quantityInput.setAttribute('max', currentStock);
                
                const requestedQty = parseFloat(quantityInput.value || 0);
                if (requestedQty > currentStock) {
                    quantityInput.style.borderColor = '#ef4444';
                    quantityInput.title = `Insufficient stock! Available: ${currentStock.toFixed(3)}`;
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
            }
        })
        .catch(() => {
            stockDisplay.textContent = 'Error';
            stockDisplay.className = 'stock-display text-sm font-medium text-red-600';
        });
}

function updateStockMrn(select) {
    const row = select.closest('tr');
    const itemId = select.value;
    if (itemId) fetchLocationSpecificStock(itemId, 1, row); // 1 = Store
    else resetStockDisplay(row);
}
function updateStockReturn(select) {
    const row = select.closest('tr');
    const itemId = select.value;
    if (itemId) fetchLocationSpecificStock(itemId, 2, row); // 2 = Production
    else resetStockDisplay(row);
}

function resetStockDisplay(row) {
    const stockDisplay = row.querySelector('.stock-display');
    const unitDisplay = row.querySelector('.unit-display');
    const quantityInput = row.querySelector('.quantity-input');
    stockDisplay.textContent = '-';
    stockDisplay.className = 'stock-display text-sm font-medium';
    if (unitDisplay) unitDisplay.textContent = '';
    quantityInput.removeAttribute('max');
    quantityInput.style.borderColor = '#d1d5db';
    quantityInput.title = '';
}

function validateQuantity(input) {
    const row = input.closest('tr');
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

// Basic validators (can be extended)
function validateMrnForm(){ return true; }
function validateReturnForm(){ return true; }

// View details (placeholder; expects your get_mrn_details.php to return JSON)
function viewMrnDetails(mrnId) {
    openModal('viewMrnModal');
    showMrnLoading();
    fetch(`get_mrn_details.php?mrn_id=${mrnId}`)
        .then(r => r.text())
        .then(t => {
            try {
                const data = JSON.parse(t);
                if (data.success) displayMrnDetails(data);
                else showMrnError(data.message || 'Unknown error occurred');
            } catch(e) {
                showMrnError('Invalid response from server: ' + t.substring(0, 200));
            }
        })
        .catch(err => showMrnError(`Network error: ${err.message}`));
}

function showMrnLoading() {
    const content = document.getElementById('mrnDetailsContent');
    content.innerHTML = `
        <div class="min-h-[300px] flex items-center justify-center">
            <div class="text-center">
                <div class="w-16 h-16 border-4 border-red-200 border-t-red-600 rounded-full animate-spin mx-auto mb-4"></div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Loading Details</h3>
                <p class="text-gray-600">Please wait...</p>
            </div>
        </div>
    `;
}

function displayMrnDetails(data) {
    const content = document.getElementById('mrnDetailsContent');
    content.innerHTML = `<pre class="p-4 bg-gray-50 rounded border overflow-auto text-xs">${JSON.stringify(data, null, 2)}</pre>`;
}

function showMrnError(message) {
    const content = document.getElementById('mrnDetailsContent');
    content.innerHTML = `
        <div class="min-h-[200px] flex items-center justify-center">
            <div class="text-center">
                <svg class="w-16 h-16 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Error Loading Details</h3>
                <p class="text-gray-600 mb-4">${message}</p>
                <button onclick="closeModal('viewMrnModal')" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors">Close</button>
            </div>
        </div>
    `;
}

function printMrnDetails(mrnId) {
    const w = window.open(`print_mrn.php?mrn_id=${mrnId}`, '_blank', 'width=900,height=700,scrollbars=yes,resizable=yes');
    if (w) { w.focus(); w.onload = () => setTimeout(() => w.print(), 500); }
    else { alert('❌ Please allow popups to print details'); }
}

function exportMrnToPdf(mrnId) {
    const downloadLink = document.createElement('a');
    downloadLink.href = `export_mrn_pdf.php?mrn_id=${mrnId}`;
    downloadLink.download = `MRN_${mrnId}.pdf`;
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    setTimeout(() => document.body.removeChild(downloadLink), 500);
}

// Modal helpers
function openModal(id){ document.getElementById(id).classList.remove('hidden'); }
function closeModal(id){ document.getElementById(id).classList.add('hidden'); }

// Small styles
const extraCSS = `
<style>
.modal-backdrop { backdrop-filter: blur(2px); }
@keyframes spin { to { transform: rotate(360deg); } }
.animate-spin { animation: spin 1s linear infinite; }
</style>`;
document.head.insertAdjacentHTML('beforeend', extraCSS);
</script>

<?php include 'footer.php'; ?>
