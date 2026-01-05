<?php
/**
 * Get Item Report Details - AJAX endpoint for item invoice details
 */

require_once 'database.php';
require_once 'report_functions.php';

header('Content-Type: application/json');

try {
    // Initialize database
    $database = new Database();
    $db = $database->getConnection();
    
    // Get parameters
    $itemId = $_GET['item_id'] ?? null;
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    $status = $_GET['status'] ?? 'confirmed';
    $paymentStatus = $_GET['payment_status'] ?? '';
    
    // Validate required parameters
    if (empty($itemId)) {
        throw new Exception('Item ID is required');
    }
    
    // Validate dates
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        throw new Exception('Invalid date_from format');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        throw new Exception('Invalid date_to format');
    }
    
    $filters = [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'status' => $status,
        'payment_status' => $paymentStatus
    ];
    
    // Get item invoices
    $invoices = getItemInvoices($db, $itemId, $filters);
    
    echo json_encode([
        'success' => true,
        'invoices' => $invoices
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
