<?php
// rolls.php - Rolls Production Module
require_once 'database.php';
require_once 'config/simple_auth.php';

// Initialize database and auth
$database = new Database();
$db = $database->getConnection();
$auth = new SimpleAuth($db);
$auth->requireAuth();

// Handle form submissions BEFORE any output
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'start_batch':
                    $db->beginTransaction();
                    
                    // Validate rolls item selection
                    if (empty($_POST['rolls_item_id'])) {
                        throw new Exception("Please select a rolls finished good");
                    }
                    
                    // Verify rolls item exists and is a finished good
                    $stmt_rolls_item = $db->prepare("SELECT id, name, type FROM items WHERE id = ?");
                    $stmt_rolls_item->execute([$_POST['rolls_item_id']]);
                    $rolls_item = $stmt_rolls_item->fetch();
                    
                    if (!$rolls_item) {
                        throw new Exception("Selected rolls item not found");
                    }
                    
                    if ($rolls_item['type'] !== 'finished') {
                        throw new Exception("Rolls item must be a finished good");
                    }
                    
                    // Generate next batch code
                    $stmt = $db->query("SELECT batch_code FROM rolls_batches ORDER BY id DESC LIMIT 1");
                    $last_batch = $stmt->fetch();
                    if ($last_batch) {
                        $last_number = intval(substr($last_batch['batch_code'], 2));
                        $next_code = 'RB' . str_pad($last_number + 1, 6, '0', STR_PAD_LEFT);
                    } else {
                        $next_code = 'RB000001';
                    }
                    
                    // Validate and collect materials
                    $materials = [];
                    if (!empty($_POST['material_items']) && is_array($_POST['material_items'])) {
                        foreach ($_POST['material_items'] as $index => $item_id) {
                            if (!empty($item_id) && !empty($_POST['material_quantities'][$index])) {
                                $qty = floatval($_POST['material_quantities'][$index]);
                                if ($qty <= 0) {
                                    throw new Exception("Material quantity must be greater than zero");
                                }
                                
                                // Get item details including unit_id
                                $stmt_item = $db->prepare("SELECT unit_id, name FROM items WHERE id = ?");
                                $stmt_item->execute([$item_id]);
                                $item_info = $stmt_item->fetch();
                                
                                if (!$item_info) {
                                    throw new Exception("Item not found");
                                }
                                
                                // Check available stock
                                $stmt_stock = $db->prepare("
                                    SELECT COALESCE(balance, 0) as available_stock
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ? 
                                    ORDER BY id DESC LIMIT 1
                                ");
                                $stmt_stock->execute([$item_id, $_POST['location_id']]);
                                $stock = $stmt_stock->fetch();
                                
                                $available = $stock ? floatval($stock['available_stock']) : 0;
                                if ($qty > $available) {
                                    throw new Exception("Insufficient stock for '{$item_info['name']}'! Available: {$available}, Requested: {$qty}");
                                }
                                
                                $materials[] = [
                                    'item_id' => $item_id,
                                    'quantity' => $qty,
                                    'unit_id' => $item_info['unit_id']
                                ];
                            }
                        }
                    }
                    
                    if (empty($materials)) {
                        throw new Exception("Please select at least one material");
                    }
                    
                    // Insert batch with 'started' status
                    $stmt = $db->prepare("
                        INSERT INTO rolls_batches (
                            batch_code, batch_date, rolls_item_id, status, started_at, location_id, notes, created_by
                        ) VALUES (?, ?, ?, 'started', NOW(), ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $next_code,
                        $_POST['batch_date'],
                        $_POST['rolls_item_id'],
                        $_POST['location_id'],
                        $_POST['notes'] ?? null,
                        $_SESSION['user_id']
                    ]);
                    
                    $batch_id = $db->lastInsertId();
                    
                    // Insert materials
                    $stmt_material = $db->prepare("
                        INSERT INTO rolls_materials (batch_id, item_id, quantity_used, unit_id)
                        VALUES (?, ?, ?, ?)
                    ");
                    
                    foreach ($materials as $material) {
                        $stmt_material->execute([
                            $batch_id,
                            $material['item_id'],
                            $material['quantity'],
                            $material['unit_id']
                        ]);
                        
                        // Update stock - reduce material quantity (rolls_out)
                        $stmt_ledger = $db->prepare("
                            INSERT INTO stock_ledger 
                            (item_id, location_id, transaction_type, reference_id, reference_no, 
                             transaction_date, quantity_in, quantity_out, balance)
                            SELECT ?, ?, 'rolls_out', ?, ?, NOW(), 0, ?, 
                                   COALESCE((SELECT balance FROM stock_ledger 
                                            WHERE item_id = ? AND location_id = ? 
                                            ORDER BY id DESC LIMIT 1), 0) - ?
                        ");
                        
                        $stmt_ledger->execute([
                            $material['item_id'],
                            $_POST['location_id'],
                            $batch_id,
                            $next_code,
                            $material['quantity'],
                            $material['item_id'],
                            $_POST['location_id'],
                            $material['quantity']
                        ]);
                    }
                    
                    $db->commit();
                    $_SESSION['success_message'] = "Rolls production started! Code: {$next_code}";
                    header("Location: rolls.php");
                    exit();
                    
                case 'complete_batch':
                    $db->beginTransaction();
                    
                    // Validate rolls quantity
                    $rolls_qty = intval($_POST['rolls_quantity']);
                    if ($rolls_qty <= 0) {
                        throw new Exception("Rolls quantity must be greater than zero");
                    }
                    
                    // Get batch details
                    $stmt = $db->prepare("SELECT * FROM rolls_batches WHERE id = ?");
                    $stmt->execute([$_POST['batch_id']]);
                    $batch = $stmt->fetch();
                    
                    if (!$batch) {
                        throw new Exception("Batch not found");
                    }
                    
                    if ($batch['status'] !== 'started') {
                        throw new Exception("Only 'started' batches can be completed");
                    }
                    
                    // Update batch status to completed
                    $stmt = $db->prepare("
                        UPDATE rolls_batches 
                        SET status = 'completed', rolls_quantity = ?, completed_at = NOW()
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([$rolls_qty, $_POST['batch_id']]);
                    
                    // Create a new "rolls" finished item if needed - for now we'll track in ledger
                    // In future, you might want to create automatic SKU for rolls
                    
                    $db->commit();
                    $_SESSION['success_message'] = "Rolls production completed! {$rolls_qty} rolls created.";
                    header("Location: rolls.php");
                    exit();
                    
                case 'delete_batch':
                    $db->beginTransaction();
                    
                    // Get batch details
                    $stmt = $db->prepare("SELECT * FROM rolls_batches WHERE id = ?");
                    $stmt->execute([$_POST['batch_id']]);
                    $batch = $stmt->fetch();
                    
                    if (!$batch) {
                        throw new Exception("Batch not found");
                    }
                    
                    if ($batch['status'] !== 'pending') {
                        throw new Exception("Can only delete pending batches");
                    }
                    
                    // Delete stock ledger entries
                    $stmt = $db->prepare("DELETE FROM stock_ledger WHERE transaction_type IN ('rolls_in', 'rolls_out') AND reference_id = ?");
                    $stmt->execute([$_POST['batch_id']]);
                    
                    // Delete materials and batch
                    $stmt = $db->prepare("DELETE FROM rolls_batches WHERE id = ?");
                    $stmt->execute([$_POST['batch_id']]);
                    
                    $db->commit();
                    $_SESSION['success_message'] = "Batch deleted successfully!";
                    header("Location: rolls.php");
                    exit();
            }
        }
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header("Location: rolls.php");
        exit();
    }
}

// Include header after POST processing
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

// Fetch all rolls batches
try {
    $stmt = $db->query("SELECT * FROM v_rolls_details ORDER BY batch_date DESC, created_at DESC");
    $batches = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching batches: " . $e->getMessage();
    $batches = [];
}

// Fetch finished items for materials selection (show all with stock info)
try {
    $stmt = $db->query("
        SELECT i.id, i.code, i.name, u.symbol AS unit_symbol,
               COALESCE((
                   SELECT balance FROM stock_ledger 
                   WHERE item_id = i.id AND location_id = (SELECT id FROM locations WHERE name = 'Production Floor')
                   ORDER BY id DESC LIMIT 1
               ), 0) as available_qty
        FROM items i
        JOIN units u ON u.id = i.unit_id
        WHERE i.type = 'finished'
        ORDER BY i.name
    ");
    $finished_items = $stmt->fetchAll();
} catch(PDOException $e) {
    $finished_items = [];
}

// Fetch raw materials with stock for materials
try {
    $stmt = $db->query("
        SELECT i.id, i.code, i.name, u.symbol AS unit_symbol,
               COALESCE((
                   SELECT balance FROM stock_ledger 
                   WHERE item_id = i.id AND location_id = (SELECT id FROM locations WHERE name = 'Production Floor')
                   ORDER BY id DESC LIMIT 1
               ), 0) as available_qty
        FROM items i
        JOIN units u ON u.id = i.unit_id
        WHERE i.type = 'raw'
        ORDER BY i.name
    ");
    $raw_items = $stmt->fetchAll();
} catch(PDOException $e) {
    $raw_items = [];
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
            <h1 class="text-3xl font-bold text-gray-900">Rolls Production</h1>
            <p class="text-gray-600">Manage rolls production batches</p>
        </div>
        <button onclick="openModal('startRollsModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Start Rolls Production
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
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 class="font-semibold text-blue-900 mb-2">Rolls Production Workflow:</h3>
        <ol class="list-decimal list-inside text-blue-800 space-y-1 text-sm">
            <li><strong>Select Rolls Finished Good:</strong> Choose the finished good that will be produced as rolls</li>
            <li><strong>Select Materials Used:</strong> Choose raw materials or finished goods consumed to make rolls</li>
            <li><strong>Start Production:</strong> Begin rolls production (status changes to "Started")</li>
            <li><strong>Complete:</strong> When done, enter total rolls produced</li>
            <li><strong>Record:</strong> System saves materials used, quantities, and final rolls count</li>
        </ol>
    </div>

    <!-- Rolls Batches Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Production History</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rolls Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Materials</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rolls Produced</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Started</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completed</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($batches)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-4 text-center text-gray-500">
                                No rolls batches found. Click "Start Rolls Production" to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($batches as $batch): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($batch['batch_code']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('d M Y', strtotime($batch['batch_date'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($batch['rolls_item_name'] ?? '-'); ?></div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($batch['rolls_item_code'] ?? '-'); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-sm font-semibold rounded-full 
                                    <?php 
                                    if ($batch['status'] == 'pending') echo 'bg-yellow-100 text-yellow-800';
                                    elseif ($batch['status'] == 'started') echo 'bg-blue-100 text-blue-800';
                                    elseif ($batch['status'] == 'completed') echo 'bg-green-100 text-green-800';
                                    ?>">
                                    <?php echo ucfirst($batch['status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo $batch['total_materials_used']; ?> items
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-sm font-semibold rounded-full bg-green-100 text-green-800">
                                    <?php echo $batch['rolls_quantity']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo $batch['started_at'] ? date('d M H:i', strtotime($batch['started_at'])) : '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo $batch['completed_at'] ? date('d M H:i', strtotime($batch['completed_at'])) : '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($batch['location_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                <button onclick="viewBatchDetails(<?php echo $batch['id']; ?>)" 
                                        class="text-indigo-600 hover:text-indigo-900">View</button>
                                <?php if ($batch['status'] == 'started'): ?>
                                    <button onclick="completeBatchModal(<?php echo $batch['id']; ?>)" 
                                            class="text-green-600 hover:text-green-900">Complete</button>
                                <?php endif; ?>
                                <?php if ($batch['status'] == 'pending'): ?>
                                    <button onclick="deleteBatch(<?php echo $batch['id']; ?>, '<?php echo htmlspecialchars($batch['batch_code']); ?>')" 
                                            class="text-red-600 hover:text-red-900">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Start Rolls Production Modal -->
<div id="startRollsModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-4xl shadow-lg rounded-md bg-white mb-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Start Rolls Production</h3>
            <button onclick="closeModal('startRollsModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <form method="POST" class="space-y-6">
            <input type="hidden" name="action" value="start_batch">
            
            <!-- Basic Information -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Production Date *</label>
                    <input type="date" name="batch_date" value="<?php echo date('Y-m-d'); ?>" required
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

            <!-- Rolls Finished Good Selection -->
            <div class="border-t pt-4">
                <h4 class="text-lg font-semibold text-gray-900 mb-3">Rolls Finished Good *</h4>
                <p class="text-xs text-gray-500 mb-3">Select the finished good that will be produced as rolls</p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Finished Good *</label>
                    <select name="rolls_item_id" id="rolls_item_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="">-- Select Item --</option>
                        <?php foreach ($finished_items as $item): ?>
                            <option value="<?php echo $item['id']; ?>" data-name="<?php echo htmlspecialchars($item['name']); ?>">
                                <?php echo htmlspecialchars($item['code'] . ' - ' . $item['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Materials Section -->
            <div class="border-t pt-4">
                <div class="flex justify-between items-center mb-3">
                    <h4 class="text-lg font-semibold text-gray-900">Materials Used</h4>
                    <button type="button" onclick="addMaterialRow()" 
                            class="text-sm bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded-md">
                        + Add Material
                    </button>
                </div>
                <p class="text-xs text-gray-500 mb-3">Select raw materials or finished goods that will be consumed to make rolls</p>
                <div id="materials_container">
                    <div class="grid grid-cols-12 gap-2 mb-2" id="material_row_1">
                        <!-- Material Type Selection -->
                        <div class="col-span-3">
                            <select name="material_types[]" class="material_type w-full px-3 py-2 border border-gray-300 rounded-md text-sm" onchange="updateItemSelect(1)">
                                <option value="">-- Type --</option>
                                <option value="raw">Raw Material</option>
                                <option value="finished">Finished Good</option>
                            </select>
                        </div>
                        <!-- Material Item Selection -->
                        <div class="col-span-4">
                            <select name="material_items[]" class="material_item w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                                <option value="">-- Select Item --</option>
                            </select>
                        </div>
                        <!-- Quantity -->
                        <div class="col-span-4">
                            <input type="number" name="material_quantities[]" step="0.001" min="0" placeholder="Quantity"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        </div>
                        <!-- Remove Button -->
                        <div class="col-span-1">
                            <button type="button" onclick="removeMaterialRow(1)" 
                                    class="w-full px-2 py-2 bg-red-100 hover:bg-red-200 text-red-600 rounded-md">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" placeholder="Any notes about this production batch..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal('startRollsModal')"
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                    Start Production
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Complete Batch Modal -->
<div id="completeBatchModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white mb-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Complete Rolls Production</h3>
            <button onclick="closeModal('completeBatchModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="complete_batch">
            <input type="hidden" name="batch_id" id="complete_batch_id" value="">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">How many rolls were produced? *</label>
                <input type="number" name="rolls_quantity" id="rolls_quantity" step="1" min="1" required placeholder="Enter quantity"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary text-lg">
                <p class="text-xs text-gray-500 mt-1">Total number of rolls created in this batch</p>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal('completeBatchModal')"
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                    Save & Complete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let materialRowIndex = 1;

// Data for items with available quantities
const rawItems = <?php echo json_encode(array_map(function($item) {
    return ['id' => $item['id'], 'name' => $item['name'], 'code' => $item['code'], 'unit' => $item['unit_symbol'], 'available' => floatval($item['available_qty'])];
}, $raw_items)); ?>;

const finishedItems = <?php echo json_encode(array_map(function($item) {
    return ['id' => $item['id'], 'name' => $item['name'], 'code' => $item['code'], 'unit' => $item['unit_symbol'], 'available' => floatval($item['available_qty'])];
}, $finished_items)); ?>;

function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

function updateItemSelect(rowIndex) {
    const typeSelect = document.querySelector(`#material_row_${rowIndex} .material_type`);
    const itemSelect = document.querySelector(`#material_row_${rowIndex} .material_item`);
    
    if (!typeSelect || !itemSelect) return;
    
    const selectedType = typeSelect.value;
    itemSelect.innerHTML = '<option value="">-- Select Item --</option>';
    
    const items = selectedType === 'raw' ? rawItems : finishedItems;
    
    items.forEach(item => {
        const option = document.createElement('option');
        option.value = item.id;
        const availText = item.available > 0 ? ` [Available: ${item.available.toFixed(3)} ${item.unit}]` : ' [Out of Stock]';
        const stockColor = item.available > 0 ? 'color: green;' : 'color: red;';
        option.textContent = `${item.name} (${item.code})${availText}`;
        option.style.cssText = stockColor;
        itemSelect.appendChild(option);
    });
}

function addMaterialRow() {
    materialRowIndex++;
    const container = document.getElementById('materials_container');
    const row = document.createElement('div');
    row.className = 'grid grid-cols-12 gap-2 mb-2';
    row.id = `material_row_${materialRowIndex}`;
    row.innerHTML = `
        <!-- Material Type Selection -->
        <div class="col-span-3">
            <select name="material_types[]" class="material_type w-full px-3 py-2 border border-gray-300 rounded-md text-sm" onchange="updateItemSelect(${materialRowIndex})">
                <option value="">-- Type --</option>
                <option value="raw">Raw Material</option>
                <option value="finished">Finished Good</option>
            </select>
        </div>
        <!-- Material Item Selection -->
        <div class="col-span-4">
            <select name="material_items[]" class="material_item w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">-- Select Item --</option>
            </select>
        </div>
        <!-- Quantity -->
        <div class="col-span-4">
            <input type="number" name="material_quantities[]" step="0.001" min="0" placeholder="Quantity"
                   class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <!-- Remove Button -->
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

function completeBatchModal(batchId) {
    document.getElementById('complete_batch_id').value = batchId;
    document.getElementById('rolls_quantity').value = '';
    openModal('completeBatchModal');
}

function viewBatchDetails(batchId) {
    // TODO: Implement detailed view
    alert('View batch details for ID: ' + batchId);
}

function deleteBatch(id, code) {
    if (confirm(`Are you sure you want to delete batch "${code}"? This cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_batch">
            <input type="hidden" name="batch_id" value="${id}">
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
