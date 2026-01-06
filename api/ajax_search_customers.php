<?php
// api/ajax_search_customers.php - Search customers
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
$limit = min(intval($_GET['limit'] ?? 20), 50);

try {
    $sql = "
        SELECT 
            c.id, 
            c.customer_code, 
            c.customer_name, 
            c.city,
            c.phone,
            c.is_active,
            pl.price_list_name
        FROM customers c
        LEFT JOIN price_lists pl ON c.price_list_id = pl.id
        WHERE c.is_active = 1 
        AND (c.customer_code LIKE ? OR c.customer_name LIKE ? OR c.city LIKE ?)
        ORDER BY c.customer_name
        LIMIT ?
    ";
    
    $search_term = '%' . $search . '%';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(1, $search_term, PDO::PARAM_STR);
    $stmt->bindValue(2, $search_term, PDO::PARAM_STR);
    $stmt->bindValue(3, $search_term, PDO::PARAM_STR);
    $stmt->bindValue(4, $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'customers' => $customers,
        'count' => count($customers)
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
