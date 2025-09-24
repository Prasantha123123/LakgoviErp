<?php
// get_po_items.php - Get PO items for GRN creation (Enhanced with debugging)
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Log the request for debugging
error_log("get_po_items.php called with PO ID: " . ($_GET['po_id'] ?? 'none'));

try {
    require_once 'database.php';
    require_once 'config/simple_auth.php';

    // Initialize database and auth
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    $auth = new SimpleAuth($db);

    // Check authentication
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized - User not logged in']);
        exit;
    }

    // Get PO ID from request
    $po_id = $_GET['po_id'] ?? null;

    if (!$po_id || !is_numeric($po_id)) {
        echo json_encode(['success' => false, 'message' => 'Valid PO ID is required. Received: ' . ($po_id ?? 'null')]);
        exit;
    }

    // First, check if PO exists
    $stmt = $db->prepare("SELECT id, po_no, status FROM purchase_orders WHERE id = ?");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$po) {
        echo json_encode(['success' => false, 'message' => 'Purchase Order not found with ID: ' . $po_id]);
        exit;
    }

    // Get PO items with details
    $stmt = $db->prepare("
        SELECT poi.id, poi.item_id, poi.quantity, poi.rate, poi.amount, 
               COALESCE(poi.received_quantity, 0) as received_quantity,
               i.name as item_name, i.code as item_code, u.symbol as unit_symbol,
               (poi.quantity - COALESCE(poi.received_quantity, 0)) as pending_quantity
        FROM po_items poi
        JOIN items i ON poi.item_id = i.id
        JOIN units u ON i.unit_id = u.id
        WHERE poi.po_id = ?
        AND (poi.quantity - COALESCE(poi.received_quantity, 0)) > 0
        ORDER BY poi.id
    ");
    $stmt->execute([$po_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($items) . " pending items for PO " . $po_id);
    
    echo json_encode([
        'success' => true,
        'po_info' => $po,
        'items' => $items,
        'debug_info' => [
            'po_id' => $po_id,
            'items_count' => count($items),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch(PDOException $e) {
    error_log("Database error in get_po_items.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'error_type' => 'database'
    ]);
} catch(Exception $e) {
    error_log("General error in get_po_items.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'error_type' => 'general'
    ]);
}
?>