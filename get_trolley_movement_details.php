<?php
/**
 * get_trolley_movement_details.php
 * 
 * AJAX API endpoint to fetch detailed information about a single trolley movement
 * including all line items (trolley_items).
 * 
 * Usage: GET get_trolley_movement_details.php?id=<movement_id>
 * Returns: JSON with movement details and associated line items
 */

require_once 'database.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Validate movement ID
    $movement_id = intval($_GET['id'] ?? 0);
    if ($movement_id <= 0) {
        throw new Exception("Invalid movement ID");
    }
    
    // ========================================================================
    // Fetch Movement Details with Production Batch Info
    // ========================================================================
    $stmt = $db->prepare("
        SELECT tm.id, tm.movement_no, tm.trolley_id, tm.production_id,
               tm.from_location_id, tm.to_location_id, tm.movement_date,
               tm.expected_weight_kg, tm.actual_weight_kg,
               tm.expected_units, tm.actual_units,
               tm.status, tm.verified_at, tm.created_at,
               t.trolley_no, t.trolley_name,
               fl.name as from_location, tl.name as to_location,
               p.batch_no, p.item_id as prod_item_id, i.name as prod_item_name, i.code as prod_item_code
        FROM trolley_movements tm
        JOIN trolleys t ON tm.trolley_id = t.id
        JOIN locations fl ON tm.from_location_id = fl.id
        JOIN locations tl ON tm.to_location_id = tl.id
        LEFT JOIN production p ON tm.production_id = p.id
        LEFT JOIN items i ON p.item_id = i.id
        WHERE tm.id = ?
    ");
    $stmt->execute([$movement_id]);
    $movement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$movement) {
        throw new Exception("Movement not found");
    }
    
    // ========================================================================
    // Fetch Line Items (trolley_items) for this movement
    // ========================================================================
    $stmt = $db->prepare("
        SELECT ti.id, ti.item_id, i.code as item_code, i.name as item_name,
               ti.expected_quantity, ti.actual_quantity,
               ti.expected_weight_kg, ti.actual_weight_kg,
               ti.unit_weight_kg, ti.status as item_status,
               ti.variance_quantity, ti.variance_weight_kg
        FROM trolley_items ti
        JOIN items i ON ti.item_id = i.id
        WHERE ti.movement_id = ?
        ORDER BY i.code ASC
    ");
    $stmt->execute([$movement_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'movement' => $movement,
        'items' => $items
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
