<?php
// api/get_production_materials.php
include '../database.php';
include '../config/simple_auth.php';

header('Content-Type: application/json');

// Initialize database and auth
$database = new Database();
$db = $database->getConnection();
$auth = new SimpleAuth($db);
$auth->requireAuth();

if (!isset($_GET['production_id']) || !is_numeric($_GET['production_id'])) {
    echo json_encode(['error' => 'Invalid or missing production_id']);
    exit;
}

$production_id = (int)$_GET['production_id'];

try {
    // Get production details and check status
    $stmt = $db->prepare("SELECT item_id, planned_qty, status, peetu_item_id, peetu_qty FROM production WHERE id = ?");
    $stmt->execute([$production_id]);
    $production = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$production) {
        echo json_encode(['error' => 'Production batch not found']);
        exit;
    }

    // Check if batch status is 'planned' or 'pending_material' or empty (treat as planned)
    $effective_status = empty($production['status']) ? 'planned' : $production['status'];
    if ($effective_status !== 'planned' && $effective_status !== 'pending_material') {
        echo json_encode(['error' => 'This batch already has materials issued or is not ready for MRN.']);
        exit;
    }

    $finished_item_id = $production['item_id'];
    $planned_qty = $production['planned_qty'];
    $peetu_item_id = $production['peetu_item_id'];
    $peetu_qty = $production['peetu_qty'];

    // Fetch raw materials
    $materials = [];

    // Check if this finished item has direct BOM first
    $stmt = $db->prepare("SELECT COUNT(*) FROM bom_direct WHERE finished_item_id = ?");
    $stmt->execute([$finished_item_id]);
    $has_direct_bom = (int)$stmt->fetchColumn() > 0;
    
    if ($has_direct_bom) {
        // Direct BOM: finished item made directly from raw materials
        $stmt = $db->prepare("
            SELECT i.id AS item_id, i.code AS item_code, i.name AS item_name, u.symbol AS uom,
                   ROUND((bd.quantity * ?), 3) AS required_quantity
            FROM bom_direct bd
            JOIN items i ON i.id = bd.raw_material_id
            JOIN units u ON u.id = i.unit_id
            WHERE bd.finished_item_id = ?
        ");
        $stmt->execute([$planned_qty, $finished_item_id]);
        $raw_materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Merge materials, combining quantities for same items
        foreach ($raw_materials as $material) {
            $existing_key = null;
            foreach ($materials as $key => $existing) {
                if ($existing['item_id'] == $material['item_id']) {
                    $existing_key = $key;
                    break;
                }
            }

            if ($existing_key !== null) {
                $materials[$existing_key]['required_quantity'] += $material['required_quantity'];
                $materials[$existing_key]['required_quantity'] = round($materials[$existing_key]['required_quantity'], 3);
            } else {
                $materials[] = $material;
            }
        }
    } elseif ($peetu_item_id) {
        // Peetu-based production
        $stmt = $db->prepare("
            SELECT i.id AS item_id, i.code AS item_code, i.name AS item_name, u.symbol AS uom,
                   ROUND((bp.quantity * ?), 3) AS required_quantity
            FROM bom_peetu bp
            JOIN items i ON i.id = bp.raw_material_id
            JOIN units u ON u.id = i.unit_id
            WHERE bp.peetu_item_id = ?
        ");
        $stmt->execute([$peetu_qty, $peetu_item_id]);
        $raw_materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Merge materials, combining quantities for same items
        foreach ($raw_materials as $material) {
            $existing_key = null;
            foreach ($materials as $key => $existing) {
                if ($existing['item_id'] == $material['item_id']) {
                    $existing_key = $key;
                    break;
                }
            }

            if ($existing_key !== null) {
                $materials[$existing_key]['required_quantity'] += $material['required_quantity'];
                $materials[$existing_key]['required_quantity'] = round($materials[$existing_key]['required_quantity'], 3);
            } else {
                $materials[] = $material;
            }
        }
    } else {
        echo json_encode(['error' => 'No BOM found for this production batch']);
        exit;
    }

    if (empty($materials)) {
        echo json_encode(['error' => 'No raw materials found for this production batch']);
        exit;
    }

    echo json_encode(['materials' => $materials]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
