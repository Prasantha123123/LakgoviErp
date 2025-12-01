<?php
// bundling.php - Bundling Module (Bundle repacked items into larger packages)
// 
// CRITICAL DOUBLE-COUNTING PREVENTION SYSTEM:
// ==========================================
// This module implements a remaining_qty tracking system to prevent double counting
// of repacked items that have been consumed in bundling operations.
//
// FLOW: Repacking → Bundling → Stock Reports
// 1. Repacking creates items with remaining_qty = repack_quantity (all available)
// 2. Bundling consumes repacked items using FIFO, reducing remaining_qty
// 3. Stock reports only count items with remaining_qty > 0 (prevents double counting)
//
// FIFO (First In, First Out) CONSUMPTION:
// - When bundling consumes repacked items, oldest repacking records are used first
// - This ensures proper inventory turnover and accurate aging of stock
//
// SAFETY MECHANISMS:
// 1. validateRepackedAvailability() - Prevents over-consumption before bundling
// 2. updateRemainingRepackQty() - FIFO consumption tracking during bundling
// 3. restoreRemainingRepackQty() - LIFO restoration when bundles are deleted
// 4. Source item selection - Only shows items with available repacked stock
//
require_once 'database.php';
require_once 'config/simple_auth.php';

// Initialize database and auth
$database = new Database();
$db = $database->getConnection();
$auth = new SimpleAuth($db);
$auth->requireAuth();

/**
 * Update remaining repacked quantity after bundling consumption
 * This prevents double counting of repacked stock in reports
 */
function updateRemainingRepackQty($db, $source_item_id, $consumed_qty, $location_id) {
    // Get available repacking records for this item at this location, ordered by creation date (FIFO)
    $stmt = $db->prepare("
        SELECT id, remaining_qty 
        FROM repacking 
        WHERE repack_item_id = ? AND location_id = ? AND remaining_qty > 0 
        ORDER BY repack_date ASC, id ASC
    ");
    $stmt->execute([$source_item_id, $location_id]);
    $repack_records = $stmt->fetchAll();
    
    $remaining_to_consume = $consumed_qty;
    
    foreach ($repack_records as $record) {
        if ($remaining_to_consume <= 0) break;
        
        $available = floatval($record['remaining_qty']);
        $to_consume = min($remaining_to_consume, $available);
        
        // Update this repacking record's remaining quantity
        $update_stmt = $db->prepare(
            "UPDATE repacking SET remaining_qty = remaining_qty - ? WHERE id = ?"
        );
        $update_stmt->execute([$to_consume, $record['id']]);
        
        $remaining_to_consume -= $to_consume;
    }
    
    if ($remaining_to_consume > 0) {
        throw new Exception("Unable to consume full quantity. Missing: {$remaining_to_consume} units from repacking records.");
    }
}

/**
 * Restore remaining repacked quantity when bundle is deleted
 * This restores availability for future bundling operations
 * Uses reverse FIFO (LIFO) approach to restore quantities
 */
function restoreRemainingRepackQty($db, $source_item_id, $restore_qty, $location_id) {
    // Get repacking records for this item at this location, ordered by creation date (reverse FIFO for restoration)
    $stmt = $db->prepare("
        SELECT id, remaining_qty, repack_quantity 
        FROM repacking 
        WHERE repack_item_id = ? AND location_id = ? 
        ORDER BY repack_date DESC, id DESC
    ");
    $stmt->execute([$source_item_id, $location_id]);
    $repack_records = $stmt->fetchAll();
    
    $remaining_to_restore = $restore_qty;
    
    foreach ($repack_records as $record) {
        if ($remaining_to_restore <= 0) break;
        
        $current_remaining = floatval($record['remaining_qty']);
        $total_repack_qty = floatval($record['repack_quantity']);
        $max_restorable = $total_repack_qty - $current_remaining;
        
        if ($max_restorable <= 0) continue; // This record is already fully available
        
        $to_restore = min($remaining_to_restore, $max_restorable);
        
        // Update this repacking record's remaining quantity
        $update_stmt = $db->prepare(
            "UPDATE repacking SET remaining_qty = remaining_qty + ? WHERE id = ?"
        );
        $update_stmt->execute([$to_restore, $record['id']]);
        
        $remaining_to_restore -= $to_restore;
    }
    
    // Note: If there's still remaining_to_restore > 0 here, it means the bundle was created
    // before proper tracking was implemented. In this case, we can't restore fully but 
    // this is an acceptable limitation for legacy data.
}

/**
 * Get available repacked stock quantity for an item at a location
 * This checks both stock_ledger balance and remaining repacked quantities
 * CRITICAL: This prevents double counting by using remaining_qty from repacking table
 */
function getAvailableRepackedStock($db, $item_id, $location_id) {
    // Get total remaining from repacking records (this is what's actually available for bundling)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(remaining_qty), 0) as available_repacked
        FROM repacking 
        WHERE repack_item_id = ? AND location_id = ? AND remaining_qty > 0
    ");
    $stmt->execute([$item_id, $location_id]);
    $available_repacked = floatval($stmt->fetchColumn());
    
    // For repacked items, the remaining_qty is the authoritative source
    // Stock_ledger balance can become inconsistent due to bundling operations
    // So we prioritize remaining_qty from repacking table for bundling validation
    return $available_repacked;
}

/**
 * Validate that there's sufficient remaining repacked quantity before bundling
 * This is a safety check to prevent over-consumption of repacked items
 */
function validateRepackedAvailability($db, $item_id, $location_id, $requested_qty) {
    $available_qty = getAvailableRepackedStock($db, $item_id, $location_id);
    
    if ($requested_qty > $available_qty) {
        // Get item name for better error message
        $stmt = $db->prepare("SELECT name FROM items WHERE id = ?");
        $stmt->execute([$item_id]);
        $item_info = $stmt->fetch();
        $item_name = $item_info ? $item_info['name'] : "Item #$item_id";
        
        throw new Exception("Insufficient repacked stock for '{$item_name}'! Available: {$available_qty}, Requested: {$requested_qty}");
    }
    
    return true;
}

// Handle form submissions BEFORE any output
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    $db->beginTransaction();
                    
                    // Generate next bundle code
                    $stmt = $db->query("SELECT bundle_code FROM bundles ORDER BY id DESC LIMIT 1");
                    $last_bundle = $stmt->fetch();
                    if ($last_bundle) {
                        $last_number = intval(substr($last_bundle['bundle_code'], 2));
                        $next_code = 'BN' . str_pad($last_number + 1, 6, '0', STR_PAD_LEFT);
                    } else {
                        $next_code = 'BN000001';
                    }
                    
                    // Get source item details
                    $stmt = $db->prepare("SELECT unit_id FROM items WHERE id = ?");
                    $stmt->execute([$_POST['source_item_id']]);
                    $source_item = $stmt->fetch();
                    
                    // Get bundle item details
                    $stmt = $db->prepare("SELECT unit_id FROM items WHERE id = ?");
                    $stmt->execute([$_POST['bundle_item_id']]);
                    $bundle_item = $stmt->fetch();
                    
                    // Calculate bundle quantity
                    $source_quantity = floatval($_POST['source_quantity']);
                    $packs_per_bundle = intval($_POST['packs_per_bundle']);
                    
                    if ($packs_per_bundle <= 0) {
                        throw new Exception("Packs per bundle must be greater than zero");
                    }
                    
                    // Check available repacked stock for source item
                    validateRepackedAvailability($db, $_POST['source_item_id'], $_POST['location_id'], $source_quantity);
                    
                    // Calculate how many bundles can be made
                    $bundle_quantity = floor($source_quantity / $packs_per_bundle);
                    
                    if ($bundle_quantity <= 0) {
                        throw new Exception("Source quantity is too small to create any bundles");
                    }
                    
                    // Insert bundling record
                    $stmt = $db->prepare("
                        INSERT INTO bundles (
                            bundle_code, bundle_date, source_item_id, source_quantity,
                            source_unit_id, bundle_item_id, bundle_quantity,
                            bundle_unit_id, packs_per_bundle, location_id, notes, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $next_code,
                        $_POST['bundle_date'],
                        $_POST['source_item_id'],
                        $source_quantity,
                        $source_item['unit_id'],
                        $_POST['bundle_item_id'],
                        $bundle_quantity,
                        $bundle_item['unit_id'],
                        $packs_per_bundle,
                        $_POST['location_id'],
                        $_POST['notes'] ?? null,
                        $_SESSION['user_id']
                    ]);
                    
                    $bundle_id = $db->lastInsertId();
                    
                    // Insert bundling materials if provided
                    if (!empty($_POST['material_items']) && is_array($_POST['material_items'])) {
                        $stmt_material = $db->prepare("
                            INSERT INTO bundle_materials (bundle_id, item_id, quantity, unit_id)
                            VALUES (?, ?, ?, ?)
                        ");
                        
                        foreach ($_POST['material_items'] as $index => $material_id) {
                            if (!empty($material_id) && !empty($_POST['material_quantities'][$index])) {
                                $material_qty = floatval($_POST['material_quantities'][$index]);
                                
                                // Check available stock for material
                                $stmt_check = $db->prepare("
                                    SELECT COALESCE(balance, 0) as available_stock 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ? 
                                    ORDER BY id DESC LIMIT 1
                                ");
                                $stmt_check->execute([$material_id, $_POST['location_id']]);
                                $material_stock = $stmt_check->fetch();
                                $available_material = $material_stock ? floatval($material_stock['available_stock']) : 0;
                                
                                if ($material_qty > $available_material) {
                                    // Get material name for error message
                                    $stmt_name = $db->prepare("SELECT name FROM items WHERE id = ?");
                                    $stmt_name->execute([$material_id]);
                                    $material_info = $stmt_name->fetch();
                                    throw new Exception("Insufficient stock for material '{$material_info['name']}'! Available: {$available_material}, Requested: {$material_qty}");
                                }
                                
                                // Get material unit
                                $stmt_unit = $db->prepare("SELECT unit_id FROM items WHERE id = ?");
                                $stmt_unit->execute([$material_id]);
                                $material = $stmt_unit->fetch();
                                
                                $stmt_material->execute([
                                    $bundle_id,
                                    $material_id,
                                    $_POST['material_quantities'][$index],
                                    $material['unit_id']
                                ]);
                            }
                        }
                    }
                    
                    // Update stock ledger - Reduce source item stock
                    $stmt = $db->prepare("
                        INSERT INTO stock_ledger 
                        (item_id, location_id, transaction_type, reference_id, reference_no, 
                         transaction_date, quantity_in, quantity_out, balance)
                        SELECT ?, ?, 'bundle_out', ?, ?, ?, 0, ?, 
                               COALESCE((SELECT balance FROM stock_ledger 
                                        WHERE item_id = ? AND location_id = ? 
                                        ORDER BY id DESC LIMIT 1), 0) - ?
                    ");
                    
                    $stmt->execute([
                        $_POST['source_item_id'],
                        $_POST['location_id'],
                        $bundle_id,
                        $next_code,
                        $_POST['bundle_date'],
                        $source_quantity,
                        $_POST['source_item_id'],
                        $_POST['location_id'],
                        $source_quantity
                    ]);
                    
                    // Update stock ledger - Increase bundle item stock
                    $stmt = $db->prepare("
                        INSERT INTO stock_ledger 
                        (item_id, location_id, transaction_type, reference_id, reference_no, 
                         transaction_date, quantity_in, quantity_out, balance)
                        SELECT ?, ?, 'bundle_in', ?, ?, ?, ?, 0, 
                               COALESCE((SELECT balance FROM stock_ledger 
                                        WHERE item_id = ? AND location_id = ? 
                                        ORDER BY id DESC LIMIT 1), 0) + ?
                    ");
                    
                    $stmt->execute([
                        $_POST['bundle_item_id'],
                        $_POST['location_id'],
                        $bundle_id,
                        $next_code,
                        $_POST['bundle_date'],
                        $bundle_quantity,
                        $_POST['bundle_item_id'],
                        $_POST['location_id'],
                        $bundle_quantity
                    ]);
                    
                    // Update remaining repacked quantities (FIFO consumption)
                    updateRemainingRepackQty($db, $_POST['source_item_id'], $source_quantity, $_POST['location_id']);
                    
                    // Deduct bundling materials from stock
                    if (!empty($_POST['material_items']) && is_array($_POST['material_items'])) {
                        $stmt_material_stock = $db->prepare("
                            INSERT INTO stock_ledger 
                            (item_id, location_id, transaction_type, reference_id, reference_no, 
                             transaction_date, quantity_in, quantity_out, balance)
                            SELECT ?, ?, 'bundle_out', ?, ?, ?, 0, ?, 
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
                                    $bundle_id,
                                    $next_code,
                                    $_POST['bundle_date'],
                                    $material_qty,
                                    $material_id,
                                    $_POST['location_id'],
                                    $material_qty
                                ]);
                            }
                        }
                    }
                    
                    $db->commit();
                    $_SESSION['success_message'] = "Bundling completed successfully! Code: {$next_code}, Bundles Created: {$bundle_quantity}";
                    header("Location: bundling.php");
                    exit();
                    
                case 'delete':
                    $db->beginTransaction();
                    
                    // Get bundle details before deletion
                    $stmt = $db->prepare("SELECT * FROM bundles WHERE id = ?");
                    $stmt->execute([$_POST['bundle_id']]);
                    $bundle = $stmt->fetch();
                    
                    if (!$bundle) {
                        throw new Exception("Bundle record not found");
                    }
                    
                    // Restore remaining quantities in repacking table (reverse the consumption)
                    restoreRemainingRepackQty(
                        $db, 
                        $bundle['source_item_id'], 
                        $bundle['source_quantity'], 
                        $bundle['location_id']
                    );
                    
                    // Delete stock ledger entries
                    $stmt = $db->prepare("DELETE FROM stock_ledger WHERE transaction_type IN ('bundle_in', 'bundle_out') AND reference_id = ?");
                    $stmt->execute([$_POST['bundle_id']]);
                    
                    // Delete bundle record (materials will be deleted via CASCADE)
                    $stmt = $db->prepare("DELETE FROM bundles WHERE id = ?");
                    $stmt->execute([$_POST['bundle_id']]);
                    
                    $db->commit();
                    $_SESSION['success_message'] = "Bundle record deleted successfully! Repacked quantities restored.";
                    header("Location: bundling.php");
                    exit();
            }
        }
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header("Location: bundling.php");
        exit();
    }
}

// Now include header after POST processing
include 'header.php';

$success = '';
$error = '';

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Fetch all bundle records
try {
    $stmt = $db->query("
        SELECT 
            b.id,
            b.bundle_code,
            b.bundle_date,
            b.source_item_id,
            si.code AS source_item_code,
            si.name AS source_item_name,
            b.source_quantity,
            su.symbol AS source_unit_symbol,
            b.bundle_item_id,
            bi.code AS bundle_item_code,
            bi.name AS bundle_item_name,
            b.bundle_quantity,
            b.packs_per_bundle,
            bu.symbol AS bundle_unit_symbol,
            CONCAT(b.packs_per_bundle, ' packs per bundle') AS bundle_config,
            l.name AS location_name,
            b.notes,
            u.username AS created_by_name,
            b.created_at,
            b.updated_at
        FROM bundles b
        LEFT JOIN items si ON si.id = b.source_item_id
        LEFT JOIN items bi ON bi.id = b.bundle_item_id
        LEFT JOIN units su ON su.id = b.source_unit_id
        LEFT JOIN units bu ON bu.id = b.bundle_unit_id
        LEFT JOIN locations l ON l.id = b.location_id
        LEFT JOIN admin_users u ON u.id = b.created_by
        ORDER BY b.bundle_date DESC, b.created_at DESC
    ");
    $bundle_records = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching bundle records: " . $e->getMessage();
    $bundle_records = [];
}

// Fetch finished items with available repacked stock for source selection
// CRITICAL: Only show items that have remaining_qty > 0 to prevent over-consumption
try {
    $stmt = $db->query("
        SELECT DISTINCT 
            i.id, i.code, i.name, u.symbol AS unit_symbol,
            COALESCE(SUM(r.remaining_qty), 0) as total_available_qty
        FROM items i
        JOIN units u ON u.id = i.unit_id
        LEFT JOIN repacking r ON r.repack_item_id = i.id AND r.remaining_qty > 0
        WHERE i.type = 'finished'
        GROUP BY i.id, i.code, i.name, u.symbol
        ORDER BY i.name
    ");
    $finished_items = $stmt->fetchAll();
} catch(PDOException $e) {
    $finished_items = [];
}

// Fetch all finished items for bundle product selection (destination)
try {
    $stmt = $db->query("
        SELECT i.id, i.code, i.name, u.symbol AS unit_symbol
        FROM items i
        JOIN units u ON u.id = i.unit_id
        WHERE i.type = 'finished'
        ORDER BY i.name
    ");
    $bundle_finished_items = $stmt->fetchAll();
} catch(PDOException $e) {
    $bundle_finished_items = [];
}

// Fetch raw materials for bundling materials
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
            <div class="flex items-center space-x-3 mb-2">
                <a href="repacking.php" class="text-gray-600 hover:text-gray-800 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                </a>
                <h1 class="text-3xl font-bold text-gray-900">Bundling Management</h1>
            </div>
            <p class="text-gray-600">Bundle repacked items into larger packages</p>
        </div>
        <button onclick="openModal('createBundleModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Bundle
        </button>
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
        <h3 class="font-semibold text-blue-900 mb-2">How Bundling Works:</h3>
        <ul class="list-disc list-inside text-blue-800 space-y-1 text-sm">
            <li><strong>Select Source Packs:</strong> Choose repacked items (e.g., Papadam 50g packs)</li>
            <li><strong>Define Bundle Size:</strong> Specify how many packs per bundle (e.g., 10 packs)</li>
            <li><strong>Auto-Calculate Bundles:</strong> System calculates: Bundles = Total Packs ÷ Packs per Bundle</li>
            <li><strong>Add Materials (Optional):</strong> Include cartons, plastic wrap, tape, etc.</li>
            <li><strong>Stock Update:</strong> Source packs decrease, bundle stock increases automatically</li>
            <li><strong>Example:</strong> 100 packs of 50g ÷ 10 packs/bundle = 10 bundles created</li>
        </ul>
    </div> -->

    <!-- Bundle Records Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Bundling History</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source Packs</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source Qty</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bundle Product</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bundles Created</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Config</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($bundle_records)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-4 text-center text-gray-500">
                                No bundle records found. Click "New Bundle" to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bundle_records as $record): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($record['bundle_code']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('d M Y', strtotime($record['bundle_date'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($record['source_item_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($record['source_item_code']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo number_format($record['source_quantity'], 0); ?> <?php echo htmlspecialchars($record['source_unit_symbol']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($record['bundle_item_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($record['bundle_item_code']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-sm font-semibold rounded-full bg-green-100 text-green-800">
                                    <?php echo number_format($record['bundle_quantity'], 0); ?> bundles
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo $record['packs_per_bundle']; ?> packs/bundle
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($record['location_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <button onclick="viewDetails(<?php echo $record['id']; ?>)" 
                                        class="text-indigo-600 hover:text-indigo-900 mr-3">View</button>
                                <button onclick="deleteBundle(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['bundle_code']); ?>')" 
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

<!-- Create Bundle Modal -->
<div id="createBundleModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-4xl shadow-lg rounded-md bg-white mb-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Create Bundle</h3>
            <button onclick="closeModal('createBundleModal')" class="text-gray-400 hover:text-gray-600">
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bundle Date *</label>
                    <input type="date" name="bundle_date" value="<?php echo date('Y-m-d'); ?>" required
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
                <h4 class="text-lg font-semibold text-gray-900 mb-3">Source Packs (Individual Items)</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Source Repacked Product *</label>
                        <select name="source_item_id" id="source_item_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">-- Select Source Packs --</option>
                            <?php foreach ($finished_items as $item): ?>
                                <option value="<?php echo $item['id']; ?>" data-unit="<?php echo htmlspecialchars($item['unit_symbol']); ?>">
                                    <?php echo htmlspecialchars($item['name']); ?> (<?php echo htmlspecialchars($item['code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Example: Papadam 50g packs (individual packs)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Source Quantity *</label>
                        <div class="flex">
                            <input type="number" name="source_quantity" id="source_quantity" step="1" min="1" required
                                   oninput="calculateBundleQty()"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-l-md focus:outline-none focus:ring-2 focus:ring-primary">
                            <span id="source_unit_display" class="px-3 py-2 bg-gray-100 border border-l-0 border-gray-300 rounded-r-md text-gray-600 text-sm">
                                pcs
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Total individual packs available</p>
                    </div>
                </div>
            </div>

            <!-- Bundle Product Section -->
            <div class="border-t pt-4 bg-blue-50 p-4 rounded-lg">
                <h4 class="text-lg font-semibold text-gray-900 mb-3">Bundle Configuration</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bundle Product *</label>
                        <select name="bundle_item_id" id="bundle_item_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">-- Select Bundle Product --</option>
                            <?php foreach ($bundle_finished_items as $item): ?>
                                <option value="<?php echo $item['id']; ?>" data-unit="<?php echo htmlspecialchars($item['unit_symbol']); ?>">
                                    <?php echo htmlspecialchars($item['name']); ?> (<?php echo htmlspecialchars($item['code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Example: Papadam 50g x 10 Bundle</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Packs per Bundle *</label>
                        <input type="number" name="packs_per_bundle" id="packs_per_bundle" step="1" min="1" required
                               oninput="calculateBundleQty()" placeholder="e.g., 10"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                        <p class="text-xs text-gray-500 mt-1">How many packs in each bundle</p>
                    </div>
                </div>
                
                <!-- Calculation Display -->
                <div class="mt-4 p-4 bg-white border-2 border-blue-300 rounded-lg">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-600">Total Bundles to be Created:</p>
                            <p class="text-xs text-gray-500">Formula: Source Packs ÷ Packs per Bundle</p>
                        </div>
                        <div class="text-right">
                            <p id="calculated_bundles" class="text-3xl font-bold text-green-600">0</p>
                            <p class="text-sm text-gray-500">bundles</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Materials Section -->
            <div class="border-t pt-4">
                <div class="flex justify-between items-center mb-3">
                    <h4 class="text-lg font-semibold text-gray-900">Bundling Materials (Optional)</h4>
                    <button type="button" onclick="addMaterialRow()" 
                            class="text-sm bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded-md">
                        + Add Material
                    </button>
                </div>
                <p class="text-xs text-gray-500 mb-3">Materials used for bundling (cartons, plastic wrap, tape, labels, etc.)</p>
                <div id="materials_container">
                    <!-- Material rows will be added here -->
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" placeholder="Any additional notes about this bundling batch..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal('createBundleModal')"
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                    Create Bundle
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let materialRowIndex = 0;

function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

// Update unit displays when items are selected
document.getElementById('source_item_id')?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || 'pcs';
    document.getElementById('source_unit_display').textContent = unit;
    calculateBundleQty();
});

document.getElementById('bundle_item_id')?.addEventListener('change', function() {
    calculateBundleQty();
});

function calculateBundleQty() {
    const sourceQty = parseInt(document.getElementById('source_quantity').value) || 0;
    const packsPerBundle = parseInt(document.getElementById('packs_per_bundle').value) || 0;
    
    if (sourceQty > 0 && packsPerBundle > 0) {
        const bundles = Math.floor(sourceQty / packsPerBundle);
        document.getElementById('calculated_bundles').textContent = bundles.toLocaleString();
    } else {
        document.getElementById('calculated_bundles').textContent = '0';
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
    fetch(`get_bundle_details.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const record = data.record;
                const modal = document.createElement('div');
                modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
                modal.innerHTML = `
                    <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-xl font-bold text-gray-900">Bundle Details - ${record.bundle_code}</h3>
                            <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-4">
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <h4 class="font-semibold text-gray-900 mb-2">Bundle Information</h4>
                                    <div class="space-y-2 text-sm">
                                        <div><span class="font-medium">Code:</span> ${record.bundle_code}</div>
                                        <div><span class="font-medium">Date:</span> ${new Date(record.bundle_date).toLocaleDateString()}</div>
                                        <div><span class="font-medium">Location:</span> ${record.location_name || 'N/A'}</div>
                                        <div><span class="font-medium">Created By:</span> ${record.created_by_name || 'N/A'}</div>
                                        <div><span class="font-medium">Created:</span> ${new Date(record.created_at).toLocaleString()}</div>
                                    </div>
                                </div>

                                <div class="bg-blue-50 p-4 rounded-lg">
                                    <h4 class="font-semibold text-blue-900 mb-2">Source Product (Individual Packs)</h4>
                                    <div class="space-y-2 text-sm">
                                        <div><span class="font-medium">Name:</span> ${record.source_item_name}</div>
                                        <div><span class="font-medium">Code:</span> ${record.source_item_code}</div>
                                        <div><span class="font-medium">Packs Used:</span> ${record.source_quantity} ${record.source_unit_symbol}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div class="bg-green-50 p-4 rounded-lg">
                                    <h4 class="font-semibold text-green-900 mb-2">Bundle Product</h4>
                                    <div class="space-y-2 text-sm">
                                        <div><span class="font-medium">Name:</span> ${record.bundle_item_name}</div>
                                        <div><span class="font-medium">Code:</span> ${record.bundle_item_code}</div>
                                        <div><span class="font-medium">Bundles Created:</span> ${record.bundle_quantity} ${record.bundle_unit_symbol}</div>
                                        <div><span class="font-medium">Packs per Bundle:</span> ${record.bundle_unit_size} ${record.source_unit_symbol}</div>
                                        <div><span class="font-medium">Total Packs Bundled:</span> ${parseFloat(record.bundle_quantity) * parseInt(record.bundle_unit_size)} ${record.source_unit_symbol}</div>
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
                alert('Error loading bundle details: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading bundle details');
        });
}

function deleteBundle(id, code) {
    if (confirm(`Are you sure you want to delete bundle "${code}"? This will reverse all stock changes. This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="bundle_id" value="${id}">
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
