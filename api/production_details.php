<?php
include '../database.php';

header('Content-Type: application/json');

try {
    $db = (new Database())->getConnection();

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('Invalid production ID');
    }

    $production_id = (int)$_GET['id'];
    // Get production details
    $stmt = $db->prepare("
        SELECT p.*, i.name AS item_name, i.code AS item_code,
               u.symbol AS unit_symbol,
               l.name AS location_name
        FROM production p
        JOIN items i ON i.id = p.item_id
        LEFT JOIN units u ON u.id = i.unit_id
        LEFT JOIN locations l ON l.id = p.location_id
        WHERE p.id = ?
    ");
    $stmt->execute([$production_id]);
    $production = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$production) {
        throw new Exception('Production not found');
    }

    // Get trolley movements for this production
    $stmt = $db->prepare("
        SELECT tm.*, t.trolley_no AS trolley_batch, t.status AS trolley_status,
               tm.actual_weight_kg, tm.expected_weight_kg,
               tm.created_at AS movement_date,
               CASE WHEN tm.status = 'completed' THEN 'Completed'
                    WHEN tm.status = 'rejected' THEN 'Rejected'
                    ELSE 'Pending' END AS status_text
        FROM trolley_movements tm
        JOIN trolleys t ON t.id = tm.trolley_id
        WHERE tm.production_id = ?
        ORDER BY tm.created_at DESC
    ");
    $stmt->execute([$production_id]);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get remaining completion if any
    $stmt = $db->prepare("
        SELECT * FROM production_remaining_completion
        WHERE production_id = ?
        ORDER BY completed_at DESC
    ");
    $stmt->execute([$production_id]);
    $remaining_completion = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'production' => $production,
        'transfers' => $transfers,
        'remaining_completion' => $remaining_completion
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>