<?php
// trolley.php - Complete Trolley Management System

// Load database and setup before form processing
require_once 'database.php';
$database = new Database();
$db = $database->getConnection();

/**
 * Update item current_stock from stock_ledger (ALL locations)
 * GLOBAL RULE: current_stock = total across all locations
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

// Handle form submissions BEFORE outputting any content
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $success = null;
    $error = null;
    $transaction_started = false;
    
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create_movement':
                    // Create new trolley movement from production - supports multiple trolleys
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    // Validate production exists and is completed
                    // Get weight from BOM tables if available, fallback to unit_weight_kg
                    $stmt = $db->prepare("
                        SELECT p.*, i.name as item_name,
                               COALESCE(bp.product_unit_qty, bd.finished_unit_qty, i.unit_weight_kg) as unit_weight_kg,
                               l.name as location_name
                        FROM production p
                        JOIN items i ON p.item_id = i.id
                        JOIN locations l ON p.location_id = l.id
                        LEFT JOIN bom_product bp ON bp.finished_item_id = i.id
                        LEFT JOIN bom_direct bd ON bd.finished_item_id = i.id
                        WHERE p.id = ? AND p.status = 'completed'
                        GROUP BY p.id
                    ");
                    $stmt->execute([$_POST['production_id']]);
                    $production = $stmt->fetch();
                    
                    if (!$production) {
                        throw new Exception("Production batch not found or not completed");
                    }
                    
                    // Get trolley IDs (can be single or multiple)
                    $trolley_ids = isset($_POST['trolley_ids']) ? $_POST['trolley_ids'] : [$_POST['trolley_id']];
                    if (!is_array($trolley_ids)) {
                        $trolley_ids = [$trolley_ids];
                    }
                    $trolley_ids = array_filter($trolley_ids); // Remove empty values
                    
                    if (empty($trolley_ids)) {
                        throw new Exception("At least one trolley must be selected");
                    }
                    
                    // Calculate total expected weight and units
                    $expected_units = floatval($production['actual_qty']);
                    $unit_weight = floatval($production['unit_weight_kg']);
                    $expected_weight = $expected_units * $unit_weight;
                    
                    // Distribute units equally among trolleys (default if not specified)
                    $trolley_count = count($trolley_ids);
                    $units_per_trolley = $expected_units / $trolley_count;
                    $weight_per_trolley = $expected_weight / $trolley_count;
                    
                    // Create movements for each trolley
                    $created_movements = [];
                    foreach ($trolley_ids as $trolley_id) {
                        // Check if movement already exists for this production + trolley combination
                        $check_stmt = $db->prepare("
                            SELECT id FROM trolley_movements 
                            WHERE production_id = ? AND trolley_id = ? AND status != 'completed' AND status != 'rejected'
                            LIMIT 1
                        ");
                        $check_stmt->execute([$_POST['production_id'], $trolley_id]);
                        $existing = $check_stmt->fetch();
                        
                        if ($existing) {
                            // Movement already exists, skip this trolley
                            $created_movements[] = $existing['id'];
                            continue;
                        }
                        
                        // Check if individual weight was specified for this trolley
                        $trolley_weight_key = 'trolley_weight_' . $trolley_id;
                        
                        if (isset($_POST[$trolley_weight_key]) && $_POST[$trolley_weight_key] != '') {
                            $weight_per_trolley = floatval($_POST[$trolley_weight_key]);
                            // Calculate units from weight if weight is provided
                            $units_per_trolley = $unit_weight > 0 ? $weight_per_trolley / $unit_weight : 0;
                        } else {
                            // Default distribution
                            $units_per_trolley = $expected_units / $trolley_count;
                            $weight_per_trolley = $expected_weight / $trolley_count;
                        }
                        
                        // Generate unique movement number for each trolley
                        $stmt = $db->query("SELECT COUNT(*) as count FROM trolley_movements");
                        $count = $stmt->fetch()['count'] + 1;
                        $movement_no = 'TM' . str_pad($count, 4, '0', STR_PAD_LEFT);
                        
                        // Insert trolley movement
                        $stmt = $db->prepare("
                            INSERT INTO trolley_movements (
                                movement_no, trolley_id, production_id, from_location_id, to_location_id,
                                movement_date, expected_weight_kg, expected_units, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                        ");
                        $stmt->execute([
                            $movement_no,
                            $trolley_id,
                            $_POST['production_id'],
                            $production['location_id'], // From production location
                            1, // To store (location_id = 1)
                            $_POST['movement_date'],
                            $weight_per_trolley,
                            $units_per_trolley
                        ]);
                        $movement_id = $db->lastInsertId();
                        $created_movements[] = $movement_id;
                        
                        // Insert trolley item
                        $stmt = $db->prepare("
                            INSERT INTO trolley_items (
                                movement_id, item_id, expected_quantity, expected_weight_kg, unit_weight_kg
                            ) VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $movement_id,
                            $production['item_id'],
                            $units_per_trolley,
                            $weight_per_trolley,
                            $unit_weight
                        ]);
                        
                        // Update trolley status to in_use
                        $stmt = $db->prepare("UPDATE trolleys SET status = 'in_use' WHERE id = ?");
                        $stmt->execute([$trolley_id]);
                    }
                    
                    // Mark production batch as moved to trolley (update status)
                    $stmt = $db->prepare("UPDATE production SET status = 'moved_to_trolley' WHERE id = ?");
                    $stmt->execute([$_POST['production_id']]);
                    
                    $db->commit();
                    $transaction_started = false;
                    $success = "✅ {$trolley_count} trolley movements created! Assigned quantities and weights per trolley.";
                    
                    // Redirect to prevent duplicate submission on page reload
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                    
                case 'verify_weight':
                    // Verify trolley weight - calculates actual units from weight
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    $movement_id = $_POST['movement_id'];
                    $actual_weight = floatval($_POST['actual_weight_kg']);
                    
                    // Get movement details
                    $stmt = $db->prepare("
                        SELECT tm.*, ti.expected_quantity, ti.expected_weight_kg, ti.unit_weight_kg, ti.item_id,
                               i.weight_tolerance_percent, i.name as item_name
                        FROM trolley_movements tm
                        JOIN trolley_items ti ON tm.id = ti.movement_id
                        JOIN items i ON ti.item_id = i.id
                        WHERE tm.id = ?
                    ");
                    $stmt->execute([$movement_id]);
                    $movement = $stmt->fetch();
                    
                    if (!$movement) {
                        throw new Exception("Trolley movement not found");
                    }
                    
                    // Calculate actual units from the entered weight
                    // For items measured in kg, the weight value IS the quantity
                    $actual_units = $actual_weight;
                    
                    // Calculate variances
                    $weight_variance = $actual_weight - $movement['expected_weight_kg'];
                    $unit_variance = $actual_units - $movement['expected_quantity'];
                    
                    // Check tolerances - use 5% default if not set
                    $tolerance_percent = !empty($movement['weight_tolerance_percent']) ? floatval($movement['weight_tolerance_percent']) : 5.0;
                    $weight_tolerance = ($tolerance_percent / 100) * $movement['expected_weight_kg'];
                    $weight_within_tolerance = abs($weight_variance) <= $weight_tolerance;
                    $units_match = $actual_units == $movement['expected_quantity'];
                    
                    // Set status based on verification
                    // IMPORTANT: Accept actual quantities even if they differ from expected
                    // Only reject if weight is outside tolerance
                    $verification_status = $weight_within_tolerance ? 'verified' : 'rejected';
                    
                    // Update movement
                    $stmt = $db->prepare("
                        UPDATE trolley_movements SET 
                            actual_weight_kg = ?, 
                            actual_units = ?,
                            weight_variance_kg = ?,
                            unit_variance = ?,
                            status = ?,
                            verified_by = ?,
                            verified_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $actual_weight,
                        $actual_units,
                        $weight_variance,
                        $unit_variance,
                        $verification_status,
                        6, // Current user ID - replace with actual user session
                        $movement_id
                    ]);
                    
                    // Update trolley item with actual quantities
                    $stmt = $db->prepare("
                        UPDATE trolley_items SET 
                            actual_quantity = ?,
                            actual_weight_kg = ?,
                            variance_quantity = ?,
                            variance_weight_kg = ?,
                            status = ?
                        WHERE movement_id = ?
                    ");
                    $stmt->execute([
                        $actual_units,
                        $actual_weight,
                        $unit_variance,
                        $weight_variance,
                        $verification_status,
                        $movement_id
                    ]);
                    
                    if ($verification_status === 'verified') {
                        // Transfer stock from production to store using ACTUAL quantities
                        $production_location = $movement['from_location_id'];
                        $store_location = $movement['to_location_id'];
                        
                        // Get current balances
                        $stmt = $db->prepare("
                            SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                            FROM stock_ledger 
                            WHERE item_id = ? AND location_id = ?
                        ");
                        
                        // Production location balance
                        $stmt->execute([$movement['item_id'], $production_location]);
                        $prod_balance = $stmt->fetch()['current_balance'];
                        
                        // If no production ledger entry exists, create one from items.current_stock
                        // This handles items that were manually added or from legacy data
                        $check_ledger = $db->prepare("
                            SELECT COUNT(*) as count FROM stock_ledger 
                            WHERE item_id = ? AND location_id = ?
                        ");
                        $check_ledger->execute([$movement['item_id'], $production_location]);
                        $has_ledger = $check_ledger->fetch()['count'] > 0;
                        
                        if (!$has_ledger) {
                            // Get item's current_stock as initial balance
                            $item_stmt = $db->prepare("SELECT current_stock FROM items WHERE id = ?");
                            $item_stmt->execute([$movement['item_id']]);
                            $current_stock = floatval($item_stmt->fetchColumn() ?? 0);
                            
                            if ($current_stock > 0) {
                                // Create initial production_in entry
                                $init_stmt = $db->prepare("
                                    INSERT INTO stock_ledger (
                                        item_id, location_id, transaction_type, reference_id, reference_no,
                                        transaction_date, quantity_in, balance, created_at
                                    ) VALUES (?, ?, 'initial_stock', 0, 'INITIAL', ?, ?, ?, NOW())
                                ");
                                $init_stmt->execute([
                                    $movement['item_id'],
                                    $production_location,
                                    date('Y-m-d'),
                                    $current_stock,
                                    $current_stock
                                ]);
                                $prod_balance = $current_stock;
                            }
                        }
                        
                        $new_prod_balance = $prod_balance - $actual_units; // Use ACTUAL units
                        
                        // Store location balance  
                        $stmt->execute([$movement['item_id'], $store_location]);
                        $store_balance = $stmt->fetch()['current_balance'];
                        $new_store_balance = $store_balance + $actual_units; // Use ACTUAL units
                        
                        // Record stock movements with ACTUAL quantities
                        // OUT from production
                        $stmt = $db->prepare("
                            INSERT INTO stock_ledger (
                                item_id, location_id, transaction_type, reference_id, reference_no,
                                transaction_date, quantity_out, balance, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $movement['item_id'],
                            $production_location,
                            'trolley_movement_out',
                            $movement_id,
                            $movement['movement_no'],
                            $movement['movement_date'],
                            $actual_units, // Real quantity out
                            $new_prod_balance
                        ]);
                        
                        // IN to store
                        $stmt = $db->prepare("
                            INSERT INTO stock_ledger (
                                item_id, location_id, transaction_type, reference_id, reference_no,
                                transaction_date, quantity_in, balance, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $movement['item_id'],
                            $store_location,
                            'trolley_movement_in',
                            $movement_id,
                            $movement['movement_no'],
                            $movement['movement_date'],
                            $actual_units, // Real quantity in
                            $new_store_balance
                        ]);
                        
                        // Update item's current_stock from ALL locations (not just store)
                        updateItemCurrentStock($db, $movement['item_id']);
                        
                        // Update movement status to completed
                        $stmt = $db->prepare("UPDATE trolley_movements SET status = 'completed' WHERE id = ?");
                        $stmt->execute([$movement_id]);
                        
                        // Free up trolley - move to store location
                        $stmt = $db->prepare("UPDATE trolleys SET status = 'available', current_location_id = ? WHERE id = ?");
                        $stmt->execute([$store_location, $movement['trolley_id']]);
                        
                        $variance_info = "";
                        if ($unit_variance != 0) {
                            $variance_info = " | Qty Variance: " . ($unit_variance >= 0 ? '+' : '') . number_format($unit_variance, 3) . " pcs";
                        }
                        
                        $success = "✅ VERIFIED & RELEASED! {$actual_units} pcs transferred to store | Weight variance: " . 
                                  ($weight_variance >= 0 ? '+' : '') . number_format($weight_variance, 3) . " kg" . $variance_info;
                    } else {
                        $rejection_reasons = [];
                        if (!$weight_within_tolerance) {
                            $rejection_reasons[] = "Weight variance " . number_format($weight_variance, 3) . 
                                                 " kg exceeds tolerance ±" . number_format($weight_tolerance, 3) . " kg";
                        }
                        
                        $stmt = $db->prepare("
                            UPDATE trolley_items SET rejection_reason = ? WHERE movement_id = ?
                        ");
                        $stmt->execute([implode('; ', $rejection_reasons), $movement_id]);
                        
                        // Return trolley to available status
                        $stmt = $db->prepare("UPDATE trolleys SET status = 'available' WHERE id = ?");
                        $stmt->execute([$movement['trolley_id']]);
                        
                        $error = "❌ WEIGHT VERIFICATION FAILED: " . implode('. ', $rejection_reasons) . " | Trolley not released";
                    }
                    
                    $db->commit();
                    $transaction_started = false;
                    
                    // Redirect to prevent duplicate submission on page reload
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                    
                case 'update_movement':
                    // Update a verified movement with new weight and recalculate ledger
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    $movement_id = $_POST['movement_id'];
                    $new_actual_weight = floatval($_POST['actual_weight_kg']);
                    
                    // Get movement details
                    $stmt = $db->prepare("
                        SELECT tm.*, ti.unit_weight_kg, ti.item_id,
                               i.weight_tolerance_percent, i.name as item_name
                        FROM trolley_movements tm
                        JOIN trolley_items ti ON tm.id = ti.movement_id
                        JOIN items i ON ti.item_id = i.id
                        WHERE tm.id = ?
                    ");
                    $stmt->execute([$movement_id]);
                    $movement = $stmt->fetch();
                    
                    if (!$movement) {
                        throw new Exception("Trolley movement not found");
                    }
                    
                    $new_actual_units = $new_actual_weight; // Weight = Quantity
                    $old_actual_weight = floatval($movement['actual_weight_kg']);
                    $old_actual_units = floatval($movement['actual_units']);
                    
                    // Only update if weight/units changed
                    if ($new_actual_weight != $old_actual_weight) {
                        $unit_weight = floatval($movement['unit_weight_kg']);
                        $weight_tolerance = floatval($movement['weight_tolerance_percent']);
                        $weight_variance = $new_actual_weight - floatval($movement['expected_weight_kg']);
                        $unit_variance = $new_actual_units - floatval($movement['expected_units']);
                        $weight_tolerance_kg = floatval($movement['expected_weight_kg']) * ($weight_tolerance / 100);
                        $weight_within_tolerance = abs($weight_variance) <= $weight_tolerance_kg;
                        
                        $new_status = $weight_within_tolerance ? 'verified' : 'rejected';
                        
                        // Update movement record
                        $stmt = $db->prepare("
                            UPDATE trolley_movements SET 
                                actual_weight_kg = ?, 
                                actual_units = ?,
                                weight_variance_kg = ?,
                                unit_variance = ?,
                                status = ?,
                                verified_by = ?,
                                verified_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $new_actual_weight,
                            $new_actual_units,
                            $weight_variance,
                            $unit_variance,
                            $new_status,
                            6, // Current user ID
                            $movement_id
                        ]);
                        
                        // Update trolley item with new quantities
                        $stmt = $db->prepare("
                            UPDATE trolley_items SET 
                                actual_quantity = ?,
                                actual_weight_kg = ?,
                                variance_quantity = ?,
                                variance_weight_kg = ?,
                                status = ?
                            WHERE movement_id = ?
                        ");
                        $stmt->execute([
                            $new_actual_units,
                            $new_actual_weight,
                            $unit_variance,
                            $weight_variance,
                            $new_status,
                            $movement_id
                        ]);
                        
                        if ($new_status === 'verified') {
                            // Delete old ledger entries for this movement
                            $stmt = $db->prepare("
                                DELETE FROM stock_ledger 
                                WHERE reference_id = ? AND reference_no LIKE 'TM%' 
                            ");
                            $stmt->execute([$movement_id]);
                            
                            // Recalculate balances
                            $production_location = $movement['from_location_id'];
                            $store_location = $movement['to_location_id'];
                            
                            $stmt = $db->prepare("
                                SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                                FROM stock_ledger 
                                WHERE item_id = ? AND location_id = ?
                            ");
                            
                            // Production location balance
                            $stmt->execute([$movement['item_id'], $production_location]);
                            $prod_balance = $stmt->fetch()['current_balance'];
                            $new_prod_balance = $prod_balance - $new_actual_units;
                            
                            // Store location balance  
                            $stmt->execute([$movement['item_id'], $store_location]);
                            $store_balance = $stmt->fetch()['current_balance'];
                            $new_store_balance = $store_balance + $new_actual_units;
                            
                            // Insert new ledger entries with updated quantities
                            // OUT from production
                            $stmt = $db->prepare("
                                INSERT INTO stock_ledger (
                                    item_id, location_id, transaction_type, reference_id, reference_no,
                                    transaction_date, quantity_out, balance, created_at
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $stmt->execute([
                                $movement['item_id'],
                                $production_location,
                                'trolley_movement_out',
                                $movement_id,
                                $movement['movement_no'],
                                $movement['movement_date'],
                                $new_actual_units,
                                $new_prod_balance
                            ]);
                            
                            // IN to store
                            $stmt = $db->prepare("
                                INSERT INTO stock_ledger (
                                    item_id, location_id, transaction_type, reference_id, reference_no,
                                    transaction_date, quantity_in, balance, created_at
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $stmt->execute([
                                $movement['item_id'],
                                $store_location,
                                'trolley_movement_in',
                                $movement_id,
                                $movement['movement_no'],
                                $movement['movement_date'],
                                $new_actual_units,
                                $new_store_balance
                            ]);
                            
                            // Update item's current_stock from ALL locations (not just store)
                            updateItemCurrentStock($db, $movement['item_id']);
                            
                            $variance_info = "";
                            if ($unit_variance != 0) {
                                $variance_info = " | Qty Variance: " . ($unit_variance >= 0 ? '+' : '') . number_format($unit_variance, 3) . " pcs";
                            }
                            
                            $success = "✅ MOVEMENT UPDATED & VERIFIED! {$new_actual_units} pcs transferred (was {$old_actual_units} pcs) | Ledger updated";
                        } else {
                            $success = "⚠️ MOVEMENT UPDATED - Weight variance exceeds tolerance, needs re-verification";
                        }
                    } else {
                        $success = "No changes made - weight is the same";
                    }
                    
                    $db->commit();
                    $transaction_started = false;
                    
                    // Redirect to prevent duplicate submission on page reload
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                    
                case 'reset_movement':
                    // Reset a rejected movement for retry
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    $stmt = $db->prepare("UPDATE trolley_movements SET status = 'pending', verified_by = NULL, verified_at = NULL WHERE id = ? AND status = 'rejected'");
                    $stmt->execute([$_POST['movement_id']]);
                    
                    $stmt = $db->prepare("UPDATE trolley_items SET status = 'pending', rejection_reason = NULL WHERE movement_id = ?");
                    $stmt->execute([$_POST['movement_id']]);
                    
                    $db->commit();
                    $transaction_started = false;
                    $success = "Movement reset for retry";
                    break;
                    
                case 'add_trolley':
                    // Add new trolley
                    $trolley_no = trim($_POST['trolley_no']);
                    $trolley_name = trim($_POST['trolley_name']);
                    $max_weight = floatval($_POST['max_weight_kg']);
                    $location_id = intval($_POST['location_id']);
                    
                    // Check if trolley number already exists
                    $stmt = $db->prepare("SELECT id FROM trolleys WHERE trolley_no = ?");
                    $stmt->execute([$trolley_no]);
                    if ($stmt->fetch()) {
                        throw new Exception("Trolley number '{$trolley_no}' already exists!");
                    }
                    
                    $stmt = $db->prepare("
                        INSERT INTO trolleys (trolley_no, trolley_name, max_weight_kg, status, current_location_id)
                        VALUES (?, ?, ?, 'available', ?)
                    ");
                    $stmt->execute([$trolley_no, $trolley_name, $max_weight, $location_id]);
                    $success = "✅ Trolley '{$trolley_name}' added successfully!";
                    break;
                    
                case 'edit_trolley':
                    // Edit existing trolley
                    $trolley_id = intval($_POST['trolley_id']);
                    $trolley_name = trim($_POST['trolley_name']);
                    $max_weight = floatval($_POST['max_weight_kg']);
                    $status = $_POST['status'];
                    $location_id = intval($_POST['location_id']);
                    
                    $stmt = $db->prepare("
                        UPDATE trolleys 
                        SET trolley_name = ?, max_weight_kg = ?, status = ?, current_location_id = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$trolley_name, $max_weight, $status, $location_id, $trolley_id]);
                    $success = "✅ Trolley updated successfully!";
                    break;
                    
                case 'delete_trolley':
                    // Delete trolley
                    $trolley_id = intval($_POST['trolley_id']);
                    
                    // Check if trolley has any active movements
                    $stmt = $db->prepare("
                        SELECT COUNT(*) as count FROM trolley_movements 
                        WHERE trolley_id = ? AND status IN ('pending', 'in_transit', 'verified')
                    ");
                    $stmt->execute([$trolley_id]);
                    if ($stmt->fetch()['count'] > 0) {
                        throw new Exception("Cannot delete trolley with active movements!");
                    }
                    
                    $stmt = $db->prepare("DELETE FROM trolleys WHERE id = ?");
                    $stmt->execute([$trolley_id]);
                    $success = "✅ Trolley deleted successfully!";
                    break;
                    
                case 'change_status':
                    // Change trolley status
                    $trolley_id = intval($_POST['trolley_id']);
                    $status = $_POST['status'];
                    
                    $stmt = $db->prepare("UPDATE trolleys SET status = ? WHERE id = ?");
                    $stmt->execute([$status, $trolley_id]);
                    $success = "✅ Trolley status updated to '{$status}'!";
                    break;
            }
        }
    } catch(PDOException $e) {
        if ($transaction_started && $db->inTransaction()) {
            $db->rollback();
        }
        $error = "Database error: " . $e->getMessage();
    } catch(Exception $e) {
        if ($transaction_started && $db->inTransaction()) {
            $db->rollback();
        }
        $error = $e->getMessage();
    }
}

// Now include header after POST processing is complete
include 'header.php';

// Fetch data for display
try {
    // Get all locations
    $stmt = $db->query("SELECT id, name FROM locations ORDER BY name");
    $locations = $stmt->fetchAll();
    
    // Get all trolleys with location names
    $stmt = $db->query("
        SELECT t.*, l.name as current_location_name 
        FROM trolleys t 
        JOIN locations l ON t.current_location_id = l.id 
        ORDER BY t.trolley_no
    ");
    $trolleys = $stmt->fetchAll();
    
    // Get available trolleys (for movements)
    $available_trolleys = array_filter($trolleys, function($t) { return $t['status'] === 'available'; });
    
    // Get completed productions ready for trolley
    // Calculate weight from BOM tables (bom_product.product_unit_qty, bom_direct.finished_unit_qty, or items.unit_weight_kg)
    $stmt = $db->query("
        SELECT p.*, i.name as item_name, i.code as item_code,
               COALESCE(bp.product_unit_qty, bd.finished_unit_qty, i.unit_weight_kg) as unit_weight_kg,
               l.name as location_name
        FROM production p
        JOIN items i ON p.item_id = i.id  
        JOIN locations l ON p.location_id = l.id
        LEFT JOIN bom_product bp ON bp.finished_item_id = i.id
        LEFT JOIN bom_direct bd ON bd.finished_item_id = i.id
        WHERE p.status = 'completed' 
        AND p.id NOT IN (SELECT production_id FROM trolley_movements WHERE production_id IS NOT NULL AND status IN ('pending', 'in_transit', 'verified'))
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $ready_productions = $stmt->fetchAll();
    
    // Get active trolley movements
    $stmt = $db->query("
        SELECT tm.*, t.trolley_no, t.trolley_name,
               fl.name as from_location, tl.name as to_location,
               ti.item_id, i.name as item_name, i.code as item_code,
               ti.expected_quantity, ti.expected_weight_kg, ti.rejection_reason
        FROM trolley_movements tm
        JOIN trolleys t ON tm.trolley_id = t.id
        JOIN locations fl ON tm.from_location_id = fl.id
        JOIN locations tl ON tm.to_location_id = tl.id
        JOIN trolley_items ti ON tm.id = ti.movement_id
        JOIN items i ON ti.item_id = i.id
        WHERE tm.status IN ('pending', 'in_transit', 'verified', 'rejected')
        ORDER BY tm.created_at DESC
    ");
    $active_movements = $stmt->fetchAll();
    
    // Get recent completed movements for history
    $stmt = $db->query("
        SELECT tm.*, t.trolley_no, i.name as item_name,
               fl.name as from_location, tl.name as to_location,
               ti.actual_quantity, ti.actual_weight_kg, ti.variance_weight_kg
        FROM trolley_movements tm
        JOIN trolleys t ON tm.trolley_id = t.id
        JOIN locations fl ON tm.from_location_id = fl.id
        JOIN locations tl ON tm.to_location_id = tl.id
        JOIN trolley_items ti ON tm.id = ti.movement_id
        JOIN items i ON ti.item_id = i.id
        WHERE tm.status = 'completed'
ORDER BY tm.verified_at DESC, tm.created_at DESC
        LIMIT 20
    ");
    $completed_movements = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = "Error fetching data: " . $e->getMessage();
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">🛒 Trolley Management</h1>
            <p class="text-gray-600">Transfer finished goods from Production to Store with weight verification</p>
        </div>
        <button onclick="openModal('createMovementModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            ➕ Create Trolley Movement
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

    <!-- Trolley Status Overview -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Available Trolleys</p>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php echo count(array_filter($trolleys, function($t) { return $t['status'] === 'available'; })); ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">In Use</p>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php echo count(array_filter($trolleys, function($t) { return $t['status'] === 'in_use'; })); ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Ready Productions</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count($ready_productions); ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Active Movements</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count($active_movements); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Trolley Management Section -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-semibold text-gray-900">📦 Trolley Management</h3>
            <button onclick="openModal('addTrolleyModal')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Add New Trolley
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trolley No</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Max Weight</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($trolleys as $trolley): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($trolley['trolley_no']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($trolley['trolley_name']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo number_format($trolley['max_weight_kg'], 2); ?> kg</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($trolley['current_location_name']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                echo $trolley['status'] === 'available' ? 'bg-green-100 text-green-800' : 
                                    ($trolley['status'] === 'in_use' ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800'); 
                            ?>">
                                <?php echo ucfirst($trolley['status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <button onclick="openEditTrolleyModal(<?php echo htmlspecialchars(json_encode($trolley)); ?>)" class="text-blue-600 hover:text-blue-900 mr-3">
                                ✏️ Edit
                            </button>
                            <button onclick="openViewTrolleyModal(<?php echo htmlspecialchars(json_encode($trolley)); ?>)" class="text-green-600 hover:text-green-900 mr-3">
                                👁️ View
                            </button>
                            <button onclick="if(confirm('Are you sure you want to delete this trolley?')) { deleteTrolley(<?php echo $trolley['id']; ?>); }" class="text-red-600 hover:text-red-900">
                                🗑️ Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($trolleys)): ?>
            <div class="text-center py-8 text-gray-500">
                <p>No trolleys found. <a href="#" onclick="openModal('addTrolleyModal'); return false;" class="text-blue-600 hover:text-blue-900">Add your first trolley</a></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Active Trolley Movements -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">🚛 Active Trolley Movements</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Movement</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trolley</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Route</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expected</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($active_movements as $movement): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $movement['movement_no']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo date('d M Y', strtotime($movement['movement_date'])); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $movement['trolley_no']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $movement['trolley_name']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $movement['item_name']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $movement['item_code']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <div class="flex items-center">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <?php echo $movement['from_location']; ?>
                                </span>
                                <svg class="w-4 h-4 mx-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <?php echo $movement['to_location']; ?>
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <div><?php echo number_format($movement['expected_quantity'], 3); ?> units</div>
                            <div class="text-gray-500"><?php echo number_format($movement['expected_weight_kg'], 3); ?> kg</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                echo $movement['status'] === 'completed' ? 'bg-green-100 text-green-800' : 
                                    ($movement['status'] === 'verified' ? 'bg-blue-100 text-blue-800' : 
                                    ($movement['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800')); 
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $movement['status'])); ?>
                            </span>
                            <?php if ($movement['status'] === 'rejected' && $movement['rejection_reason']): ?>
                                <div class="text-xs text-red-600 mt-1" title="<?php echo htmlspecialchars($movement['rejection_reason']); ?>">
                                    <?php echo substr($movement['rejection_reason'], 0, 30) . (strlen($movement['rejection_reason']) > 30 ? '...' : ''); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <?php if ($movement['status'] === 'pending'): ?>
                                <button onclick="openVerificationModal(<?php echo htmlspecialchars(json_encode($movement)); ?>)" 
                                        class="text-blue-600 hover:text-blue-900 mr-2">
                                    ⚖️ Verify Weight
                                </button>
                            <?php elseif ($movement['status'] === 'verified'): ?>
                                <span class="text-green-600">✅ Ready to complete</span>
                            <?php elseif ($movement['status'] === 'rejected'): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="reset_movement">
                                    <input type="hidden" name="movement_id" value="<?php echo $movement['id']; ?>">
                                    <button type="submit" class="text-orange-600 hover:text-orange-900">
                                        🔄 Reset for Retry
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($active_movements)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">No active trolley movements</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- All Trolleys Status -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">🛒 Trolley Fleet Status</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trolley</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Max Weight</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($trolleys as $trolley): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $trolley['trolley_no']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $trolley['trolley_name']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo $trolley['current_location_name']; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo number_format($trolley['max_weight_kg'], 1); ?> kg
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                echo $trolley['status'] === 'available' ? 'bg-green-100 text-green-800' : 
                                    ($trolley['status'] === 'in_use' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'); 
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $trolley['status'])); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Completed Movements -->
    <?php if (!empty($completed_movements)): ?>
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">📋 Recent Completed Movements</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Movement</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trolley</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actual Results</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Weight Variance</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($completed_movements as $movement): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $movement['movement_no']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo date('d M Y', strtotime($movement['movement_date'])); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo $movement['trolley_no']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $movement['item_name']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <div><?php echo number_format($movement['actual_quantity'], 3); ?> units</div>
                            <div class="text-gray-500"><?php echo number_format($movement['actual_weight_kg'], 3); ?> kg</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="<?php echo $movement['variance_weight_kg'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                <?php echo ($movement['variance_weight_kg'] >= 0 ? '+' : '') . number_format($movement['variance_weight_kg'], 3); ?> kg
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                ✅ Completed
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Create Movement Modal -->
<div id="createMovementModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">🛒 Create Trolley Movement</h3>
            <button onclick="closeModal('createMovementModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4" onsubmit="return validateWeightInputs()">
            <input type="hidden" name="action" value="create_movement">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Production Batch</label>
                <select name="production_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary" onchange="updateExpectedWeight(this)">
                    <option value="">Select Production Batch</option>
                    <?php foreach ($ready_productions as $prod): ?>
                        <option value="<?php echo $prod['id']; ?>" 
                                data-quantity="<?php echo $prod['actual_qty']; ?>"
                                data-planned-qty="<?php echo $prod['planned_qty']; ?>"
                                data-weight="<?php echo $prod['unit_weight_kg']; ?>"
                                data-item="<?php echo htmlspecialchars($prod['item_name']); ?>"
                                data-batch="<?php echo htmlspecialchars($prod['batch_no']); ?>">
                            <?php echo $prod['batch_no']; ?> - <?php echo $prod['item_name']; ?> (<?php echo number_format($prod['actual_qty'], 3); ?> units)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Trolleys (One or Multiple)</label>
                <div class="border border-gray-300 rounded-md p-3 max-h-40 overflow-y-auto bg-white">
                    <?php foreach ($trolleys as $trolley): ?>
                        <?php if ($trolley['status'] === 'available'): ?>
                            <label class="flex items-center mb-2 cursor-pointer">
                                <input type="checkbox" name="trolley_ids[]" value="<?php echo $trolley['id']; ?>" data-max-weight="<?php echo $trolley['max_weight_kg']; ?>" class="trolley-checkbox w-4 h-4 border-gray-300 rounded" onchange="updateTrolleyInputs()">
                                <span class="ml-2 text-sm text-gray-700">
                                    <?php echo $trolley['trolley_no']; ?> - <?php echo $trolley['trolley_name']; ?> (Max: <?php echo number_format($trolley['max_weight_kg'], 1); ?> kg)
                                </span>
                            </label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 mt-1">✓ Check one or more trolleys to assign to this production batch</p>
            </div>
            
            <!-- Trolley Weight Input -->
            <div id="trolleyInputsContainer" class="hidden space-y-3 border border-blue-200 rounded-md p-3 bg-blue-50">
                <h4 class="text-sm font-medium text-blue-800">⚖️ Enter Actual Weight per Trolley</h4>
                <p class="text-xs text-blue-600 mb-2">After placing products in each trolley, enter the actual weight. Quantity will be calculated from weight.</p>
                <div id="trolleyInputsContent"></div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Movement Date</label>
                <input type="date" name="movement_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary" value="<?php echo date('Y-m-d'); ?>">
            </div>
            
            <!-- Expected Weight Display -->
            <div id="expectedWeightDisplay" class="bg-blue-50 border border-blue-200 rounded-md p-3 hidden">
                <h4 class="text-sm font-medium text-blue-800 mb-2">📦 Production Details:</h4>
                <div class="text-sm text-blue-700 space-y-1">
                    <div><strong>Planned Quantity:</strong> <span id="plannedQty">-</span> units</div>
                    <div><strong>Planned Weight:</strong> <span id="plannedWeight">-</span> kg</div>
                    <div><strong>Actual Quantity:</strong> <span id="expectedUnits">-</span> units</div>
                    <div><strong>Actual Weight:</strong> <span id="expectedWeight">-</span> kg</div>
                    <div><strong>Item:</strong> <span id="expectedItem">-</span></div>
                </div>
            </div>
            
            <!-- Weight Check Warning -->
            <div id="weightWarning" class="bg-red-50 border border-red-200 rounded-md p-3 hidden">
                <div class="flex">
                    <svg class="w-5 h-5 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <strong class="text-red-800">Warning:</strong>
                        <p class="text-sm text-red-700 mt-1">
                            Expected weight exceeds trolley capacity!
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createMovementModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" id="createMovementBtn" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Create Movement</button>
            </div>
        </form>
    </div>
</div>

<!-- Weight Verification Modal -->
<!-- Weight Verification Modal -->
<div id="verificationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">⚖️ Weight Verification</h3>
            <button onclick="closeModal('verificationModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4" onsubmit="return confirmWeightVerification()">
            <input type="hidden" name="action" value="verify_weight">
            <input type="hidden" name="movement_id" id="verify_movement_id">
            
            <!-- Expected vs Actual Display -->
            <div class="bg-gray-50 border border-gray-200 rounded-md p-4">
                <h4 class="text-sm font-medium text-gray-800 mb-3">Expected Values:</h4>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <div class="text-gray-600">Units:</div>
                        <div class="font-medium" id="verify_expected_units">-</div>
                    </div>
                    <div>
                        <div class="text-gray-600">Weight:</div>
                        <div class="font-medium" id="verify_expected_weight">-</div>
                    </div>
                </div>
                <div class="mt-2 text-xs text-gray-600">
                    Tolerance: <span id="verify_tolerance">±5%</span>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Actual Weight (kg)</label>
                <input type="number" name="actual_weight_kg" step="0.001" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Weigh the trolley load" onchange="calculateWeightVariance()">
            </div>
            
            <!-- Weight Variance Display -->
            <div id="weightVarianceDisplay" class="hidden">
                <div class="bg-blue-50 border border-blue-200 rounded-md p-3">
                    <h4 class="text-sm font-medium text-blue-800 mb-2">Weight Analysis:</h4>
                    <div class="text-sm text-blue-700">
                        <div>Expected: <span id="expectedWeightVal">-</span> kg</div>
                        <div>Actual: <span id="actualWeightVal">-</span> kg</div>
                        <div>Variance: <span id="weightVarianceVal">-</span></div>
                        <div>Status: <span id="weightStatus">-</span></div>
                    </div>
                </div>
            </div>
            
            <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3">
                <div class="flex">
                    <svg class="w-5 h-5 text-yellow-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <strong class="text-yellow-800">Important:</strong>
                        <p class="text-sm text-yellow-700 mt-1">
                            Weigh accurately. Weight outside tolerance will require investigation before moving to store.
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('verificationModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                    ✓ Verify & Move to Store
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Trolley Modal -->
<div id="addTrolleyModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">➕ Add New Trolley</h3>
            <button onclick="closeModal('addTrolleyModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_trolley">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Trolley Number</label>
                <input type="text" name="trolley_no" required placeholder="e.g., TRL001" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Trolley Name</label>
                <input type="text" name="trolley_name" required placeholder="e.g., Main Floor Trolley" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Max Weight (kg)</label>
                <input type="number" name="max_weight_kg" step="0.01" required placeholder="e.g., 100" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Current Location</label>
                <select name="location_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Select Location</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('addTrolleyModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Add Trolley</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Trolley Modal -->
<div id="editTrolleyModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">✏️ Edit Trolley</h3>
            <button onclick="closeModal('editTrolleyModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="edit_trolley">
            <input type="hidden" name="trolley_id" id="edit_trolley_id">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Trolley Number (Read-Only)</label>
                <input type="text" id="edit_trolley_no" readonly class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-gray-600">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Trolley Name</label>
                <input type="text" name="trolley_name" id="edit_trolley_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Max Weight (kg)</label>
                <input type="number" name="max_weight_kg" id="edit_max_weight" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" id="edit_status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="available">Available</option>
                    <option value="in_use">In Use</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Current Location</label>
                <select name="location_id" id="edit_location_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Select Location</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('editTrolleyModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Update Trolley</button>
            </div>
        </form>
    </div>
</div>

<!-- View Trolley Modal -->
<div id="viewTrolleyModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">👁️ Trolley Details</h3>
            <button onclick="closeModal('viewTrolleyModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <div class="space-y-4">
            <div class="bg-gray-50 p-4 rounded-lg">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-medium text-gray-500 uppercase">Trolley Number</label>
                        <p class="text-lg font-semibold text-gray-900" id="view_trolley_no">-</p>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 uppercase">Trolley Name</label>
                        <p class="text-lg font-semibold text-gray-900" id="view_trolley_name">-</p>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-medium text-gray-500 uppercase">Max Weight</label>
                        <p class="text-lg font-semibold text-gray-900" id="view_max_weight">-</p>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 uppercase">Status</label>
                        <p class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium" id="view_status">-</p>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div>
                    <label class="text-xs font-medium text-gray-500 uppercase">Current Location</label>
                    <p class="text-lg font-semibold text-gray-900" id="view_location">-</p>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('viewTrolleyModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function updateTrolleyInputs() {
    const trolleyCheckboxes = document.querySelectorAll('input.trolley-checkbox:checked');
    const container = document.getElementById('trolleyInputsContainer');
    const content = document.getElementById('trolleyInputsContent');
    const prodSelect = document.querySelector('select[name="production_id"]');
    
    if (trolleyCheckboxes.length > 0 && prodSelect.value) {
        let html = '';
        trolleyCheckboxes.forEach((checkbox) => {
            const trolleyId = checkbox.value;
            const trolleyName = checkbox.closest('label').querySelector('span').textContent.trim();
            html += `
                <div class="flex items-end gap-3 pb-3 border-b border-blue-200 last:border-b-0">
                    <div class="flex-1">
                        <label class="text-xs font-medium text-blue-700">${trolleyName}</label>
                    </div>
                    <div class="flex-1">
                        <input type="number" step="0.001" name="trolley_weight_${trolleyId}" 
                               placeholder="Enter weight (kg)" 
                               class="w-full px-2 py-2 border border-blue-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                               onchange="updateTrolleyDistribution()" 
                               required>
                    </div>
                </div>
            `;
        });
        
        content.innerHTML = html;
        container.classList.remove('hidden');
        updateExpectedWeight();
    } else {
        container.classList.add('hidden');
    }
}

function updateTrolleyDistribution() {
    updateExpectedWeight();
}
let currentMovement = null;
let expectedWeight = 0;
let expectedUnits = 0;
let weightTolerance = 5; // Default 5%

function updateExpectedWeight() {
    // Get all checked trolley checkboxes
    const trolleyCheckboxes = document.querySelectorAll('input.trolley-checkbox:checked');
    const prodSelect = document.querySelector('select[name="production_id"]');
    
    if (!prodSelect) {
        console.error('Production select not found');
        return;
    }
    
    const selectedOption = prodSelect.options[prodSelect.selectedIndex];
    const submitBtn = document.getElementById('createMovementBtn');
    
    if (selectedOption.value && trolleyCheckboxes.length > 0) {
        const totalQuantity = parseFloat(selectedOption.getAttribute('data-quantity'));
        const plannedQty = parseFloat(selectedOption.getAttribute('data-planned-qty'));
        const unitWeight = parseFloat(selectedOption.getAttribute('data-weight'));
        const itemName = selectedOption.getAttribute('data-item');
        const totalWeight = totalQuantity * unitWeight;
        const plannedWeight = plannedQty * unitWeight;
        
        expectedWeight = totalWeight;
        expectedUnits = totalQuantity;
        
        const trolleyCount = trolleyCheckboxes.length;
        
        // Check if individual weights are entered
        let hasIndividualWeights = false;
        let totalEnteredWeight = 0;
        let trolleyDetails = '<div class="text-sm text-blue-700">';
        
        trolleyCheckboxes.forEach((checkbox) => {
            const trolleyId = checkbox.value;
            const weightInput = document.querySelector(`input[name="trolley_weight_${trolleyId}"]`);
            
            if (weightInput && weightInput.value) {
                hasIndividualWeights = true;
                const weight = parseFloat(weightInput.value) || 0;
                totalEnteredWeight += weight;
                const trolleyName = checkbox.closest('label').querySelector('span').textContent.trim();
                trolleyDetails += `<div><strong>${trolleyName}:</strong> ${weight.toFixed(3)} kg</div>`;
            }
        });
        trolleyDetails += '</div>';
        
        // Update the display with planned and actual values
        document.getElementById('plannedQty').textContent = plannedQty.toFixed(3);
        document.getElementById('plannedWeight').textContent = plannedWeight.toFixed(3);
        document.getElementById('expectedUnits').textContent = totalQuantity.toFixed(3);
        document.getElementById('expectedWeight').textContent = totalWeight.toFixed(3);
        document.getElementById('expectedItem').textContent = itemName;
        
        if (hasIndividualWeights) {
            const calculatedQty = (totalEnteredWeight / unitWeight).toFixed(3);
            document.getElementById('expectedWeightDisplay').innerHTML = `
                <h4 class="text-sm font-medium text-blue-800 mb-2">⚖️ Actual Weight & Calculated Quantity:</h4>
                ${trolleyDetails}
                <hr class="my-2">
                <div class="text-sm text-blue-700 space-y-1">
                    <div><strong>Total Entered Weight:</strong> ${totalEnteredWeight.toFixed(3)} kg</div>
                    <div><strong>Unit Weight:</strong> ${unitWeight.toFixed(3)} kg/unit</div>
                    <div class="text-blue-900 font-semibold"><strong>Calculated Total Qty:</strong> ${calculatedQty} units</div>
                    <hr class="my-1">
                    <div><strong>Expected Weight:</strong> ${totalWeight.toFixed(3)} kg</div>
                    <div><strong>Expected Qty:</strong> ${totalQuantity.toFixed(3)} units</div>
                </div>
            `;
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        } else {
            const unitsPerTrolley = (totalQuantity / trolleyCount).toFixed(3);
            const weightPerTrolley = (totalWeight / trolleyCount).toFixed(3);
            
            document.getElementById('expectedWeightDisplay').classList.remove('hidden');
            document.getElementById('expectedWeightDisplay').innerHTML = `
                <h4 class="text-sm font-medium text-blue-800 mb-2">📦 Production Details:</h4>
                <div class="text-sm text-blue-700 space-y-1">
                    <div><strong>Planned Quantity:</strong> ${plannedQty.toFixed(3)} units</div>
                    <div><strong>Planned Weight:</strong> ${plannedWeight.toFixed(3)} kg</div>
                    <div><strong>Actual Quantity:</strong> ${totalQuantity.toFixed(3)} units</div>
                    <div><strong>Actual Weight:</strong> ${totalWeight.toFixed(3)} kg</div>
                    <div><strong>Item:</strong> ${itemName}</div>
                    <hr class="my-2">
                    <div><strong>Distribution (${trolleyCount} trolleys):</strong></div>
                    <div>Per Trolley: ${unitsPerTrolley} units × ${weightPerTrolley} kg</div>
                </div>
            `;
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        }
        
        document.getElementById('expectedWeightDisplay').classList.remove('hidden');
        
        // Check trolley capacity
        checkTrolleyCapacity(hasIndividualWeights ? totalEnteredWeight / trolleyCount : totalWeight / trolleyCount, trolleyCheckboxes);
    } else {
        document.getElementById('expectedWeightDisplay').classList.add('hidden');
        document.getElementById('weightWarning').classList.add('hidden');
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
    }
}

function checkTrolleyCapacity(weightPerTrolley, trolleyCheckboxes) {
    const submitBtn = document.getElementById('createMovementBtn');
    const warningDiv = document.getElementById('weightWarning');
    
    let capacityIssue = false;
    
    trolleyCheckboxes.forEach(checkbox => {
        const maxWeight = parseFloat(checkbox.getAttribute('data-max-weight'));
        if (weightPerTrolley > maxWeight) {
            capacityIssue = true;
        }
    });
    
    if (capacityIssue) {
        warningDiv.innerHTML = `
            <div class="flex">
                <svg class="w-5 h-5 text-yellow-400 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <div>
                    <strong class="text-yellow-800">⚠️ Capacity Warning:</strong>
                    <p class="text-sm text-yellow-700 mt-1">
                        One or more selected trolleys may exceed max capacity. You can still proceed - weight will be verified during physical loading.
                    </p>
                </div>
            </div>
        `;
        warningDiv.classList.remove('hidden');
        // Allow button but keep it visible as warning
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    } else {
        warningDiv.classList.add('hidden');
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    }
}

// Add event listeners for multiple trolley selection
document.addEventListener('DOMContentLoaded', function() {
    const prodSelect = document.querySelector('select[name="production_id"]');
    
    // Use event delegation for checkboxes - detects changes on any .trolley-checkbox
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('trolley-checkbox')) {
            updateExpectedWeight();
        }
    });
    
    if (prodSelect) {
        prodSelect.addEventListener('change', updateExpectedWeight);
    }
});

function openVerificationModal(movement) {
    currentMovement = movement;
    expectedWeight = parseFloat(movement.expected_weight_kg);
    expectedUnits = parseFloat(movement.expected_quantity);
    weightTolerance = 5; // Default, you might want to get this from the item data
    
    document.getElementById('verify_movement_id').value = movement.id;
    document.getElementById('verify_expected_units').textContent = expectedUnits.toFixed(3) + ' units';
    document.getElementById('verify_expected_weight').textContent = expectedWeight.toFixed(3) + ' kg';
    document.getElementById('verify_tolerance').textContent = '±' + weightTolerance + '%';
    
    // Reset form - only weight field now
    const weightInput = document.querySelector('input[name="actual_weight_kg"]');
    if (weightInput) {
        weightInput.value = '';
    }
    document.getElementById('weightVarianceDisplay').classList.add('hidden');
    
    openModal('verificationModal');
}

function calculateWeightVariance() {
    const actualWeightInput = document.querySelector('input[name="actual_weight_kg"]');
    
    if (actualWeightInput.value) {
        const actualWeight = parseFloat(actualWeightInput.value);
        const weightVariance = actualWeight - expectedWeight;
        const weightToleranceAmount = (weightTolerance / 100) * expectedWeight;
        const weightWithinTolerance = Math.abs(weightVariance) <= weightToleranceAmount;
        
        document.getElementById('expectedWeightVal').textContent = expectedWeight.toFixed(3);
        document.getElementById('actualWeightVal').textContent = actualWeight.toFixed(3);
        document.getElementById('weightVarianceVal').textContent = 
            (weightVariance >= 0 ? '+' : '') + weightVariance.toFixed(3) + ' kg (' + ((weightVariance / expectedWeight) * 100).toFixed(1) + '%)';
        
        let statusText = '';
        let statusClass = '';
        
        if (weightWithinTolerance) {
            statusText = '✅ PASS - Within ±' + weightTolerance + '% tolerance';
            statusClass = 'text-green-600 font-medium';
        } else {
            statusText = '❌ FAIL - Outside ±' + weightTolerance + '% tolerance (' + weightToleranceAmount.toFixed(3) + ' kg)';
            statusClass = 'text-red-600 font-medium';
        }
        
        const statusElement = document.getElementById('weightStatus');
        statusElement.textContent = statusText;
        statusElement.className = statusClass;
        
        document.getElementById('weightVarianceDisplay').classList.remove('hidden');
    }
}

function confirmWeightVerification() {
    const actualWeight = parseFloat(document.querySelector('input[name="actual_weight_kg"]').value);
    const weightVariance = actualWeight - expectedWeight;
    const weightToleranceAmount = (weightTolerance / 100) * expectedWeight;
    const weightWithinTolerance = Math.abs(weightVariance) <= weightToleranceAmount;
    
    if (!weightWithinTolerance) {
        alert('⚠️ Weight is outside tolerance (' + weightToleranceAmount.toFixed(3) + ' kg). This requires investigation. Do you want to proceed anyway?');
    }
    
    return true;
}

// Modal helper functions
function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    
    // Reset form and checkboxes when opening createMovementModal
    if (modalId === 'createMovementModal') {
        // Uncheck all trolley checkboxes
        document.querySelectorAll('.trolley-checkbox').forEach(checkbox => {
            checkbox.checked = false;
        });
        // Reset production select
        const prodSelect = document.querySelector('select[name="production_id"]');
        if (prodSelect) {
            prodSelect.value = '';
        }
        // Hide the weight display
        const weightDisplay = document.getElementById('expectedWeightDisplay');
        if (weightDisplay) {
            weightDisplay.classList.add('hidden');
        }
        // Hide capacity warning
        const warningDiv = document.getElementById('weightWarning');
        if (warningDiv) {
            warningDiv.classList.add('hidden');
        }
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Trolley Management Functions
function openEditTrolleyModal(trolley) {
    document.getElementById('edit_trolley_id').value = trolley.id;
    document.getElementById('edit_trolley_no').value = trolley.trolley_no;
    document.getElementById('edit_trolley_name').value = trolley.trolley_name;
    document.getElementById('edit_max_weight').value = trolley.max_weight_kg;
    document.getElementById('edit_status').value = trolley.status;
    document.getElementById('edit_location_id').value = trolley.current_location_id;
    openModal('editTrolleyModal');
}

function openViewTrolleyModal(trolley) {
    document.getElementById('view_trolley_no').textContent = trolley.trolley_no;
    document.getElementById('view_trolley_name').textContent = trolley.trolley_name;
    document.getElementById('view_max_weight').textContent = trolley.max_weight_kg + ' kg';
    document.getElementById('view_location').textContent = trolley.current_location_name;
    
    // Set status badge styling
    const statusElement = document.getElementById('view_status');
    statusElement.textContent = trolley.status.charAt(0).toUpperCase() + trolley.status.slice(1);
    
    if (trolley.status === 'available') {
        statusElement.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-green-100 text-green-800';
    } else if (trolley.status === 'in_use') {
        statusElement.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800';
    } else {
        statusElement.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800';
    }
    
    openModal('viewTrolleyModal');
}

function deleteTrolley(trolleyId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="delete_trolley"><input type="hidden" name="trolley_id" value="' + trolleyId + '">';
    document.body.appendChild(form);
    form.submit();
}

function validateWeightInputs() {
    // Check if any trolleys are checked
    const trolleyCheckboxes = document.querySelectorAll('input.trolley-checkbox:checked');
    if (trolleyCheckboxes.length === 0) {
        alert('Please select at least one trolley');
        return false;
    }
    
    // Check if all weights are entered
    let allWeightsEntered = true;
    trolleyCheckboxes.forEach((checkbox) => {
        const trolleyId = checkbox.value;
        const weightInput = document.querySelector(`input[name="trolley_weight_${trolleyId}"]`);
        if (!weightInput || !weightInput.value || parseFloat(weightInput.value) <= 0) {
            allWeightsEntered = false;
        }
    });
    
    if (!allWeightsEntered) {
        alert('Please enter actual weight for all selected trolleys');
        return false;
    }
    
    return true;
}
// Auto-refresh page every 30 seconds to show updates
setInterval(function() {
    if (!document.querySelector('.modal-backdrop:not(.hidden)')) {
        location.reload();
    }
}, 30000);
</script>

<?php include 'footer.php'; ?>