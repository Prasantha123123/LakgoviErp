<?php
// get_bundle_details.php - API endpoint to get bundle details
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid bundle ID']);
    exit;
}

$bundle_id = (int)$_GET['id'];

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT
            b.id,
            b.bundle_code,
            b.bundle_date,
            b.source_item_id,
            si.code AS source_item_code,
            si.name AS source_item_name,
            b.source_quantity,
            su.symbol AS source_unit_symbol,
            b.bundle_item_id,
            bi.code AS bundle_item_code,
            bi.name AS bundle_item_name,
            b.bundle_quantity,
            b.packs_per_bundle as bundle_unit_size,
            bu.symbol AS bundle_unit_symbol,
            l.name AS location_name,
            b.notes,
            au.full_name AS created_by_name,
            b.created_at,
            b.updated_at
        FROM bundles b
        LEFT JOIN items si ON si.id = b.source_item_id
        LEFT JOIN items bi ON bi.id = b.bundle_item_id
        LEFT JOIN units su ON su.id = b.source_unit_id
        LEFT JOIN units bu ON bu.id = b.bundle_unit_id
        LEFT JOIN locations l ON l.id = b.location_id
        LEFT JOIN admin_users au ON au.id = b.created_by
        WHERE b.id = ?
    ");

    $stmt->execute([$bundle_id]);
    $bundle = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($bundle) {
        echo json_encode(['success' => true, 'record' => $bundle]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Bundle not found']);
    }

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>