<?php
// api/ajax_get_item_price.php - Fetch item price from price list
header('Content-Type: application/json');

require_once '../database.php';
require_once '../config/simple_auth.php';

session_start();

$database = new Database();
$db = $database->getConnection();
$auth = new SimpleAuth($db);

if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$item_id = $_GET['item_id'] ?? null;
$price_list_id = $_GET['price_list_id'] ?? null;

if (!$item_id) {
    echo json_encode(['success' => false, 'error' => 'Item ID is required']);
    exit;
}

try {
    // Fetch item details
    $stmt = $db->prepare("
        SELECT i.id, i.code, i.name, i.type, u.symbol as unit_symbol
        FROM items i
        JOIN units u ON i.unit_id = u.id
        WHERE i.id = ?
    ");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Item not found']);
        exit;
    }
    
    // Fetch price from price list (if price_list_id provided and > 0)
    $price_item = null;
    if ($price_list_id && $price_list_id > 0) {
        $stmt = $db->prepare("
            SELECT pli.unit_price, pli.min_quantity, pli.discount_percentage, pli.is_active
            FROM price_list_items pli
            WHERE pli.price_list_id = ? AND pli.item_id = ? AND pli.is_active = 1
        ");
        $stmt->execute([$price_list_id, $item_id]);
        $price_item = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $response = [
        'success' => true,
        'item' => [
            'id' => $item['id'],
            'code' => $item['code'],
            'name' => $item['name'],
            'type' => $item['type'],
            'unit_symbol' => $item['unit_symbol']
        ],
        'has_price' => $price_item ? true : false,
        'price_info' => $price_item ? [
            'unit_price' => floatval($price_item['unit_price']),
            'min_quantity' => floatval($price_item['min_quantity']),
            'discount_percentage' => floatval($price_item['discount_percentage'])
        ] : null
    ];
    
    echo json_encode($response);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
