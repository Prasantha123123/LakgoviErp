<?php
// api/ajax_search_items.php - Search items for quotation
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

$search = $_GET['search'] ?? '';
$price_list_id = $_GET['price_list_id'] ?? null;
$limit = min(intval($_GET['limit'] ?? 20), 50);

try {
    // Search all active items (finished goods for quotations)
    $sql = "
        SELECT 
            i.id, 
            i.code, 
            i.name, 
            i.type,
            u.symbol as unit_symbol,
            pli.unit_price,
            pli.is_active as price_active
        FROM items i
        JOIN units u ON i.unit_id = u.id
        LEFT JOIN price_list_items pli ON i.id = pli.item_id AND pli.price_list_id = ?
        WHERE i.type = 'finished' 
        AND (i.code LIKE ? OR i.name LIKE ?)
        ORDER BY i.name
        LIMIT ?
    ";
    
    $search_term = '%' . $search . '%';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(1, $price_list_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $search_term, PDO::PARAM_STR);
    $stmt->bindValue(3, $search_term, PDO::PARAM_STR);
    $stmt->bindValue(4, $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format items - always include even without price
    $formatted_items = array_map(function($item) {
        return [
            'id' => $item['id'],
            'code' => $item['code'],
            'name' => $item['name'],
            'unit_symbol' => $item['unit_symbol'],
            'unit_price' => $item['unit_price'] ? floatval($item['unit_price']) : 0,
            'has_price' => $item['unit_price'] && $item['price_active'] == 1
        ];
    }, $items);
    
    echo json_encode([
        'success' => true,
        'items' => $formatted_items,
        'count' => count($formatted_items)
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
