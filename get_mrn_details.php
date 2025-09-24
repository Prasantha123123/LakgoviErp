<?php
header('Content-Type: application/json');

// Include database connection
require_once 'database.php';

// Create database instance
$database = new Database();
$db = $database->getConnection();

// Check if database connection exists
if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$mrn_id = $_GET['mrn_id'] ?? null;

if (!$mrn_id) {
    echo json_encode(['success' => false, 'message' => 'MRN ID is required']);
    exit;
}

try {
// Get MRN header details with created_by information
$stmt = $db->prepare("
    SELECT m.*, 
           DATE_FORMAT(m.mrn_date, '%d %b %Y') as formatted_date,
           DATE_FORMAT(m.created_at, '%d %b %Y %H:%i') as formatted_created_at,
           u.full_name as created_by_name,
           u.username as created_by_username
    FROM mrn m
    LEFT JOIN admin_users u ON m.created_by = u.id
    WHERE m.id = ?
");
$stmt->execute([$mrn_id]);
$mrn = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$mrn) {
    echo json_encode(['success' => false, 'message' => 'MRN not found']);
    exit;
}
    
    // Get MRN items
    $stmt = $db->prepare("
        SELECT mi.*, 
               i.name as item_name, 
               i.code as item_code, 
               i.type as item_type,
               u.symbol as unit_symbol,
               l.name as location_name,
               l.type as location_type
        FROM mrn_items mi
        JOIN items i ON mi.item_id = i.id
        JOIN units u ON i.unit_id = u.id
        JOIN locations l ON mi.location_id = l.id
        WHERE mi.mrn_id = ?
        ORDER BY mi.id
    ");
    $stmt->execute([$mrn_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add stock information for each item
    foreach ($items as &$item) {
        // Get current stock for this item-location combination
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_stock
            FROM stock_ledger 
            WHERE item_id = ? AND location_id = ?
        ");
        $stmt->execute([$item['item_id'], $item['location_id']]);
        $stock_result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $item['current_stock'] = $stock_result['current_stock'];
        $item['stock_before_mrn'] = $stock_result['current_stock'] + $item['quantity'];
    }
    
    // Calculate summary
    $summary = [
        'total_items' => count($items),
        'total_quantity' => array_sum(array_column($items, 'quantity')),
        'unique_locations' => count(array_unique(array_column($items, 'location_id'))),
        'raw_materials' => count(array_filter($items, function($item) { 
            return $item['item_type'] === 'raw'; 
        })),
        'semi_finished' => count(array_filter($items, function($item) { 
            return $item['item_type'] === 'semi_finished'; 
        })),
        'finished_goods' => count(array_filter($items, function($item) { 
            return $item['item_type'] === 'finished'; 
        }))
    ];
    
    // Get stock movements for this MRN
    $stmt = $db->prepare("
        SELECT sl.*, i.name as item_name, i.code as item_code, 
               l.name as location_name, u.symbol as unit_symbol
        FROM stock_ledger sl
        JOIN items i ON sl.item_id = i.id
        JOIN locations l ON sl.location_id = l.id
        JOIN units u ON i.unit_id = u.id
        WHERE sl.transaction_type = 'mrn' AND sl.reference_id = ?
        ORDER BY sl.id
    ");
    $stmt->execute([$mrn_id]);
    $stock_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'mrn' => $mrn,
        'items' => $items,
        'summary' => $summary,
        'stock_movements' => $stock_movements
    ]);
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>