<?php
// repacking.php - Repacking Module (Convert finished products into smaller units)
//
// REMAINING QUANTITY TRACKING SYSTEM:
// ==================================
// This module tracks remaining_qty to prevent double counting in bundling operations.
//
// KEY FIELDS:
// - repack_quantity: Total repacked units produced (never changes)
// - remaining_qty: Available units for bundling (decreases when used in bundling)
// - consumed_qty: repack_quantity - remaining_qty (calculated field)
//
// INTEGRATION WITH BUNDLING:
// - When bundling.php consumes repacked items, it calls updateRemainingRepackQty()
// - This ensures repacking records show accurate availability
// - Reports use remaining_qty > 0 to filter out fully consumed repacking
//
// STOCK CONSISTENCY RULES:
// - stock_ledger balance = current physical stock in warehouse
// - remaining_qty = available for future bundling operations
// - Only repacking records with remaining_qty > 0 appear as "available"
//

// Start session and initialize database before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize database and process POST BEFORE outputting any content
require_once 'database.php';
$database = new Database();
$db = $database->getConnection();

/**
 * Update item current_stock from stock_ledger (ALL locations)
 * GLOBAL RULE: current_stock = total across all locations
 * This ensures stock reports use stock_ledger as single source of truth
 */
function updateItemCurrentStock($db, $item_id) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as total_stock
        FROM stock_ledger
        WHERE item_id = ?
    ");
    $stmt->execute([$item_id]);
    $total = floatval($stmt->fetchColumn());
    
    $upd = $db->prepare("UPDATE items SET current_stock = ? WHERE id = ?");
    $upd->execute([$total, $item_id]);
}

/**
 * Get current stock for item at specific location from stock_ledger
 * This prevents double counting by using only stock_ledger data
 */
function getCurrentStockAtLocation($db, $item_id, $location_id) {
    $stmt = $db->prepare("
        SELECT COALESCE(balance, 0) as current_stock
        FROM stock_ledger
        WHERE item_id = ? AND location_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$item_id, $location_id]);
    $result = $stmt->fetch();
    return $result ? floatval($result['current_stock']) : 0;
}

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    $db->beginTransaction();
                    
                    // Generate next repack code
                    $stmt = $db->query("SELECT repack_code FROM repacking ORDER BY id DESC LIMIT 1");
                    $last_repack = $stmt->fetch();
                    if ($last_repack) {
                        $last_number = intval(substr($last_repack['repack_code'], 2));
                        $next_code = 'RP' . str_pad($last_number + 1, 6, '0', STR_PAD_LEFT);
                    } else {
                        $next_code = 'RP000001';
                    }
                    
                    // Get source item details with unit info
                    $stmt = $db->prepare("SELECT i.*, u.symbol AS unit_symbol FROM items i JOIN units u ON u.id = i.unit_id WHERE i.id = ?");
                    $stmt->execute([$_POST['source_item_id']]);
                    $source_item = $stmt->fetch();
                    
                    if (!$source_item) {
                        throw new Exception("Source item not found");
                    }
                    
                    // Get repack item details
                    $stmt = $db->prepare("SELECT unit_id FROM items WHERE id = ?");
                    $stmt->execute([$_POST['repack_item_id']]);
                    $repack_item = $stmt->fetch();
                    
                    if (!$repack_item) {
                        throw new Exception("Repack item not found");
                    }
                    
                    $source_quantity = floatval($_POST['source_quantity']);
                    $repack_unit_size = floatval($_POST['repack_unit_size']);
                    $location_id = intval($_POST['location_id']);
                    
                    // CONVERT PCS TO KG if needed (BEFORE stock check)
                    // Stock ledger stores everything in KG, but user may input in PCS
                    $source_quantity_kg = $source_quantity; // Default: assume already in kg
                    
                    if (strtolower($source_item['unit_symbol']) == 'pcs' || strtolower($source_item['unit_symbol']) == 'pc') {
                        // Unit is PCS, need to convert to KG using BOM tables
                        $kg_per_piece = 0;
                        
                        // Try bom_product first (finished items made via Peetu)
                        $stmt = $db->prepare("
                            SELECT product_unit_qty, total_quantity, quantity 
                            FROM bom_product 
                            WHERE finished_item_id = ? 
                            LIMIT 1
                        ");
                        $stmt->execute([$_POST['source_item_id']]);
                        $bom_product = $stmt->fetch();
                        
                        if ($bom_product && $bom_product['product_unit_qty'] > 0) {
                            // Calculate kg per piece from bom_product
                            $kg_per_piece = floatval($bom_product['product_unit_qty']);
                        } else {
                            // Try bom_direct (finished items made directly from raw)
                            $stmt = $db->prepare("
                                SELECT finished_unit_qty 
                                FROM bom_direct 
                                WHERE finished_item_id = ? 
                                LIMIT 1
                            ");
                            $stmt->execute([$_POST['source_item_id']]);
                            $bom_direct = $stmt->fetch();
                            
                            if ($bom_direct && $bom_direct['finished_unit_qty'] > 0) {
                                // finished_unit_qty is kg per piece (e.g., 20 kg for 1 pc Papadam)
                                $kg_per_piece = floatval($bom_direct['finished_unit_qty']);
                            } else {
                                throw new Exception("Cannot determine kg per piece for this item. Please set up BOM data.");
                            }
                        }
                        
                        if ($kg_per_piece > 0) {
                            $source_quantity_kg = $source_quantity * $kg_per_piece;
                        } else {
                            throw new Exception("Invalid BOM configuration: kg per piece is zero");
                        }
                    }
                    
                    // Check available stock in store location (stock is in KG)
                    $stock_stmt = $db->prepare("
                        SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as available_stock 
                        FROM stock_ledger 
                        WHERE item_id = ? AND location_id = ?
                    ");
                    $stock_stmt->execute([$_POST['source_item_id'], $location_id]);
                    $available_stock = floatval($stock_stmt->fetchColumn());
                    
                    if ($source_quantity_kg > $available_stock) {
                        throw new Exception("Insufficient stock! Available: " . number_format($available_stock, 3) . " kg, Requested: " . number_format($source_quantity_kg, 3) . " kg (" . number_format($source_quantity, 0) . " " . $source_item['unit_symbol'] . ")");
                    }
                    
                    if ($repack_unit_size <= 0) {
                        throw new Exception("Repack unit size must be greater than zero");
                    }
                    
                    // Calculate how many packs can be made using kg
                    $repack_quantity = floor($source_quantity_kg / $repack_unit_size);
                    
                    if ($repack_quantity <= 0) {
                        throw new Exception("Source quantity is too small to create any repack units");
                    }
                    
                    // Insert repacking record
                    $stmt = $db->prepare("
                        INSERT INTO repacking (
                            repack_code, repack_date, source_item_id, source_batch_code,
                            source_quantity, source_unit_id, repack_item_id, repack_quantity,
                            repack_unit_id, repack_unit_size, remaining_qty, location_id, notes, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $next_code,
                        $_POST['repack_date'],
                        $_POST['source_item_id'],
                        $_POST['source_batch_code'] ?? null,
                        $source_quantity,
                        $source_item['unit_id'],
                        $_POST['repack_item_id'],
                        $repack_quantity,
                        $repack_item['unit_id'],
                        $repack_unit_size,
                        $repack_quantity, // Initialize remaining_qty with full repack_quantity
                        $_POST['location_id'],
                        $_POST['notes'] ?? null,
                        $_SESSION['user_id']
                    ]);
                    
                    $repacking_id = $db->lastInsertId();
                    
                    // Insert additional materials if provided
                    if (!empty($_POST['material_items']) && is_array($_POST['material_items'])) {
                        $stmt_material = $db->prepare("
                            INSERT INTO repacking_materials (repacking_id, item_id, quantity, unit_id)
                            VALUES (?, ?, ?, ?)
                        ");
                        
                        foreach ($_POST['material_items'] as $index => $material_id) {
                            if (!empty($material_id) && !empty($_POST['material_quantities'][$index])) {
                                // Get material unit
                                $stmt_unit = $db->prepare("SELECT unit_id FROM items WHERE id = ?");
                                $stmt_unit->execute([$material_id]);
                                $material = $stmt_unit->fetch();
                                
                                $stmt_material->execute([
                                    $repacking_id,
                                    $material_id,
                                    $_POST['material_quantities'][$index],
                                    $material['unit_id']
                                ]);
                            }
                        }
                    }
                    
                    // Update stock ledger - Reduce source item stock (use KG amount)
                    $stmt = $db->prepare("
                        INSERT INTO stock_ledger 
                        (item_id, location_id, transaction_type, reference_id, reference_no, 
                         transaction_date, quantity_in, quantity_out, balance)
                        SELECT ?, ?, 'repack_out', ?, ?, ?, 0, ?, 
                               COALESCE((SELECT balance FROM stock_ledger 
                                        WHERE item_id = ? AND location_id = ? 
                                        ORDER BY id DESC LIMIT 1), 0) - ?
                    ");
                    
                    $stmt->execute([
                        $_POST['source_item_id'],
                        $_POST['location_id'],
                        $repacking_id,
                        $next_code,
                        $_POST['repack_date'],
                        $source_quantity_kg,
                        $_POST['source_item_id'],
                        $_POST['location_id'],
                        $source_quantity_kg
                    ]);
                    
                    // Update current_stock for source item (GLOBAL - all locations)
                    updateItemCurrentStock($db, $_POST['source_item_id']);
                    
                    // Update stock ledger - Increase repack item stock
                    $stmt = $db->prepare("
                        INSERT INTO stock_ledger 
                        (item_id, location_id, transaction_type, reference_id, reference_no, 
                         transaction_date, quantity_in, quantity_out, balance)
                        SELECT ?, ?, 'repack_in', ?, ?, ?, ?, 0, 
                               COALESCE((SELECT balance FROM stock_ledger 
                                        WHERE item_id = ? AND location_id = ? 
                                        ORDER BY id DESC LIMIT 1), 0) + ?
                    ");
                    
                    $stmt->execute([
                        $_POST['repack_item_id'],
                        $_POST['location_id'],
                        $repacking_id,
                        $next_code,
                        $_POST['repack_date'],
                        $repack_quantity,
                        $_POST['repack_item_id'],
                        $_POST['location_id'],
                        $repack_quantity
                    ]);
                    
                    // Update current_stock for repack item (GLOBAL - all locations)
                    updateItemCurrentStock($db, $_POST['repack_item_id']);
                    
                    // Deduct additional materials from stock
                    if (!empty($_POST['material_items']) && is_array($_POST['material_items'])) {
                        $stmt_material_stock = $db->prepare("
                            INSERT INTO stock_ledger 
                            (item_id, location_id, transaction_type, reference_id, reference_no, 
                             transaction_date, quantity_in, quantity_out, balance)
                            SELECT ?, ?, 'repack_out', ?, ?, ?, 0, ?, 
                                   COALESCE((SELECT balance FROM stock_ledger 
                                            WHERE item_id = ? AND location_id = ? 
                                            ORDER BY id DESC LIMIT 1), 0) - ?
                        ");
                        
                        foreach ($_POST['material_items'] as $index => $material_id) {
                            if (!empty($material_id) && !empty($_POST['material_quantities'][$index])) {
                                $material_qty = floatval($_POST['material_quantities'][$index]);
                                
                                $stmt_material_stock->execute([
                                    $material_id,
                                    $_POST['location_id'],
                                    $repacking_id,
                                    $next_code,
                                    $_POST['repack_date'],
                                    $material_qty,
                                    $material_id,
                                    $_POST['location_id'],
                                    $material_qty
                                ]);
                                
                                // Update current_stock for material (GLOBAL - all locations)
                                updateItemCurrentStock($db, $material_id);
                            }
                        }
                    }
                    
                    $db->commit();
                    $success = "Repacking completed successfully! Code: {$next_code}, Packs Created: {$repack_quantity}";
                    
                    // Redirect to prevent duplicate submission on page reload
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                    
                case 'delete':
                    $db->beginTransaction();
                    
                    // Get repacking details before deletion
                    $stmt = $db->prepare("SELECT * FROM repacking WHERE id = ?");
                    $stmt->execute([$_POST['repacking_id']]);
                    $repack = $stmt->fetch();
                    
                    if (!$repack) {
                        throw new Exception("Repacking record not found");
                    }
                    
                    // Get affected items BEFORE deletion
                    $stmt_items = $db->prepare("
                        SELECT DISTINCT item_id 
                        FROM stock_ledger 
                        WHERE transaction_type IN ('repack_in', 'repack_out') AND reference_id = ?
                    ");
                    $stmt_items->execute([$_POST['repacking_id']]);
                    $affected_items = $stmt_items->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Delete stock ledger entries
                    $stmt = $db->prepare("DELETE FROM stock_ledger WHERE transaction_type IN ('repack_in', 'repack_out') AND reference_id = ?");
                    $stmt->execute([$_POST['repacking_id']]);
                    
                    // Delete repacking record (materials will be deleted via CASCADE)
                    $stmt = $db->prepare("DELETE FROM repacking WHERE id = ?");
                    $stmt->execute([$_POST['repacking_id']]);
                    
                    // Recalculate current_stock for all affected items
                    foreach ($affected_items as $item_id) {
                        updateItemCurrentStock($db, $item_id);
                    }
                    
                    $db->commit();
                    $success = "Repacking record deleted successfully!";
                    
                    // Redirect to prevent duplicate submission on page reload
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                    
                case 'transfer':
                    $db->beginTransaction();
                    
                    $transfer_date = $_POST['transfer_date'];
                    $from_location_id = intval($_POST['from_location_id']);
                    $to_location_id = intval($_POST['to_location_id']);
                    $transfer_items = $_POST['transfer_items'] ?? [];
                    $transfer_quantities = $_POST['transfer_quantities'] ?? [];
                    
                    if (empty($transfer_items) || empty($transfer_quantities)) {
                        throw new Exception("No items selected for transfer");
                    }
                    
                    if ($from_location_id == $to_location_id) {
                        throw new Exception("From and To locations cannot be the same");
                    }
                    
                    // Generate transfer reference number
                    $stmt = $db->query("SELECT transfer_no FROM repacking_transfers ORDER BY id DESC LIMIT 1");
                    $last_transfer = $stmt->fetch();
                    if ($last_transfer) {
                        $last_number = intval(substr($last_transfer['transfer_no'], 3));
                        $transfer_no = 'TRF' . str_pad($last_number + 1, 6, '0', STR_PAD_LEFT);
                    } else {
                        $transfer_no = 'TRF000001';
                    }
                    
                    $total_items = 0;
                    
                    // Process each transfer item
                    foreach ($transfer_items as $index => $item_id) {
                        if (empty($item_id) || empty($transfer_quantities[$index])) {
                            continue;
                        }
                        
                        $item_id = intval($item_id);
                        $quantity = floatval($transfer_quantities[$index]);
                        
                        if ($quantity <= 0) {
                            continue;
                        }
                        
                        // Check available stock in from location
                        $stock_stmt = $db->prepare("
                            SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as available_stock 
                            FROM stock_ledger 
                            WHERE item_id = ? AND location_id = ?
                        ");
                        $stock_stmt->execute([$item_id, $from_location_id]);
                        $available_stock = floatval($stock_stmt->fetchColumn());
                        
                        if ($quantity > $available_stock) {
                            // Get item name for error message
                            $item_stmt = $db->prepare("SELECT name FROM items WHERE id = ?");
                            $item_stmt->execute([$item_id]);
                            $item_name = $item_stmt->fetchColumn();
                            
                            throw new Exception("Insufficient stock for {$item_name}! Available: " . number_format($available_stock, 3) . " kg, Requested: " . number_format($quantity, 3) . " kg");
                        }
                        
                        // Insert transfer record
                        $stmt = $db->prepare("
                            INSERT INTO repacking_transfers (
                                transfer_no, transfer_date, item_id, quantity, 
                                from_location_id, to_location_id, created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $transfer_no,
                            $transfer_date,
                            $item_id,
                            $quantity,
                            $from_location_id,
                            $to_location_id,
                            $_SESSION['user_id']
                        ]);
                        
                        // Update stock ledger - Reduce from source location
                        $stmt = $db->prepare("
                            INSERT INTO stock_ledger 
                            (item_id, location_id, transaction_type, reference_id, reference_no, 
                             transaction_date, quantity_in, quantity_out, balance)
                            SELECT ?, ?, 'transfer_out', ?, ?, ?, 0, ?, 
                                   COALESCE((SELECT balance FROM stock_ledger 
                                            WHERE item_id = ? AND location_id = ? 
                                            ORDER BY id DESC LIMIT 1), 0) - ?
                        ");
                        $stmt->execute([
                            $item_id,
                            $from_location_id,
                            $db->lastInsertId(),
                            $transfer_no,
                            $transfer_date,
                            $quantity,
                            $item_id,
                            $from_location_id,
                            $quantity
                        ]);
                        
                        // Update stock ledger - Increase in destination location
                        $stmt = $db->prepare("
                            INSERT INTO stock_ledger 
                            (item_id, location_id, transaction_type, reference_id, reference_no, 
                             transaction_date, quantity_in, quantity_out, balance)
                            SELECT ?, ?, 'transfer_in', ?, ?, ?, ?, 0, 
                                   COALESCE((SELECT balance FROM stock_ledger 
                                            WHERE item_id = ? AND location_id = ? 
                                            ORDER BY id DESC LIMIT 1), 0) + ?
                        ");
                        $stmt->execute([
                            $item_id,
                            $to_location_id,
                            $db->lastInsertId(),
                            $transfer_no,
                            $transfer_date,
                            $quantity,
                            $item_id,
                            $to_location_id,
                            $quantity
                        ]);
                        
                        // Update current_stock for the item (GLOBAL - all locations)
                        updateItemCurrentStock($db, $item_id);
                        
                        $total_items++;
                    }
                    
                    if ($total_items == 0) {
                        throw new Exception("No valid items to transfer");
                    }
                    
                    $db->commit();
                    $success = "Transfer completed successfully! Reference: {$transfer_no}, Items transferred: {$total_items}";
                    
                    // Redirect to prevent duplicate submission on page reload
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
            }
        }
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// Now include header after POST processing
include 'header.php';

// Fetch all repacking records 
// Option: Add WHERE r.remaining_qty > 0 to show only pending repacking records
try {
    // Check if user wants to see all records or only pending ones
    $show_only_pending = isset($_GET['filter']) && $_GET['filter'] === 'pending';
    $where_clause = $show_only_pending ? "WHERE r.remaining_qty > 0" : "";
    
    $stmt = $db->query("
        SELECT 
            r.id, r.repack_code, r.repack_date, r.source_item_id, r.repack_item_id,
            r.source_quantity, r.repack_quantity, r.repack_unit_size, r.remaining_qty,
            r.source_unit_id, r.repack_unit_id, r.location_id, r.notes,
            r.created_by, r.created_at, r.updated_at,
            i1.code AS source_item_code, i1.name AS source_item_name, u1.symbol AS source_unit_symbol,
            i2.code AS repack_item_code, i2.name AS repack_item_name, u2.symbol AS repack_unit_symbol,
            l.name AS location_name,
            au.full_name AS created_by_name,
            (r.repack_quantity - r.remaining_qty) as consumed_qty,
            CASE 
                WHEN r.remaining_qty = 0 THEN 'Fully Consumed'
                WHEN r.remaining_qty = r.repack_quantity THEN 'Available'
                WHEN r.remaining_qty > 0 THEN 'Partially Consumed'
                ELSE 'Over-consumed'
            END as status
        FROM repacking r
        LEFT JOIN items i1 ON r.source_item_id = i1.id
        LEFT JOIN items i2 ON r.repack_item_id = i2.id
        LEFT JOIN units u1 ON r.source_unit_id = u1.id
        LEFT JOIN units u2 ON r.repack_unit_id = u2.id
        LEFT JOIN locations l ON r.location_id = l.id
        LEFT JOIN admin_users au ON r.created_by = au.id
        $where_clause
        ORDER BY r.repack_date DESC, r.created_at DESC
    ");
    $repacking_records = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching repacking records: " . $e->getMessage();
    $repacking_records = [];
}

// Fetch finished items for source selection with BOM data
try {
    $stmt = $db->query("
        SELECT i.id, i.code, i.name, u.symbol AS unit_symbol,
               (SELECT product_unit_qty FROM bom_product WHERE finished_item_id = i.id LIMIT 1) as product_unit_qty,
               (SELECT total_quantity FROM bom_product WHERE finished_item_id = i.id LIMIT 1) as total_quantity,
               (SELECT finished_unit_qty FROM bom_direct WHERE finished_item_id = i.id LIMIT 1) as finished_unit_qty
        FROM items i
        JOIN units u ON u.id = i.unit_id
        WHERE i.type = 'finished'
        ORDER BY i.name
    ");
    $finished_items = $stmt->fetchAll();
} catch(PDOException $e) {
    $finished_items = [];
}

// Fetch raw materials for additional materials
try {
    $stmt = $db->query("
        SELECT i.id, i.code, i.name, u.symbol AS unit_symbol
        FROM items i
        JOIN units u ON u.id = i.unit_id
        WHERE i.type = 'raw'
        ORDER BY i.name
    ");
    $raw_materials = $stmt->fetchAll();
} catch(PDOException $e) {
    $raw_materials = [];
}

// Fetch locations
try {
    $stmt = $db->query("SELECT id, name FROM locations ORDER BY name");
    $locations = $stmt->fetchAll();
} catch(PDOException $e) {
    $locations = [];
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Repacking Management</h1>
            <p class="text-gray-600">Convert finished products into smaller repack units</p>
        </div>
        <div class="flex space-x-3">
            <a href="bundling.php" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                Go to Bundling
            </a>
            <button onclick="openModal('createRepackModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                New Repacking
            </button>
        </div>
    </div>

    <!-- Success/Error Messages -->
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

    <!-- Info Box -->
    <!-- <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 class="font-semibold text-blue-900 mb-2">How Repacking Works:</h3>
        <ul class="list-disc list-inside text-blue-800 space-y-1 text-sm">
            <li><strong>Select Source Product:</strong> Choose the finished product (e.g., Papadam 5 kg)</li>
            <li><strong>Define Repack Size:</strong> Specify the size per pack (e.g., 50 g or 0.05 kg)</li>
            <li><strong>Auto-Calculate Output:</strong> System calculates: Packs = Source Quantity ÷ Repack Size</li>
            <li><strong>Add Materials (Optional):</strong> Include packaging materials, labels, etc.</li>
            <li><strong>Stock Update:</strong> Source stock decreases, repack stock increases automatically</li>
            <li><strong>Batch Tracking:</strong> Link to original batch code for traceability</li>
        </ul>
    </div> -->

    <!-- Repacking Records Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-900">Repacking History</h2>
            <div class="flex space-x-2">
                <?php 
                $current_filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
                $base_url = 'repacking.php';
                ?>
                <a href="<?php echo $base_url; ?>" 
                   class="px-3 py-1 text-sm rounded <?php echo $current_filter === 'all' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    All Records
                </a>
                <a href="<?php echo $base_url; ?>?filter=pending" 
                   class="px-3 py-1 text-sm rounded <?php echo $current_filter === 'pending' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    Available Only
                </a>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source Product</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source Qty</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Repack Product</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Packs Created</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Size</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Available Packs</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($repacking_records)): ?>
                        <tr>
                            <td colspan="11" class="px-6 py-4 text-center text-gray-500">
                                No repacking records found. Click "New Repacking" to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($repacking_records as $record): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($record['repack_code']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('d M Y', strtotime($record['repack_date'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($record['source_item_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($record['source_item_code']); ?></div>
                                <?php if (isset($record['source_batch_code']) && $record['source_batch_code']): ?>
                                    <div class="text-xs text-blue-600">Batch: <?php echo htmlspecialchars($record['source_batch_code']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo number_format($record['source_quantity'], 3); ?> <?php echo htmlspecialchars($record['source_unit_symbol']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($record['repack_item_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($record['repack_item_code']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-sm font-semibold rounded-full bg-green-100 text-green-800">
                                    <?php echo number_format($record['repack_quantity'], 0); ?> packs
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo number_format($record['repack_unit_size'], 3); ?> <?php echo htmlspecialchars($record['repack_unit_symbol']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php 
                                $remaining = floatval($record['remaining_qty']);
                                $total = floatval($record['repack_quantity']);
                                $consumed = floatval($record['consumed_qty']);
                                $percentage = $total > 0 ? ($remaining / $total) * 100 : 0;
                                
                                if ($percentage >= 80) {
                                    $color_class = 'bg-green-100 text-green-800';
                                } elseif ($percentage >= 50) {
                                    $color_class = 'bg-yellow-100 text-yellow-800';
                                } elseif ($percentage > 0) {
                                    $color_class = 'bg-orange-100 text-orange-800';
                                } else {
                                    $color_class = 'bg-gray-100 text-gray-800';
                                }
                                ?>
                                <span class="px-2 py-1 text-sm font-semibold rounded-full <?php echo $color_class; ?>">
                                    <?php echo number_format($remaining, 0); ?> packs
                                </span>
                                <?php if ($consumed > 0): ?>
                                    <div class="text-xs text-gray-500 mt-1">
                                        Used: <?php echo number_format($consumed, 0); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($record['location_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <?php 
                                $status = $record['status'];
                                $status_colors = [
                                    'Available' => 'bg-green-100 text-green-800',
                                    'Partially Consumed' => 'bg-yellow-100 text-yellow-800',
                                    'Fully Consumed' => 'bg-gray-100 text-gray-800',
                                    'Over-consumed' => 'bg-red-100 text-red-800'
                                ];
                                $status_color = $status_colors[$status] ?? 'bg-gray-100 text-gray-500';
                                ?>
                                <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_color; ?>">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <button onclick="viewDetails(<?php echo $record['id']; ?>)" 
                                        class="text-indigo-600 hover:text-indigo-900 mr-3">View</button>
                                <button onclick="deleteRepack(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['repack_code']); ?>')" 
                                        class="text-red-600 hover:text-red-900">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Repacking Modal -->
<div id="createRepackModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-4xl shadow-lg rounded-md bg-white mb-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Create Repacking</h3>
            <button onclick="closeModal('createRepackModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <form method="POST" class="space-y-6">
            <input type="hidden" name="action" value="create">
            
            <!-- Basic Information -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Repack Date *</label>
                    <input type="date" name="repack_date" value="<?php echo date('Y-m-d'); ?>" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location *</label>
                    <select name="location_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="">-- Select Location --</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo $loc['id']; ?>">
                                <?php echo htmlspecialchars($loc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Source Product Section -->
            <div class="border-t pt-4">
                <h4 class="text-lg font-semibold text-gray-900 mb-3">Source Product (Original)</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Source Finished Product *</label>
                        <select name="source_item_id" id="source_item_id" required onchange="updateSourceItemInfo()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">-- Select Source Product --</option>
                            <?php foreach ($finished_items as $item): 
                                $kg_per_pc = $item['product_unit_qty'] ?: ($item['finished_unit_qty'] ?: 0);
                            ?>
                                <option value="<?php echo $item['id']; ?>" 
                                        data-unit="<?php echo htmlspecialchars($item['unit_symbol']); ?>"
                                        data-kg-per-pc="<?php echo $kg_per_pc; ?>">
                                    <?php echo htmlspecialchars($item['name']); ?> (<?php echo htmlspecialchars($item['code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Source Quantity *</label>
                        <div class="flex">
                            <input type="number" name="source_quantity" id="source_quantity" step="0.001" min="0.001" required
                                   oninput="calculateRepackQty()"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-l-md focus:outline-none focus:ring-2 focus:ring-primary">
                            <span id="source_unit_display" class="px-3 py-2 bg-gray-100 border border-l-0 border-gray-300 rounded-r-md text-gray-600 text-sm">
                                kg
                            </span>
                        </div>
                        <!-- Conversion info -->
                        <div id="conversion_info" class="hidden mt-1 p-2 bg-blue-50 border border-blue-200 rounded text-xs">
                            <span id="conversion_text" class="text-blue-800"></span>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Source Batch Code (Optional)</label>
                    <input type="text" name="source_batch_code" placeholder="e.g., BATCH20251028"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <!-- Repack Product Section -->
            <div class="border-t pt-4 bg-blue-50 p-4 rounded-lg">
                <h4 class="text-lg font-semibold text-gray-900 mb-3">Repack Product (New Small Units)</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Repack Finished Product *</label>
                        <select name="repack_item_id" id="repack_item_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">-- Select Repack Product --</option>
                            <?php foreach ($finished_items as $item): ?>
                                <option value="<?php echo $item['id']; ?>" data-unit="<?php echo htmlspecialchars($item['unit_symbol']); ?>">
                                    <?php echo htmlspecialchars($item['name']); ?> (<?php echo htmlspecialchars($item['code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Size per Pack *</label>
                        <div class="flex">
                            <input type="number" name="repack_unit_size" id="repack_unit_size" step="0.001" min="0.001" required
                                   onchange="calculateRepackQty()" placeholder="e.g., 0.05 for 50g"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-l-md focus:outline-none focus:ring-2 focus:ring-primary">
                            <span id="repack_unit_display" class="px-3 py-2 bg-gray-100 border border-l-0 border-gray-300 rounded-r-md text-gray-600 text-sm">
                                kg
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Example: 0.05 kg = 50g</p>
                    </div>
                </div>
                
                <!-- Calculation Display -->
                <div class="mt-4 p-4 bg-white border-2 border-blue-300 rounded-lg">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-600">Total Packs to be Created:</p>
                            <p class="text-xs text-gray-500">Formula: Source Qty ÷ Size per Pack</p>
                        </div>
                        <div class="text-right">
                            <p id="calculated_packs" class="text-3xl font-bold text-green-600">0</p>
                            <p class="text-sm text-gray-500">packs</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Materials Section -->
            <div class="border-t pt-4">
                <div class="flex justify-between items-center mb-3">
                    <h4 class="text-lg font-semibold text-gray-900">Additional Materials (Optional)</h4>
                    <button type="button" onclick="addMaterialRow()" 
                            class="text-sm bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded-md">
                        + Add Material
                    </button>
                </div>
                <div id="materials_container">
                    <!-- Material rows will be added here -->
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" placeholder="Any additional notes about this repacking batch..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal('createRepackModal')"
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                    Create Repacking
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let materialRowIndex = 0;
let currentKgPerPc = 0;
let currentSourceUnit = 'kg';

function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

function updateSourceItemInfo() {
    const sourceSelect = document.getElementById('source_item_id');
    const selectedOption = sourceSelect.options[sourceSelect.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || 'kg';
    const kgPerPc = parseFloat(selectedOption.getAttribute('data-kg-per-pc')) || 0;
    
    currentSourceUnit = unit.toLowerCase();
    currentKgPerPc = kgPerPc;
    
    document.getElementById('source_unit_display').textContent = unit;
    
    // Show conversion info if unit is pcs
    const conversionInfo = document.getElementById('conversion_info');
    const conversionText = document.getElementById('conversion_text');
    
    if (currentSourceUnit === 'pcs' && kgPerPc > 0) {
        conversionInfo.classList.remove('hidden');
        conversionText.textContent = `1 pc = ${kgPerPc} kg`;
    } else {
        conversionInfo.classList.add('hidden');
    }
    
    calculateRepackQty();
}

// Update unit displays when items are selected
document.getElementById('source_item_id')?.addEventListener('change', updateSourceItemInfo);

document.getElementById('repack_item_id')?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || 'kg';
    document.getElementById('repack_unit_display').textContent = unit;
    calculateRepackQty();
});

function calculateRepackQty() {
    let sourceQty = parseFloat(document.getElementById('source_quantity').value) || 0;
    const repackSize = parseFloat(document.getElementById('repack_unit_size').value) || 0;
    
    // Convert pcs to kg if needed
    let sourceQtyKg = sourceQty;
    if (currentSourceUnit === 'pcs' && currentKgPerPc > 0) {
        sourceQtyKg = sourceQty * currentKgPerPc;
        
        // Update conversion info
        const conversionText = document.getElementById('conversion_text');
        conversionText.textContent = `${sourceQty} pcs × ${currentKgPerPc} kg/pc = ${sourceQtyKg.toFixed(3)} kg total`;
    }
    
    if (sourceQtyKg > 0 && repackSize > 0) {
        const packs = Math.floor(sourceQtyKg / repackSize);
        document.getElementById('calculated_packs').textContent = packs.toLocaleString();
        
        // Update formula display
        const formulaDiv = document.querySelector('.text-xs.text-gray-500');
        if (currentSourceUnit === 'pcs' && currentKgPerPc > 0) {
            formulaDiv.textContent = `Formula: ${sourceQtyKg.toFixed(3)} kg ÷ ${repackSize} kg per pack`;
        } else {
            formulaDiv.textContent = `Formula: ${sourceQty} kg ÷ ${repackSize} kg per pack`;
        }
    } else {
        document.getElementById('calculated_packs').textContent = '0';
    }
}

function addMaterialRow() {
    materialRowIndex++;
    const container = document.getElementById('materials_container');
    const row = document.createElement('div');
    row.className = 'grid grid-cols-12 gap-2 mb-2';
    row.id = `material_row_${materialRowIndex}`;
    row.innerHTML = `
        <div class="col-span-7">
            <select name="material_items[]" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">-- Select Material --</option>
                <?php foreach ($raw_materials as $mat): ?>
                    <option value="<?php echo $mat['id']; ?>">
                        <?php echo htmlspecialchars($mat['name']); ?> (<?php echo htmlspecialchars($mat['unit_symbol']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-span-4">
            <input type="number" name="material_quantities[]" step="0.001" min="0" placeholder="Quantity"
                   class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div class="col-span-1">
            <button type="button" onclick="removeMaterialRow(${materialRowIndex})" 
                    class="w-full px-2 py-2 bg-red-100 hover:bg-red-200 text-red-600 rounded-md">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    `;
    container.appendChild(row);
}

function removeMaterialRow(index) {
    const row = document.getElementById(`material_row_${index}`);
    if (row) row.remove();
}

function viewDetails(id) {
    // Fetch repacking details via AJAX
    fetch('get_repacking_details.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const record = data.record;
                const modal = document.createElement('div');
                modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
                modal.innerHTML = `
                    <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-xl font-bold text-gray-900">Repacking Details - ${record.repack_code}</h3>
                            <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-4">
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <h4 class="font-semibold text-gray-900 mb-2">Repacking Information</h4>
                                    <div class="space-y-2 text-sm">
                                        <div><span class="font-medium">Code:</span> ${record.repack_code}</div>
                                        <div><span class="font-medium">Date:</span> ${new Date(record.repack_date).toLocaleDateString()}</div>
                                        <div><span class="font-medium">Location:</span> ${record.location_name || 'N/A'}</div>
                                        <div><span class="font-medium">Created By:</span> ${record.created_by_name || 'N/A'}</div>
                                        <div><span class="font-medium">Created:</span> ${new Date(record.created_at).toLocaleString()}</div>
                                    </div>
                                </div>

                                <div class="bg-blue-50 p-4 rounded-lg">
                                    <h4 class="font-semibold text-blue-900 mb-2">Source Product</h4>
                                    <div class="space-y-2 text-sm">
                                        <div><span class="font-medium">Name:</span> ${record.source_item_name}</div>
                                        <div><span class="font-medium">Code:</span> ${record.source_item_code}</div>
                                        <div><span class="font-medium">Quantity Used:</span> ${record.source_quantity} ${record.source_unit_symbol}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div class="bg-green-50 p-4 rounded-lg">
                                    <h4 class="font-semibold text-green-900 mb-2">Repack Product</h4>
                                    <div class="space-y-2 text-sm">
                                        <div><span class="font-medium">Name:</span> ${record.repack_item_name}</div>
                                        <div><span class="font-medium">Code:</span> ${record.repack_item_code}</div>
                                        <div><span class="font-medium">Packs Created:</span> ${record.repack_quantity}</div>
                                        <div><span class="font-medium">Unit Size:</span> ${record.repack_unit_size} ${record.repack_unit_symbol}</div>
                                        <div><span class="font-medium">Available Packs:</span> ${record.remaining_qty}</div>
                                        <div><span class="font-medium">Consumed Packs:</span> ${record.consumed_qty}</div>
                                        <div><span class="font-medium">Status:</span> 
                                            <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">${record.status}</span>
                                        </div>
                                    </div>
                                </div>

                                ${record.notes ? `
                                <div class="bg-yellow-50 p-4 rounded-lg">
                                    <h4 class="font-semibold text-yellow-900 mb-2">Notes</h4>
                                    <p class="text-sm text-gray-700">${record.notes}</p>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
            } else {
                alert('Error loading repacking details: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading repacking details');
        });
}

function deleteRepack(id, code) {
    if (confirm(`Are you sure you want to delete repacking "${code}"? This will reverse all stock changes. This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="repacking_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('fixed')) {
        event.target.classList.add('hidden');
    }
}
</script>

<?php include 'footer.php'; ?>
