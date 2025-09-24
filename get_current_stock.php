<?php
header('Content-Type: application/json');

// Include database connection
require_once 'database.php';

// Create database instance
$database = new Database();
$db = $database->getConnection();

if (!isset($_GET['item_id']) || !isset($_GET['location_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing item_id or location_id'
    ]);
    exit;
}

$item_id = (int)$_GET['item_id'];
$location_id = (int)$_GET['location_id'];

try {
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(sl.quantity_in - sl.quantity_out), 0) as current_stock,
            u.symbol as unit,
            i.name as item_name,
            l.name as location_name
        FROM items i
        LEFT JOIN stock_ledger sl ON i.id = sl.item_id AND sl.location_id = ?
        LEFT JOIN units u ON i.unit_id = u.id
        LEFT JOIN locations l ON l.id = ?
        WHERE i.id = ?
        GROUP BY i.id, u.symbol, i.name, l.name
    ");
    
    $stmt->execute([$location_id, $location_id, $item_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'current_stock' => $result['current_stock'],
            'unit' => $result['unit'],
            'item_name' => $result['item_name'],
            'location_name' => $result['location_name']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Item or location not found'
        ]);
    }
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>