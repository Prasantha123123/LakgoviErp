<?php
// bom.php - Peetu (Semi-Finished) & BOM Product mappings
include 'header.php';

/* =========================== Helpers =========================== */
function safe_arr($v) { return is_array($v) ? $v : []; }
function as_int_or_zero($v){ return is_numeric($v) ? (int)$v : 0; }
function as_float_or_zero($v){ 
    if (!is_numeric($v)) {
        // Try to normalize decimal separator (in case comma is used instead of dot)
        $normalized = str_replace(',', '.', (string)$v);
        if (is_numeric($normalized)) {
            return (float)$normalized;
        }
        return 0.0;
    }
    return (float)$v; 
}

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
                    $category_ids  = safe_arr($_POST['category_id'] ?? []);
                    $qtys          = safe_arr($_POST['quantity'] ?? []);

                    if ($peetu_item_id <= 0) {
                        throw new Exception("Please select a Semi-Finished (Peetu) item.");
                    }
                    
                    // Filter out empty values and get count of valid entries
                    $raw_ids_clean = array_filter($raw_ids, function($v) { return !empty($v); });
                    $category_ids_clean = array_filter($category_ids, function($v) { return !empty($v); });
                    
                    if (empty($raw_ids_clean) && empty($category_ids_clean)) {
                        throw new Exception("Please add at least one raw material or category row.");
                    }

                    $db->beginTransaction();
                    $inserted = 0;
                    $processed_materials = []; // Track processed materials to prevent duplicates
                    $processed_categories = []; // Track processed categories to prevent duplicates

                    // Process each quantity entry
                    $num_rows = count($qtys);
                    for ($i = 0; $i < $num_rows; $i++) {
                        $rid = isset($raw_ids[$i]) && !empty($raw_ids[$i]) ? as_int_or_zero($raw_ids[$i]) : 0;
                        $cid = isset($category_ids[$i]) && !empty($category_ids[$i]) ? as_int_or_zero($category_ids[$i]) : 0;
                        $q   = as_float_or_zero($qtys[$i] ?? 0);
                        
                        // Each row must have either a raw material OR a category (not both)
                        if (($rid > 0 || $cid > 0) && $q > 0) {
                            // Check for duplicates in the current submission
                            if ($rid > 0 && in_array($rid, $processed_materials)) {
                                continue; // Skip duplicate material
                            }
                            if ($cid > 0 && in_array($cid, $processed_categories)) {
                                continue; // Skip duplicate category
                            }
                            // Upsert by (peetu_item_id, raw_material_id or category_id)
                            if ($rid > 0) {
                                // Raw material entry
                                $stmt = $db->prepare("SELECT id FROM bom_peetu WHERE peetu_item_id = ? AND raw_material_id = ? AND category_id IS NULL");
                                $stmt->execute([$peetu_item_id, $rid]);
                                $exists = $stmt->fetch(PDO::FETCH_ASSOC);

                                if ($exists) {
                                    $stmt = $db->prepare("UPDATE bom_peetu SET quantity = ? WHERE id = ?");
                                    $stmt->execute([$q, $exists['id']]);
                                } else {
                                    $stmt = $db->prepare("INSERT INTO bom_peetu (peetu_item_id, raw_material_id, category_id, quantity) VALUES (?, ?, NULL, ?)");
                                    $stmt->execute([$peetu_item_id, $rid, $q]);
                                }
                                $processed_materials[] = $rid; // Mark as processed
                                $inserted++;
                            } else if ($cid > 0) {
                                // Category entry
                                $stmt = $db->prepare("SELECT id FROM bom_peetu WHERE peetu_item_id = ? AND category_id = ? AND raw_material_id IS NULL");
                                $stmt->execute([$peetu_item_id, $cid]);
                                $exists = $stmt->fetch(PDO::FETCH_ASSOC);

                                if ($exists) {
                                    $stmt = $db->prepare("UPDATE bom_peetu SET quantity = ? WHERE id = ?");
                                    $stmt->execute([$q, $exists['id']]);
                                } else {
                                    $stmt = $db->prepare("INSERT INTO bom_peetu (peetu_item_id, raw_material_id, category_id, quantity) VALUES (?, NULL, ?, ?)");
                                    $stmt->execute([$peetu_item_id, $cid, $q]);
                                }
                                $processed_categories[] = $cid; // Mark as processed
                                $inserted++;
                            }
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

                /* ---------------------------
                 *  Direct BOM (Finished → Raw Materials)
                 * --------------------------- */

                // Create direct BOM (finished product made directly from raw materials)
                case 'create_direct_bom':
                    $finished_item_id = as_int_or_zero($_POST['finished_item_id'] ?? 0);
                    $finished_unit_qty = as_float_or_zero($_POST['finished_unit_qty'] ?? 1.0);
                    $raw_ids = safe_arr($_POST['raw_material_id'] ?? []);
                    $category_ids = safe_arr($_POST['category_id'] ?? []);
                    $qtys = safe_arr($_POST['quantity'] ?? []);

                    if ($finished_item_id <= 0) throw new Exception("Please select a Finished item.");
                    if ($finished_unit_qty <= 0) throw new Exception("Please enter a valid unit quantity for the finished product.");
                    
                    // Filter out empty values
                    $raw_ids_clean = array_filter($raw_ids, function($v) { return !empty($v); });
                    $category_ids_clean = array_filter($category_ids, function($v) { return !empty($v); });
                    
                    if (empty($raw_ids_clean) && empty($category_ids_clean)) {
                        throw new Exception("Please add at least one raw material or category row.");
                    }

                    $db->beginTransaction();
                    $added = 0;
                    $num_rows = count($qtys);
                    for ($i = 0; $i < $num_rows; $i++) {
                        $rid = isset($raw_ids[$i]) && !empty($raw_ids[$i]) ? as_int_or_zero($raw_ids[$i]) : 0;
                        $cid = isset($category_ids[$i]) && !empty($category_ids[$i]) ? as_int_or_zero($category_ids[$i]) : 0;
                        $q = as_float_or_zero($qtys[$i] ?? 0);
                        
                        if (($rid > 0 || $cid > 0) && $q > 0) {
                            if ($rid > 0) {
                                // Raw material entry
                                $chk = $db->prepare("SELECT id FROM bom_direct WHERE finished_item_id = ? AND raw_material_id = ? AND category_id IS NULL");
                                $chk->execute([$finished_item_id, $rid]);
                                if ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
                                    $upd = $db->prepare("UPDATE bom_direct SET quantity = ?, finished_unit_qty = ? WHERE id = ?");
                                    $upd->execute([$q, $finished_unit_qty, $row['id']]);
                                } else {
                                    $ins = $db->prepare("INSERT INTO bom_direct (finished_item_id, finished_unit_qty, raw_material_id, category_id, quantity) VALUES (?, ?, ?, NULL, ?)");
                                    $ins->execute([$finished_item_id, $finished_unit_qty, $rid, $q]);
                                }
                                $added++;
                            } else if ($cid > 0) {
                                // Category entry
                                $chk = $db->prepare("SELECT id FROM bom_direct WHERE finished_item_id = ? AND category_id = ? AND raw_material_id IS NULL");
                                $chk->execute([$finished_item_id, $cid]);
                                if ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
                                    $upd = $db->prepare("UPDATE bom_direct SET quantity = ?, finished_unit_qty = ? WHERE id = ?");
                                    $upd->execute([$q, $finished_unit_qty, $row['id']]);
                                } else {
                                    $ins = $db->prepare("INSERT INTO bom_direct (finished_item_id, finished_unit_qty, raw_material_id, category_id, quantity) VALUES (?, ?, NULL, ?, ?)");
                                    $ins->execute([$finished_item_id, $finished_unit_qty, $cid, $q]);
                                }
                                $added++;
                            }
                        }
                    }
                    $db->commit();
                    $success = $added > 0 ? "Direct BOM saved successfully! ($added row(s))" : "No valid rows to save.";
                    break;

                // Update direct BOM entry
                case 'update_direct_bom':
                    $id = as_int_or_zero($_POST['id'] ?? 0);
                    $q = as_float_or_zero($_POST['quantity'] ?? 0);
                    $finished_unit_qty = as_float_or_zero($_POST['finished_unit_qty'] ?? 1.0);
                    if ($id <= 0) throw new Exception("Invalid Direct BOM entry id.");
                    if ($finished_unit_qty <= 0) throw new Exception("Please enter a valid unit quantity for the finished product.");
                    $stmt = $db->prepare("UPDATE bom_direct SET quantity = ?, finished_unit_qty = ? WHERE id = ?");
                    $stmt->execute([$q, $finished_unit_qty, $id]);
                    $success = "Direct BOM entry updated successfully!";
                    break;

                // Delete direct BOM entry
                case 'delete_direct_bom':
                    $id = as_int_or_zero($_POST['id'] ?? 0);
                    if ($id <= 0) throw new Exception("Invalid Direct BOM entry id.");
                    $stmt = $db->prepare("DELETE FROM bom_direct WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Direct BOM entry deleted successfully!";
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

/* Categories for BOM - FETCH FIRST (needed for peetu_rows processing) */
$categories = [];
try {
    $stmt = $db->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(PDOException $e) {
    $error = "Error fetching categories: " . $e->getMessage();
    $categories = [];
}

/* Category stocks - FETCH FIRST (needed for peetu_rows processing) */
/* Now includes items from both items.category_id AND item_categories junction table */
$category_stocks = [];
try {
    $stmt = $db->query("
        SELECT c.id, c.name, COALESCE(SUM(
            COALESCE((SELECT SUM(sl.quantity_in - sl.quantity_out) FROM stock_ledger sl WHERE sl.item_id = all_items.item_id), 0)
        ), 0) as total_stock
        FROM categories c
        LEFT JOIN (
            -- Items with category_id (old way)
            SELECT DISTINCT i.id as item_id, i.category_id as cat_id
            FROM items i
            WHERE i.type = 'raw' AND i.category_id IS NOT NULL
            
            UNION
            
            -- Items with category from junction table (new multi-category way)
            SELECT DISTINCT ic.item_id, ic.category_id as cat_id
            FROM item_categories ic
            JOIN items i ON i.id = ic.item_id
            WHERE i.type = 'raw'
        ) as all_items ON all_items.cat_id = c.id
        GROUP BY c.id, c.name
    ");
    $category_stocks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(PDOException $e) {
    $error = "Error fetching category stocks: " . $e->getMessage();
    $category_stocks = [];
}

/* Peetu rows (semi-finished + raw requirements) */
$peetu_rows = [];
try {
    // First, let's see ALL bom_peetu entries for debugging
    $debug_stmt = $db->query("SELECT * FROM bom_peetu WHERE peetu_item_id = 96 ORDER BY id");
    $all_bom_entries = $debug_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("=== ALL BOM_PEETU ENTRIES FOR PEETU_ITEM_ID 96 ===");
    foreach ($all_bom_entries as $entry) {
        error_log("BOM ID={$entry['id']}, raw_material_id={$entry['raw_material_id']}, category_id={$entry['category_id']}, quantity={$entry['quantity']}");
    }
    
    // raw stock via stock_ledger (avoids relying on items.current_stock)
    $stmt = $db->query("
        SELECT bp.*,
               fi.name  AS peetu_name,  fi.code AS peetu_code,  COALESCE(fu.symbol, 'units') AS peetu_unit,
               rm.name  AS raw_material_name, rm.code AS raw_material_code, COALESCE(ru.symbol, 'units') AS raw_unit,
               cat.name AS category_name,
               COALESCE((SELECT SUM(sl.quantity_in - sl.quantity_out)
                         FROM stock_ledger sl
                         WHERE sl.item_id = rm.id), 0) AS raw_material_stock
        FROM bom_peetu bp
        JOIN items fi ON bp.peetu_item_id = fi.id AND fi.type = 'semi_finished'
        LEFT JOIN units fu ON fi.unit_id = fu.id
        LEFT JOIN items rm ON bp.raw_material_id = rm.id
        LEFT JOIN units ru ON ru.id = rm.unit_id
        LEFT JOIN categories cat ON bp.category_id = cat.id
        ORDER BY fi.name, rm.name, bp.id
    ");
    $peetu_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Debug: Check for duplicates in database
    $debug_duplicates = [];
    $seen_ids = [];
    foreach ($peetu_rows as $row) {
        // Check if this exact ID has been seen before (which would be a PHP bug)
        if (in_array($row['id'], $seen_ids)) {
            error_log("ERROR: Same BOM entry ID appearing multiple times in result set: ID={$row['id']}");
        }
        $seen_ids[] = $row['id'];
        
        // Check for duplicate combinations in database
        $key = $row['peetu_item_id'] . '_' . ($row['raw_material_id'] ?: 'NULL') . '_' . ($row['category_id'] ?: 'NULL');
        if (isset($debug_duplicates[$key])) {
            error_log("DUPLICATE COMBINATION in database: Peetu ID={$row['peetu_item_id']}, Raw Material ID={$row['raw_material_id']}, Category ID={$row['category_id']}, BOM IDs: {$debug_duplicates[$key]} and {$row['id']}");
        }
        $debug_duplicates[$key] = $row['id'];
    }
    
    error_log("Total peetu_rows fetched: " . count($peetu_rows));
    
    // Debug: Check for rows with missing item data
    foreach ($peetu_rows as $row) {
        if ($row['raw_material_id'] && empty($row['raw_material_name'])) {
            error_log("WARNING: BOM entry ID={$row['id']} has raw_material_id={$row['raw_material_id']} but no material name (item might not exist or have no unit)");
        }
    }
} catch(PDOException $e) {
    $error = "Error fetching Peetu entries: " . $e->getMessage();
    $peetu_rows = [];
}

// Adjust for category rows
foreach ($peetu_rows as &$r) {
    if ($r['category_id'] && !$r['raw_material_id']) {
        $cat = null;
        foreach ($category_stocks as $c) {
            if ($c['id'] == $r['category_id']) {
                $cat = $c;
                break;
            }
        }
        $r['raw_material_stock'] = $cat ? (float)$cat['total_stock'] : 0;
        $r['raw_material_name'] = $r['category_name'];
        $r['raw_material_code'] = '';
        $r['raw_unit'] = 'units';
    }
    // Handle rows where raw_material_id exists but item lookup failed
    elseif ($r['raw_material_id'] && empty($r['raw_material_name'])) {
        error_log("FIXING MISSING ITEM: BOM ID={$r['id']}, raw_material_id={$r['raw_material_id']}");
        $r['raw_material_name'] = "Missing Item (ID: {$r['raw_material_id']})";
        $r['raw_material_code'] = 'MISSING';
        $r['raw_unit'] = 'units';
        $r['raw_material_stock'] = 0;
    }
}
unset($r); // CRITICAL: Break the reference to prevent bugs

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

/* Raw materials (dropdown for Peetu create) - includes category_id and using_category */
$raw_materials = [];
try {
    $stmt = $db->query("
        SELECT 
            i.*, 
            u.symbol, 
            i.category_id, 
            i.using_category,
            GROUP_CONCAT(DISTINCT ic.category_id) AS all_category_ids,
            GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') AS all_category_names
        FROM items i 
        JOIN units u ON i.unit_id = u.id 
        LEFT JOIN item_categories ic ON ic.item_id = i.id
        LEFT JOIN categories c ON c.id = ic.category_id
        WHERE i.type = 'raw' 
        GROUP BY i.id
        ORDER BY i.name
    ");
    $raw_materials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Convert all_category_ids from string to array for each item
    foreach ($raw_materials as &$raw) {
        if (!empty($raw['all_category_ids'])) {
            $raw['category_ids_array'] = explode(',', $raw['all_category_ids']);
        } else {
            $raw['category_ids_array'] = [];
        }
    }
} catch(PDOException $e) {
    $error = "Error fetching raw materials: " . $e->getMessage();
    $raw_materials = [];
}

/* Pagination and Tab Variables */
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'peetu'; // peetu, direct_bom, bom_product

/* Group Peetu rows by peetu (semi-finished) */
$grouped_peetu = [];
// Debug: Log the raw rows before grouping
error_log("=== PEETU ROWS BEFORE GROUPING ===");
foreach ($peetu_rows as $idx => $row) {
    error_log("Row $idx: ID={$row['id']}, Peetu={$row['peetu_item_id']}, Material={$row['raw_material_id']}, Name={$row['raw_material_name']}");
}

foreach ($peetu_rows as $r) {
    $key = $r['peetu_item_id'];
    error_log("GROUPING: Processing row ID={$r['id']}, Material={$r['raw_material_id']}, Name={$r['raw_material_name']}");
    
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
    error_log("  -> Added to grouped_peetu[$key], now has " . count($grouped_peetu[$key]['materials']) . " materials");
    
    if ((float)$r['raw_material_stock'] < (float)$r['quantity']) {
        $grouped_peetu[$key]['can_produce'] = false;
    }
}

// Debug: Log grouped materials
error_log("=== GROUPED MATERIALS ===");
foreach ($grouped_peetu as $peetu_id => $peetu_data) {
    error_log("Peetu ID $peetu_id ({$peetu_data['item_name']}): " . count($peetu_data['materials']) . " materials");
    foreach ($peetu_data['materials'] as $mat) {
        error_log("  - ID={$mat['id']}, Material={$mat['raw_material_id']}, Name={$mat['raw_material_name']}");
    }
}

// IMPORTANT FIX: Remove any duplicate IDs from the materials array
foreach ($grouped_peetu as $key => &$peetu_data) {
    $seen_bom_ids = [];
    $unique_materials = [];
    
    error_log("Processing Peetu ID $key: " . count($peetu_data['materials']) . " materials before dedup");
    
    foreach ($peetu_data['materials'] as $material) {
        $bom_id = $material['id'];
        error_log("  Checking BOM ID={$bom_id}, Material={$material['raw_material_id']}, Name={$material['raw_material_name']}");
        
        // Only add if we haven't seen this BOM entry ID before
        if (!in_array($bom_id, $seen_bom_ids)) {
            $unique_materials[] = $material;
            $seen_bom_ids[] = $bom_id;
            error_log("    -> ADDED (unique)");
        } else {
            error_log("    -> SKIPPED (duplicate BOM ID)");
        }
    }
    
    error_log("After dedup: " . count($unique_materials) . " unique materials");
    
    // Replace materials array with deduplicated version
    $peetu_data['materials'] = $unique_materials;
    $peetu_data['total_materials'] = count($unique_materials);
}
unset($peetu_data); // Break reference

/* Peetu stats */
$peetu_stats = [
    'total_boms'       => count($grouped_peetu),
    'total_entries'    => count($peetu_rows),
    'can_produce'      => count(array_filter($grouped_peetu, fn($b) => $b['can_produce'])),
    'cannot_produce'   => count(array_filter($grouped_peetu, fn($b) => !$b['can_produce']))
];

/* Paginate Peetu */
$total_peetu = count($grouped_peetu);
$total_peetu_pages = ceil($total_peetu / $items_per_page);
$peetu_offset = ($current_page - 1) * $items_per_page;
$grouped_peetu_paginated = array_slice($grouped_peetu, $peetu_offset, $items_per_page, true);

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

/* Direct BOM rows (Finished → Raw directly) */
$direct_bom_rows = [];
try {
    $stmt = $db->query("
        SELECT bd.*,
               f.name AS finished_name, f.code AS finished_code, fu.symbol AS finished_unit,
               r.name AS raw_name, r.code AS raw_code, ru.symbol AS raw_unit,
               cat.name AS category_name,
               COALESCE((
                   SELECT SUM(sl.quantity_in - sl.quantity_out)
                   FROM stock_ledger sl
                   WHERE sl.item_id = r.id
               ), 0) AS raw_stock
        FROM bom_direct bd
        JOIN items f  ON f.id = bd.finished_item_id AND f.type = 'finished'
        JOIN units fu ON fu.id = f.unit_id
        LEFT JOIN items r  ON r.id = bd.raw_material_id AND r.type = 'raw'
        LEFT JOIN units ru ON ru.id = r.unit_id
        LEFT JOIN categories cat ON bd.category_id = cat.id
        ORDER BY f.name, r.name
    ");
    $direct_bom_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(PDOException $e) {
    $error = "Error fetching Direct BOM entries: " . $e->getMessage();
    $direct_bom_rows = [];
}

// Adjust for category rows in Direct BOM
foreach ($direct_bom_rows as &$r) {
    if ($r['category_id'] && !$r['raw_material_id']) {
        $cat = null;
        foreach ($category_stocks as $c) {
            if ($c['id'] == $r['category_id']) {
                $cat = $c;
                break;
            }
        }
        $r['raw_stock'] = $cat ? (float)$cat['total_stock'] : 0;
        $r['raw_name'] = $r['category_name'];
        $r['raw_code'] = '';
        $r['raw_unit'] = 'units';
    }
}
unset($r); // CRITICAL: Break the reference to prevent bugs

/* Group Direct BOM by finished item */
$grouped_direct_bom = [];
foreach ($direct_bom_rows as $r) {
    $key = $r['finished_item_id'];
    if (!isset($grouped_direct_bom[$key])) {
        $grouped_direct_bom[$key] = [
            'item_id'         => $r['finished_item_id'],
            'item_name'       => $r['finished_name'],
            'item_code'       => $r['finished_code'],
            'item_unit'       => $r['finished_unit'],
            'materials'       => [],
            'total_materials' => 0,
            'can_produce'     => true
        ];
    }
    $grouped_direct_bom[$key]['materials'][] = $r;
    $grouped_direct_bom[$key]['total_materials']++;
    if ((float)$r['raw_stock'] < (float)$r['quantity']) {
        $grouped_direct_bom[$key]['can_produce'] = false;
    }
}

/* Pagination and Tab Variables */
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'peetu'; // peetu, direct_bom, bom_product

/* Paginate Peetu */
$total_peetu = count($grouped_peetu);
$total_peetu_pages = $total_peetu > 0 ? ceil($total_peetu / $items_per_page) : 1;
$peetu_offset = ($current_page - 1) * $items_per_page;
$grouped_peetu_paginated = array_slice($grouped_peetu, $peetu_offset, $items_per_page, true);

/* Paginate Direct BOM */
$total_direct_bom = count($grouped_direct_bom);
$total_direct_bom_pages = $total_direct_bom > 0 ? ceil($total_direct_bom / $items_per_page) : 1;
$direct_bom_offset = ($current_page - 1) * $items_per_page;
$grouped_direct_bom_paginated = array_slice($grouped_direct_bom, $direct_bom_offset, $items_per_page, true);

/* Paginate BOM Product */
$total_bom_product = count($grouped_bomprod);
$total_bom_product_pages = $total_bom_product > 0 ? ceil($total_bom_product / $items_per_page) : 1;
$bom_product_offset = ($current_page - 1) * $items_per_page;
$grouped_bomprod_paginated = array_slice($grouped_bomprod, $bom_product_offset, $items_per_page, true);
?>

<div class="space-y-10">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Bill of Materials (BOM)</h1>
            <p class="text-gray-600">Manage Peetu, Direct BOM, and BOM Product mappings</p>
        </div>
        <div class="flex gap-2">
            <button onclick="openModal('copyBomModal')" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors">
                Copy Peetu Map
            </button>
            <?php if ($active_tab === 'peetu'): ?>
                <button onclick="openCreatePeetu()" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                    Add Peetu Entry
                </button>
            <?php elseif ($active_tab === 'direct_bom'): ?>
                <button onclick="openCreateDirectBom()" class="bg-purple-600 text-white px-4 py-2 rounded-md hover:bg-purple-700 transition-colors">
                    Add Direct BOM
                </button>
            <?php elseif ($active_tab === 'bom_product'): ?>
                <button onclick="openCreateBomProduct()" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition-colors">
                    Add BOM Product
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="bg-white shadow rounded-lg">
        <div class="flex border-b border-gray-200">
            <a href="?tab=peetu&page=1" class="<?php echo $active_tab === 'peetu' ? 'bg-blue-50 border-b-2 border-blue-600 text-blue-600' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'; ?> px-6 py-3 font-medium text-sm transition-colors">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>Peetu (Semi-Finished)</span>
                    <span class="bg-gray-200 text-gray-700 px-2 py-0.5 rounded-full text-xs"><?php echo $total_peetu; ?></span>
                </div>
            </a>
            <a href="?tab=direct_bom&page=1" class="<?php echo $active_tab === 'direct_bom' ? 'bg-purple-50 border-b-2 border-purple-600 text-purple-600' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'; ?> px-6 py-3 font-medium text-sm transition-colors">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span>Direct BOM</span>
                    <span class="bg-gray-200 text-gray-700 px-2 py-0.5 rounded-full text-xs"><?php echo $total_direct_bom; ?></span>
                </div>
            </a>
            <a href="?tab=bom_product&page=1" class="<?php echo $active_tab === 'bom_product' ? 'bg-indigo-50 border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'; ?> px-6 py-3 font-medium text-sm transition-colors">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    <span>BOM Product</span>
                    <span class="bg-gray-200 text-gray-700 px-2 py-0.5 rounded-full text-xs"><?php echo $total_bom_product; ?></span>
                </div>
            </a>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Tab Content -->   <?php if ($active_tab === 'peetu'): ?>
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
    <?php if (!empty($grouped_peetu_paginated)): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <?php foreach ($grouped_peetu_paginated as $item_bom): ?>
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
                                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($material['raw_material_name']); ?> 
                                    <span class="text-xs text-gray-400">[ID: <?php echo (int)$material['id']; ?>, Material: <?php echo (int)$material['raw_material_id']; ?>]</span>
                                </p>
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

    <!-- Peetu Pagination -->
    <?php if ($total_peetu_pages > 1): ?>
    <div class="flex justify-center items-center gap-2 mt-6">
        <?php if ($current_page > 1): ?>
            <a href="?tab=peetu&page=<?php echo $current_page - 1; ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-md hover:bg-gray-50">Previous</a>
        <?php endif; ?>
        
        <?php for ($i = 1; $i <= $total_peetu_pages; $i++): ?>
            <?php if ($i == 1 || $i == $total_peetu_pages || abs($i - $current_page) <= 2): ?>
                <a href="?tab=peetu&page=<?php echo $i; ?>" class="px-4 py-2 <?php echo $i == $current_page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 hover:bg-gray-50'; ?> rounded-md">
                    <?php echo $i; ?>
                </a>
            <?php elseif (abs($i - $current_page) == 3): ?>
                <span class="px-2 py-2 text-gray-500">...</span>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($current_page < $total_peetu_pages): ?>
            <a href="?tab=peetu&page=<?php echo $current_page + 1; ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-md hover:bg-gray-50">Next</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; // End Peetu Tab ?>

    <!-- ====================== DIRECT BOM SECTION ====================== -->
    <?php if ($active_tab === 'direct_bom'): ?>
    <div>
        <h2 class="text-2xl font-bold text-gray-900 mb-4">Direct BOM (Finished → Raw Materials)</h2>
        <p class="text-gray-600 mb-6">Finished products made directly from raw materials without Peetu intermediates.</p>

        <?php if (!empty($grouped_direct_bom_paginated)): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <?php foreach ($grouped_direct_bom_paginated as $item_bom): ?>
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
                                <span class="inline-block mt-1 bg-purple-100 text-purple-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                    <?php 
                                        // Get unit quantity from first material (they should all have the same finished_unit_qty)
                                        $unit_qty = isset($item_bom['materials'][0]['finished_unit_qty']) ? (float)$item_bom['materials'][0]['finished_unit_qty'] : 1.0;
                                        echo number_format($unit_qty, 3) . 'kg → 1pc'; 
                                    ?> (Direct)
                                </span>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <h4 class="text-sm font-medium text-gray-700">Raw Materials Required:</h4>
                            <?php foreach ($item_bom['materials'] as $material): ?>
                            <div class="flex justify-between items-center p-3 <?php echo ((float)$material['raw_stock'] < (float)$material['quantity']) ? 'bg-red-50 border border-red-200' : 'bg-gray-50'; ?> rounded-md">
                                <div class="flex-1">
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($material['raw_name']); ?></p>
                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($material['raw_code']); ?></p>
                                    <p class="text-xs <?php echo ((float)$material['raw_stock'] < (float)$material['quantity']) ? 'text-red-600' : 'text-gray-500'; ?>">
                                        Available: <?php echo number_format((float)$material['raw_stock'], 3); ?> <?php echo $material['raw_unit']; ?>
                                    </p>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-sm font-medium <?php echo ((float)$material['raw_stock'] < (float)$material['quantity']) ? 'text-red-900' : 'text-gray-900'; ?>">
                                        <?php echo number_format((float)$material['quantity'], 3); ?> <?php echo $material['raw_unit']; ?>
                                    </span>
                                    <div class="flex space-x-1">
                                        <button onclick='editDirectBom(<?php echo json_encode($material, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' class="text-indigo-600 hover:text-indigo-900" title="Edit">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this direct BOM entry?')">
                                            <input type="hidden" name="action" value="delete_direct_bom">
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
                <h3 class="text-lg font-medium text-gray-900 mb-2">No direct BOM entries found</h3>
                <p class="text-gray-600 mb-4">Create direct mappings from finished products to raw materials.</p>
                <button onclick="openCreateDirectBom()" class="bg-purple-600 text-white px-4 py-2 rounded-md hover:bg-purple-700 transition-colors">Add Direct BOM</button>
            </div>
        <?php endif; ?>
        
        <!-- Direct BOM Pagination -->
        <?php if ($total_direct_bom_pages > 1): ?>
        <div class="flex justify-center items-center gap-2 mt-6">
            <?php if ($current_page > 1): ?>
                <a href="?tab=direct_bom&page=<?php echo $current_page - 1; ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-md hover:bg-gray-50">Previous</a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= $total_direct_bom_pages; $i++): ?>
                <?php if ($i == 1 || $i == $total_direct_bom_pages || abs($i - $current_page) <= 2): ?>
                    <a href="?tab=direct_bom&page=<?php echo $i; ?>" class="px-4 py-2 <?php echo $i == $current_page ? 'bg-purple-600 text-white' : 'bg-white border border-gray-300 hover:bg-gray-50'; ?> rounded-md">
                        <?php echo $i; ?>
                    </a>
                <?php elseif (abs($i - $current_page) == 3): ?>
                    <span class="px-2 py-2 text-gray-500">...</span>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($current_page < $total_direct_bom_pages): ?>
                <a href="?tab=direct_bom&page=<?php echo $current_page + 1; ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-md hover:bg-gray-50">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; // End Direct BOM Tab ?>

    <!-- ====================== BOM PRODUCT SECTION ====================== -->
    <?php if ($active_tab === 'bom_product'): ?>
    <div>
        <h2 class="text-2xl font-bold text-gray-900 mb-4">BOM Product (Finished → Peetu)</h2>

        <?php if (!empty($grouped_bomprod_paginated)): ?>
            <div class="grid grid-cols-1 gap-6">
                <?php foreach ($grouped_bomprod_paginated as $grp): ?>
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
        
        <!-- BOM Product Pagination -->
        <?php if ($total_bom_product_pages > 1): ?>
        <div class="flex justify-center items-center gap-2 mt-6">
            <?php if ($current_page > 1): ?>
                <a href="?tab=bom_product&page=<?php echo $current_page - 1; ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-md hover:bg-gray-50">Previous</a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= $total_bom_product_pages; $i++): ?>
                <?php if ($i == 1 || $i == $total_bom_product_pages || abs($i - $current_page) <= 2): ?>
                    <a href="?tab=bom_product&page=<?php echo $i; ?>" class="px-4 py-2 <?php echo $i == $current_page ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-300 hover:bg-gray-50'; ?> rounded-md">
                        <?php echo $i; ?>
                    </a>
                <?php elseif (abs($i - $current_page) == 3): ?>
                    <span class="px-2 py-2 text-gray-500">...</span>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($current_page < $total_bom_product_pages): ?>
                <a href="?tab=bom_product&page=<?php echo $current_page + 1; ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-md hover:bg-gray-50">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; // End BOM Product Tab ?>
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
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Raw Material / Category</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Qty</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Unit / Stock</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase border">Action</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    <strong>Tip:</strong> Add as many raw materials as needed. If a raw material has "Use Category for BOM" enabled, 
                    it will automatically switch to category selection.
                </p>
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

<!-- Create Direct BOM Modal -->
<div id="createDirectBomModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-16 mx-auto p-5 border w-[40rem] max-w-[95vw] shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add Direct BOM</h3>
            <button onclick="closeModal('createDirectBomModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" class="space-y-4" onsubmit="return validateDirectBomForm()">
            <input type="hidden" name="action" value="create_direct_bom">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Finished Product</label>
                <select name="finished_item_id" id="direct_finished_item_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                        onchange="onFinishedItemChange()">
                    <option value="">Select Finished Product</option>
                    <?php foreach ($finished_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>" data-unit="<?php echo htmlspecialchars($item['symbol']); ?>">
                            <?php echo htmlspecialchars($item['name'].' ('.$item['code'].')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Weight per Piece (kg)</label>
                <input type="number" step="0.001" min="0.001" name="finished_unit_qty" id="direct_finished_unit_qty" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                       placeholder="15.000">
                <p class="text-xs text-gray-500 mt-1">Enter the weight per piece (e.g., for 15kg packets, enter 15.000)</p>
            </div>

            <div>
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-sm font-medium text-gray-700">Raw Materials & Quantity (per piece)</label>
                    <button type="button" onclick="addDirectBomRow()" class="bg-green-600 text-white px-2 py-1 rounded text-sm hover:bg-green-700">+ Add Row</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300" id="directBomTable">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Raw Material / Category</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Qty</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border">Unit</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase border">Action</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    Add raw materials needed to make 1 piece of the finished product. 
                    If a raw material has "Use Category for BOM" enabled, it will switch to category selection.
                </p>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal('createDirectBomModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Direct BOM Modal -->
<div id="editDirectBomModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Edit Direct BOM Entry</h3>
            <button onclick="closeModal('editDirectBomModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update_direct_bom">
            <input type="hidden" name="id" id="edit_direct_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Finished Product</label>
                <input type="text" id="edit_direct_finished" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Weight per Piece (kg)</label>
                <input type="number" step="0.001" min="0.001" name="finished_unit_qty" id="edit_direct_finished_unit_qty" required 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Weight of 1 piece of this finished product</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Raw Material</label>
                <input type="text" id="edit_direct_raw" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity (per piece)</label>
                <input type="number" name="quantity" step="0.001" id="edit_direct_quantity" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div class="flex justify-end gap-3 pt-4">
                <button type="button" onclick="closeModal('editDirectBomModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700">Update Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================ Scripts ============================ -->
<script>
/* ---------- Peetu (Semi-Finished → Raw) ---------- */
const RAWS = <?php echo json_encode($raw_materials ?? []); ?>;
const CATEGORIES = <?php echo json_encode($categories ?? []); ?>;
const CATEGORY_STOCKS = <?php echo json_encode($category_stocks ?? []); ?>;

function rawSelectHTML() {
    // Build dropdown showing categories directly for category-enabled items
    // Now supports items with multiple categories
    let opts = '<option value="">Select Material / Category</option>';
    
    // Collect all unique categories from items that use categories
    const categoryItemsMap = new Map(); // catId -> [items]
    const nonCategorizedItems = [];
    
    (RAWS || []).forEach(r => {
        const useCat = parseInt(r.using_category || 0);
        
        if (useCat === 1) {
            // This item uses category mode - get ALL its categories
            const catIdsArray = r.category_ids_array || [];
            
            if (catIdsArray.length > 0) {
                // Add this item to each of its categories
                catIdsArray.forEach(catIdStr => {
                    const catId = parseInt(catIdStr);
                    if (catId > 0) {
                        if (!categoryItemsMap.has(catId)) {
                            categoryItemsMap.set(catId, []);
                        }
                        categoryItemsMap.get(catId).push(r);
                    }
                });
            }
        } else {
            // Regular item - not using category mode
            nonCategorizedItems.push(r);
        }
    });
    
    // Add categories first (show all categories that have items)
    const sortedCategoryIds = Array.from(categoryItemsMap.keys()).sort((a, b) => {
        const catA = (CATEGORIES || []).find(c => parseInt(c.id) === a);
        const catB = (CATEGORIES || []).find(c => parseInt(c.id) === b);
        const nameA = catA ? catA.name : '';
        const nameB = catB ? catB.name : '';
        return nameA.localeCompare(nameB);
    });
    
    sortedCategoryIds.forEach(catId => {
        const cat = (CATEGORIES || []).find(c => parseInt(c.id) === catId);
        if (cat) {
            const stock = (CATEGORY_STOCKS.find(cs => parseInt(cs.id) === catId) || {}).total_stock || 0;
            const itemCount = categoryItemsMap.get(catId).length;
            const label = `${cat.name} (Category - ${itemCount} items, Stock: ${parseFloat(stock).toFixed(3)})`;
            opts += `<option value="cat_${catId}" data-type="category" data-category_id="${catId}" data-stock="${stock}">${escapeHtml(label)}</option>`;
        }
    });
    
    // Add separator if we have both categories and materials
    if (categoryItemsMap.size > 0 && nonCategorizedItems.length > 0) {
        opts += `<option disabled>──────────────────────</option>`;
    }
    
    // Add non-categorized items
    nonCategorizedItems.forEach(r => {
        const label = `${r.name || ''} (${r.code || ''}) - ${r.symbol || ''}`;
        opts += `<option value="${r.id}" data-type="material" data-unit="${r.symbol||''}">${escapeHtml(label)}</option>`;
    });
    
    return `
        <select class="w-full px-2 py-1 border border-gray-300 rounded text-sm raw-select" onchange="handleMaterialOrCategorySelect(this)" required>
            ${opts}
        </select>
    `;
}

function addRawRow() {
    const tb = document.querySelector('#peetuRawTable tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="px-3 py-2 border material-selection-cell">${rawSelectHTML()}</td>
        <td class="px-3 py-2 border">
            <input type="number" name="quantity[]" step="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" placeholder="0.000" required>
        </td>
        <td class="px-3 py-2 border">
            <span class="inline-block text-xs text-gray-600 unit-stock-badge"></span>
        </td>
        <td class="px-3 py-2 border text-center">
            <button type="button" class="text-red-600 hover:text-red-800 text-sm" onclick="removeRawRow(this)">Remove</button>
        </td>
    `;
    tb.appendChild(tr);
    // Name attribute will be set by handleMaterialOrCategorySelect when user makes selection
}

function removeRawRow(btn) {
    const tb = document.querySelector('#peetuRawTable tbody');
    if (tb.children.length <= 1) { alert('At least one row is required.'); return; }
    btn.closest('tr').remove();
}

function syncRowUnit(select) {
    const unit = select.selectedOptions[0]?.getAttribute('data-unit') || '';
    const row = select.closest('tr');
    const badge = row.querySelector('.unit-stock-badge') || row.querySelector('.unit-badge');
    if (badge) {
        badge.textContent = unit;
    }
}

function categorySelectHTML() {
    let opts = '<option value="">Select Category</option>';
    (CATEGORIES || []).forEach(c => {
        const stock = (CATEGORY_STOCKS.find(cs => cs.id == c.id) || {}).total_stock || 0;
        opts += `<option value="${c.id}" data-stock="${stock}">${escapeHtml(c.name)}</option>`;
    });
    return `
        <select class="w-full px-2 py-1 border border-gray-300 rounded text-sm category-select" onchange="syncCategoryStock(this)" required>
            ${opts}
        </select>
    `;
}

function handleMaterialOrCategorySelect(select) {
    const selectedOption = select.selectedOptions[0];
    if (!selectedOption) return;
    
    const selectedValue = select.value;
    const dataType = selectedOption.getAttribute('data-type');
    const row = select.closest('tr');
    const td = select.closest('td');
    
    // Clear any existing hidden inputs in this cell
    const existingHiddenInputs = td.querySelectorAll('input[type="hidden"]');
    existingHiddenInputs.forEach(input => input.remove());
    
    // Clear the select name to prevent it from being submitted
    select.removeAttribute('name');
    
    if (dataType === 'category') {
        // Category selected - extract category ID from value (cat_5 -> 5)
        const categoryId = selectedValue.replace('cat_', '');
        const stock = selectedOption.getAttribute('data-stock') || '0';
        
        // Create hidden input for category_id[]
        const categoryInput = document.createElement('input');
        categoryInput.type = 'hidden';
        categoryInput.name = 'category_id[]';
        categoryInput.className = 'category-id-input';
        categoryInput.value = categoryId;
        td.appendChild(categoryInput);
        
        // Create hidden input for raw_material_id[] with empty value to maintain array alignment
        const rawInput = document.createElement('input');
        rawInput.type = 'hidden';
        rawInput.name = 'raw_material_id[]';
        rawInput.value = '';
        td.appendChild(rawInput);
        
        // Update stock display
        const badge = row.querySelector('.unit-stock-badge') || row.querySelector('.unit-badge');
        if (badge) {
            badge.textContent = `Stock: ${parseFloat(stock).toFixed(3)} units`;
        }
    } else if (dataType === 'material') {
        // Material selected
        const unit = selectedOption.getAttribute('data-unit') || '';
        
        // Create hidden input for raw_material_id[]
        const rawInput = document.createElement('input');
        rawInput.type = 'hidden';
        rawInput.name = 'raw_material_id[]';
        rawInput.value = selectedValue;
        td.appendChild(rawInput);
        
        // Create hidden input for category_id[] with empty value to maintain array alignment
        const categoryInput = document.createElement('input');
        categoryInput.type = 'hidden';
        categoryInput.name = 'category_id[]';
        categoryInput.value = '';
        td.appendChild(categoryInput);
        
        // Update unit display
        const badge = row.querySelector('.unit-stock-badge') || row.querySelector('.unit-badge');
        if (badge) {
            badge.textContent = unit;
        }
    }
}

// Keep the old function for backward compatibility
function updateMaterialSelect(select) {
    handleMaterialOrCategorySelect(select);
}

function syncCategoryStock(select) {
    const stock = select.selectedOptions[0]?.getAttribute('data-stock') || '0';
    const row = select.closest('tr');
    row.querySelector('.unit-stock-badge').textContent = `Stock: ${parseFloat(stock).toFixed(3)} units`;
}

function validatePeetuForm() {
    const peetu = document.getElementById('create_peetu_item_id').value;
    if (!peetu) { alert('Please select a Semi-Finished (Peetu) item.'); return false; }
    const rows = document.querySelectorAll('#peetuRawTable tbody tr');
    if (!rows.length) { alert('Please add at least one raw material row.'); return false; }
    
    // Debug: Log form data
    console.log('=== Form Validation Debug ===');
    console.log('Peetu ID:', peetu);
    console.log('Number of rows:', rows.length);
    
    const usedMaterials = new Set();
    const usedCategories = new Set();
    
    for (const row of rows) {
        const td = row.querySelector('.material-selection-cell');
        const qty = row.querySelector('input[name="quantity[]"]');
        
        // Check if selection dropdown has a value OR if hidden inputs exist
        const select = td.querySelector('.raw-select');
        const rawInput = td.querySelector('input[name="raw_material_id[]"]');
        const categoryInput = td.querySelector('input[name="category_id[]"]');
        
        console.log('Row:', {
            selectValue: select?.value,
            rawInputValue: rawInput?.value,
            categoryInputValue: categoryInput?.value,
            quantity: qty?.value
        });
        
        let hasValidSelection = false;
        let selectedId = null;
        let selectedType = null;
        
        // Check if dropdown has a value (before user interacts)
        if (select && select.value && select.value !== '') {
            hasValidSelection = true;
            if (select.value.startsWith('cat_')) {
                selectedType = 'category';
                selectedId = select.value;
            } else {
                selectedType = 'material';
                selectedId = select.value;
            }
        }
        // Or check if hidden inputs have values (after handleMaterialOrCategorySelect is called)
        else if (rawInput && rawInput.value) {
            hasValidSelection = true;
            selectedType = 'material';
            selectedId = rawInput.value;
        }
        else if (categoryInput && categoryInput.value) {
            hasValidSelection = true;
            selectedType = 'category';
            selectedId = 'cat_' + categoryInput.value;
        }
        
        if (!hasValidSelection) {
            alert('Each row must have a material or category selected.');
            return false;
        }
        
        // Check for duplicates
        if (selectedType === 'material' && usedMaterials.has(selectedId)) {
            alert('You have selected the same raw material multiple times. Please remove duplicate rows.');
            return false;
        }
        if (selectedType === 'category' && usedCategories.has(selectedId)) {
            alert('You have selected the same category multiple times. Please remove duplicate rows.');
            return false;
        }
        
        // Add to used sets
        if (selectedType === 'material') {
            usedMaterials.add(selectedId);
        } else {
            usedCategories.add(selectedId);
        }
        
        if (!qty.value || parseFloat(qty.value) <= 0) {
            alert('Each row must have a positive quantity.');
            return false;
        }
    }
    
    console.log('=== Validation Passed ===');
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

/* ---------- Direct BOM (Finished → Raw directly) ---------- */
function onFinishedItemChange() {
    const select = document.getElementById('direct_finished_item_id');
    const unit = select.options[select.selectedIndex]?.getAttribute('data-unit') || '';
    const display = document.getElementById('finished_unit_display');
    if (unit) {
        display.textContent = `(${unit})`;
    } else {
        display.textContent = '';
    }
    
    // Optional: prefill weight from item data if available and field is empty
    const itemId = parseInt(select.value || '0');
    const weightInput = document.getElementById('direct_finished_unit_qty');
    if (itemId > 0 && weightInput && !weightInput.value.trim()) {
        const item = (FINISHED_ITEMS || []).find(it => parseInt(it.id) === itemId);
        if (item && item.unit_weight_kg && parseFloat(item.unit_weight_kg) > 0) {
            weightInput.value = parseFloat(item.unit_weight_kg).toFixed(3);
        }
    }
}

function openCreateDirectBom() {
    document.getElementById('direct_finished_item_id').value = '';
    document.getElementById('direct_finished_unit_qty').value = '15.000'; // Default to 15kg
    const tb = document.querySelector('#directBomTable tbody');
    tb.innerHTML = '';
    addDirectBomRow();
    openModal('createDirectBomModal');
}

function addDirectBomRow() {
    const tb = document.querySelector('#directBomTable tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="px-3 py-2 border material-selection-cell">${rawSelectHTML()}</td>
        <td class="px-3 py-2 border">
            <input type="number" name="quantity[]" step="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" placeholder="0.000" required>
        </td>
        <td class="px-3 py-2 border">
            <span class="inline-block text-xs text-gray-600 unit-badge"></span>
        </td>
        <td class="px-3 py-2 border text-center">
            <button type="button" class="text-red-600 hover:text-red-800 text-sm" onclick="removeDirectBomRow(this)">Remove</button>
        </td>
    `;
    tb.appendChild(tr);
    // Name attribute will be set by handleMaterialOrCategorySelect when user makes selection
}

function removeDirectBomRow(btn) {
    const tb = document.querySelector('#directBomTable tbody');
    if (tb.children.length <= 1) { alert('At least one row is required.'); return; }
    btn.closest('tr').remove();
}

function validateDirectBomForm() {
    const finished = document.getElementById('direct_finished_item_id').value;
    if (!finished) { alert('Please select a Finished product.'); return false; }
    
    const unitQty = document.getElementById('direct_finished_unit_qty').value;
    if (!unitQty || parseFloat(unitQty) <= 0) { 
        alert('Please enter a valid unit quantity for the finished product.'); 
        return false; 
    }
    
    const rows = document.querySelectorAll('#directBomTable tbody tr');
    if (!rows.length) { alert('Please add at least one raw material row.'); return false; }
    
    const usedMaterials = new Set();
    const usedCategories = new Set();
    
    for (const row of rows) {
        const td = row.querySelector('.material-selection-cell');
        const qty = row.querySelector('input[name="quantity[]"]');
        
        // Check if selection dropdown has a value OR if hidden inputs exist
        const select = td.querySelector('.raw-select');
        const rawInput = td.querySelector('input[name="raw_material_id[]"]');
        const categoryInput = td.querySelector('input[name="category_id[]"]');
        
        let hasValidSelection = false;
        let selectedId = null;
        let selectedType = null;
        
        // Check if dropdown has a value (before user interacts)
        if (select && select.value && select.value !== '') {
            hasValidSelection = true;
            if (select.value.startsWith('cat_')) {
                selectedType = 'category';
                selectedId = select.value;
            } else {
                selectedType = 'material';
                selectedId = select.value;
            }
        }
        // Or check if hidden inputs have values (after handleMaterialOrCategorySelect is called)
        else if (rawInput && rawInput.value) {
            hasValidSelection = true;
            selectedType = 'material';
            selectedId = rawInput.value;
        }
        else if (categoryInput && categoryInput.value) {
            hasValidSelection = true;
            selectedType = 'category';
            selectedId = 'cat_' + categoryInput.value;
        }
        
        if (!hasValidSelection) {
            alert('Each row must have a material or category selected.');
            return false;
        }
        
        // Check for duplicates
        if (selectedType === 'material' && usedMaterials.has(selectedId)) {
            alert('You have selected the same raw material multiple times. Please remove duplicate rows.');
            return false;
        }
        if (selectedType === 'category' && usedCategories.has(selectedId)) {
            alert('You have selected the same category multiple times. Please remove duplicate rows.');
            return false;
        }
        
        // Add to used sets
        if (selectedType === 'material') {
            usedMaterials.add(selectedId);
        } else {
            usedCategories.add(selectedId);
        }
        
        if (!qty.value || parseFloat(qty.value) <= 0) {
            alert('Each row must have a positive quantity.');
            return false;
        }
    }
    return true;
}

function editDirectBom(row) {
    document.getElementById('edit_direct_id').value = row.id;
    document.getElementById('edit_direct_finished').value = (row.finished_name || '') + ' (' + (row.finished_code || '') + ')';
    document.getElementById('edit_direct_raw').value = (row.raw_name || '') + ' (' + (row.raw_code || '') + ')';
    document.getElementById('edit_direct_quantity').value = row.quantity;
    document.getElementById('edit_direct_finished_unit_qty').value = row.finished_unit_qty || '1.000';
    openModal('editDirectBomModal');
}

/* ---------- Utils ---------- */
function escapeHtml(s){return String(s).replace(/[&<>"']/g,m=>({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;" }[m]));}

/* ---------- View Filter (All | Peetu | Direct BOM | BOM Product) ---------- */
function setBomView(view) {
    const secPeetu = document.getElementById('section-peetu');
    const secDirect = document.getElementById('section-direct');
    const secBomProd = document.getElementById('section-bomprod');
    const pageTitle = document.getElementById('pageTitle');
    const btnPeetu = document.querySelectorAll('.action-peetu');
    const btnDirect = document.querySelectorAll('.action-direct');
    const btnBomProd = document.querySelectorAll('.action-bomprod');

    function show(el, yes) { if (!el) return; el.classList.toggle('hidden', !yes); }
    function toggleList(list, yes) { list.forEach(el => el.classList.toggle('hidden', !yes)); }

    const v = (view || 'all');
    show(secPeetu, v === 'all' || v === 'peetu');
    show(secDirect, v === 'all' || v === 'direct');
    show(secBomProd, v === 'all' || v === 'bom_product');

    toggleList(btnPeetu, v === 'all' || v === 'peetu');
    toggleList(btnDirect, v === 'all' || v === 'direct');
    toggleList(btnBomProd, v === 'all' || v === 'bom_product');

    if (pageTitle) {
        if (v === 'all') pageTitle.textContent = 'BOM Overview';
        else if (v === 'peetu') pageTitle.textContent = 'Peetu (Semi-Finished) Map';
        else if (v === 'direct') pageTitle.textContent = 'Direct BOM';
        else if (v === 'bom_product') pageTitle.textContent = 'BOM Product';
    }

    try {
        const url = new URL(window.location.href);
        url.searchParams.set('view', v);
        window.history.replaceState({}, '', url.toString());
    } catch (_) {}

    const sel = document.getElementById('bomViewSelect');
    if (sel && sel.value !== v) sel.value = v;
}

document.addEventListener('DOMContentLoaded', function () {
    // Don't add rows on page load - only when modals are opened
    // This prevents duplicate rows when opening modals
    
    // Re-prefill all visible rows when finished item changes
    document.getElementById('bp_finished_item_id')?.addEventListener('change', () => {
      document.querySelectorAll('#bomProdTable tbody tr').forEach(tr => prefillRowFromSelections(tr));
    });

    // Initialize view from URL parameter or default to 'all'
    try {
        const params = new URLSearchParams(window.location.search);
        const view = params.get('view') || 'all';
        const sel = document.getElementById('bomViewSelect');
        if (sel) sel.value = view;
        setBomView(view);
    } catch (_) {
        setBomView('all');
    }
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
