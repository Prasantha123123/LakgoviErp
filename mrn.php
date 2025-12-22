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
                        
                        $items_requested = 0;
                        
                        // Always FROM Store (1) TO Production (2)
                        $source_location_id = 1;      // Store
                        $destination_location_id = 2; // Production
                        
                        // Process each MRN item (just create records, no stock movement yet)
                        foreach ($_POST['items'] as $item) {
                            if (!empty($item['is_category']) && !empty($item['category_id']) && !empty($item['quantity'])) {
                                // Handle category-based material - distribute quantity across all items in category
                                $category_id = intval($item['category_id']);
                                $total_requested_qty = floatval($item['quantity']);
                                
                                // Get all items in this category with their current stock
                                // Includes items from both items.category_id AND item_categories junction table
                                $stmt = $db->prepare("
                                    SELECT i.id AS item_id, i.name, i.code, u.symbol as unit_symbol,
                                           COALESCE(SUM(sl.quantity_in - sl.quantity_out), 0) as available_stock
                                    FROM items i
                                    JOIN units u ON u.id = i.unit_id
                                    LEFT JOIN stock_ledger sl ON sl.item_id = i.id AND sl.location_id = ?
                                    LEFT JOIN item_categories ic ON ic.item_id = i.id
                                    WHERE (i.category_id = ? OR ic.category_id = ?) AND i.type = 'raw'
                                    GROUP BY i.id, i.name, i.code, u.symbol
                                    HAVING available_stock > 0
                                    ORDER BY available_stock DESC
                                ");
                                $stmt->execute([$source_location_id, $category_id, $category_id]);
                                $category_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (empty($category_items)) {
                                    throw new Exception("No items with stock found in category ID: $category_id");
                                }
                                
                                // Calculate total available stock in category
                                $total_category_stock = 0;
                                foreach ($category_items as $cat_item) {
                                    $total_category_stock += floatval($cat_item['available_stock']);
                                }
                                
                                if ($total_category_stock < $total_requested_qty) {
                                    throw new Exception("Insufficient stock in category. Available: " . number_format($total_category_stock, 3) . " kg, Requested: " . number_format($total_requested_qty, 3) . " kg");
                                }
                                
                                // Distribute quantity sequentially - use first item fully, then move to next
                                $remaining_qty = $total_requested_qty;
                                
                                foreach ($category_items as $cat_item) {
                                    if ($remaining_qty <= 0) break; // No more quantity to allocate
                                    
                                    $item_stock = floatval($cat_item['available_stock']);
                                    if ($item_stock <= 0) continue; // Skip items with no stock
                                    
                                    // Take minimum of what's needed vs what's available
                                    $allocated_qty = min($remaining_qty, $item_stock);
                                    
                                    if ($allocated_qty > 0) {
                                        // Insert MRN item for this specific item
                                        $stmt = $db->prepare("INSERT INTO mrn_items (mrn_id, item_id, location_id, quantity) VALUES (?, ?, ?, ?)");
                                        $stmt->execute([$mrn_id, $cat_item['item_id'], $destination_location_id, $allocated_qty]);
                                        $remaining_qty -= $allocated_qty;
                                        $items_requested++;
                                    }
                                }
                                
                            } elseif (!empty($item['item_id']) && !empty($item['quantity'])) {
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
                                
                                // Validate available stock in STORE (check KG amount) - for request validation only
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
                                    $source_balance = $fallback_stock;
                                }
                                
                                // Validate available stock in STORE (check KG amount)
                                if ($source_balance < $requested_qty_kg) {
                                    throw new Exception("Insufficient stock in Store for {$item_data['name']}. Available: " . number_format($source_balance, 3) . " kg, Requested: " . number_format($requested_qty_kg, 3) . " kg (" . number_format($requested_qty, 0) . " " . $item_data['unit_symbol'] . ")");
                                }
                                
                                // Insert MRN item (store requested qty in pieces for display)
                                $stmt = $db->prepare("INSERT INTO mrn_items (mrn_id, item_id, location_id, quantity) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$mrn_id, $item['item_id'], $destination_location_id, $requested_qty]);
                                $items_requested++;
                            }
                        }
                        
                        if ($items_requested == 0) {
                            throw new Exception("No items were requested. Please add items to the MRN.");
                        }
                        
                        $db->commit();
                        
                        // If a production batch was selected, mark it as materials issued
                        if (!empty($_POST['production_batch'])) {
                            $stmt = $db->prepare("UPDATE production SET status = 'materials_issued' WHERE id = ?");
                            $stmt->execute([$_POST['production_batch']]);
                        }
                        
                        $success = "MRN request created successfully! {$items_requested} items requested. Click 'Complete' to execute the material transfer.";
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

                // Mark completed (works for MRN, Return MRN, and Transfer TRN)
                case 'complete':
                    $db->beginTransaction();
                    try {
                        // Get MRN details to check if it's a TRN (transfer) or regular MRN
                        $stmt = $db->prepare("SELECT mrn_no, mrn_date FROM mrn WHERE id = ?");
                        $stmt->execute([$_POST['id']]);
                        $mrn_record = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$mrn_record) {
                            throw new Exception("MRN record not found");
                        }
                        
                        $is_return = (strpos($mrn_record['mrn_no'], 'RTN') === 0);
                        $is_transfer = (strpos($mrn_record['mrn_no'], 'TRN') === 0);
                        
                        if ($is_transfer) {
                            // Execute the transfer: get items and do stock movements
                            $stmt = $db->prepare("
                                SELECT mi.*, i.name as item_name 
                                FROM mrn_items mi 
                                JOIN items i ON mi.item_id = i.id 
                                WHERE mi.mrn_id = ?
                            ");
                            $stmt->execute([$_POST['id']]);
                            $transfer_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (empty($transfer_items)) {
                                throw new Exception("No items found for transfer");
                            }
                            
                            // Resolve locations
                            $stmt = $db->prepare("SELECT id, name FROM locations WHERE name IN ('Production Floor', 'Store')");
                            $stmt->execute();
                            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            $production_floor_id = null;
                            $store_id = null;
                            foreach ($locations as $loc) {
                                if ($loc['name'] == 'Production Floor') $production_floor_id = $loc['id'];
                                if ($loc['name'] == 'Store') $store_id = $loc['id'];
                            }
                            
                            if (!$production_floor_id || !$store_id) {
                                throw new Exception("Required locations 'Production Floor' and 'Store' not found in database.");
                            }
                            
                            $affected_items = [];
                            
                            foreach ($transfer_items as $item) {
                                $item_id = $item['item_id'];
                                $transfer_qty = floatval($item['quantity']);
                                
                                // Double-check stock availability in Production Floor
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item_id, $production_floor_id]);
                                $production_balance = floatval($stmt->fetchColumn());
                                
                                if ($production_balance < $transfer_qty) {
                                    throw new Exception("Insufficient stock in Production Floor for {$item['item_name']}. Available: " . number_format($production_balance, 3) . ", Requested: " . number_format($transfer_qty, 3));
                                }
                                
                                // Get Store balance for correct new balance
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item_id, $store_id]);
                                $store_balance = floatval($stmt->fetchColumn());
                                
                                $new_production_balance = $production_balance - $transfer_qty;
                                $new_store_balance = $store_balance + $transfer_qty;
                                
                                // OUT from Production Floor
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'transfer_out', ?, ?, ?, 0, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $item_id, 
                                    $production_floor_id,
                                    $_POST['id'], 
                                    $mrn_record['mrn_no'], 
                                    $mrn_record['mrn_date'], 
                                    $transfer_qty, 
                                    $new_production_balance
                                ]);
                                
                                // IN to Store
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'transfer_in', ?, ?, ?, ?, 0, ?, NOW())
                                ");
                                $stmt->execute([
                                    $item_id, 
                                    $store_id,
                                    $_POST['id'], 
                                    $mrn_record['mrn_no'], 
                                    $mrn_record['mrn_date'], 
                                    $transfer_qty, 
                                    $new_store_balance
                                ]);
                                
                                $affected_items[] = $item_id;
                            }
                            
                            // Update current_stock for all affected items
                            foreach ($affected_items as $item_id) {
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as total_stock
                                    FROM stock_ledger
                                    WHERE item_id = ?
                                ");
                                $stmt->execute([$item_id]);
                                $total_stock = floatval($stmt->fetchColumn());
                                
                                $stmt = $db->prepare("UPDATE items SET current_stock = ? WHERE id = ?");
                                $stmt->execute([$total_stock, $item_id]);
                            }
                            
                            // UPDATE LOCATION FOR RELATED RECORDS: Transfer rolls, bundles, and repacking items
                            // These items are finished goods moved from Production Floor to Store
                            foreach ($transfer_items as $item) {
                                $item_id = $item['item_id'];
                                
                                // Update rolls_batches: Move completed rolls batches to Store
                                // Match by rolls_item_id (the item being transferred)
                                $stmt = $db->prepare("
                                    UPDATE rolls_batches 
                                    SET location_id = ?
                                    WHERE rolls_item_id = ? AND location_id = ? AND status = 'completed'
                                ");
                                $stmt->execute([$store_id, $item_id, $production_floor_id]);
                                
                                // Update bundles: Move bundles to Store
                                // Match by bundle_item_id (the item being transferred)
                                $stmt = $db->prepare("
                                    UPDATE bundles 
                                    SET location_id = ?
                                    WHERE bundle_item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$store_id, $item_id, $production_floor_id]);
                                
                                // When bundles are transferred, also move the repacking records that produced the source items
                                // Get all source items from bundles that use this item
                                $stmt = $db->prepare("
                                    SELECT DISTINCT source_item_id FROM bundles WHERE bundle_item_id = ?
                                ");
                                $stmt->execute([$item_id]);
                                $bundle_sources = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                
                                // Update repacking for each source item used in transferred bundles
                                foreach ($bundle_sources as $source_item_id) {
                                    $stmt = $db->prepare("
                                        UPDATE repacking 
                                        SET location_id = ?
                                        WHERE repack_item_id = ? AND location_id = ?
                                    ");
                                    $stmt->execute([$store_id, $source_item_id, $production_floor_id]);
                                }
                                
                                // Also directly update repacking if the transferred item is a repacked item
                                $stmt = $db->prepare("
                                    UPDATE repacking 
                                    SET location_id = ?
                                    WHERE repack_item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$store_id, $item_id, $production_floor_id]);
                            }
                        } elseif ($is_return) {
                            // Execute return: get items and do stock movements (Production -> Store)
                            $stmt = $db->prepare("
                                SELECT mi.*, i.name as item_name, i.type, u.symbol as unit_symbol
                                FROM mrn_items mi 
                                JOIN items i ON mi.item_id = i.id 
                                JOIN units u ON i.unit_id = u.id 
                                WHERE mi.mrn_id = ?
                            ");
                            $stmt->execute([$_POST['id']]);
                            $return_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (empty($return_items)) {
                                throw new Exception("No items found for return");
                            }
                            
                            $source_location_id = 2; // Production
                            $destination_location_id = 1; // Store
                            $affected_items = [];
                            
                            foreach ($return_items as $item) {
                                $item_id = $item['item_id'];
                                $requested_qty = floatval($item['quantity']);
                                
                                // CONVERT PIECES TO KG if needed
                                $requested_qty_kg = $requested_qty; // Default: assume already in kg
                                
                                // If unit is PCS/PC, convert to KG using BOM data
                                if (strtolower($item['unit_symbol']) == 'pcs' || strtolower($item['unit_symbol']) == 'pc') {
                                    $kg_per_piece = 0;
                                    
                                    // Try bom_product first
                                    $stmt = $db->prepare("SELECT product_unit_qty FROM bom_product WHERE finished_item_id = ? LIMIT 1");
                                    $stmt->execute([$item_id]);
                                    $bom_product = $stmt->fetch();
                                    
                                    if ($bom_product && $bom_product['product_unit_qty'] > 0) {
                                        $kg_per_piece = floatval($bom_product['product_unit_qty']);
                                    } else {
                                        // Try bom_direct
                                        $stmt = $db->prepare("SELECT finished_unit_qty FROM bom_direct WHERE finished_item_id = ? LIMIT 1");
                                        $stmt->execute([$item_id]);
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
                                
                                // Stock in PRODUCTION (source) - from ledger
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item_id, $source_location_id]);
                                $ledger_balance = floatval($stmt->fetchColumn());
                                
                                // If no ledger entry, check items.current_stock as fallback
                                $source_balance = $ledger_balance;
                                if ($source_balance == 0) {
                                    $stmt = $db->prepare("SELECT COALESCE(current_stock, 0) as stock FROM items WHERE id = ?");
                                    $stmt->execute([$item_id]);
                                    $fallback_stock = floatval($stmt->fetchColumn());
                                    $source_balance = $fallback_stock;
                                }
                                
                                if ($source_balance < $requested_qty_kg) {
                                    throw new Exception("Insufficient stock in Production for {$item['item_name']}. Available: " . number_format($source_balance, 3) . " kg, Requested: " . number_format($requested_qty_kg, 3) . " kg (" . number_format($requested_qty, 0) . " " . $item['unit_symbol'] . ")");
                                }
                                
                                // Stock in STORE (dest)
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item_id, $destination_location_id]);
                                $dest_balance = floatval($stmt->fetchColumn());
                                
                                $new_source_balance = $source_balance - $requested_qty_kg;
                                $new_dest_balance   = $dest_balance + $requested_qty_kg;
                                
                                // 1) OUT from PRODUCTION (transaction_type='mrn_return', use KG)
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'mrn_return', ?, ?, ?, 0, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $item_id, 
                                    $source_location_id,
                                    $_POST['id'], 
                                    $mrn_record['mrn_no'], 
                                    $mrn_record['mrn_date'], 
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
                                    $item_id, 
                                    $destination_location_id,
                                    $_POST['id'], 
                                    $mrn_record['mrn_no'], 
                                    $mrn_record['mrn_date'], 
                                    $requested_qty_kg, 
                                    $new_dest_balance
                                ]);
                                
                                $affected_items[] = $item_id;
                            }
                            
                            // Update current_stock for all affected items
                            foreach ($affected_items as $item_id) {
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as total_stock
                                    FROM stock_ledger
                                    WHERE item_id = ?
                                ");
                                $stmt->execute([$item_id]);
                                $total_stock = floatval($stmt->fetchColumn());
                                
                                $stmt = $db->prepare("UPDATE items SET current_stock = ? WHERE id = ?");
                                $stmt->execute([$total_stock, $item_id]);
                            }
                        } else {
                            // Execute regular MRN: get items and do stock movements (Store -> Production)
                            $stmt = $db->prepare("
                                SELECT mi.*, i.name as item_name, i.type, u.symbol as unit_symbol
                                FROM mrn_items mi 
                                JOIN items i ON mi.item_id = i.id 
                                JOIN units u ON i.unit_id = u.id 
                                WHERE mi.mrn_id = ?
                            ");
                            $stmt->execute([$_POST['id']]);
                            $mrn_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (empty($mrn_items)) {
                                throw new Exception("No items found for MRN");
                            }
                            
                            $source_location_id = 1;      // Store
                            $destination_location_id = 2; // Production
                            $affected_items = [];
                            
                            foreach ($mrn_items as $item) {
                                $item_id = $item['item_id'];
                                $requested_qty = floatval($item['quantity']);
                                
                                // CONVERT PIECES TO KG if needed
                                $requested_qty_kg = $requested_qty; // Default: assume already in kg
                                
                                // If unit is PCS/PC, convert to KG using BOM data
                                if (strtolower($item['unit_symbol']) == 'pcs' || strtolower($item['unit_symbol']) == 'pc') {
                                    $kg_per_piece = 0;
                                    
                                    // Try bom_product first
                                    $stmt = $db->prepare("SELECT product_unit_qty FROM bom_product WHERE finished_item_id = ? LIMIT 1");
                                    $stmt->execute([$item_id]);
                                    $bom_product = $stmt->fetch();
                                    
                                    if ($bom_product && $bom_product['product_unit_qty'] > 0) {
                                        $kg_per_piece = floatval($bom_product['product_unit_qty']);
                                    } else {
                                        // Try bom_direct
                                        $stmt = $db->prepare("SELECT finished_unit_qty FROM bom_direct WHERE finished_item_id = ? LIMIT 1");
                                        $stmt->execute([$item_id]);
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
                                
                                // Get current stock in STORE (source) - from ledger
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item_id, $source_location_id]);
                                $ledger_balance = floatval($stmt->fetchColumn());
                                
                                // If no ledger entry, check items.current_stock as fallback
                                $source_balance = $ledger_balance;
                                if ($source_balance == 0) {
                                    $stmt = $db->prepare("SELECT COALESCE(current_stock, 0) as stock FROM items WHERE id = ?");
                                    $stmt->execute([$item_id]);
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
                                            $item_id,
                                            $source_location_id,
                                            $mrn_record['mrn_date'],
                                            $source_balance,
                                            $source_balance
                                        ]);
                                    }
                                }
                                
                                // Double-check available stock in STORE (check KG amount)
                                if ($source_balance < $requested_qty_kg) {
                                    throw new Exception("Insufficient stock in Store for {$item['item_name']}. Available: " . number_format($source_balance, 3) . " kg, Requested: " . number_format($requested_qty_kg, 3) . " kg (" . number_format($requested_qty, 0) . " " . $item['unit_symbol'] . ")");
                                }
                                
                                // Balance in PRODUCTION (dest)
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item_id, $destination_location_id]);
                                $dest_balance = floatval($stmt->fetchColumn());
                                
                                $new_source_balance = $source_balance - $requested_qty_kg;
                                $new_dest_balance   = $dest_balance + $requested_qty_kg;
                                
                                // 1) OUT from STORE (use KG)
                                $stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, quantity_out, balance, created_at
                                    ) VALUES (?, ?, 'mrn', ?, ?, ?, 0, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $item_id, 
                                    $source_location_id,
                                    $_POST['id'], 
                                    $mrn_record['mrn_no'], 
                                    $mrn_record['mrn_date'], 
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
                                    $item_id, 
                                    $destination_location_id,
                                    $_POST['id'], 
                                    $mrn_record['mrn_no'], 
                                    $mrn_record['mrn_date'], 
                                    $requested_qty_kg, 
                                    $new_dest_balance
                                ]);
                                
                                $affected_items[] = $item_id;
                            }
                            
                            // Update current_stock for all affected items
                            foreach ($affected_items as $item_id) {
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as total_stock
                                    FROM stock_ledger
                                    WHERE item_id = ?
                                ");
                                $stmt->execute([$item_id]);
                                $total_stock = floatval($stmt->fetchColumn());
                                
                                $stmt = $db->prepare("UPDATE items SET current_stock = ? WHERE id = ?");
                                $stmt->execute([$total_stock, $item_id]);
                            }
                        }
                        
                        // Mark as completed
                        $stmt = $db->prepare("UPDATE mrn SET status = 'completed' WHERE id = ?");
                        $stmt->execute([$_POST['id']]);
                        
                        $db->commit();
                        $success = $is_transfer ? "Transfer completed successfully! Stock movements executed." : ($is_return ? "Return MRN completed successfully! Stock movements executed." : "MRN completed successfully! Stock movements executed.");
                    } catch(Exception $e) {
                        $db->rollback();
                        throw $e;
                    }
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
                        $is_transfer = (strpos($mrn_no, 'TRN') === 0);
                        
                        if ($is_transfer) {
                            // Transfer records use 'transfer_in' and 'transfer_out'
                            $ledger_types = ['transfer_in', 'transfer_out'];
                            $reversal_type = 'transfer_reversal';
                        } elseif ($is_return) {
                            // Return records use 'mrn_return'
                            $ledger_types = ['mrn_return'];
                            $reversal_type = 'mrn_return_reversal';
                        } else {
                            // Regular MRN records use 'mrn'
                            $ledger_types = ['mrn'];
                            $reversal_type = 'mrn_reversal';
                        }
                        
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

                        // Delete original stock ledger entries for this ref & types
                        $placeholders = str_repeat('?,', count($ledger_types) - 1) . '?';
                        $stmt = $db->prepare("DELETE FROM stock_ledger WHERE transaction_type IN ($placeholders) AND reference_id = ?");
                        $stmt->execute(array_merge($ledger_types, [$_POST['id']]));

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

                // =========================
                // TRANSFER FINISHED GOODS (Production Floor -> Store)
                // =========================
                case 'transfer_finished':
                    $db->beginTransaction();
                    try {
                        // Resolve locations
                        $stmt = $db->prepare("SELECT id, name FROM locations WHERE name IN ('Production Floor', 'Store')");
                        $stmt->execute();
                        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        $production_floor_id = null;
                        $store_id = null;
                        foreach ($locations as $loc) {
                            if ($loc['name'] == 'Production Floor') $production_floor_id = $loc['id'];
                            if ($loc['name'] == 'Store') $store_id = $loc['id'];
                        }
                        
                        if (!$production_floor_id || !$store_id) {
                            throw new Exception("Required locations 'Production Floor' and 'Store' not found in database.");
                        }
                        
                        // Generate TRN number
                        $trn_count = (int)$db->query("SELECT COUNT(*) FROM mrn WHERE mrn_no LIKE 'TRN%'")->fetchColumn();
                        $trn_no = 'TRN' . str_pad($trn_count + 1, 3, '0', STR_PAD_LEFT);
                        
                        // Insert MRN header
                        $purpose = $_POST['purpose'] ?? 'Rolls & Bundles Transfer Production → Store';
                        $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d');
                        
                        $stmt = $db->prepare("INSERT INTO mrn (mrn_no, mrn_date, purpose, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                        $stmt->execute([$trn_no, $transfer_date, $purpose]);
                        $mrn_id = $db->lastInsertId();
                        
                        $items_requested = 0;
                        
                        // Process transfer items (just create records, no stock movement yet)
                        foreach ($_POST['transfer_items'] as $item) {
                            if (!empty($item['item_id']) && !empty($item['quantity'])) {
                                $item_id = intval($item['item_id']);
                                $transfer_qty = floatval($item['quantity']);
                                
                                if ($transfer_qty <= 0) continue;
                                
                                // Verify item exists and is finished
                                $stmt = $db->prepare("SELECT id, name, type FROM items WHERE id = ?");
                                $stmt->execute([$item_id]);
                                $item_data = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if (!$item_data) {
                                    throw new Exception("Item not found");
                                }
                                
                                if ($item_data['type'] !== 'finished') {
                                    throw new Exception("Only finished goods can be transferred: {$item_data['name']}");
                                }
                                
                                // Check stock in Production Floor (for validation only)
                                $stmt = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                    FROM stock_ledger 
                                    WHERE item_id = ? AND location_id = ?
                                ");
                                $stmt->execute([$item_id, $production_floor_id]);
                                $production_balance = floatval($stmt->fetchColumn());
                                
                                if ($production_balance < $transfer_qty) {
                                    throw new Exception("Insufficient stock in Production Floor for {$item_data['name']}. Available: " . number_format($production_balance, 3) . ", Requested: " . number_format($transfer_qty, 3));
                                }
                                
                                // Insert into mrn_items (location_id = Store, quantity = requested amount)
                                $stmt = $db->prepare("INSERT INTO mrn_items (mrn_id, item_id, location_id, quantity) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$mrn_id, $item_id, $store_id, $transfer_qty]);
                                $items_requested++;
                            }
                        }
                        
                        if ($items_requested == 0) {
                            throw new Exception("No items were requested for transfer. Please select items and quantities.");
                        }
                        
                        $db->commit();
                        $success = "Transfer request created successfully! {$items_requested} items requested for transfer. Click 'Complete' to execute the transfer.";
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
    $trn_count = (int)$db->query("SELECT COUNT(*) FROM mrn WHERE mrn_no LIKE 'TRN%'")->fetchColumn();
} catch(PDOException $e) {
    $mrn_count = 0; $rtn_count = 0; $trn_count = 0;
}

// Fetch finished items that are bundles or rolls with stock in Production Floor
try {
    $stmt = $db->query("
        SELECT DISTINCT i.id, i.code, i.name, u.symbol as unit_symbol,
               COALESCE((
                   SELECT SUM(quantity_in - quantity_out) 
                   FROM stock_ledger 
                   WHERE item_id = i.id AND location_id = (SELECT id FROM locations WHERE name = 'Production Floor')
               ), 0) as production_stock
        FROM items i
        JOIN units u ON i.unit_id = u.id
        LEFT JOIN bundles b ON b.bundle_item_id = i.id
        LEFT JOIN rolls_batches rb ON rb.rolls_item_id = i.id
        WHERE i.type = 'finished' AND (b.id IS NOT NULL OR rb.id IS NOT NULL)
        HAVING production_stock > 0
        ORDER BY i.name
    ");
    $transfer_items = $stmt->fetchAll();
} catch(PDOException $e) {
    $transfer_items = [];
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Material Request Note (MRN) & Return MRN</h1>
            <p class="text-gray-600">MRN: Store → Production | Return MRN: Production → Store | Transfer: Production → Store</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
            <div class="flex flex-col sm:flex-row gap-2">
                <button onclick="openModal('createMrnModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors text-sm font-medium">
                    Create New MRN
                </button>
                <button onclick="openModal('createReturnModal')" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition-colors text-sm font-medium">
                    Create Return MRN
                </button>
            </div>
            <button onclick="openModal('transferFinishedModal')" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors text-sm font-medium whitespace-nowrap">
                Transfer Rolls & Bundles<br class="hidden sm:block"><span class="text-xs">(Production → Store)</span>
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
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase w-48">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($mrns as $mrn): 
                    $is_return = strpos($mrn['mrn_no'], 'RTN') === 0;
                    $is_transfer = strpos($mrn['mrn_no'], 'TRN') === 0; ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                            <?php 
                            if ($is_transfer) echo 'bg-green-100 text-green-800';
                            elseif ($is_return) echo 'bg-indigo-100 text-indigo-800';
                            else echo 'bg-blue-100 text-blue-800';
                            ?>">
                            <?php 
                            if ($is_transfer) echo 'Transfer';
                            elseif ($is_return) echo 'Return MRN';
                            else echo 'MRN';
                            ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900 font-mono"><?php echo htmlspecialchars($mrn['mrn_no']); ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm text-gray-900 break-words max-w-xs"><?php echo htmlspecialchars($mrn['purpose']); ?></div>
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
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2 w-48">
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

            <!-- Production Batch Selection -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Production Batch (Optional)</label>
                    <select name="production_batch" id="production_batch" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                        <option value="">Select Production Batch</option>
                        <?php
                        $stmt = $db->query("SELECT id, batch_no, status FROM production WHERE status IN ('planned', 'pending_material', '') OR status IS NULL ORDER BY batch_no");
                        while ($row = $stmt->fetch()) {
                            $effective_status = empty($row['status']) ? 'planned' : $row['status'];
                            $status_label = $effective_status === 'pending_material' ? ' (Pending Materials)' : '';
                            echo "<option value='{$row['id']}'>{$row['batch_no']}{$status_label}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="button" id="load_materials_btn" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:bg-gray-400" disabled>
                        Load from Production Batch
                    </button>
                </div>
            </div>

            <!-- Items Section -->
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h4 class="text-md font-semibold text-gray-900">Items to Issue (From Store)</h4>
                    <button type="button" onclick="addMrnItem()" class="bg-green-500 text-white px-3 py-1 rounded text-sm hover:bg-green-600">Add Item</button>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300 table-fixed" style="min-width: 800px;">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r" style="width: calc(100% - 160px);">Item</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r" style="width: 80px;">Available</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r" style="width: 80px;">Quantity</th>
                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase" style="width: 80px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="mrnItemsTable">
                            <tr class="mrn-item-row">
                                <td class="px-4 py-2 border-r" style="width: calc(100% - 160px);">
                                    <select name="items[0][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-mrn" onchange="updateStockMrn(this)" required>
                                        <option value="">Select Item</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-4 py-2 border-r" style="width: 80px;">
                                    <div class="flex items-center">
                                        <span class="stock-display text-sm font-medium">-</span>
                                        <span class="ml-1 text-xs text-gray-500 unit-display"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 border-r" style="width: 80px;">
                                    <input type="number" name="items[0][quantity]" step="0.001" min="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateQuantity(this)" required>
                                </td>
                                <td class="px-4 py-2 text-center" style="width: 80px;">
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
                    <table class="min-w-full border border-gray-300 table-fixed" style="min-width: 800px;">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r" style="width: calc(100% - 160px);">Item</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r" style="width: 80px;">Available</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r" style="width: 80px;">Quantity</th>
                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase" style="width: 80px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="returnItemsTable">
                            <tr class="return-item-row">
                                <td class="px-4 py-2 border-r" style="width: calc(100% - 160px);">
                                    <select name="return_items[0][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-return" onchange="updateStockReturn(this)" required>
                                        <option value="">Select Item</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-4 py-2 border-r" style="width: 80px;">
                                    <div class="flex items-center">
                                        <span class="stock-display text-sm font-medium">-</span>
                                        <span class="ml-1 text-xs text-gray-500 unit-display"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 border-r" style="width: 80px;">
                                    <input type="number" name="return_items[0][quantity]" step="0.001" min="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateQuantity(this)" required>
                                </td>
                                <td class="px-4 py-2 text-center" style="width: 80px;">
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

<!-- Transfer Finished Goods Modal (Production Floor -> Store) -->
<div id="transferFinishedModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-10 mx-auto p-5 border w-5/6 max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Transfer Rolls & Bundles (Production Floor → Store)</h3>
            <button onclick="closeModal('transferFinishedModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-6" onsubmit="return validateTransferForm()">
            <input type="hidden" name="action" value="transfer_finished">
            
            <!-- Transfer Header -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Transfer Date *</label>
                    <input type="date" name="transfer_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purpose</label>
                    <input type="text" name="purpose" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Rolls & Bundles Transfer Production → Store" value="Rolls & Bundles Transfer Production → Store">
                </div>
            </div>

            <!-- Items Section -->
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h4 class="text-md font-semibold text-gray-900">Items to Transfer (Bundles & Rolls from Production Floor)</h4>
                    <button type="button" onclick="addTransferItem()" class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">Add Item</button>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-300 table-fixed" style="min-width: 800px;">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r" style="width: calc(100% - 160px);">Item</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r" style="width: 80px;">Available</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase border-r" style="width: 80px;">Quantity</th>
                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase" style="width: 80px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="transferItemsTable">
                            <tr class="transfer-item-row">
                                <td class="px-4 py-2 border-r" style="width: calc(100% - 160px);">
                                    <select name="transfer_items[0][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-transfer" onchange="updateTransferStock(this)" required>
                                        <option value="">Select Item</option>
                                        <?php foreach ($transfer_items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>" data-stock="<?php echo $item['production_stock']; ?>" data-unit="<?php echo $item['unit_symbol']; ?>">
                                                <?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-4 py-2 border-r" style="width: 80px;">
                                    <div class="flex items-center">
                                        <span class="stock-display text-sm font-medium">-</span>
                                        <span class="ml-1 text-xs text-gray-500 unit-display"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 border-r" style="width: 80px;">
                                    <input type="number" name="transfer_items[0][quantity]" step="0.001" min="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateTransferQuantity(this)" required>
                                </td>
                                <td class="px-4 py-2 text-center" style="width: 80px;">
                                    <button type="button" onclick="removeTransferItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('transferFinishedModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Transfer Items</button>
            </div>
        </form>
    </div>
</div>

<!-- MRN Details Modal - Shows full MRN information in readable format -->
<div id="viewMrnModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden z-50">
    <div class="relative my-4 mx-auto p-6 w-11/12 max-w-4xl shadow-2xl rounded-lg bg-white">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6 pb-4 border-b-2 border-gray-200">
            <div class="flex items-center gap-3">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <h2 class="text-2xl font-bold text-gray-900">MRN Details</h2>
            </div>
            <button onclick="closeModal('viewMrnModal')" class="text-gray-400 hover:text-gray-600 p-1 hover:bg-gray-100 rounded transition" title="Close (Esc)">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <!-- Content Area -->
        <div id="mrnDetailsContent" class="max-h-[calc(100vh-280px)] overflow-y-auto pr-3">
            <!-- Placeholder content shown initially -->
            <div class="text-center py-12">
                <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <h3 class="text-lg font-medium text-gray-600 mt-2">MRN Details</h3>
                <p class="text-gray-500 text-sm mt-1">Select a record to view details</p>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="flex justify-between items-center mt-6 pt-4 border-t border-gray-200">
            <p class="text-sm text-gray-600">Last updated: <span id="lastUpdated">-</span></p>
            <div class="flex gap-2">
                <button onclick="closeModal('viewMrnModal')" class="px-6 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 font-medium text-sm transition">
                    Close
                </button>
            </div>
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
        <td class="px-4 py-2 border-r" style="width: calc(100% - 160px);">
            <select name="items[${mrnItemCount}][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-mrn" onchange="updateStockMrn(this)" required>
                <option value="">Select Item</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="px-4 py-2 border-r" style="width: 80px;">
            <div class="flex items-center">
                <span class="stock-display text-sm font-medium">-</span>
                <span class="ml-1 text-xs text-gray-500 unit-display"></span>
            </div>
        </td>
        <td class="px-4 py-2 border-r" style="width: 80px;">
            <input type="number" name="items[${mrnItemCount}][quantity]" step="0.001" min="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateQuantity(this)" required>
        </td>
        <td class="px-4 py-2 text-center" style="width: 80px;">
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
        <td class="px-4 py-2 border-r" style="width: calc(100% - 160px);">
            <select name="return_items[${returnItemCount}][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-return" onchange="updateStockReturn(this)" required>
                <option value="">Select Item</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="px-4 py-2 border-r" style="width: 80px;">
            <div class="flex items-center">
                <span class="stock-display text-sm font-medium">-</span>
                <span class="ml-1 text-xs text-gray-500 unit-display"></span>
            </div>
        </td>
        <td class="px-4 py-2 border-r" style="width: 80px;">
            <input type="number" name="return_items[${returnItemCount}][quantity]" step="0.001" min="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateQuantity(this)" required>
        </td>
        <td class="px-4 py-2 text-center" style="width: 80px;">
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

// ===== Add/Remove rows (Transfer)
let transferItemCount = 1;

function addTransferItem() {
    const table = document.getElementById('transferItemsTable');
    const row = document.createElement('tr');
    row.className = 'transfer-item-row';
    row.innerHTML = `
        <td class="px-4 py-2 border-r" style="width: 40%;">
            <select name="transfer_items[${transferItemCount}][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-transfer" onchange="updateTransferStock(this)" required>
                <option value="">Select Item</option>
                <?php foreach ($transfer_items as $item): ?>
                    <option value="<?php echo $item['id']; ?>" data-stock="<?php echo $item['production_stock']; ?>" data-unit="<?php echo $item['unit_symbol']; ?>">
                        <?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="px-4 py-2 border-r" style="width: 25%;">
            <div class="flex items-center">
                <span class="stock-display text-sm font-medium">-</span>
                <span class="ml-1 text-xs text-gray-500 unit-display"></span>
            </div>
        </td>
        <td class="px-4 py-2 border-r" style="width: 25%;">
            <input type="number" name="transfer_items[${transferItemCount}][quantity]" step="0.001" min="0.001" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateTransferQuantity(this)" required>
        </td>
        <td class="px-4 py-2 text-center sticky right-0 bg-white border-l-2 border-gray-300" style="width: 80px;">
            <button type="button" onclick="removeTransferItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
        </td>
    `;
    table.appendChild(row);
    transferItemCount++;
}

function removeTransferItem(button) {
    const table = document.getElementById('transferItemsTable');
    if (table.rows.length > 1) { button.closest('tr').remove(); }
}

function updateTransferStock(select) {
    const row = select.closest('tr');
    const option = select.options[select.selectedIndex];
    if (option.value) {
        const stock = parseFloat(option.getAttribute('data-stock') || 0);
        const unit = option.getAttribute('data-unit') || '';
        const stockDisplay = row.querySelector('.stock-display');
        const unitDisplay = row.querySelector('.unit-display');
        const quantityInput = row.querySelector('.quantity-input');
        
        stockDisplay.textContent = stock.toFixed(3);
        stockDisplay.className = 'stock-display text-sm font-medium ' + 
            (stock === 0 ? 'text-red-600' : stock < 50 ? 'text-yellow-600' : 'text-green-600');
        
        if (unitDisplay) unitDisplay.textContent = unit;
        
        quantityInput.setAttribute('max', stock);
    } else {
        resetStockDisplay(row);
    }
}

function validateTransferQuantity(input) {
    const row = input.closest('tr');
    const maxStock = parseFloat(input.getAttribute('max') || 0);
    const requestedQty = parseFloat(input.value || 0);
    if (requestedQty > maxStock) {
        input.style.borderColor = '#ef4444';
        input.title = `Insufficient stock! Available: ${maxStock.toFixed(3)}, Requested: ${requestedQty.toFixed(3)}`;
        row.classList.add('insufficient-stock');
        let warningDiv = row.querySelector('.stock-warning');
        if (!warningDiv) {
            warningDiv = document.createElement('div');
            warningDiv.className = 'stock-warning text-xs text-red-600 mt-1';
            row.cells[1].appendChild(warningDiv);
        }
        warningDiv.textContent = `Insufficient: Avail ${maxStock.toFixed(3)}, Req ${requestedQty.toFixed(3)}`;
        return false;
    } else {
        input.style.borderColor = '#d1d5db';
        input.title = '';
        row.classList.remove('insufficient-stock');
        const warningDiv = row.querySelector('.stock-warning');
        if (warningDiv) warningDiv.remove();
        return true;
    }
}

function validateTransferForm() {
    let isValid = true;
    const quantityInputs = document.querySelectorAll('#transferItemsTable .quantity-input');
    quantityInputs.forEach(input => {
        if (!validateTransferQuantity(input)) {
            isValid = false;
        }
    });
    if (!isValid) {
        alert('Please correct the quantity errors before submitting.');
    }
    return isValid;
}

// ===== Stock helpers
function fetchLocationSpecificStock(itemId, locationId, row) {
    const stockDisplay = row.querySelector('.stock-display');
    const isCategoryRow = row.classList.contains('category-row');
    
    stockDisplay.textContent = 'Loading...';
    stockDisplay.className = 'stock-display text-sm font-medium text-blue-600';
    
    // For category rows, hide the category total when loading
    if (isCategoryRow) {
        const categoryTotalSpan = row.querySelector('.category-total');
        if (categoryTotalSpan) categoryTotalSpan.style.display = 'none';
    }
    
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
                
                // For category rows, keep the category total hidden when item is selected
                if (isCategoryRow) {
                    const categoryTotalSpan = row.querySelector('.category-total');
                    if (categoryTotalSpan) categoryTotalSpan.style.display = 'none';
                }
                
                quantityInput.setAttribute('max', currentStock);
                
                const requestedQty = parseFloat(quantityInput.value || 0);
                if (requestedQty > currentStock) {
                    quantityInput.style.borderColor = '#ef4444';
                    quantityInput.title = `Insufficient stock! Available: ${currentStock.toFixed(3)}, Requested: ${requestedQty.toFixed(3)}`;
                    // Add warning class and message
                    row.classList.add('insufficient-stock');
                    let warningDiv = row.querySelector('.stock-warning');
                    if (!warningDiv) {
                        warningDiv = document.createElement('div');
                        warningDiv.className = 'stock-warning text-xs text-red-600 mt-1';
                        row.cells[1].appendChild(warningDiv);
                    }
                    warningDiv.textContent = `Insufficient: Avail ${currentStock.toFixed(3)}, Req ${requestedQty.toFixed(3)}`;
                } else {
                    quantityInput.style.borderColor = '#d1d5db';
                    quantityInput.title = '';
                    row.classList.remove('insufficient-stock');
                    const warningDiv = row.querySelector('.stock-warning');
                    if (warningDiv) warningDiv.remove();
                }
            } else {
                stockDisplay.textContent = 'Error';
                stockDisplay.className = 'stock-display text-sm font-medium text-red-600';
                // For category rows, show category total again on error
                if (isCategoryRow) {
                    const categoryTotalSpan = row.querySelector('.category-total');
                    if (categoryTotalSpan) categoryTotalSpan.style.display = 'inline';
                }
            }
        })
        .catch(() => {
            stockDisplay.textContent = 'Error';
            stockDisplay.className = 'stock-display text-sm font-medium text-red-600';
            // For category rows, show category total again on error
            if (isCategoryRow) {
                const categoryTotalSpan = row.querySelector('.category-total');
                if (categoryTotalSpan) categoryTotalSpan.style.display = 'inline';
            }
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
    const isCategoryRow = row.classList.contains('category-row');
    
    if (isCategoryRow) {
        stockDisplay.textContent = 'Select item first';
        // Show category total for category rows
        const categoryTotalSpan = row.querySelector('.category-total');
        if (categoryTotalSpan) categoryTotalSpan.style.display = 'inline';
    } else {
        stockDisplay.textContent = '-';
    }
    
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
        input.title = `Insufficient stock! Available: ${maxStock.toFixed(3)}, Requested: ${requestedQty.toFixed(3)}`;
        // Add warning class and message
        row.classList.add('insufficient-stock');
        let warningDiv = row.querySelector('.stock-warning');
        if (!warningDiv) {
            warningDiv = document.createElement('div');
            warningDiv.className = 'stock-warning text-xs text-red-600 mt-1';
            row.cells[1].appendChild(warningDiv);
        }
        warningDiv.textContent = `Insufficient: Avail ${maxStock.toFixed(3)}, Req ${requestedQty.toFixed(3)}`;
        return false;
    } else {
        input.style.borderColor = '#d1d5db';
        input.title = '';
        row.classList.remove('insufficient-stock');
        const warningDiv = row.querySelector('.stock-warning');
        if (warningDiv) warningDiv.remove();
        return true;
    }
}

// Production Batch Raw Materials Loading
document.getElementById('production_batch').addEventListener('change', function() {
    const btn = document.getElementById('load_materials_btn');
    btn.disabled = !this.value;
});

document.getElementById('load_materials_btn').addEventListener('click', function() {
    const productionId = document.getElementById('production_batch').value;
    if (!productionId) {
        alert('Please select a Production Batch first.');
        return;
    }

    fetch(`api/get_production_materials.php?production_id=${productionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert(data.error);
                return;
            }

            const table = document.getElementById('mrnItemsTable');
            const existingItems = new Map(); // item_id to row index

            // Build map of existing items and categories
            Array.from(table.rows).forEach((row, index) => {
                const select = row.querySelector('.item-select-mrn');
                if (select && select.value) {
                    existingItems.set(select.value, index);
                }
                // Also check for category rows
                const categoryIdInput = row.querySelector('input[name*="[category_id]"]');
                if (categoryIdInput && categoryIdInput.value) {
                    existingItems.set('category_' + categoryIdInput.value, index);
                }
            });

            data.materials.forEach(material => {
                if (material.is_category) {
                    // Check if this category already exists
                    const categoryKey = 'category_' + material.category_id;
                    if (existingItems.has(categoryKey)) {
                        // Update existing category row: add to quantity
                        const rowIndex = existingItems.get(categoryKey);
                        const row = table.rows[rowIndex];
                        const quantityInput = row.querySelector('.quantity-input');
                        const currentQty = parseFloat(quantityInput.value || 0);
                        const newQty = currentQty + parseFloat(material.required_quantity);
                        quantityInput.value = newQty.toFixed(3);
                        // Update max to reflect combined total stock
                        const currentMax = parseFloat(quantityInput.getAttribute('max') || 0);
                        quantityInput.setAttribute('max', (currentMax + parseFloat(material.total_available_stock)).toFixed(3));
                        // Re-validate quantity
                        validateQuantity(quantityInput);
                    } else {
                        // Category-based material - use all items in category proportionally
                        const row = table.insertRow();
                        row.className = 'mrn-item-row category-row';
                        row.innerHTML = `
                            <td class="px-4 py-2 border-r w-2/5">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-gray-700">${material.category_name} (Category)</span>
                                    <span class="text-xs text-gray-600">Will use all items in this category proportionally</span>
                                    <input type="hidden" name="items[${mrnItemCount}][category_id]" value="${material.category_id}">
                                    <input type="hidden" name="items[${mrnItemCount}][is_category]" value="1">
                                </div>
                            </td>
                            <td class="px-4 py-2 border-r w-1/4">
                                <div class="flex items-center">
                                    <span class="stock-display text-sm font-medium ${parseFloat(material.total_available_stock) === 0 ? 'text-red-600' : parseFloat(material.total_available_stock) < 50 ? 'text-yellow-600' : 'text-green-600'}">${parseFloat(material.total_available_stock).toFixed(3)}</span>
                                    <span class="ml-1 text-xs text-gray-500 unit-display">${material.uom}</span>
                                    <span class="ml-2 text-xs text-gray-600">(Total category stock)</span>
                                </div>
                            </td>
                            <td class="px-4 py-2 border-r w-1/4">
                                <input type="number" name="items[${mrnItemCount}][quantity]" step="0.001" min="0.001" max="${parseFloat(material.total_available_stock)}" value="${parseFloat(material.required_quantity).toFixed(3)}" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateQuantity(this)" required>
                            </td>
                            <td class="px-4 py-2 text-center w-20 sticky right-0 bg-white border-l-2 border-gray-300">
                                <button type="button" onclick="removeMrnItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
                            </td>
                        `;
                        mrnItemCount++;
                    }
                } else {
                    // Normal material - check if already exists
                    const itemId = material.item_id.toString();
                    if (existingItems.has(itemId)) {
                        // Update existing row: add to quantity
                        const rowIndex = existingItems.get(itemId);
                        const row = table.rows[rowIndex];
                        const quantityInput = row.querySelector('.quantity-input');
                        const currentQty = parseFloat(quantityInput.value || 0);
                        const newQty = currentQty + parseFloat(material.required_quantity);
                        quantityInput.value = newQty.toFixed(3);
                        // Re-validate stock for this row
                        updateStockMrn(row.querySelector('.item-select-mrn'));
                    } else {
                        // Add new row for normal material
                        const row = table.insertRow();
                        row.className = 'mrn-item-row';
                        row.innerHTML = `
                            <td class="px-4 py-2 border-r">
                                <select name="items[${mrnItemCount}][item_id]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm item-select-mrn" onchange="updateStockMrn(this)" required>
                                    <option value="${material.item_id}" selected>${material.item_name} (${material.item_code})</option>
                                </select>
                            </td>
                            <td class="px-4 py-2 border-r">
                                <div class="flex items-center">
                                    <span class="stock-display text-sm font-medium text-blue-600">Loading...</span>
                                    <span class="ml-1 text-xs text-gray-500 unit-display">${material.uom}</span>
                                </div>
                            </td>
                            <td class="px-4 py-2 border-r">
                                <input type="number" name="items[${mrnItemCount}][quantity]" step="0.001" min="0.001" value="${parseFloat(material.required_quantity).toFixed(3)}" class="w-full px-2 py-1 border border-gray-300 rounded text-sm quantity-input" placeholder="0.000" onchange="validateQuantity(this)" required>
                            </td>
                            <td class="px-4 py-2 text-center">
                                <button type="button" onclick="removeMrnItem(this)" class="text-red-600 hover:text-red-900 text-sm">Remove</button>
                            </td>
                        `;
                        // Trigger stock update for the new row
                        updateStockMrn(row.querySelector('.item-select-mrn'));
                        mrnItemCount++;
                    }
                }
            });

            alert('Raw materials loaded successfully. You can edit quantities as needed.');
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to load raw materials. Please try again.');
        });
});

// Basic validators (can be extended)
function validateMrnForm(){
    // All validations are now handled by HTML required attributes and quantity validation
    return true;
}
function validateReturnForm(){ return true; }

// Fetch and display MRN details
function viewMrnDetails(mrnId) {
    if (!mrnId || isNaN(mrnId)) {
        showMrnError('Invalid MRN ID');
        return;
    }
    
    openModal('viewMrnModal');
    showMrnLoading();
    
    fetch(`get_mrn_details.php?mrn_id=${encodeURIComponent(mrnId)}`)
        .then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        })
        .then(data => {
            if (data.success && data.mrn) {
                displayMrnDetails(data);
            } else {
                showMrnError(data.message || 'No data found');
            }
        })
        .catch(err => {
            console.error('MRN View Error:', err);
            showMrnError(`Error: ${err.message}`);
        });
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
    
    if (!data.mrn || !data.items) {
        showMrnError('Invalid data format received from server');
        return;
    }
    
    const mrn = data.mrn;
    const items = data.items || [];
    
    let itemsHtml = '';
    if (items.length > 0) {
        itemsHtml = `
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100 border-b-2 border-gray-300">
                        <th class="text-left px-4 py-2 font-semibold text-gray-700">Item Name</th>
                        <th class="text-left px-4 py-2 font-semibold text-gray-700">Code</th>
                        <th class="text-right px-4 py-2 font-semibold text-gray-700">Quantity</th>
                        <th class="text-left px-4 py-2 font-semibold text-gray-700">Unit</th>
                        <th class="text-left px-4 py-2 font-semibold text-gray-700">Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item, idx) => `
                        <tr class="${idx % 2 === 0 ? 'bg-white' : 'bg-gray-50'} border-b border-gray-200 hover:bg-blue-50">
                            <td class="px-4 py-3 text-gray-900">${escapeHtml(item.item_name || 'N/A')}</td>
                            <td class="px-4 py-3 text-gray-600 font-mono">${escapeHtml(item.item_code || 'N/A')}</td>
                            <td class="px-4 py-3 text-right font-semibold">${parseFloat(item.quantity || 0).toFixed(3)}</td>
                            <td class="px-4 py-3 text-gray-600">${escapeHtml(item.unit || 'kg')}</td>
                            <td class="px-4 py-3"><span class="inline-block px-2 py-1 text-xs font-semibold rounded ${getStatusColor(item.status)}">${escapeHtml(item.status || 'pending')}</span></td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    } else {
        itemsHtml = '<p class="text-gray-600 mt-4 text-center">No items found</p>';
    }
    
    const totalQty = items.reduce((sum, item) => sum + parseFloat(item.quantity || 0), 0);
    
    content.innerHTML = `
        <div class="space-y-6">
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4 bg-gray-50 p-4 rounded-lg border border-gray-200">
                <div><p class="text-xs font-semibold text-gray-500 uppercase">MRN No</p><p class="text-lg font-bold text-gray-900 font-mono mt-1">${escapeHtml(mrn.mrn_no || 'N/A')}</p></div>
                <div><p class="text-xs font-semibold text-gray-500 uppercase">Date</p><p class="text-lg font-bold text-gray-900 mt-1">${escapeHtml(mrn.mrn_date || 'N/A')}</p></div>
                <div><p class="text-xs font-semibold text-gray-500 uppercase">Status</p><p class="text-lg font-bold mt-1"><span class="inline-block px-3 py-1 text-sm rounded-full font-semibold ${getStatusColor(mrn.status)}">${escapeHtml(mrn.status || 'pending')}</span></p></div>
                <div><p class="text-xs font-semibold text-gray-500 uppercase">Items</p><p class="text-lg font-bold text-gray-900 mt-1">${items.length}</p></div>
            </div>
            
            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded">
                <p class="text-xs font-semibold text-gray-600 uppercase mb-1">Purpose</p>
                <p class="text-gray-900 text-base">${escapeHtml(mrn.purpose || 'No purpose specified')}</p>
            </div>
            
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-3">Materials Requested</h4>
                ${itemsHtml}
            </div>
            
            <div class="bg-green-50 border border-green-200 p-4 rounded-lg">
                <h4 class="font-semibold text-green-900 mb-2">Summary</h4>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-gray-700">Total Items:</dt><dd class="font-semibold">${items.length}</dd>
                    <dt class="text-gray-700">Total Quantity:</dt><dd class="font-semibold">${totalQty.toFixed(3)} kg</dd>
                    <dt class="text-gray-700">Created:</dt><dd class="font-semibold">${escapeHtml(mrn.created_at ? mrn.created_at.split(' ')[0] : 'N/A')}</dd>
                </dl>
            </div>
        </div>
    `;
}

function getStatusColor(status) {
    const colors = {
        'pending': 'bg-yellow-100 text-yellow-800',
        'approved': 'bg-blue-100 text-blue-800',
        'issued': 'bg-green-100 text-green-800',
        'completed': 'bg-green-100 text-green-800',
        'rejected': 'bg-red-100 text-red-800'
    };
    return colors[status?.toLowerCase()] || 'bg-gray-100 text-gray-800';
}

function escapeHtml(text) {
    const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    return String(text).replace(/[&<>"']/g, char => map[char]);
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
