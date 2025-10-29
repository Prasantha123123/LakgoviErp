<?php
// repacking.php - Repacking Module (Convert finished products into smaller units)
include 'header.php';

require_once 'database.php';
$database = new Database();
$db = $database->getConnection();

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
                    
                    // Get source item details
                    $stmt = $db->prepare("SELECT unit_id FROM items WHERE id = ?");
                    $stmt->execute([$_POST['source_item_id']]);
                    $source_item = $stmt->fetch();
                    
                    // Get repack item details
                    $stmt = $db->prepare("SELECT unit_id FROM items WHERE id = ?");
                    $stmt->execute([$_POST['repack_item_id']]);
                    $repack_item = $stmt->fetch();
                    
                    // Calculate repack quantity
                    $source_quantity = floatval($_POST['source_quantity']);
                    $repack_unit_size = floatval($_POST['repack_unit_size']);
                    
                    if ($repack_unit_size <= 0) {
                        throw new Exception("Repack unit size must be greater than zero");
                    }
                    
                    // Calculate how many packs can be made
                    $repack_quantity = floor($source_quantity / $repack_unit_size);
                    
                    if ($repack_quantity <= 0) {
                        throw new Exception("Source quantity is too small to create any repack units");
                    }
                    
                    // Insert repacking record
                    $stmt = $db->prepare("
                        INSERT INTO repacking (
                            repack_code, repack_date, source_item_id, source_batch_code,
                            source_quantity, source_unit_id, repack_item_id, repack_quantity,
                            repack_unit_id, repack_unit_size, location_id, notes, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                    
                    // Update stock ledger - Reduce source item stock
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
                        $source_quantity,
                        $_POST['source_item_id'],
                        $_POST['location_id'],
                        $source_quantity
                    ]);
                    
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
                            }
                        }
                    }
                    
                    $db->commit();
                    $success = "Repacking completed successfully! Code: {$next_code}, Packs Created: {$repack_quantity}";
                    break;
                    
                case 'delete':
                    $db->beginTransaction();
                    
                    // Get repacking details before deletion
                    $stmt = $db->prepare("SELECT * FROM repacking WHERE id = ?");
                    $stmt->execute([$_POST['repacking_id']]);
                    $repack = $stmt->fetch();
                    
                    if (!$repack) {
                        throw new Exception("Repacking record not found");
                    }
                    
                    // Delete stock ledger entries
                    $stmt = $db->prepare("DELETE FROM stock_ledger WHERE transaction_type IN ('repack_in', 'repack_out') AND reference_id = ?");
                    $stmt->execute([$_POST['repacking_id']]);
                    
                    // Delete repacking record (materials will be deleted via CASCADE)
                    $stmt = $db->prepare("DELETE FROM repacking WHERE id = ?");
                    $stmt->execute([$_POST['repacking_id']]);
                    
                    $db->commit();
                    $success = "Repacking record deleted successfully!";
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

// Fetch all repacking records
try {
    $stmt = $db->query("SELECT * FROM v_repacking_details ORDER BY repack_date DESC, created_at DESC");
    $repacking_records = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching repacking records: " . $e->getMessage();
    $repacking_records = [];
}

// Fetch finished items for source selection
try {
    $stmt = $db->query("
        SELECT i.id, i.code, i.name, u.symbol AS unit_symbol
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
        <button onclick="openModal('createRepackModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Repacking
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
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Repacking History</h2>
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($repacking_records)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-4 text-center text-gray-500">
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
                                <?php if ($record['source_batch_code']): ?>
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
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($record['location_name']); ?>
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
                        <select name="source_item_id" id="source_item_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">-- Select Source Product --</option>
                            <?php foreach ($finished_items as $item): ?>
                                <option value="<?php echo $item['id']; ?>" data-unit="<?php echo htmlspecialchars($item['unit_symbol']); ?>">
                                    <?php echo htmlspecialchars($item['name']); ?> (<?php echo htmlspecialchars($item['code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Source Quantity *</label>
                        <div class="flex">
                            <input type="number" name="source_quantity" id="source_quantity" step="0.001" min="0.001" required
                                   onchange="calculateRepackQty()"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-l-md focus:outline-none focus:ring-2 focus:ring-primary">
                            <span id="source_unit_display" class="px-3 py-2 bg-gray-100 border border-l-0 border-gray-300 rounded-r-md text-gray-600 text-sm">
                                kg
                            </span>
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

function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

// Update unit displays when items are selected
document.getElementById('source_item_id')?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || 'kg';
    document.getElementById('source_unit_display').textContent = unit;
    calculateRepackQty();
});

document.getElementById('repack_item_id')?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || 'kg';
    document.getElementById('repack_unit_display').textContent = unit;
    calculateRepackQty();
});

function calculateRepackQty() {
    const sourceQty = parseFloat(document.getElementById('source_quantity').value) || 0;
    const repackSize = parseFloat(document.getElementById('repack_unit_size').value) || 0;
    
    if (sourceQty > 0 && repackSize > 0) {
        const packs = Math.floor(sourceQty / repackSize);
        document.getElementById('calculated_packs').textContent = packs.toLocaleString();
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
    // You can implement a view details modal here
    alert('View details for repacking ID: ' + id);
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
