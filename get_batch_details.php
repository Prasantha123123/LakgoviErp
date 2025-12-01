<?php
// get_batch_details.php - API endpoint to get rolls batch details
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid batch ID']);
    exit;
}

$batch_id = (int)$_GET['id'];

try {
    $database = new Database();
    $db = $database->getConnection();

    // Get batch details
    $stmt = $db->prepare("SELECT * FROM v_rolls_details WHERE id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        echo json_encode(['success' => false, 'message' => 'Batch not found']);
        exit;
    }

    // Get materials used in this batch
    $stmt = $db->prepare("
        SELECT
            rm.quantity_used,
            i.code AS item_code,
            i.name AS item_name,
            u.symbol AS unit_symbol
        FROM rolls_materials rm
        JOIN items i ON rm.item_id = i.id
        JOIN units u ON rm.unit_id = u.id
        WHERE rm.batch_id = ?
        ORDER BY i.name
    ");
    $stmt->execute([$batch_id]);
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'batch' => $batch,
        'materials' => $materials
    ]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>