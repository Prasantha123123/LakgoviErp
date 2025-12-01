<?php
// get_repacking_details.php - API endpoint to get repacking details
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid repacking ID']);
    exit;
}

$repacking_id = (int)$_GET['id'];

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT
            r.id, r.repack_code, r.repack_date, r.source_item_id, r.repack_item_id,
            r.source_quantity, r.repack_quantity, r.repack_unit_size,
            r.remaining_qty,
            (r.repack_quantity - r.remaining_qty) as consumed_qty,
            r.source_unit_id, r.repack_unit_id, r.location_id, r.notes,
            r.source_batch_code, r.created_by, r.created_at, r.updated_at,
            i1.code AS source_item_code, i1.name AS source_item_name, u1.symbol AS source_unit_symbol,
            i2.code AS repack_item_code, i2.name AS repack_item_name, u2.symbol AS repack_unit_symbol,
            l.name AS location_name,
            au.full_name AS created_by_name,
            CASE 
                WHEN r.remaining_qty = 0 THEN 'Fully Consumed'
                WHEN r.remaining_qty = r.repack_quantity THEN 'Available'
                WHEN r.remaining_qty > 0 THEN 'Partially Consumed'
                ELSE 'Over-consumed'
            END as status
        FROM repacking r
        LEFT JOIN items i1 ON r.source_item_id = i1.id
        LEFT JOIN items i2 ON r.repack_item_id = i2.id
        LEFT JOIN units u1 ON r.source_unit_id = u1.id
        LEFT JOIN units u2 ON r.repack_unit_id = u2.id
        LEFT JOIN locations l ON r.location_id = l.id
        LEFT JOIN admin_users au ON r.created_by = au.id
        WHERE r.id = ?
    ");

    $stmt->execute([$repacking_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($record) {
        echo json_encode(['success' => true, 'record' => $record]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Repacking record not found']);
    }

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>