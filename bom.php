<?php
// bom.php - Peetu (Semi-Finished) mappings: raw requirements per unit
include 'header.php';

// ---------------------------
// Helpers
// ---------------------------
function safe_arr($v) { return is_array($v) ? $v : []; }

// ---------------------------
// Handle form submissions
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {

                // Add one or more raw materials for a single semi-finished (peetu) item
                case 'create_peetu':
                    $peetu_item_id = $_POST['peetu_item_id'] ?? null;
                    $raw_ids       = safe_arr($_POST['raw_material_id'] ?? []);
                    $qtys          = safe_arr($_POST['quantity'] ?? []);

                    if (!$peetu_item_id || !is_numeric($peetu_item_id)) {
                        throw new Exception("Please select a Semi-Finished (Peetu) item.");
                    }
                    if (empty($raw_ids)) {
                        throw new Exception("Please add at least one raw material row.");
                    }

                    $db->beginTransaction();

                    // Insert each row (skip blanks; upsert-like behavior)
                    $inserted = 0;
                    for ($i = 0; $i < count($raw_ids); $i++) {
                        $rid = (int)($raw_ids[$i] ?? 0);
                        $q   = (float)($qtys[$i] ?? 0);
                        if ($rid > 0 && $q > 0) {
                            // Check if exists
                            $stmt = $db->prepare("SELECT id FROM bom_peetu WHERE peetu_item_id = ? AND raw_material_id = ?");
                            $stmt->execute([$peetu_item_id, $rid]);
                            $exists = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($exists) {
                                // Update quantity
                                $stmt = $db->prepare("UPDATE bom_peetu SET quantity = ? WHERE id = ?");
                                $stmt->execute([$q, $exists['id']]);
                            } else {
                                // Insert new
                                $stmt = $db->prepare("INSERT INTO bom_peetu (peetu_item_id, raw_material_id, quantity) VALUES (?, ?, ?)");
                                $stmt->execute([$peetu_item_id, $rid, $q]);
                            }
                            $inserted++;
                        }
                    }

                    $db->commit();
                    $success = $inserted > 0
                        ? "Peetu entry saved successfully! ($inserted row(s))"
                        : "No valid rows to save.";
                    break;

                case 'update':
                    // Update single mapping quantity
                    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
                        throw new Exception("Invalid entry id.");
                    }
                    $stmt = $db->prepare("UPDATE bom_peetu SET quantity = ? WHERE id = ?");
                    $stmt->execute([$_POST['quantity'], $_POST['id']]);
                    $success = "Peetu entry updated successfully!";
                    break;

                case 'delete':
                    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
                        throw new Exception("Invalid entry id.");
                    }
                    $stmt = $db->prepare("DELETE FROM bom_peetu WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $success = "Peetu entry deleted successfully!";
                    break;

                case 'copy_bom':
                    // Copy peetu mapping from source semi-finished item to target semi-finished item
                    $src = $_POST['source_item_id'] ?? null;  // semi_finished
                    $dst = $_POST['target_item_id'] ?? null;  // semi_finished
                    if (!$src || !$dst || !is_numeric($src) || !is_numeric($dst)) {
                        throw new Exception("Please select valid Semi-Finished items to copy.");
                    }
                    if ((int)$src === (int)$dst) {
                        throw new Exception("Source and target cannot be the same.");
                    }

                    $db->beginTransaction();

                    $stmt = $db->prepare("SELECT raw_material_id, quantity FROM bom_peetu WHERE peetu_item_id = ?");
                    $stmt->execute([$src]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($rows)) {
                        throw new Exception("Source Peetu has no entries to copy.");
                    }

                    $copied = 0;
                    foreach ($rows as $r) {
                        // upsert
                        $stmt = $db->prepare("SELECT id FROM bom_peetu WHERE peetu_item_id = ? AND raw_material_id = ?");
                        $stmt->execute([$dst, $r['raw_material_id']]);
                        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($exists) {
                            $stmt = $db->prepare("UPDATE bom_peetu SET quantity = ? WHERE id = ?");
                            $stmt->execute([$r['quantity'], $exists['id']]);
                        } else {
                            $stmt = $db->prepare("INSERT INTO bom_peetu (peetu_item_id, raw_material_id, quantity) VALUES (?, ?, ?)");
                            $stmt->execute([$dst, $r['raw_material_id'], $r['quantity']]);
                        }
                        $copied++;
                    }

                    $db->commit();
                    $success = "Peetu map copied successfully! $copied entries copied.";
                    break;

                case 'calculate_cost':
                    // Raw-material cost for one peetu unit using average GRN rate per raw
                    $peetu_item_id = $_POST['item_id'] ?? null;
                    if (!$peetu_item_id || !is_numeric($peetu_item_id)) {
                        throw new Exception("Invalid Semi-Finished item.");
                    }

                    $stmt = $db->prepare("
                        SELECT bp.quantity, rm.name as raw_material_name,
                               COALESCE(AVG(gi.rate), 0) as avg_rate
                        FROM bom_peetu bp
                        JOIN items rm ON bp.raw_material_id = rm.id
                        LEFT JOIN grn_items gi ON gi.item_id = rm.id
                        WHERE bp.peetu_item_id = ?
                        GROUP BY bp.id, bp.quantity, rm.name
                    ");
                    $stmt->execute([$peetu_item_id]);
                    $cost_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $total_cost = 0;
                    foreach ($cost_breakdown as $row) {
                        $total_cost += ((float)$row['quantity']) * ((float)$row['avg_rate']);
                    }
                    $success = "Cost (materials only): රු." . number_format($total_cost, 2);
                    break;
            }
        }
    } catch(PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        $error = "Error: " . $e->getMessage();
    } catch(Exception $e) {
        if ($db->inTransaction()) $db->rollback();
        $error = "Error: " . $e->getMessage();
    }
}

// ---------------------------
// Fetch data for UI
// ---------------------------

// Peetu rows (semi-finished + raw requirements)
$peetu_rows = [];
try {
    // Compute raw material stock from stock_ledger to avoid depending on a current_stock column
    $stmt = $db->query("
        SELECT bp.*,
               fi.name  AS peetu_name,  fi.code AS peetu_code,  fu.symbol AS peetu_unit,
               rm.name  AS raw_material_name, rm.code AS raw_material_code, ru.symbol AS raw_unit,
               COALESCE((
                   SELECT SUM(sl.quantity_in - sl.quantity_out)
                   FROM stock_ledger sl
                   WHERE sl.item_id = rm.id
               ), 0) AS raw_material_stock
        FROM bom_peetu bp
        JOIN items fi ON bp.peetu_item_id = fi.id AND fi.type = 'semi_finished'
        JOIN units fu ON fi.unit_id = fu.id
        JOIN items rm ON bp.raw_material_id = rm.id
        JOIN units ru ON rm.unit_id = ru.id
        ORDER BY fi.name, rm.name
    ");
    $peetu_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(PDOException $e) {
    $error = "Error fetching Peetu entries: " . $e->getMessage();
    $peetu_rows = [];
}

// Semi-finished items for dropdown
$semi_finished_items = [];
try {
    $stmt = $db->query("SELECT i.*, u.symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.type = 'semi_finished' ORDER BY i.name");
    $semi_finished_items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(PDOException $e) {
    $error = "Error fetching semi-finished items: " . $e->getMessage();
    $semi_finished_items = [];
}

// Raw materials for dropdown
$raw_materials = [];
try {
    $stmt = $db->query("SELECT i.*, u.symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.type = 'raw' ORDER BY i.name");
    $raw_materials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(PDOException $e) {
    $error = "Error fetching raw materials: " . $e->getMessage();
    $raw_materials = [];
}

// Group by peetu (semi-finished) item for card view
$grouped_peetu = [];
foreach ($peetu_rows as $r) {
    $key = $r['peetu_item_id'];
    if (!isset($grouped_peetu[$key])) {
        $grouped_peetu[$key] = [
            'item_id'         => $r['peetu_item_id'],
            'item_name'       => $r['peetu_name'],
            'item_code'       => $r['peetu_code'],
            'item_unit'       => $r['peetu_unit'],
            'materials'       => [],
            'total_materials' => 0,
            'can_produce'     => true
        ];
    }
    $grouped_peetu[$key]['materials'][] = $r;
    $grouped_peetu[$key]['total_materials']++;

    if ((float)$r['raw_material_stock'] < (float)$r['quantity']) {
        $grouped_peetu[$key]['can_produce'] = false;
    }
}

// Stats
$peetu_stats = [
    'total_boms'       => count($grouped_peetu),
    'total_entries'    => count($peetu_rows),
    'can_produce'      => count(array_filter($grouped_peetu, fn($b) => $b['can_produce'])),
    'cannot_produce'   => count(array_filter($grouped_peetu, fn($b) => !$b['can_produce']))
];
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Peetu (Semi-Finished) Map</h1>
            <p class="text-gray-600">Define raw material requirements per unit of Semi-Finished (Peetu).</p>
        </div>
        <div class="flex space-x-2">
            <button onclick="openModal('copyBomModal')" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors">
                Copy Peetu Map
            </button>
            <button onclick="openCreatePeetu()" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
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

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Total Peetu</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $peetu_stats['total_boms']; ?></p>
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
                    <p class="text-2xl font-bold text-gray-900"><?php echo $peetu_stats['total_entries']; ?></p>
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
                    <p class="text-2xl font-bold text-gray-900"><?php echo $peetu_stats['can_produce']; ?></p>
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
                    <p class="text-2xl font-bold text-gray-900"><?php echo $peetu_stats['cannot_produce']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Peetu Cards -->
    <?php if (!empty($grouped_peetu)): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <?php foreach ($grouped_peetu as $item_key => $item_bom): ?>
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
                            <button onclick="calculateItemCost(<?php echo (int)$item_bom['item_id']; ?>)" class="text-purple-600 hover:text-purple-900 text-sm" title="Calculate Cost">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 01-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            </button>
                            <button onclick="addBomMaterial(<?php echo (int)$item_bom['item_id']; ?>)" class="text-green-600 hover:text-green-900 text-sm" title="Add Material">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <h4 class="text-sm font-medium text-gray-700">Raw Materials Required:</h4>
                        <?php foreach ($item_bom['materials'] as $material): ?>
                        <div class="flex justify-between items-center p-3 <?php echo ((float)$material['raw_material_stock'] < (float)$material['quantity']) ? 'bg-red-50 border border-red-200' : 'bg-gray-50'; ?> rounded-md">
                            <div class="flex-1">
                                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($material['raw_material_name']); ?></p>
                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($material['raw_material_code']); ?></p>
                                <p class="text-xs <?php echo ((float)$material['raw_material_stock'] < (float)$material['quantity']) ? 'text-red-600' : 'text-gray-500'; ?>">
                                    Available: <?php echo number_format((float)$material['raw_material_stock'], 3); ?> <?php echo $material['raw_unit']; ?>
                                </p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="text-sm font-medium <?php echo ((float)$material['raw_material_stock'] < (float)$material['quantity']) ? 'text-red-900' : 'text-gray-900'; ?>">
                                    <?php echo number_format((float)$material['quantity'], 3); ?> <?php echo $material['raw_unit']; ?>
                                </span>
                                <div class="flex space-x-1">
                                    <button onclick='editBom(<?php echo json_encode($material, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' class="text-indigo-600 hover:text-indigo-900">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this Peetu entry?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
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
                            <span class="font-medium"><?php echo (int)$item_bom['total_materials']; ?> items</span>
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
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Peetu entries found</h3>
            <p class="text-gray-600 mb-4">Create your first mapping from Semi-Finished (Peetu) to Raw Materials.</p>
            <button onclick="openCreatePeetu()" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                Add Peetu Entry
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Create Peetu Modal (keeps same id to avoid breaking existing JS) -->
<div id="createBomModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-[40rem] max-w-[95vw] shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add Peetu Entry</h3>
            <button onclick="closeModal('createBomModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" class="space-y-4" onsubmit="return validatePeetuForm()">
            <input type="hidden" name="action" value="create_peetu">

            <!-- Semi-Finished (Peetu) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Semi-Finished (Peetu)</label>
                <select name="peetu_item_id" id="create_peetu_item_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Semi-Finished Item</option>
                    <?php foreach ($semi_finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>">
                            <?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Multiple raw rows -->
            <div>
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-sm font-medium text-gray-700">Raw Materials & Quantity (per 1 Peetu)</label>
                    <button type="button" onclick="addRawRow()" class="bg-green-600 text-white px-2 py-1 rounded text-sm hover:bg-green-700">
                        + Add Row
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300" id="peetuRawTable">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Raw Material</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Qty</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Unit</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase border">Action</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500 mt-1">Tip: add as many raw materials as needed for one unit of the selected Peetu.</p>
            </div>

            <div class="flex justify-end space-x-3 pt-2">
                <button type="button" onclick="closeModal('createBomModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Peetu Modal -->
<div id="editBomModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Edit Peetu Entry</h3>
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Peetu (Semi-Finished)</label>
                <input type="text" id="edit_finished_item" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Raw Material</label>
                <input type="text" id="edit_raw_material" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity (per 1 Peetu)</label>
                <input type="number" name="quantity" step="0.001" id="edit_quantity" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('editBomModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Update Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- Copy Peetu Modal -->
<div id="copyBomModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Copy Peetu Map</h3>
            <button onclick="closeModal('copyBomModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="copy_bom">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Source Peetu (Copy From)</label>
                <select name="source_item_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Semi-Finished Item</option>
                    <?php foreach ($semi_finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Target Peetu (Copy To)</label>
                <select name="target_item_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Semi-Finished Item</option>
                    <?php foreach ($semi_finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-md p-3">
                <p class="text-sm text-blue-800">
                    <strong>Note:</strong> Copies all raw entries from source to target. Existing pairs will be updated with the source quantity.
                </p>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('copyBomModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Copy</button>
            </div>
        </form>
    </div>
</div>

<!-- Existing Detail/Cost modals (optional UI placeholders) -->
<div id="bomDetailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Peetu Details</h3>
            <button onclick="closeModal('bomDetailsModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="bomDetailsContent"></div>
    </div>
</div>

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
        <div id="costCalculationContent"></div>
    </div>
</div>

<script>
// ---------- Add Peetu (multiple raws) ----------
const RAWS = <?php echo json_encode($raw_materials ?? []); ?>;

function rawSelectHTML() {
    let opts = '<option value="">Select Raw Material</option>';
    RAWS.forEach(r => {
        const label = `${r.name || ''} (${r.code || ''}) - ${r.symbol || ''}`;
        opts += `<option value="${r.id}" data-unit="${r.symbol||''}">${escapeHtml(label)}</option>`;
    });
    return `
        <select name="raw_material_id[]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm raw-select" onchange="syncRowUnit(this)" required>
            ${opts}
        </select>
    `;
}

function addRawRow() {
    const tb = document.querySelector('#peetuRawTable tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="px-3 py-2 border">${rawSelectHTML()}</td>
        <td class="px-3 py-2 border">
            <input type="number" name="quantity[]" step="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" placeholder="0.000" required>
        </td>
        <td class="px-3 py-2 border">
            <span class="inline-block text-xs text-gray-600 unit-badge"></span>
        </td>
        <td class="px-3 py-2 border text-center">
            <button type="button" class="text-red-600 hover:text-red-800 text-sm" onclick="removeRawRow(this)">Remove</button>
        </td>
    `;
    tb.appendChild(tr);
}

function removeRawRow(btn) {
    const tb = document.querySelector('#peetuRawTable tbody');
    if (tb.children.length <= 1) {
        alert('At least one row is required.');
        return;
    }
    btn.closest('tr').remove();
}

function syncRowUnit(select) {
    const unit = select.options[select.selectedIndex]?.getAttribute('data-unit') || '';
    const row = select.closest('tr');
    row.querySelector('.unit-badge').textContent = unit;
}

function validatePeetuForm() {
    const peetu = document.getElementById('create_peetu_item_id').value;
    if (!peetu) { alert('Please select a Semi-Finished (Peetu) item.'); return false; }

    const raws = document.querySelectorAll('#peetuRawTable tbody tr');
    if (!raws.length) { alert('Please add at least one raw material row.'); return false; }

    let ok = true;
    raws.forEach(r => {
        const sel = r.querySelector('.raw-select');
        const qty = r.querySelector('input[name="quantity[]"]');
        if (!sel.value || !qty.value || parseFloat(qty.value) <= 0) ok = false;
    });
    if (!ok) { alert('Each row must have a raw material and a positive quantity.'); }
    return ok;
}

// Wrapper to always open modal with one fresh row
function openCreatePeetu() {
    document.getElementById('create_peetu_item_id').value = '';
    const tb = document.querySelector('#peetuRawTable tbody');
    tb.innerHTML = '';
    addRawRow();
    openModal('createBomModal');
}

// ---------- Reuse existing functions (kept names) ----------
function addBomMaterial(itemId) {
    // Pre-select peetu and show modal
    document.getElementById('create_peetu_item_id').value = itemId;
    // Reset rows
    const tb = document.querySelector('#peetuRawTable tbody');
    tb.innerHTML = '';
    addRawRow();
    openModal('createBomModal');
}

function editBom(row) {
    document.getElementById('edit_bom_id').value = row.id;
    document.getElementById('edit_finished_item').value = (row.peetu_name || '') + ' (' + (row.peetu_code || '') + ')';
    document.getElementById('edit_raw_material').value = (row.raw_material_name || '') + ' (' + (row.raw_material_code || '') + ')';
    document.getElementById('edit_quantity').value = row.quantity;
    openModal('editBomModal');
}

// Optional demo cost popup (client-side placeholder)
function calculateItemCost(itemId) {
    document.getElementById('costCalculationContent').innerHTML = `
        <div class="text-center py-4">
            <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
            <p class="mt-2 text-gray-600">Calculating cost...</p>
        </div>
    `;
    openModal('costCalculationModal');

    setTimeout(() => {
        // Placeholder values; wire to POST action=calculate_cost for live data.
        const material = 100.00, labor = 20.00, overhead = 10.00;
        const total = material + labor + overhead;
        const suggested = total * 1.2;
        document.getElementById('costCalculationContent').innerHTML = `
            <div class="space-y-4">
                <div class="space-y-3">
                    <div class="flex justify-between"><span class="text-gray-600">Raw Materials:</span><span class="font-medium">රු.${material.toFixed(2)}</span></div>
                    <div class="flex justify-between"><span class="text-gray-600">Labor (Est.):</span><span class="font-medium">රු.${labor.toFixed(2)}</span></div>
                    <div class="flex justify-between"><span class="text-gray-600">Overhead (Est.):</span><span class="font-medium">රු.${overhead.toFixed(2)}</span></div>
                    <hr>
                    <div class="flex justify-between text-lg"><span class="font-semibold">Total Cost:</span><span class="font-bold text-green-600">රු.${total.toFixed(2)}</span></div>
                    <div class="flex justify-between"><span class="text-gray-600">Suggested Price (20% margin):</span><span class="font-medium text-blue-600">රු.${suggested.toFixed(2)}</span></div>
                </div>
                <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3">
                    <p class="text-xs text-yellow-800"><strong>Note:</strong> Labor & overhead are placeholders. Use server calc (action=calculate_cost) for exact raw costs from GRN.</p>
                </div>
                <div class="flex justify-end"><button onclick="closeModal('costCalculationModal')" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Close</button></div>
            </div>
        `;
    }, 500);
}

// Small utilities
function escapeHtml(s){return String(s).replace(/[&<>"']/g,m=>({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;" }[m]));}

// Ensure the create modal always has at least one row when the page loads (first use)
document.addEventListener('DOMContentLoaded', function () {
    const tb = document.querySelector('#peetuRawTable tbody');
    if (tb && !tb.children.length) addRawRow();
});
</script>

<?php include 'footer.php'; ?>
