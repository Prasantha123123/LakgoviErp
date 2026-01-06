<?php
// api/ajax_get_customer.php - Fetch customer details with price list info
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

$customer_id = $_GET['customer_id'] ?? null;

if (!$customer_id) {
    echo json_encode(['success' => false, 'error' => 'Customer ID is required']);
    exit;
}

try {
    // Fetch customer details
    $stmt = $db->prepare("
        SELECT 
            c.*,
            pl.id as assigned_price_list_id,
            pl.price_list_code as assigned_pl_code,
            pl.price_list_name as assigned_pl_name,
            pl.currency as assigned_pl_currency,
            pl.is_active as assigned_pl_active
        FROM customers c
        LEFT JOIN price_lists pl ON c.price_list_id = pl.id
        WHERE c.id = ?
    ");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        echo json_encode(['success' => false, 'error' => 'Customer not found']);
        exit;
    }
    
    // Determine the effective price list
    $effective_price_list = null;
    $price_list_source = '';
    
    // 1) Check if customer has assigned price list and it's active
    if ($customer['price_list_id'] && $customer['assigned_pl_active'] == 1) {
        $effective_price_list = [
            'id' => $customer['assigned_price_list_id'],
            'code' => $customer['assigned_pl_code'],
            'name' => $customer['assigned_pl_name'],
            'currency' => $customer['assigned_pl_currency']
        ];
        $price_list_source = 'customer';
    } else {
        // 2) Fallback to default price list
        $stmt = $db->prepare("
            SELECT id, price_list_code, price_list_name, currency 
            FROM price_lists 
            WHERE is_default = 1 AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute();
        $default_pl = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($default_pl) {
            $effective_price_list = [
                'id' => $default_pl['id'],
                'code' => $default_pl['price_list_code'],
                'name' => $default_pl['price_list_name'],
                'currency' => $default_pl['currency']
            ];
            $price_list_source = 'default';
        }
    }
    
    // Build response
    $response = [
        'success' => true,
        'customer' => [
            'id' => $customer['id'],
            'customer_code' => $customer['customer_code'],
            'customer_name' => $customer['customer_name'],
            'contact_person' => $customer['contact_person'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'mobile' => $customer['mobile'],
            'address_line1' => $customer['address_line1'],
            'address_line2' => $customer['address_line2'],
            'city' => $customer['city'],
            'state' => $customer['state'],
            'postal_code' => $customer['postal_code'],
            'country' => $customer['country'],
            'credit_limit' => $customer['credit_limit'],
            'credit_days' => $customer['credit_days']
        ],
        'price_list' => $effective_price_list,
        'price_list_source' => $price_list_source
    ];
    
    echo json_encode($response);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
