<?php
// get_pricelist_items.php - AJAX endpoint to fetch price list items
require_once 'database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

try {
    $price_list_id = $_GET['price_list_id'] ?? null;
    
    if (!$price_list_id) {
        echo json_encode([]);
        exit;
    }
    
    $stmt = $db->prepare("
        SELECT 
            pli.item_id,
            pli.unit_price,
            pli.discount_percentage,
            pli.min_quantity,
            i.code as item_code,
            i.name as item_name,
            u.symbol as unit
        FROM price_list_items pli
        JOIN items i ON pli.item_id = i.id
        JOIN units u ON i.unit_id = u.id
        WHERE pli.price_list_id = ? AND pli.is_active = 1
        ORDER BY i.name
    ");
    
    $stmt->execute([$price_list_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($items);
    
} catch(PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>