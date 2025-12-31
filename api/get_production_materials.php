<?php
// api/get_production_materials.php
include '../database.php';
include '../config/simple_auth.php';

header('Content-Type: application/json');

// Initialize database and auth
$database = new Database();
$db = $database->getConnection();
$auth = new SimpleAuth($db);
// $auth->requireAuth(); // Temporarily disabled for testing

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
        // First, get all BOM lines (both direct items and category-based)
        $stmt = $db->prepare("
            SELECT bd.raw_material_id, bd.category_id, bd.quantity, bd.finished_unit_qty, c.name as category_name
            FROM bom_direct bd
            LEFT JOIN categories c ON bd.category_id = c.id
            WHERE bd.finished_item_id = ?
        ");
        $stmt->execute([$finished_item_id]);
        $bom_lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bom_lines as $bom_line) {
            // Calculate required quantity: Use BOM quantity directly (per unit requirement)
            // Previously: $unit_qty = floatval($bom_line['finished_unit_qty'] ?: 1.0);
            // $material_per_unit = floatval($bom_line['quantity']);
            // $pieces_to_produce = floatval($planned_qty) / $unit_qty;
            // $required_quantity = $material_per_unit * $pieces_to_produce;
            
            // Now use the BOM quantity directly as the required quantity
            $required_quantity = floatval($bom_line['quantity']);
            
            // Format with appropriate precision (3 decimal places) but avoid unnecessary rounding
            $display_quantity = round($required_quantity, 3); // Display precision

            if ($bom_line['raw_material_id']) {
                // Normal BOM line with specific raw material
                $stmt = $db->prepare("
                    SELECT i.id AS item_id, i.code AS item_code, i.name AS item_name, u.symbol AS uom
                    FROM items i
                    JOIN units u ON u.id = i.unit_id
                    WHERE i.id = ?
                ");
                $stmt->execute([$bom_line['raw_material_id']]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($item) {
                    // Check for existing material to combine quantities (only for direct items)
                    $existing_key = null;
                    foreach ($materials as $key => $existing) {
                        if (isset($existing['item_id']) && $existing['item_id'] == $item['item_id']) {
                            $existing_key = $key;
                            break;
                        }
                    }

                    if ($existing_key !== null) {
                        $materials[$existing_key]['required_quantity'] += $required_quantity;
                        $materials[$existing_key]['required_quantity'] = round($materials[$existing_key]['required_quantity'], 3);
                    } else {
                        $materials[] = array_merge($item, [
                            'required_quantity' => $required_quantity,
                            'is_category' => false
                        ]);
                    }
                }
            } elseif ($bom_line['category_id']) {
                // Category-based BOM line
                $category_id = $bom_line['category_id'];
                $category_name = $bom_line['category_name'];

                // Get all items in this category with their stock in Store (location_id = 1)
                $stmt = $db->prepare("
                    SELECT i.id AS item_id, i.code AS item_code, i.name AS item_name, u.symbol AS uom,
                           COALESCE(SUM(sl.quantity_in - sl.quantity_out), 0) AS available_stock
                    FROM items i
                    JOIN units u ON u.id = i.unit_id
                    LEFT JOIN stock_ledger sl ON sl.item_id = i.id AND sl.location_id = 1
                    LEFT JOIN item_categories ic ON ic.item_id = i.id
                    WHERE (i.category_id = ? OR ic.category_id = ?) AND i.type = 'raw'
                    GROUP BY i.id, i.code, i.name, u.symbol
                    ORDER BY i.name
                ");
                $stmt->execute([$category_id, $category_id]);
                $category_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Calculate total available stock for the category
                $total_available_stock = 0;
                foreach ($category_items as $cat_item) {
                    $total_available_stock += floatval($cat_item['available_stock']);
                }

                $materials[] = [
                    'is_category' => true,
                    'category_id' => $category_id,
                    'category_name' => $category_name,
                    'required_quantity' => $required_quantity,
                    'display_quantity' => $display_quantity,
                    'uom' => 'kg', // Assume kg for category-based lines
                    'total_available_stock' => round($total_available_stock, 3),
                    'candidate_items' => $category_items
                ];
            }
        }
    } elseif ($peetu_item_id) {
        // Peetu-based production
        // First, get all BOM lines (both direct items and category-based)
        $stmt = $db->prepare("
            SELECT bp.raw_material_id, bp.category_id, bp.quantity, c.name as category_name
            FROM bom_peetu bp
            LEFT JOIN categories c ON bp.category_id = c.id
            WHERE bp.peetu_item_id = ?
        ");
        $stmt->execute([$peetu_item_id]);
        $bom_lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bom_lines as $bom_line) {
            $required_quantity = round($bom_line['quantity'], 3);

            if ($bom_line['raw_material_id']) {
                // Normal BOM line with specific raw material
                $stmt = $db->prepare("
                    SELECT i.id AS item_id, i.code AS item_code, i.name AS item_name, u.symbol AS uom
                    FROM items i
                    JOIN units u ON u.id = i.unit_id
                    WHERE i.id = ?
                ");
                $stmt->execute([$bom_line['raw_material_id']]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($item) {
                    // Check for existing material to combine quantities (only for direct items)
                    $existing_key = null;
                    foreach ($materials as $key => $existing) {
                        if (isset($existing['item_id']) && $existing['item_id'] == $item['item_id']) {
                            $existing_key = $key;
                            break;
                        }
                    }

                    if ($existing_key !== null) {
                        $materials[$existing_key]['required_quantity'] += $required_quantity;
                        $materials[$existing_key]['required_quantity'] = round($materials[$existing_key]['required_quantity'], 3);
                    } else {
                        $materials[] = array_merge($item, [
                            'required_quantity' => $required_quantity,
                            'is_category' => false
                        ]);
                    }
                }
            } elseif ($bom_line['category_id']) {
                // Category-based BOM line
                $category_id = $bom_line['category_id'];
                $category_name = $bom_line['category_name'];

                // Get all items in this category with their stock in Store (location_id = 1)
                $stmt = $db->prepare("
                    SELECT i.id AS item_id, i.code AS item_code, i.name AS item_name, u.symbol AS uom,
                           COALESCE(SUM(sl.quantity_in - sl.quantity_out), 0) AS available_stock
                    FROM items i
                    JOIN units u ON u.id = i.unit_id
                    LEFT JOIN stock_ledger sl ON sl.item_id = i.id AND sl.location_id = 1
                    LEFT JOIN item_categories ic ON ic.item_id = i.id
                    WHERE (i.category_id = ? OR ic.category_id = ?) AND i.type = 'raw'
                    GROUP BY i.id, i.code, i.name, u.symbol
                    ORDER BY i.name
                ");
                $stmt->execute([$category_id, $category_id]);
                $category_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Calculate total available stock for the category
                $total_available_stock = 0;
                foreach ($category_items as $cat_item) {
                    $total_available_stock += floatval($cat_item['available_stock']);
                }

                $materials[] = [
                    'is_category' => true,
                    'category_id' => $category_id,
                    'category_name' => $category_name,
                    'required_quantity' => $required_quantity,
                    'uom' => 'kg', // Assume kg for category-based lines
                    'total_available_stock' => round($total_available_stock, 3),
                    'candidate_items' => $category_items
                ];
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
