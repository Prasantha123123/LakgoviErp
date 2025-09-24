<?php
// production.php - Production management
include 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Initialize variables
    $success = null;
    $error = null;
    $transaction_started = false;
    
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    // Validate input
                    if (empty($_POST['batch_no']) || empty($_POST['item_id']) || empty($_POST['planned_qty'])) {
                        throw new Exception("All fields are required for production creation");
                    }
                    
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    $stmt = $db->prepare("INSERT INTO production (batch_no, item_id, location_id, planned_qty, production_date, status) VALUES (?, ?, ?, ?, ?, 'planned')");
                    $stmt->execute([$_POST['batch_no'], $_POST['item_id'], $_POST['location_id'], $_POST['planned_qty'], $_POST['production_date']]);
                    
                    $db->commit();
                    $transaction_started = false;
                    $success = "Production order created successfully!";
                    break;
                    
                case 'start':
                    // Handle starting production (planned -> in_progress)
                    if (empty($_POST['id'])) {
                        throw new Exception("Production ID is required to start production");
                    }
                    
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    // Check if production exists and is in planned status
                    $stmt = $db->prepare("SELECT id, batch_no, status FROM production WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $production = $stmt->fetch();
                    
                    if (!$production) {
                        throw new Exception("Production record not found");
                    }
                    
                    if ($production['status'] !== 'planned') {
                        throw new Exception("Production can only be started if it's in 'planned' status");
                    }
                    
                    // Update status to in_progress
                    $stmt = $db->prepare("UPDATE production SET status = 'in_progress' WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    
                    $db->commit();
                    $transaction_started = false;
                    $success = "Production batch {$production['batch_no']} started successfully!";
                    break;
                    
                case 'complete':
                    // Validate input first (before starting transaction)
                    if (empty($_POST['id']) || empty($_POST['actual_qty'])) {
                        throw new Exception("Production ID and actual quantity are required");
                    }
                    
                    $production_id = $_POST['id'];
                    $actual_qty = floatval($_POST['actual_qty']);
                    
                    if ($actual_qty <= 0) {
                        throw new Exception("Actual quantity must be greater than 0");
                    }
                    
                    // Start transaction
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    // Get production details
                    $stmt = $db->prepare("
                        SELECT p.*, i.name as item_name, l.name as location_name 
                        FROM production p 
                        JOIN items i ON p.item_id = i.id 
                        JOIN locations l ON p.location_id = l.id 
                        WHERE p.id = ? AND p.status = 'in_progress'
                    ");
                    $stmt->execute([$production_id]);
                    $production = $stmt->fetch();
                    
                    if (!$production) {
                        throw new Exception("Production record not found or not in progress. Please start the production first.");
                    }
                    
                    // Get BOM items for raw material consumption
                    $stmt = $db->prepare("
                        SELECT b.*, i.name as raw_material_name
                        FROM bom b
                        JOIN items i ON b.raw_material_id = i.id
                        WHERE b.item_id = ?
                    ");
                    $stmt->execute([$production['item_id']]);
                    $bom_items = $stmt->fetchAll();
                    
                    if (empty($bom_items)) {
                        throw new Exception("No BOM (Bill of Materials) found for this item. Please create a BOM first.");
                    }
                    
                    // Check raw material availability
                    foreach ($bom_items as $bom_item) {
                        $required_qty = $bom_item['quantity'] * $actual_qty;
                        
                        // Get current stock balance
                        $stmt = $db->prepare("
                            SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_stock
                            FROM stock_ledger 
                            WHERE item_id = ? AND location_id = ?
                        ");
                        $stmt->execute([$bom_item['raw_material_id'], $production['location_id']]);
                        $result = $stmt->fetch();
                        $current_stock = $result ? floatval($result['current_stock']) : 0;
                        
                        if ($current_stock < $required_qty) {
                            throw new Exception("Insufficient raw material: {$bom_item['raw_material_name']}. Required: {$required_qty}, Available: {$current_stock}");
                        }
                    }
                    
                    // Consume raw materials
                    foreach ($bom_items as $bom_item) {
                        $required_qty = $bom_item['quantity'] * $actual_qty;
                        
                        // Get current balance BEFORE inserting
                        $stmt = $db->prepare("
                            SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                            FROM stock_ledger 
                            WHERE item_id = ? AND location_id = ?
                        ");
                        $stmt->execute([$bom_item['raw_material_id'], $production['location_id']]);
                        $result = $stmt->fetch();
                        $current_balance = $result ? floatval($result['current_balance']) : 0;
                        $new_balance = $current_balance - $required_qty;
                        
                        // Insert stock ledger entry for raw material consumption
                        $stmt = $db->prepare("
                            INSERT INTO stock_ledger (item_id, location_id, transaction_type, reference_id, reference_no, transaction_date, quantity_out, balance, created_at)
                            VALUES (?, ?, 'production_out', ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $bom_item['raw_material_id'], 
                            $production['location_id'], 
                            $production_id, 
                            $production['batch_no'], 
                            $production['production_date'], 
                            $required_qty, 
                            $new_balance
                        ]);
                        
                        // Update item current stock
                        $stmt = $db->prepare("UPDATE items SET current_stock = current_stock - ? WHERE id = ?");
                        $stmt->execute([$required_qty, $bom_item['raw_material_id']]);
                    }
                    
                    // Add finished goods to stock
                    // Get current balance for finished goods
                    $stmt = $db->prepare("
                        SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                        FROM stock_ledger 
                        WHERE item_id = ? AND location_id = ?
                    ");
                    $stmt->execute([$production['item_id'], $production['location_id']]);
                    $result = $stmt->fetch();
                    $current_balance = $result ? floatval($result['current_balance']) : 0;
                    $new_balance = $current_balance + $actual_qty;
                    
                    // Record finished goods in stock ledger
                    $stmt = $db->prepare("
                        INSERT INTO stock_ledger (item_id, location_id, transaction_type, reference_id, reference_no, transaction_date, quantity_in, balance, created_at)
                        VALUES (?, ?, 'production_in', ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $production['item_id'], 
                        $production['location_id'], 
                        $production_id, 
                        $production['batch_no'], 
                        $production['production_date'], 
                        $actual_qty, 
                        $new_balance
                    ]);
                    
                    // Update finished goods current stock
                    $stmt = $db->prepare("UPDATE items SET current_stock = current_stock + ? WHERE id = ?");
                    $stmt->execute([$actual_qty, $production['item_id']]);
                    
                    // Update production record
                    $stmt = $db->prepare("UPDATE production SET actual_qty = ?, status = 'completed' WHERE id = ?");
                    $stmt->execute([$actual_qty, $production_id]);
                    
                    $db->commit();
                    $transaction_started = false;
                    $success = "Production completed successfully! Consumed raw materials and added {$actual_qty} units of {$production['item_name']} to stock.";
                    break;
                    
                case 'delete':
                    if (empty($_POST['id'])) {
                        throw new Exception("Production ID is required for deletion");
                    }
                    
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    // Check if production is not completed before deleting
                    $stmt = $db->prepare("SELECT status FROM production WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $status = $stmt->fetchColumn();
                    
                    if ($status === 'completed') {
                        throw new Exception("Cannot delete completed production orders");
                    }
                    
                    $stmt = $db->prepare("DELETE FROM production WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    
                    $db->commit();
                    $transaction_started = false;
                    $success = "Production order deleted successfully!";
                    break;
                    
                default:
                    throw new Exception("Invalid action specified: " . $_POST['action']);
            }
        }
        
    } catch(PDOException $e) {
        // Rollback only if transaction was started
        if ($transaction_started && $db->inTransaction()) {
            $db->rollback();
        }
        $error = "Database error: " . $e->getMessage();
        error_log("Production DB Error: " . $e->getMessage());
        
    } catch(Exception $e) {
        // Rollback only if transaction was started
        if ($transaction_started && $db->inTransaction()) {
            $db->rollback();
        }
        $error = $e->getMessage();
        error_log("Production Error: " . $e->getMessage());
    }
}

// Fetch productions with item details
try {
    $stmt = $db->query("
        SELECT p.*, i.name as item_name, i.code as item_code, u.symbol as unit_symbol,
               l.name as location_name
        FROM production p
        JOIN items i ON p.item_id = i.id
        JOIN units u ON i.unit_id = u.id
        JOIN locations l ON p.location_id = l.id
        ORDER BY p.production_date DESC, p.id DESC
    ");
    $productions = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching productions: " . $e->getMessage();
}

// Fetch items for dropdown (semi-finished and finished)
try {
    $stmt = $db->query("SELECT i.*, u.symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.type IN ('semi_finished', 'finished') ORDER BY i.name");
    $items = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

// Fetch locations for dropdown
try {
    $stmt = $db->query("SELECT * FROM locations WHERE type = 'production' ORDER BY name");
    $locations = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching locations: " . $e->getMessage();
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Production Management</h1>
            <p class="text-gray-600">Plan and track production batches</p>
        </div>
        <button onclick="openModal('createProductionModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            Create Production Batch
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

    <!-- Production Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Planned</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p) => $p['status'] === 'planned')); ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">In Progress</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p) => $p['status'] === 'in_progress')); ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Completed</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p) => $p['status'] === 'completed')); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Production Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch No</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Planned Qty</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actual Qty</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($productions as $production): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($production['batch_no']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <div>
                            <div class="font-medium"><?php echo htmlspecialchars($production['item_name']); ?></div>
                            <div class="text-gray-500"><?php echo htmlspecialchars($production['item_code']); ?></div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($production['location_name']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('M d, Y', strtotime($production['production_date'])); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                        <?php echo number_format($production['planned_qty'], 3); ?> <?php echo $production['unit_symbol']; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                        <?php echo $production['actual_qty'] ? number_format($production['actual_qty'], 3) . ' ' . $production['unit_symbol'] : '-'; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="<?php 
                            echo $production['status'] === 'completed' ? 'bg-green-100 text-green-800' : 
                                ($production['status'] === 'in_progress' ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800'); 
                        ?> text-xs font-medium px-2.5 py-0.5 rounded capitalize">
                            <?php echo str_replace('_', ' ', $production['status']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <?php if ($production['status'] === 'planned'): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="start">
                                <input type="hidden" name="id" value="<?php echo $production['id']; ?>">
                                <button type="submit" class="text-blue-600 hover:text-blue-900">Start</button>
                            </form>
                        <?php endif; ?>
                        
                        <?php if ($production['status'] === 'in_progress'): ?>
                            <button onclick="completeProduction(<?php echo htmlspecialchars(json_encode($production)); ?>)" class="text-green-600 hover:text-green-900">Complete</button>
                        <?php endif; ?>
                        
                        <?php if ($production['status'] === 'planned'): ?>
                            <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this production batch?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $production['id']; ?>">
                                <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($productions)): ?>
                <tr>
                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">No production batches found. Create your first batch!</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Production Modal -->
<div id="createProductionModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Create Production Batch</h3>
            <button onclick="closeModal('createProductionModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Batch Number</label>
                <input type="text" name="batch_no" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="BATCH001" value="BATCH<?php echo str_pad(count($productions) + 1, 3, '0', STR_PAD_LEFT); ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Item to Produce</label>
                <select name="item_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Item</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Production Location</label>
                <select name="location_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Location</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo $location['id']; ?>"><?php echo htmlspecialchars($location['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Planned Quantity</label>
                <input type="number" name="planned_qty" step="0.001" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="0.000">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Production Date</label>
                <input type="date" name="production_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createProductionModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Create Batch</button>
            </div>
        </form>
    </div>
</div>

<!-- Complete Production Modal -->
<div id="completeProductionModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Complete Production</h3>
            <button onclick="closeModal('completeProductionModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="id" id="complete_production_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Batch Number</label>
                <input type="text" id="complete_batch_no" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Item</label>
                <input type="text" id="complete_item_name" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Planned Quantity</label>
                <input type="text" id="complete_planned_qty" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Actual Quantity Produced</label>
                <input type="number" name="actual_qty" step="0.001" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="0.000">
            </div>
            <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3">
                <p class="text-sm text-yellow-800">
                    <strong>Note:</strong> Completing production will consume raw materials according to BOM and add finished goods to stock.
                </p>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('completeProductionModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Complete Production</button>
            </div>
        </form>
    </div>
</div>

<script>
function completeProduction(production) {
    document.getElementById('complete_production_id').value = production.id;
    document.getElementById('complete_batch_no').value = production.batch_no;
    document.getElementById('complete_item_name').value = production.item_name + ' (' + production.item_code + ')';
    document.getElementById('complete_planned_qty').value = production.planned_qty + ' ' + production.unit_symbol;
    openModal('completeProductionModal');
}
</script>

<?php include 'footer.php'; ?>