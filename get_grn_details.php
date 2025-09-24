<?php
// debug_get_grn_details.php - Debug version to identify the issue
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: application/json');

// Log the start of the script
error_log("get_grn_details.php started");

try {
    // Step 1: Test basic PHP
    error_log("Step 1: Basic PHP working");
    
    // Step 2: Test database connection
    require_once 'database.php';
    error_log("Step 2: Database file included");
    
    $database = new Database();
    error_log("Step 3: Database instance created");
    
    $db = $database->getConnection();
    error_log("Step 4: Database connection obtained");
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    // Step 3: Test parameter
    $grn_id = $_GET['grn_id'] ?? null;
    error_log("Step 5: GRN ID = " . ($grn_id ?? 'null'));
    
    if (!$grn_id || !is_numeric($grn_id)) {
        throw new Exception('Valid GRN ID is required. Received: ' . ($grn_id ?? 'null'));
    }
    
    // Step 4: Test simple query first
    error_log("Step 6: Testing simple GRN query");
    $stmt = $db->prepare("SELECT * FROM grn WHERE id = ?");
    $stmt->execute([$grn_id]);
    $grn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$grn) {
        throw new Exception('GRN not found with ID: ' . $grn_id);
    }
    
    error_log("Step 7: GRN found - " . $grn['grn_no']);
    
    // Step 5: Test GRN items query
    error_log("Step 8: Testing GRN items query");
    $stmt = $db->prepare("
        SELECT gi.*, 
               i.name as item_name, 
               i.code as item_code, 
               i.type as item_type,
               u.symbol as unit_symbol,
               l.name as location_name
        FROM grn_items gi
        JOIN items i ON gi.item_id = i.id
        JOIN units u ON i.unit_id = u.id
        JOIN locations l ON gi.location_id = l.id
        WHERE gi.grn_id = ?
        ORDER BY gi.id
    ");
    $stmt->execute([$grn_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Step 9: Found " . count($items) . " items");
    
    // Step 6: Create summary
    $summary = [
        'total_items' => count($items),
        'total_quantity' => array_sum(array_column($items, 'quantity')),
        'total_amount' => 0 // We'll calculate this properly later
    ];
    
    error_log("Step 10: Summary created");
    
    // Return basic response
    echo json_encode([
        'success' => true,
        'grn' => $grn,
        'items' => $items,
        'summary' => $summary,
        'debug' => [
            'grn_id' => $grn_id,
            'items_count' => count($items),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
    error_log("Step 11: JSON response sent successfully");
    
} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    error_log("SQL State: " . $e->getCode());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'error_type' => 'database',
        'sql_state' => $e->getCode(),
        'debug_step' => 'Database operation failed'
    ]);
} catch(Exception $e) {
    error_log("General error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_type' => 'general'
    ]);
} catch(Error $e) {
    error_log("Fatal error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fatal error: ' . $e->getMessage(),
        'error_type' => 'fatal'
    ]);
}
?>