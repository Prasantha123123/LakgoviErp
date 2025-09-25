<?php
// bom.php - Peetu (Semi-Finished) & BOM Product mappings
include 'header.php';

/* =========================== Helpers =========================== */
function safe_arr($v) { return is_array($v) ? $v : []; }
function as_int_or_zero($v){ return is_numeric($v) ? (int)$v : 0; }
function as_float_or_zero($v){ return is_numeric($v) ? (float)$v : 0.0; }

/* ===================== Handle form submissions ===================== */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {

                /* ---------------------------
                 *  Peetu (Semi-Finished → Raw)
                 * --------------------------- */

                // Add one or more raw materials for a single semi-finished (peetu) item
                case 'create_peetu':
                    $peetu_item_id = as_int_or_zero($_POST['peetu_item_id'] ?? null);
                    $raw_ids       = safe_arr($_POST['raw_material_id'] ?? []);
                    $qtys          = safe_arr($_POST['quantity'] ?? []);

                    if ($peetu_item_id <= 0) {
                        throw new Exception("Please select a Semi-Finished (Peetu) item.");
                    }
                    if (empty($raw_ids)) {
                        throw new Exception("Please add at least one raw material row.");
                    }

                    $db->beginTransaction();
                    $inserted = 0;

                    for ($i = 0; $i < count($raw_ids); $i++) {
                        $rid = as_int_or_zero($raw_ids[$i] ?? 0);
                        $q   = as_float_or_zero($qtys[$i] ?? 0);
                        if ($rid > 0 && $q > 0) {
                            // Upsert by (peetu_item_id, raw_material_id)
                            $stmt = $db->prepare("SELECT id FROM bom_peetu WHERE peetu_item_id = ? AND raw_material_id = ?");
                            $stmt->execute([$peetu_item_id, $rid]);
                            $exists = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($exists) {
                                $stmt = $db->prepare("UPDATE bom_peetu SET quantity = ? WHERE id = ?");
                                $stmt->execute([$q, $exists['id']]);
                            } else {
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

                // Update single Peetu mapping quantity
                case 'update':
                    $id = as_int_or_zero($_POST['id'] ?? 0);
                    $q  = as_float_or_zero($_POST['quantity'] ?? 0);
                    if ($id <= 0) throw new Exception("Invalid entry id.");
                    $stmt = $db->prepare("UPDATE bom_peetu SET quantity = ? WHERE id = ?");
                    $stmt->execute([$q, $id]);
                    $success = "Peetu entry updated successfully!";
                    break;

                // Delete single Peetu mapping
                case 'delete':
                    $id = as_int_or_zero($_POST['id'] ?? 0);
                    if ($id <= 0) throw new Exception("Invalid entry id.");
                    $stmt = $db->prepare("DELETE FROM bom_peetu WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Peetu entry deleted successfully!";
                    break;

                // Copy Peetu map from one semi-finished to another
                case 'copy_bom':
                    $src = as_int_or_zero($_POST['source_item_id'] ?? 0);
                    $dst = as_int_or_zero($_POST['target_item_id'] ?? 0);
                    if ($src <= 0 || $dst <= 0) throw new Exception("Please select valid Semi-Finished items to copy.");
                    if ($src === $dst)       throw new Exception("Source and target cannot be the same.");

                    $db->beginTransaction();

                    $stmt = $db->prepare("SELECT raw_material_id, quantity FROM bom_peetu WHERE peetu_item_id = ?");
                    $stmt->execute([$src]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($rows)) throw new Exception("Source Peetu has no entries to copy.");

                    $copied = 0;
                    foreach ($rows as $r) {
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

                // (Optional) server-side cost calc for a semi-finished item
                case 'calculate_cost':
                    $peetu_item_id = as_int_or_zero($_POST['item_id'] ?? 0);
                    if ($peetu_item_id <= 0) throw new Exception("Invalid Semi-Finished item.");

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

                /* ---------------------------
                 *  BOM Product (Finished → Peetu)
                 *  quantity = FINISHED PACKS per 1 PEETU (yield)
                 * --------------------------- */

                // Create (multi-rows)
                case 'create_bom_product':
                    $finished_item_id = as_int_or_zero($_POST['finished_item_id'] ?? 0);
                    $peetu_ids = safe_arr($_POST['bom_peetu_id'] ?? []);   // peetu item IDs
                    $qtys      = safe_arr($_POST['bom_peetu_qty'] ?? []);  // pieces per 1 peetu (existing)
                    // NEW optional helper fields (parallel to rows)
                    $unit_qtys = safe_arr($_POST['bp_unit_qty'] ?? []);    // product unit qty (kg)
                    $totals    = safe_arr($_POST['bp_total_qty'] ?? []);   // total qty from 1 peetu (kg)

                    if ($finished_item_id <= 0) throw new Exception("Please select a Finished item.");
                    if (empty($peetu_ids))      throw new Exception("Please add at least one Peetu row.");

                    $db->beginTransaction();
                    $added = 0;
                    for ($i = 0; $i < count($peetu_ids); $i++) {
                        $pid = as_int_or_zero($peetu_ids[$i] ?? 0);

                        // Start from user-entered pieces (keeps backward compatibility)
                        $q   = as_float_or_zero($qtys[$i] ?? 0);

                        // Optional calculator values
                        $u   = isset($unit_qtys[$i]) ? as_float_or_zero($unit_qtys[$i]) : null;
                        $t   = isset($totals[$i])    ? as_float_or_zero($totals[$i])    : null;

                        // If provided, compute pieces safely
                        if ($u > 0 && $t > 0) {
                            if ($t + 1e-12 < $u) {
                                throw new Exception("Total quantity must be ≥ Unit quantity (row ".($i+1).").");
                            }
                            $q = $t / $u; // store into bom_product.quantity
                        }

                        if ($pid > 0 && $q > 0) {
                            // Upsert by (finished_item_id, peetu_item_id)
                            $chk = $db->prepare("SELECT id FROM bom_product WHERE finished_item_id = ? AND peetu_item_id = ?");
                            $chk->execute([$finished_item_id, $pid]);
                            if ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
                                $upd = $db->prepare("
                                    UPDATE bom_product
                                       SET quantity = ?, product_unit_qty = ?, total_quantity = ?
                                     WHERE id = ?
                                ");
                                $upd->execute([$q, $u, $t, $row['id']]);
                            } else {
                                $ins = $db->prepare("
                                    INSERT INTO bom_product (finished_item_id, peetu_item_id, quantity, product_unit_qty, total_quantity)
                                    VALUES (?, ?, ?, ?, ?)
                                ");
                                $ins->execute([$finished_item_id, $pid, $q, $u, $t]);
                            }
                            $added++;
                        }
                    }
                    $db->commit();
                    $success = $added > 0
                        ? "BOM Product saved successfully! ($added row(s))"
                        : "No valid rows to save.";
                    break;

                // Update one mapping
                case 'update_bom_product':
                    $id = as_int_or_zero($_POST['id'] ?? 0);
                    $q  = as_float_or_zero($_POST['quantity'] ?? 0);
                    if ($id <= 0) throw new Exception("Invalid BOM Product entry id.");
                    $stmt = $db->prepare("UPDATE bom_product SET quantity = ? WHERE id = ?");
                    $stmt->execute([$q, $id]);
                    $success = "BOM Product entry updated successfully!";
                    break;

                // Delete one mapping
                case 'delete_bom_product':
                    $id = as_int_or_zero($_POST['id'] ?? 0);
                    if ($id <= 0) throw new Exception("Invalid BOM Product entry id.");
                    $stmt = $db->prepare("DELETE FROM bom_product WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "BOM Product entry deleted successfully!";
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

/* ============================ Fetch data ============================ */

/* Peetu rows (semi-finished + raw requirements) */
$peetu_rows = [];
try {
    // raw stock via stock_ledger (avoids relying on items.current_stock)
    $stmt = $db->query("
        SELECT bp.*,
               fi.name  AS peetu_name,  fi.code AS peetu_code,  fu.symbol AS peetu_unit,
               rm.name  AS raw_material_name, rm.code AS raw_material_code, ru.symbol AS raw_unit,
               COALESCE((SELECT SUM(sl.quantity_in - sl.quantity_out)
                         FROM stock_ledger sl
                         WHERE sl.item_id = rm.id), 0) AS raw_material_stock
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

/* Semi-finished items (dropdowns) */
$semi_finished_items = [];
try {
    $stmt = $db->query("SELECT i.*, u.symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.type = 'semi_finished' ORDER BY i.name");
    $semi_finished_items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(PDOException $e) {
    $error = "Error fetching semi-finished items: " . $e->getMessage();
    $semi_finished_items = [];
}

/* Finished items (dropdown for BOM Product) */
$finished_items = [];
try {
    $stmt = $db->query("SELECT i.*, u.symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.type = 'finished' ORDER BY i.name");
    $finished_items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(PDOException $e) {
    $error = "Error fetching finished items: " . $e->getMessage();
    $finished_items = [];
}

/* Raw materials (dropdown for Peetu create) */
$raw_materials = [];
try {
    $stmt = $db->query("SELECT i.*, u.symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.type = 'raw' ORDER BY i.name");
    $raw_materials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(PDOException $e) {
    $error = "Error fetching raw materials: " . $e->getMessage();
    $raw_materials = [];
}

/* Group Peetu rows by peetu (semi-finished) */
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

/* Peetu stats */
$peetu_stats = [
    'total_boms'       => count($grouped_peetu),
    'total_entries'    => count($peetu_rows),
    'can_produce'      => count(array_filter($grouped_peetu, fn($b) => $b['can_produce'])),
    'cannot_produce'   => count(array_filter($grouped_peetu, fn($b) => !$b['can_produce']))
];

/* BOM Product rows (Finished → Peetu) */
$bomprod_rows = [];
try {
    $stmt = $db->query("
        SELECT bp.*,
               f.name AS finished_name, f.code AS finished_code, fu.symbol AS finished_unit,
               p.name AS peetu_name,    p.code AS peetu_code,    pu.symbol AS peetu_unit
        FROM bom_product bp
        JOIN items f  ON f.id = bp.finished_item_id AND f.type = 'finished'
        JOIN units fu ON fu.id = f.unit_id
        JOIN items p  ON p.id = bp.peetu_item_id     AND p.type = 'semi_finished'
        JOIN units pu ON pu.id = p.unit_id
        ORDER BY f.name, p.name
    ");
    $bomprod_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(PDOException $e) {
    $error = "Error fetching BOM Product entries: " . $e->getMessage();
    $bomprod_rows = [];
}

/* Group BOM Product by finished item */
$grouped_bomprod = [];
foreach ($bomprod_rows as $r) {
    $key = $r['finished_item_id'];
    if (!isset($grouped_bomprod[$key])) {
        $grouped_bomprod[$key] = [
            'item_id'   => $r['finished_item_id'],
            'item_name' => $r['finished_name'],
            'item_code' => $r['finished_code'],
            'item_unit' => $r['finished_unit'],
            'rows'      => []
        ];
    }
    $grouped_bomprod[$key]['rows'][] = $r;
}
?>

<div class="space-y-10">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Peetu (Semi-Finished) Map</h1>
            <p class="text-gray-600">Define raw material requirements per unit of Semi-Finished (Peetu).</p>
        </div>
        <div class="flex gap-2">
            <button onclick="openModal('copyBomModal')" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors">
                Copy Peetu Map
            </button>
            <button onclick="openCreatePeetu()" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                Add Peetu Entry
            </button>
            <button onclick="openCreateBomProduct()" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition-colors">
                Add BOM Product
            </button>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Peetu Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
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
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
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
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
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
            <?php foreach ($grouped_peetu as $item_bom): ?>
            <div class="bg-white rounded-lg shadow hover:shadow-md transition-shadow <?php echo !$item_bom['can_produce'] ? 'ring-2 ring-red-200' : ''; ?>">
                <div class="p-6">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex-1">
                            <div class="flex items-center space-x-2">
                                <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($item_bom['item_name']); ?></h3>
                                <?php if (!$item_bom['can_produce']): ?>
                                    <span class="bg-red-100 text-red-800 text-xs font-medium px-2 py-1 rounded">Material Shortage</span>
                                <?php else: ?>
                                    <span class="bg-green-100 text-green-800 text-xs font-medium px-2 py-1 rounded">Ready to Produce</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($item_bom['item_code']); ?></p>
                            <span class="inline-block mt-1 bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                Per <?php echo $item_bom['item_unit']; ?>
                            </span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button onclick="calculateItemCost(<?php echo (int)$item_bom['item_id']; ?>)" class="text-purple-600 hover:text-purple-900 text-sm" title="Calculate Cost">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 01-2 2v14a2 2 0 002 2z"/></svg>
                            </button>
                            <button onclick="addBomMaterial(<?php echo (int)$item_bom['item_id']; ?>)" class="text-green-600 hover:text-green-900 text-sm" title="Add Material">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
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
                                    <button onclick='editBom(<?php echo json_encode($material, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' class="text-indigo-600 hover:text-indigo-900" title="Edit">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this Peetu entry?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900" title="Delete">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
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
            <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Peetu entries found</h3>
            <p class="text-gray-600 mb-4">Create your first mapping from Semi-Finished (Peetu) to Raw Materials.</p>
            <button onclick="openCreatePeetu()" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">Add Peetu Entry</button>
        </div>
    <?php endif; ?>

    <!-- ====================== BOM PRODUCT SECTION ====================== -->
    <div class="pt-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-4">BOM Product (Finished → Peetu)</h2>

        <?php if (!empty($grouped_bomprod)): ?>
            <div class="grid grid-cols-1 gap-6">
                <?php foreach ($grouped_bomprod as $grp): ?>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="mb-3">
                        <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($grp['item_name']); ?></h3>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($grp['item_code']); ?></p>
                        <span class="inline-block mt-1 bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">Per <?php echo $grp['item_unit']; ?></span>
                    </div>

                    <div class="space-y-3">
                        <h4 class="text-sm font-medium text-gray-700">Peetu Required:</h4>
                        <?php foreach ($grp['rows'] as $row): ?>
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-md">
                            <div class="flex-1">
                                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($row['peetu_name']); ?></p>
                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($row['peetu_code']); ?></p>
                                <?php if (!is_null($row['total_quantity']) && !is_null($row['product_unit_qty']) && $row['total_quantity']>0 && $row['product_unit_qty']>0): ?>
                                    <p class="text-xs text-gray-500 mt-1">
                                        <?php
                                          $pieces = (float)$row['total_quantity'] / (float)$row['product_unit_qty'];
                                          $pieces_fmt = (fmod($pieces,1.0)==0.0) ? number_format($pieces,0) : number_format($pieces,3);
                                          echo number_format((float)$row['total_quantity'],3)."kg ÷ ".number_format((float)$row['product_unit_qty'],3)."kg = ".$pieces_fmt." pieces";
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-900">
                                  1 × <?php echo htmlspecialchars($row['peetu_name']); ?>
                                  <span class="px-2">→</span>
                                  <?php
                                      $packs = (float)$row['quantity']; // packs per 1 peetu
                                      echo (fmod($packs, 1.0) == 0.0) ? number_format($packs, 0) : number_format($packs, 3);
                                  ?>
                                  × <?php echo htmlspecialchars($grp['item_name']); ?>
                                </span>
                                <div class="flex gap-1">
                                    <button class="text-indigo-600 hover:text-indigo-900" title="Edit"
                                        onclick='editBomProduct(<?php echo json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this BOM Product entry?')">
                                        <input type="hidden" name="action" value="delete_bom_product">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900" title="Delete">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-lg shadow p-8 text-center">
                <h3 class="text-lg font-medium text-gray-900 mb-2">No BOM Product entries</h3>
                <p class="text-gray-600 mb-4">Map your Finished items to Peetu (semi-finished) components.</p>
                <button onclick="openCreateBomProduct()" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition-colors">
                    Add BOM Product
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================ Modals ============================ -->

<!-- Create Peetu Modal -->
<div id="createBomModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-[40rem] max-w-[95vw] shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add Peetu Entry</h3>
            <button onclick="closeModal('createBomModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" class="space-y-4" onsubmit="return validatePeetuForm()">
            <input type="hidden" name="action" value="create_peetu">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Semi-Finished (Peetu)</label>
                <select name="peetu_item_id" id="create_peetu_item_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Semi-Finished Item</option>
                    <?php foreach ($semi_finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'].' ('.$item['code'].')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-sm font-medium text-gray-700">Raw Materials & Quantity (per 1 Peetu)</label>
                    <button type="button" onclick="addRawRow()" class="bg-green-600 text-white px-2 py-1 rounded text-sm hover:bg-green-700">+ Add Row</button>
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

            <div class="flex justify-end gap-3 pt-2">
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
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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
            <div class="flex justify-end gap-3 pt-4">
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
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="copy_bom">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Source Peetu (Copy From)</label>
                <select name="source_item_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Semi-Finished Item</option>
                    <?php foreach ($semi_finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'].' ('.$item['code'].')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Target Peetu (Copy To)</label>
                <select name="target_item_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Semi-Finished Item</option>
                    <?php foreach ($semi_finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'].' ('.$item['code'].')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-md p-3">
                <p class="text-sm text-blue-800"><strong>Note:</strong> Copies all raw entries from source to target. Existing pairs will be updated with the source quantity.</p>
            </div>
            <div class="flex justify-end gap-3 pt-4">
                <button type="button" onclick="closeModal('copyBomModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Copy</button>
            </div>
        </form>
    </div>
</div>

<!-- Create BOM Product Modal -->
<div id="createBomProductModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-16 mx-auto p-5 border w-[42rem] max-w-[95vw] shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add BOM Product</h3>
            <button onclick="closeModal('createBomProductModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" class="space-y-4" onsubmit="return validateBomProductForm()">
            <input type="hidden" name="action" value="create_bom_product">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Finished Item</label>
                <select name="finished_item_id" id="bp_finished_item_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Finished Item</option>
                    <?php foreach ($finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'].' ('.$item['code'].')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-sm font-medium text-gray-700">
                        Peetu & Yield <span class="text-gray-500">(finished packs per 1 Peetu)</span>
                    </label>
                    <button type="button" onclick="addBomProdRow()" class="bg-green-600 text-white px-2 py-1 rounded text-sm hover:bg-green-700">+ Add Row</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300" id="bomProdTable">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Peetu (Semi-Finished)</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Unit Qty (kg)</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Total Qty (kg)</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Qty (pieces)</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase border">Action</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                  Enter <strong>Unit Qty (kg)</strong> and <strong>Total Qty (kg)</strong>. We auto-calc pieces as <em>Total ÷ Unit</em> and fill <strong>Qty (pieces)</strong>.
                </p>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal('createBomProductModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit BOM Product Modal -->
<div id="editBomProductModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Edit BOM Product Entry</h3>
            <button onclick="closeModal('editBomProductModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update_bom_product">
            <input type="hidden" name="id" id="edit_bp_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Finished</label>
                <input type="text" id="edit_bp_finished" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Peetu</label>
                <input type="text" id="edit_bp_peetu" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity (finished packs per 1 Peetu)</label>
                <input type="number" name="quantity" step="0.001" id="edit_bp_qty" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div class="flex justify-end gap-3 pt-4">
                <button type="button" onclick="closeModal('editBomProductModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Update Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================ Scripts ============================ -->
<script>
/* ---------- Peetu (Semi-Finished → Raw) ---------- */
const RAWS = <?php echo json_encode($raw_materials ?? []); ?>;

function rawSelectHTML() {
    let opts = '<option value="">Select Raw Material</option>';
    (RAWS || []).forEach(r => {
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
    const select = tr.querySelector('.raw-select');
    if (select) syncRowUnit(select);
}

function removeRawRow(btn) {
    const tb = document.querySelector('#peetuRawTable tbody');
    if (tb.children.length <= 1) { alert('At least one row is required.'); return; }
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
    for (const r of raws) {
        const sel = r.querySelector('.raw-select');
        const qty = r.querySelector('input[name="quantity[]"]');
        if (!sel.value || !qty.value || parseFloat(qty.value) <= 0) {
            alert('Each row must have a raw material and a positive quantity.');
            return false;
        }
    }
    return true;
}

function openCreatePeetu() {
    document.getElementById('create_peetu_item_id').value = '';
    const tb = document.querySelector('#peetuRawTable tbody');
    tb.innerHTML = '';
    addRawRow();
    openModal('createBomModal');
}

function addBomMaterial(itemId) {
    document.getElementById('create_peetu_item_id').value = itemId;
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

/* ---------- BOM Product (Finished → Peetu) ---------- */
const PEETU = <?php echo json_encode($semi_finished_items ?? []); ?>;
const FINISHED_ITEMS = <?php echo json_encode($finished_items ?? []); ?>; // used for optional prefill

function peetuSelectHTML() {
    let opts = '<option value="">Select Peetu</option>';
    (PEETU || []).forEach(p => {
        const label = `${p.name || ''} (${p.code || ''})`;
        opts += `<option value="${p.id}">${escapeHtml(label)}</option>`;
    });
    return `
        <select name="bom_peetu_id[]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" required>
            ${opts}
        </select>
    `;
}

function addBomProdRow() {
    const tb = document.querySelector('#bomProdTable tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="px-3 py-2 border">
            ${peetuSelectHTML()}
            <div class="text-[11px] text-gray-500 mt-1 peetu-hint"></div>
        </td>
        <td class="px-3 py-2 border">
            <input type="number" name="bp_unit_qty[]" step="0.001"
                   class="w-full px-2 py-1 border border-gray-300 rounded text-sm unit-qty"
                   placeholder="e.g. 4.000" oninput="calculatePieces(this)">
        </td>
        <td class="px-3 py-2 border">
            <input type="number" name="bp_total_qty[]" step="0.001"
                   class="w-full px-2 py-1 border border-gray-300 rounded text-sm total-qty"
                   placeholder="e.g. 32.000" oninput="calculatePieces(this)">
            <div class="text-[11px] text-gray-500 mt-1 formula"></div>
        </td>
        <td class="px-3 py-2 border">
            <input type="number" name="bom_peetu_qty[]" step="0.001"
                   class="w-full px-2 py-1 border border-gray-300 rounded text-sm pieces"
                   placeholder="auto" readonly>
        </td>
        <td class="px-3 py-2 border text-center">
            <button type="button" class="text-red-600 hover:text-red-800 text-sm" onclick="removeBomProdRow(this)">Remove</button>
        </td>
    `;
    tb.appendChild(tr);

    prefillRowFromSelections(tr);
    tr.querySelector('select[name="bom_peetu_id[]"]').addEventListener('change', () => prefillRowFromSelections(tr));
}

function removeBomProdRow(btn) {
    const tb = document.querySelector('#bomProdTable tbody');
    if (tb.children.length <= 1) { alert('At least one row is required.'); return; }
    btn.closest('tr').remove();
}

function openCreateBomProduct() {
    document.getElementById('bp_finished_item_id').value = '';
    const tb = document.querySelector('#bomProdTable tbody');
    tb.innerHTML = '';
    addBomProdRow();
    openModal('createBomProductModal');
}

function editBomProduct(row) {
    document.getElementById('edit_bp_id').value = row.id;
    document.getElementById('edit_bp_finished').value = (row.finished_name || '') + ' (' + (row.finished_code || '') + ')';
    document.getElementById('edit_bp_peetu').value = (row.peetu_name || '') + ' (' + (row.peetu_code || '') + ')';
    document.getElementById('edit_bp_qty').value = row.quantity;
    openModal('editBomProductModal');
}

function validateBomProductForm() {
    const finished = document.getElementById('bp_finished_item_id').value;
    if (!finished) { alert('Please select a Finished item.'); return false; }
    const rows = document.querySelectorAll('#bomProdTable tbody tr');
    if (!rows.length) { alert('Please add at least one Peetu row.'); return false; }
    for (const r of rows) {
        const sel   = r.querySelector('select[name="bom_peetu_id[]"]');
        const qty   = r.querySelector('input[name="bom_peetu_qty[]"]');
        const unit  = r.querySelector('.unit-qty');
        const total = r.querySelector('.total-qty');

        if (!sel.value || !qty.value || parseFloat(qty.value) <= 0) {
            alert('Each row must have a Peetu and a positive pieces quantity.');
            return false;
        }
        if (unit.value && total.value) {
            const u = parseFloat(unit.value || '0');
            const t = parseFloat(total.value || '0');
            if (u > 0 && t > 0 && t + 1e-12 < u) {
                alert('Total quantity must be greater than or equal to Unit quantity.');
                return false;
            }
        }
    }
    return true;
}

/* ---------- Demo cost calc (client placeholder) ---------- */
function calculateItemCost(itemId) {
    document.getElementById('costCalculationContent').innerHTML = `
        <div class="text-center py-4">
            <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
            <p class="mt-2 text-gray-600">Calculating cost...</p>
        </div>
    `;
    openModal('costCalculationModal');

    setTimeout(() => {
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
                    <p class="text-xs text-yellow-800"><strong>Note:</strong> Placeholder only. Wire to server action=calculate_cost for exact raw costs from GRN.</p>
                </div>
                <div class="flex justify-end"><button onclick="closeModal('costCalculationModal')" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Close</button></div>
            </div>
        `;
    }, 500);
}

/* ---------- Calculator helpers ---------- */
function getSelectedFinished() {
  const sel = document.getElementById('bp_finished_item_id');
  const id  = parseInt(sel?.value || '0', 10);
  return (FINISHED_ITEMS || []).find(it => parseInt(it.id,10) === id);
}
function getPeetuById(id) {
  return (PEETU || []).find(p => parseInt(p.id,10) === parseInt(id,10));
}
function prefillRowFromSelections(tr) {
  const fin = getSelectedFinished();
  const unitInput  = tr.querySelector('.unit-qty');
  const totalInput = tr.querySelector('.total-qty');
  const peetuSel   = tr.querySelector('select[name="bom_peetu_id[]"]');
  const peetu      = getPeetuById(peetuSel?.value || 0);
  const hint       = tr.querySelector('.peetu-hint');

  // Optional: if your items table has unit_weight_kg columns, these will prefill. If not, user can type.
  if (fin && typeof fin.unit_weight_kg !== 'undefined' && fin.unit_weight_kg && !unitInput.value) {
    unitInput.value = parseFloat(fin.unit_weight_kg).toFixed(3);
  }
  if (peetu && typeof peetu.unit_weight_kg !== 'undefined' && peetu.unit_weight_kg && !totalInput.value) {
    totalInput.value = parseFloat(peetu.unit_weight_kg).toFixed(3);
  }
  hint.textContent = (peetu && peetu.unit_weight_kg)
      ? `1 Peetu ≈ ${Number(peetu.unit_weight_kg).toFixed(3)} kg`
      : '';

  calculatePieces(unitInput);
}
function calculatePieces(el) {
  const tr = el.closest('tr');
  const unit = parseFloat(tr.querySelector('.unit-qty')?.value || '0');
  const total = parseFloat(tr.querySelector('.total-qty')?.value || '0');
  const piecesInput = tr.querySelector('.pieces');
  const formula = tr.querySelector('.formula');

  if (unit > 0 && total > 0 && total + 1e-12 < unit) {
    formula.textContent = 'Total must be ≥ Unit';
    piecesInput.value = '';
    return;
  }
  if (unit > 0 && total > 0) {
    const pieces = total / unit;
    piecesInput.value = pieces.toFixed(3);
    formula.textContent = `${total.toFixed(3)}kg ÷ ${unit.toFixed(3)}kg = ${(pieces % 1 === 0 ? pieces.toFixed(0) : pieces.toFixed(3))} pieces`;
  } else {
    formula.textContent = '';
  }
}

/* ---------- Utils ---------- */
function escapeHtml(s){return String(s).replace(/[&<>"']/g,m=>({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;" }[m]));}

document.addEventListener('DOMContentLoaded', function () {
    // Ensure at least one row appears in both create tables on first open
    const tb1 = document.querySelector('#peetuRawTable tbody');
    if (tb1 && !tb1.children.length) addRawRow();
    const tb2 = document.querySelector('#bomProdTable tbody');
    if (tb2 && !tb2.children.length) addBomProdRow();

    // Re-prefill all visible rows when finished item changes
    document.getElementById('bp_finished_item_id')?.addEventListener('change', () => {
      document.querySelectorAll('#bomProdTable tbody tr').forEach(tr => prefillRowFromSelections(tr));
    });
});
</script>

<!-- Optional tiny modal used by calculateItemCost() demo -->
<div id="costCalculationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
  <div class="relative top-24 mx-auto p-5 border w-[26rem] max-w-[95vw] shadow-lg rounded-md bg-white">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-lg font-bold text-gray-900">Cost Calculation</h3>
      <button onclick="closeModal('costCalculationModal')" class="text-gray-400 hover:text-gray-600">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div id="costCalculationContent"></div>
  </div>
</div>

<?php include 'footer.php'; ?>
