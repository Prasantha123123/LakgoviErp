<?php
// bom.php - Bill of Materials management
include 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    // Check if BOM entry already exists
                    $stmt = $db->prepare("SELECT id FROM bom WHERE item_id = ? AND raw_material_id = ?");
                    $stmt->execute([$_POST['item_id'], $_POST['raw_material_id']]);
                    if ($stmt->fetch()) {
                        throw new Exception("BOM entry already exists for this item and raw material combination.");
                    }
                    
                    $stmt = $db->prepare("INSERT INTO bom (item_id, raw_material_id, quantity) VALUES (?, ?, ?)");
                    $stmt->execute([$_POST['item_id'], $_POST['raw_material_id'], $_POST['quantity']]);
                    $success = "BOM entry created successfully!";
                    break;
                    
                case 'update':
                    $stmt = $db->prepare("UPDATE bom SET quantity = ? WHERE id = ?");
                    $stmt->execute([$_POST['quantity'], $_POST['id']]);
                    $success = "BOM entry updated successfully!";
                    break;
                    
                case 'delete':
                    $stmt = $db->prepare("DELETE FROM bom WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $success = "BOM entry deleted successfully!";
                    break;
                    
                case 'copy_bom':
                    $db->beginTransaction();
                    
                    // Get BOM entries from source item
                    $stmt = $db->prepare("
                        SELECT raw_material_id, quantity 
                        FROM bom 
                        WHERE item_id = ?
                    ");
                    $stmt->execute([$_POST['source_item_id']]);
                    $source_bom = $stmt->fetchAll();
                    
                    if (empty($source_bom)) {
                        throw new Exception("Source item has no BOM entries to copy.");
                    }
                    
                    // Copy to target item
                    $copied = 0;
                    foreach ($source_bom as $bom_entry) {
                        // Check if entry already exists
                        $stmt = $db->prepare("SELECT id FROM bom WHERE item_id = ? AND raw_material_id = ?");
                        $stmt->execute([$_POST['target_item_id'], $bom_entry['raw_material_id']]);
                        
                        if (!$stmt->fetch()) {
                            $stmt = $db->prepare("INSERT INTO bom (item_id, raw_material_id, quantity) VALUES (?, ?, ?)");
                            $stmt->execute([$_POST['target_item_id'], $bom_entry['raw_material_id'], $bom_entry['quantity']]);
                            $copied++;
                        }
                    }
                    
                    $db->commit();
                    $success = "BOM copied successfully! $copied entries copied.";
                    break;
                    
                case 'calculate_cost':
                    // Calculate total raw material cost for finished item
                    $stmt = $db->prepare("
                        SELECT b.quantity, i.name as raw_material_name, 
                               COALESCE(AVG(gi.rate), 0) as avg_rate
                        FROM bom b
                        JOIN items i ON b.raw_material_id = i.id
                        LEFT JOIN grn_items gi ON b.raw_material_id = gi.item_id
                        WHERE b.item_id = ?
                        GROUP BY b.id, b.quantity, i.name
                    ");
                    $stmt->execute([$_POST['item_id']]);
                    $cost_breakdown = $stmt->fetchAll();
                    
                    $total_cost = 0;
                    foreach ($cost_breakdown as $item) {
                        $total_cost += $item['quantity'] * $item['avg_rate'];
                    }
                    
                    $success = "Cost calculated: රු." . number_format($total_cost, 2);
                    break;
            }
        }
    } catch(PDOException $e) {
        $error = "Error: " . $e->getMessage();
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch BOMs with item details
try {
    $stmt = $db->query("
        SELECT b.*, 
               fi.name as finished_item_name, fi.code as finished_item_code, fu.symbol as finished_unit,
               rm.name as raw_material_name, rm.code as raw_material_code, ru.symbol as raw_unit,
               rm.current_stock as raw_material_stock
        FROM bom b
        JOIN items fi ON b.item_id = fi.id
        JOIN units fu ON fi.unit_id = fu.id
        JOIN items rm ON b.raw_material_id = rm.id
        JOIN units ru ON rm.unit_id = ru.id
        ORDER BY fi.name, rm.name
    ");
    $boms = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching BOMs: " . $e->getMessage();
}

// Fetch finished items for dropdown (semi-finished and finished)
try {
    $stmt = $db->query("SELECT i.*, u.symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.type IN ('semi_finished', 'finished') ORDER BY i.name");
    $finished_items = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching finished items: " . $e->getMessage();
}

// Fetch raw materials for dropdown
try {
    $stmt = $db->query("SELECT i.*, u.symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.type = 'raw' ORDER BY i.name");
    $raw_materials = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching raw materials: " . $e->getMessage();
}

// Group BOMs by finished item
$grouped_boms = [];
foreach ($boms as $bom) {
    $key = $bom['item_id'];
    if (!isset($grouped_boms[$key])) {
        $grouped_boms[$key] = [
            'item_id' => $bom['item_id'],
            'item_name' => $bom['finished_item_name'],
            'item_code' => $bom['finished_item_code'],
            'item_unit' => $bom['finished_unit'],
            'materials' => [],
            'total_materials' => 0,
            'can_produce' => true
        ];
    }
    $grouped_boms[$key]['materials'][] = $bom;
    $grouped_boms[$key]['total_materials']++;
    
    // Check if we can produce (sufficient raw materials)
    if ($bom['raw_material_stock'] < $bom['quantity']) {
        $grouped_boms[$key]['can_produce'] = false;
    }
}

// Get BOM statistics
$bom_stats = [
    'total_boms' => count($grouped_boms),
    'total_entries' => count($boms),
    'can_produce' => count(array_filter($grouped_boms, fn($bom) => $bom['can_produce'])),
    'cannot_produce' => count(array_filter($grouped_boms, fn($bom) => !$bom['can_produce']))
];
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Bill of Materials (BOM)</h1>
            <p class="text-gray-600">Manage recipes and material requirements for production</p>
        </div>
        <div class="flex space-x-2">
            <button onclick="openModal('copyBomModal')" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors">
                Copy BOM
            </button>
            <!-- NEW BUTTON: BOM Entry -->
            <button onclick="openModal('createBomModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                BOM Entry
            </button>
            <!-- Existing (renamed earlier) button -->
            <button onclick="openModal('createBomModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                Add Peetu Entry
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

    <!-- BOM Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Total BOMs</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $bom_stats['total_boms']; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Total Entries</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $bom_stats['total_entries']; ?></p>
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
                    <p class="text-sm font-medium text-gray-600">Can Produce</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $bom_stats['can_produce']; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-red-100">
                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Material Shortage</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $bom_stats['cannot_produce']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- BOM Cards -->
    <?php if (!empty($grouped_boms)): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <?php foreach ($grouped_boms as $item_key => $item_bom): ?>
            <div class="bg-white rounded-lg shadow hover:shadow-md transition-shadow <?php echo !$item_bom['can_produce'] ? 'ring-2 ring-red-200' : ''; ?>">
                <div class="p-6">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex-1">
                            <div class="flex items-center space-x-2">
                                <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($item_bom['item_name']); ?></h3>
                                <?php if (!$item_bom['can_produce']): ?>
                                    <span class="bg-red-100 text-red-800 text-xs font-medium px-2 py-1 rounded">
                                        Material Shortage
                                    </span>
                                <?php else: ?>
                                    <span class="bg-green-100 text-green-800 text-xs font-medium px-2 py-1 rounded">
                                        Ready to Produce
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($item_bom['item_code']); ?></p>
                            <span class="inline-block mt-1 bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                Per <?php echo $item_bom['item_unit']; ?>
                            </span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button onclick="calculateItemCost(<?php echo $item_bom['item_id']; ?>)" class="text-purple-600 hover:text-purple-900 text-sm" title="Calculate Cost">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            </button>
                            <button onclick="addBomMaterial(<?php echo $item_bom['item_id']; ?>)" class="text-green-600 hover:text-green-900 text-sm" title="Add Material">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                </svg>
                            </button>
                            <button onclick="viewBomDetails(<?php echo $item_bom['item_id']; ?>)" class="text-blue-600 hover:text-blue-900 text-sm" title="View Details">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <div class="space-y-3">
                        <h4 class="text-sm font-medium text-gray-700">Raw Materials Required:</h4>
                        <?php foreach ($item_bom['materials'] as $material): ?>
                        <div class="flex justify-between items-center p-3 <?php echo $material['raw_material_stock'] < $material['quantity'] ? 'bg-red-50 border border-red-200' : 'bg-gray-50'; ?> rounded-md">
                            <div class="flex-1">
                                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($material['raw_material_name']); ?></p>
                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($material['raw_material_code']); ?></p>
                                <p class="text-xs <?php echo $material['raw_material_stock'] < $material['quantity'] ? 'text-red-600' : 'text-gray-500'; ?>">
                                    Available: <?php echo number_format($material['raw_material_stock'], 3); ?> <?php echo $material['raw_unit']; ?>
                                </p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="text-sm font-medium <?php echo $material['raw_material_stock'] < $material['quantity'] ? 'text-red-900' : 'text-gray-900'; ?>">
                                    <?php echo number_format($material['quantity'], 3); ?> <?php echo $material['raw_unit']; ?>
                                </span>
                                <div class="flex space-x-1">
                                    <button onclick="editBom(<?php echo htmlspecialchars(json_encode($material)); ?>)" class="text-indigo-600 hover:text-indigo-900">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this BOM entry?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $material['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-4 pt-4 border-t">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Total Materials:</span>
                            <span class="font-medium"><?php echo $item_bom['total_materials']; ?> items</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow p-8 text-center">
            <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No BOMs found</h3>
            <p class="text-gray-600 mb-4">Get started by creating your first Bill of Materials.</p>
            <button onclick="openModal('createBomModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                Create BOM
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Create BOM Modal -->
<div id="createBomModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add BOM Entry</h3>
            <button onclick="closeModal('createBomModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Finished Item</label>
                <select name="item_id" id="create_item_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Finished Item</option>
                    <?php foreach ($finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Raw Material</label>
                <select name="raw_material_id" id="create_raw_material_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" onchange="updateMaterialInfo(this)">
                    <option value="">Select Raw Material</option>
                    <?php foreach ($raw_materials as $material): ?>
                        <option value="<?php echo $material['id']; ?>" data-stock="<?php echo $material['current_stock']; ?>" data-unit="<?php echo $material['symbol']; ?>"><?php echo htmlspecialchars($material['name'] . ' (' . $material['code'] . ') - ' . $material['symbol']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1" id="material_info"></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity Required</label>
                <input type="number" name="quantity" step="0.001" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="0.000">
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createBomModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Add BOM Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit BOM Modal -->
<div id="editBomModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Edit BOM Entry</h3>
            <button onclick="closeModal('editBomModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_bom_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Finished Item</label>
                <input type="text" id="edit_finished_item" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Raw Material</label>
                <input type="text" id="edit_raw_material" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity Required</label>
                <input type="number" name="quantity" step="0.001" id="edit_quantity" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('editBomModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Update Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- Copy BOM Modal -->
<div id="copyBomModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Copy BOM</h3>
            <button onclick="closeModal('copyBomModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="copy_bom">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Source Item (Copy From)</label>
                <select name="source_item_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Source Item</option>
                    <?php foreach ($finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Target Item (Copy To)</label>
                <select name="target_item_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Target Item</option>
                    <?php foreach ($finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-md p-3">
                <p class="text-sm text-blue-800">
                    <strong>Note:</strong> This will copy all BOM entries from the source item to the target item. Existing entries will be skipped.
                </p>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('copyBomModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Copy BOM</button>
            </div>
        </form>
    </div>
</div>

<!-- BOM Details Modal -->
<div id="bomDetailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">BOM Details</h3>
            <button onclick="closeModal('bomDetailsModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="bomDetailsContent">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<!-- Cost Calculation Modal -->
<div id="costCalculationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Cost Calculation</h3>
            <button onclick="closeModal('costCalculationModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="costCalculationContent">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<script>
function addBomMaterial(itemId) {
    document.getElementById('create_item_id').value = itemId;
    
    // Reset other fields
    document.getElementById('create_raw_material_id').value = '';
    document.getElementById('material_info').textContent = '';
    
    openModal('createBomModal');
}

function editBom(bom) {
    document.getElementById('edit_bom_id').value = bom.id;
    document.getElementById('edit_finished_item').value = bom.finished_item_name + ' (' + bom.finished_item_code + ')';
    document.getElementById('edit_raw_material').value = bom.raw_material_name + ' (' + bom.raw_material_code + ')';
    document.getElementById('edit_quantity').value = bom.quantity;
    openModal('editBomModal');
}

function updateMaterialInfo(select) {
    const selectedOption = select.options[select.selectedIndex];
    const stock = selectedOption.getAttribute('data-stock') || '0';
    const unit = selectedOption.getAttribute('data-unit') || '';
    const infoElement = document.getElementById('material_info');
    
    if (selectedOption.value) {
        infoElement.textContent = `Available Stock: ${parseFloat(stock).toFixed(3)} ${unit}`;
        infoElement.className = parseFloat(stock) > 0 ? 'text-xs text-green-600 mt-1' : 'text-xs text-red-600 mt-1';
    } else {
        infoElement.textContent = '';
        infoElement.className = 'text-xs text-gray-500 mt-1';
    }
}

function viewBomDetails(itemId) {
    // Show loading message
    document.getElementById('bomDetailsContent').innerHTML = `
        <div class="text-center py-8">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
            <p class="mt-2 text-gray-600">Loading BOM details...</p>
        </div>
    `;
    openModal('bomDetailsModal');
    
    // Fetch BOM details via AJAX (simulated)
    setTimeout(() => {
        fetchBomDetails(itemId);
    }, 500);
}

function fetchBomDetails(itemId) {
    // In a real implementation, this would be an AJAX call
    // For now, we'll simulate the response
    const bomData = {
        item_name: "Sample Item",
        item_code: "ITEM001",
        materials: [
            {name: "Raw Material 1", code: "RM001", quantity: 0.500, unit: "kg", available: 100.000, cost: 50.00},
            {name: "Raw Material 2", code: "RM002", quantity: 0.250, unit: "kg", available: 50.000, cost: 80.00}
        ]
    };
    
    let materialsHtml = '';
    let totalCost = 0;
    
    bomData.materials.forEach(material => {
        const itemCost = material.quantity * material.cost;
        totalCost += itemCost;
        const canProduce = material.available >= material.quantity;
        
        materialsHtml += `
            <tr class="${canProduce ? '' : 'bg-red-50'}">
                <td class="px-4 py-2 border">
                    <div>
                        <div class="font-medium">${material.name}</div>
                        <div class="text-sm text-gray-500">${material.code}</div>
                    </div>
                </td>
                <td class="px-4 py-2 border text-right">${material.quantity.toFixed(3)} ${material.unit}</td>
                <td class="px-4 py-2 border text-right ${canProduce ? 'text-green-600' : 'text-red-600'}">${material.available.toFixed(3)} ${material.unit}</td>
                <td class="px-4 py-2 border text-right">රු.${material.cost.toFixed(2)}</td>
                <td class="px-4 py-2 border text-right">රු.${itemCost.toFixed(2)}</td>
                <td class="px-4 py-2 border text-center">
                    <span class="px-2 py-1 text-xs rounded ${canProduce ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                        ${canProduce ? 'Available' : 'Shortage'}
                    </span>
                </td>
            </tr>
        `;
    });
    
    document.getElementById('bomDetailsContent').innerHTML = `
        <div class="space-y-6">
            <!-- Item Header -->
            <div class="bg-gray-50 rounded-lg p-4">
                <h4 class="text-lg font-semibold text-gray-900">${bomData.item_name}</h4>
                <p class="text-sm text-gray-600">${bomData.item_code}</p>
            </div>
            
            <!-- Materials Table -->
            <div>
                <h5 class="font-medium text-gray-900 mb-3">Material Requirements</h5>
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Material</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Required</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Available</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Unit Cost</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Total Cost</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${materialsHtml}
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="4" class="px-4 py-2 text-right font-medium border">Total Cost:</td>
                                <td class="px-4 py-2 text-right font-bold border">රු.${totalCost.toFixed(2)}</td>
                                <td class="px-4 py-2 border"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <!-- Production Analysis -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-blue-50 border border-blue-200 rounded-md p-4">
                    <h6 class="font-medium text-blue-900 mb-2">Production Capacity</h6>
                    <p class="text-sm text-blue-800">Based on current stock, you can produce:</p>
                    <p class="text-lg font-bold text-blue-900">50 units</p>
                </div>
                <div class="bg-purple-50 border border-purple-200 rounded-md p-4">
                    <h6 class="font-medium text-purple-900 mb-2">Cost per Unit</h6>
                    <p class="text-sm text-purple-800">Raw material cost:</p>
                    <p class="text-lg font-bold text-purple-900">රු.${totalCost.toFixed(2)}</p>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="flex justify-end space-x-3">
                <button onclick="exportBom(${itemId})" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200">
                    Export BOM
                </button>
                <button onclick="closeModal('bomDetailsModal')" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                    Close
                </button>
            </div>
        </div>
    `;
}

function calculateItemCost(itemId) {
    // Show loading message
    document.getElementById('costCalculationContent').innerHTML = `
        <div class="text-center py-4">
            <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
            <p class="mt-2 text-gray-600">Calculating cost...</p>
        </div>
    `;
    openModal('costCalculationModal');
    
    // Simulate cost calculation
    setTimeout(() => {
        const costData = {
            material_cost: 125.50,
            labor_cost: 25.00,
            overhead_cost: 15.75,
            total_cost: 166.25,
            suggested_price: 199.50
        };
        
        document.getElementById('costCalculationContent').innerHTML = `
            <div class="space-y-4">
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Raw Materials:</span>
                        <span class="font-medium">රු.${costData.material_cost.toFixed(2)}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Labor Cost (Est.):</span>
                        <span class="font-medium">රු.${costData.labor_cost.toFixed(2)}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Overhead (Est.):</span>
                        <span class="font-medium">රු.${costData.overhead_cost.toFixed(2)}</span>
                    </div>
                    <hr>
                    <div class="flex justify-between text-lg">
                        <span class="font-semibold">Total Cost:</span>
                        <span class="font-bold text-green-600">රු.${costData.total_cost.toFixed(2)}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Suggested Price (20% margin):</span>
                        <span class="font-medium text-blue-600">රු.${costData.suggested_price.toFixed(2)}</span>
                    </div>
                </div>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3">
                    <p class="text-xs text-yellow-800">
                        <strong>Note:</strong> Labor and overhead costs are estimated. Update these values in settings for accurate calculations.
                    </p>
                </div>
                
                <div class="flex justify-end">
                    <button onclick="closeModal('costCalculationModal')" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                        Close
                    </button>
                </div>
            </div>
        `;
    }, 1000);
}

function exportBom(itemId) {
    // Open export window
    window.open(`exports/export_bom.php?id=${itemId}`, '_blank');
}

// Initialize tooltips and other functionality
document.addEventListener('DOMContentLoaded', function() {
    // Add any initialization code here
    console.log('BOM page loaded successfully');
});
</script>

<?php include 'footer.php'; ?>
