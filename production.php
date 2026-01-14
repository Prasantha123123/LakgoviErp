<?php
// production.php - Production management (Finished via Peetu; Peetu via Raw)
include 'header.php';

/**
 * Assumptions
 * - bom_product.quantity = FINISHED UNITS produced per 1 Peetu (e.g., 1 Peetu => 8 pcs of AMAMI 4KG)
 * - bom_peetu.quantity   = RAW quantity required per 1 Peetu
 * - items.type in {'finished', 'semi_finished'}; units table gives symbol
 * - stock_ledger tracks balances per item & location
 *
 * DB patch (run once):
 *   ALTER TABLE production
 *     ADD COLUMN peetu_item_id INT NULL,
 *     ADD COLUMN peetu_qty DECIMAL(18,6) NULL,
 *     ADD CONSTRAINT fk_production_peetu
 *       FOREIGN KEY (peetu_item_id) REFERENCES items(id);
 */

function as_int($v){ return is_numeric($v)? (int)$v : 0; }
function as_float($v){ return is_numeric($v)? (float)$v : 0.0; }

function checkRawMaterialAvailability($db, $item_id, $item_type, $planned_qty, $peetu_item_id, $peetu_qty, $location_id) {
    $available = true;
    $errors = [];

    if ($item_type === 'finished') {
        // Check if this finished item has direct BOM first
        $stmt = $db->prepare("SELECT COUNT(*) FROM bom_direct WHERE finished_item_id = ?");
        $stmt->execute([$item_id]);
        $has_direct_bom = (int)$stmt->fetchColumn() > 0;
        
        if ($has_direct_bom) {
            // Direct BOM validation
            $stmt = $db->prepare("
                SELECT bd.raw_material_id, bd.category_id, bd.quantity AS per_unit_qty, bd.finished_unit_qty, 
                       COALESCE(i.name, c.name) AS raw_name
                FROM bom_direct bd
                LEFT JOIN items i ON i.id = bd.raw_material_id
                LEFT JOIN categories c ON c.id = bd.category_id
                WHERE bd.finished_item_id = ?
            ");
            $stmt->execute([$item_id]);
            $raws = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($raws as $rw) {
                $per_unit_qty = (float)$rw['per_unit_qty'];
                $req = $planned_qty * $per_unit_qty;
                
                if ($rw['category_id']) {
                    $s = $db->prepare("
                        SELECT COALESCE(SUM(sl.quantity_in - sl.quantity_out),0)
                        FROM stock_ledger sl
                        WHERE sl.item_id IN (
                            SELECT DISTINCT i.id 
                            FROM items i
                            LEFT JOIN item_categories ic ON ic.item_id = i.id
                            WHERE (i.category_id = ? OR ic.category_id = ?)
                        ) AND sl.location_id=?
                    ");
                    $s->execute([(int)$rw['category_id'], (int)$rw['category_id'], $location_id]);
                } else {
                    $s = $db->prepare("
                        SELECT COALESCE(SUM(quantity_in - quantity_out),0)
                        FROM stock_ledger
                        WHERE item_id=? AND location_id=?
                    ");
                    $s->execute([(int)$rw['raw_material_id'], $location_id]);
                }
                $have = (float)$s->fetchColumn();
                if ($have + 1e-9 < $req) {
                    $available = false;
                    $need = number_format($req, 3);
                    $haveStr = number_format($have, 3);
                    $errors[] = "Insufficient RAW: {$rw['raw_name']}. Need {$need}, have {$haveStr}";
                }
            }
        } else {
            // Peetu-based validation
            $stmt = $db->prepare("
                SELECT bp.raw_material_id, bp.category_id, bp.quantity AS per_peetu_qty, 
                       COALESCE(i.name, c.name) AS raw_name
                FROM bom_peetu bp
                LEFT JOIN items i ON i.id = bp.raw_material_id
                LEFT JOIN categories c ON c.id = bp.category_id
                WHERE bp.peetu_item_id = ?
            ");
            $stmt->execute([$peetu_item_id]);
            $raws = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($raws as $rw) {
                $req = (float)$rw['per_peetu_qty'] * $peetu_qty;

                if ($rw['category_id']) {
                    $s = $db->prepare("
                        SELECT COALESCE(SUM(sl.quantity_in - sl.quantity_out),0)
                        FROM stock_ledger sl
                        WHERE sl.item_id IN (
                            SELECT DISTINCT i.id 
                            FROM items i
                            LEFT JOIN item_categories ic ON ic.item_id = i.id
                            WHERE (i.category_id = ? OR ic.category_id = ?)
                        ) AND sl.location_id=?
                    ");
                    $s->execute([(int)$rw['category_id'], (int)$rw['category_id'], $location_id]);
                } else {
                    $s = $db->prepare("
                        SELECT COALESCE(SUM(quantity_in - quantity_out),0)
                        FROM stock_ledger
                        WHERE item_id=? AND location_id=?
                    ");
                    $s->execute([(int)$rw['raw_material_id'], $location_id]);
                }
                $have = (float)$s->fetchColumn();
                if ($have + 1e-9 < $req) {
                    $available = false;
                    $need = number_format($req, 3);
                    $haveStr = number_format($have, 3);
                    $errors[] = "Insufficient RAW: {$rw['raw_name']}. Need {$need}, have {$haveStr}";
                }
            }
        }
    } else {
        // Semi-finished validation
        $stmt = $db->prepare("
            SELECT bp.raw_material_id, bp.category_id, bp.quantity AS per_peetu_qty, 
                   COALESCE(i.name, c.name) AS raw_name
            FROM bom_peetu bp
            LEFT JOIN items i ON i.id = bp.raw_material_id
            LEFT JOIN categories c ON c.id = bp.category_id
            WHERE bp.peetu_item_id = ?
        ");
        $stmt->execute([$item_id]);
        $raws = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($raws as $rw) {
            $req = (float)$rw['per_peetu_qty'] * $planned_qty;
            
            if ($rw['category_id']) {
                $s = $db->prepare("
                    SELECT COALESCE(SUM(sl.quantity_in - sl.quantity_out),0)
                    FROM stock_ledger sl
                    WHERE sl.item_id IN (
                        SELECT DISTINCT i.id 
                        FROM items i
                        LEFT JOIN item_categories ic ON ic.item_id = i.id
                        WHERE (i.category_id = ? OR ic.category_id = ?)
                    ) AND sl.location_id=?
                ");
                $s->execute([(int)$rw['category_id'], (int)$rw['category_id'], $location_id]);
            } else {
                $s = $db->prepare("
                    SELECT COALESCE(SUM(quantity_in - quantity_out),0)
                    FROM stock_ledger
                    WHERE item_id=? AND location_id=?
                ");
                $s->execute([(int)$rw['raw_material_id'], $location_id]);
            }
            $have = (float)$s->fetchColumn();
            if ($have + 1e-9 < $req) {
                $available = false;
                $need = number_format($req, 3);
                $haveStr = number_format($have, 3);
                $errors[] = "Insufficient RAW: {$rw['raw_name']}. Need {$need}, have {$haveStr}";
            }
        }
    }

    return ['available' => $available, 'errors' => $errors];
}

/**
 * Update item current_stock from stock_ledger (ALL locations)
 * GLOBAL RULE: current_stock = total across all locations
 */
function updateItemCurrentStock($db, $item_id) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as total_stock
        FROM stock_ledger
        WHERE item_id = ?
    ");
    $stmt->execute([$item_id]);
    $total = floatval($stmt->fetchColumn());
    
    $upd = $db->prepare("UPDATE items SET current_stock = ? WHERE id = ?");
    $upd->execute([$total, $item_id]);
}

$success = null;
$error   = null;
$transaction_started = false;

/* ---------- Preload data for UI logic ---------- */

// Items (finished + semi_finished)
try {
    $stmt = $db->query("
        SELECT i.id, i.name, i.code, i.type, u.symbol AS unit_symbol
        FROM items i
        JOIN units u ON u.id = i.unit_id
        WHERE i.type IN ('finished','semi_finished')
        ORDER BY i.name
    ");
    $ui_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $ui_items = [];
    $error = "Error loading items: ".$e->getMessage();
}

// Map of Finished -> [ {peetu_id, peetu_name, yield_units_per_peetu} ]
try {
    $stmt = $db->query("
        SELECT bp.finished_item_id, bp.peetu_item_id, bp.quantity AS units_per_peetu,
               p.name AS peetu_name, p.code AS peetu_code
        FROM bom_product bp
        JOIN items p ON p.id = bp.peetu_item_id AND p.type='semi_finished'
        ORDER BY bp.finished_item_id, p.name
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $bom_yield = [];
    foreach ($rows as $r) {
        $f = (int)$r['finished_item_id'];
        if (!isset($bom_yield[$f])) $bom_yield[$f] = [];
        $bom_yield[$f][] = [
            'peetu_id' => (int)$r['peetu_item_id'],
            'peetu_name' => $r['peetu_name'],
            'peetu_code' => $r['peetu_code'],
            'units_per_peetu' => (float)$r['units_per_peetu']
        ];
    }
} catch(PDOException $e) {
    $bom_yield = [];
    $error = "Error loading BOM Product yields: ".$e->getMessage();
}

// Direct BOM items (finished items that can be made directly from raw materials)
$direct_bom_items = [];
try {
    $stmt = $db->query("
        SELECT DISTINCT bd.finished_item_id
        FROM bom_direct bd
        JOIN items i ON i.id = bd.finished_item_id AND i.type = 'finished'
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $direct_bom_items[] = (int)$row['finished_item_id'];
    }
} catch(PDOException $e) {
    $direct_bom_items = [];
    $error = "Error loading Direct BOM items: ".$e->getMessage();
}

// Production locations
try {
    $stmt = $db->query("SELECT id, name FROM locations WHERE type='production' ORDER BY name");
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $locations = [];
    $error = "Error loading locations: ".$e->getMessage();
}

/* ---------- Handle POST actions ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!isset($_POST['action'])) throw new Exception("No action specified.");

        switch ($_POST['action']) {

            /* Create batch
               - If finished item: require peetu + peetu_qty, compute planned_qty = peetu_qty * units_per_peetu
               - Validate raw availability for needed Peetu qty via bom_peetu
            */
            case 'create': {
                $batch_no    = trim($_POST['batch_no'] ?? '');
                $item_id     = as_int($_POST['item_id'] ?? 0);
                $location_id = as_int($_POST['location_id'] ?? 0);
                $production_date = $_POST['production_date'] ?? date('Y-m-d');

                if (!$batch_no || !$item_id || !$location_id) {
                    throw new Exception("All fields are required for production creation");
                }

                // Get the item type + unit for logic
                $stmt = $db->prepare("SELECT i.id, i.type, u.symbol AS unit_symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.id=?");
                $stmt->execute([$item_id]);
                $itm = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$itm) throw new Exception("Selected item not found.");
                $item_type = $itm['type'];
                $unit_symbol = $itm['unit_symbol'];

                // Determine unit_type based on unit symbol
                $unit_type = 'pcs'; // default
                if (in_array(strtolower($unit_symbol), ['kg', 'g', 't'])) {
                    $unit_type = 'weight';
                } elseif (in_array(strtolower($unit_symbol), ['bndl'])) {
                    $unit_type = 'bundles';
                } elseif (in_array(strtolower($unit_symbol), ['pcs', 'pkt', 'box', 'bag'])) {
                    $unit_type = 'pcs';
                }

                $planned_qty = 0.0;
                $peetu_item_id = null;
                $peetu_qty = null;

                if ($item_type === 'finished') {
                    // Check if this finished item has direct BOM first
                    $stmt = $db->prepare("SELECT COUNT(*) FROM bom_direct WHERE finished_item_id = ?");
                    $stmt->execute([$item_id]);
                    $has_direct_bom = (int)$stmt->fetchColumn() > 0;
                    
                    if ($has_direct_bom) {
                        $planned_qty = as_float($_POST['planned_qty'] ?? 0);
                        if ($planned_qty <= 0) throw new Exception("Planned quantity must be greater than 0");
                    } else {
                        // Traditional Peetu-based production
                        // From UI
                        $peetu_item_id = as_int($_POST['peetu_item_id'] ?? 0);
                        $peetu_qty     = as_float($_POST['peetu_qty'] ?? 0);
                        if ($peetu_item_id<=0 || $peetu_qty<=0) {
                            throw new Exception("Please select a Peetu and enter Peetu quantity, or create a Direct BOM for this item.");
                        }
                        // find yield
                        $units_per_peetu = null;
                        foreach ($bom_yield[$item_id] ?? [] as $opt) {
                            if ((int)$opt['peetu_id'] === $peetu_item_id) {
                                $units_per_peetu = (float)$opt['units_per_peetu'];
                                break;
                            }
                        }
                        if ($units_per_peetu===null) {
                            throw new Exception("No BOM Product yield configured for this finished → peetu pair.");
                        }
                        $planned_qty = $peetu_qty * $units_per_peetu; // finished units
                    }
                } else {
                    // Semi-finished batch
                    $planned_qty = as_float($_POST['planned_qty'] ?? 0);
                    if ($planned_qty<=0) throw new Exception("Planned quantity must be greater than 0");
                }

                // Check raw material availability
                $availability = checkRawMaterialAvailability($db, $item_id, $item_type, $planned_qty, $peetu_item_id, $peetu_qty, $location_id);
                $status = $availability['available'] ? 'planned' : 'pending_material';

                $db->beginTransaction();
                $transaction_started = true;

                $stmt = $db->prepare("
                    INSERT INTO production (batch_no, item_id, location_id, planned_qty, production_date, status, peetu_item_id, peetu_qty, unit_type)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$batch_no, $item_id, $location_id, $planned_qty, $production_date, $status, $peetu_item_id, $peetu_qty, $unit_type]);

                $db->commit();
                $transaction_started = false;
                $success = "Production order created successfully! Status: " . ($status === 'planned' ? 'Ready to Start' : 'Pending Material');
                break;
            }

            case 'start': {
                $pid = as_int($_POST['id'] ?? 0);
                if ($pid<=0) throw new Exception("Production ID is required to start production");

                $db->beginTransaction();
                $transaction_started = true;

                $stmt = $db->prepare("SELECT id, batch_no, status, item_id, planned_qty, peetu_item_id, peetu_qty, location_id FROM production WHERE id=?");
                $stmt->execute([$pid]);
                $p = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$p) throw new Exception("Production record not found");
                if ($p['status'] !== 'planned' && $p['status'] !== 'pending_material' && $p['status'] !== 'materials_issued' && !empty($p['status'])) throw new Exception("Only planned, pending material, or materials issued batches can be started.");

                // Check raw material availability at production location (skip if materials already issued)
                if ($p['status'] !== 'materials_issued') {
                    $availability = checkRawMaterialAvailability($db, $p['item_id'], 'finished', $p['planned_qty'], $p['peetu_item_id'], $p['peetu_qty'], $p['location_id']);
                    if (!$availability['available']) {
                        $errors = implode('; ', $availability['errors']);
                        throw new Exception("Cannot start production: Raw materials not available. " . $errors);
                    }
                }

                $stmt = $db->prepare("UPDATE production SET status='in_progress' WHERE id=?");
                $stmt->execute([$pid]);

                $db->commit();
                $transaction_started = false;
                $success = "Production batch {$p['batch_no']} started successfully!";
                break;
            }

            /* Complete
               IMPORTANT: Production completion now ONLY consumes raw materials.
               NO finished stock is added here. Finished stock is added ONLY when
               trolley weights are verified in trolley.php.
            */
            case 'complete': {
                $pid = as_int($_POST['id'] ?? 0);
                if ($pid<=0) throw new Exception("Production ID is required");

                $db->beginTransaction();
                $transaction_started = true;

                $stmt = $db->prepare("
                    SELECT p.*, i.name AS item_name, i.code AS item_code, i.type AS item_type,
                           l.name AS location_name,
                           COALESCE((SELECT product_unit_qty FROM bom_product WHERE finished_item_id = i.id LIMIT 1), 
                                    (SELECT finished_unit_qty FROM bom_direct WHERE finished_item_id = i.id LIMIT 1), 
                                    i.unit_weight_kg) as unit_weight_kg
                    FROM production p
                    JOIN items i ON i.id = p.item_id
                    JOIN locations l ON l.id = p.location_id
                    WHERE p.id=? AND p.status='in_progress'
                ");

              
                $stmt->execute([$pid]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$prod) throw new Exception("Production not found or not in progress.");

                // Use planned_qty for raw material consumption calculation
                $planned_qty = (float)$prod['planned_qty'];
                if ($planned_qty <= 0) throw new Exception("Invalid planned quantity in production record.");
                
                $unit_weight = (float)$prod['unit_weight_kg'];

                $components = []; // array of [raw_id, raw_name, qty]
                if ($prod['item_type']==='finished') {
                    // Check if this finished item has direct BOM (raw materials) first
                    $stmt = $db->prepare("
                        SELECT bd.raw_material_id, bd.category_id, bd.quantity AS per_unit_qty, bd.finished_unit_qty, 
                               COALESCE(i.name, c.name) AS raw_name
                        FROM bom_direct bd
                        LEFT JOIN items i ON i.id = bd.raw_material_id
                        LEFT JOIN categories c ON c.id = bd.category_id
                        WHERE bd.finished_item_id = ?
                    ");
                    $stmt->execute([(int)$prod['item_id']]);
                    $direct_raws = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($direct_raws)) {
                        // Direct BOM: finished item made directly from raw materials
                        // BOM LOGIC FOR COMPLETION:
                        // - finished_unit_qty = weight per piece (e.g., 20 kg for 1 pc Papadam)
                        // - per_unit_qty = raw material needed for 1 piece
                        // - planned_qty = NUMBER OF PIECES to produce
                        // Example: 1 pc × 2 kg Salt/pc = 2 kg Salt to deduct
                        foreach ($direct_raws as $rw) {
                            $per_unit_qty = (float)$rw['per_unit_qty'];
                            
                            // Calculate raw consumption based on number of pieces (use planned_qty)
                            // Actual quantity comes later from trolley verification
                            $raw_consumed = $planned_qty * $per_unit_qty;
                            
                            $components[] = [
                                'raw_id'   => (int)($rw['raw_material_id'] ?: 0),
                                'category_id' => (int)($rw['category_id'] ?: 0),
                                'raw_name' => $rw['raw_name'],
                                'qty'      => $raw_consumed
                            ];
                        }
                    } else {
                        // Traditional Peetu-based BOM
                        $peetu_id = (int)$prod['peetu_item_id'];
                        if ($peetu_id<=0) {
                            // try infer if single mapping exists
                            $stmt = $db->prepare("SELECT COUNT(*) FROM bom_product WHERE finished_item_id=?");
                            $stmt->execute([(int)$prod['item_id']]);
                            $cnt = (int)$stmt->fetchColumn();
                            if ($cnt!==1) throw new Exception("No BOM recipe found. Please create either a Direct BOM or Peetu-based BOM for this item.");
                            $stmt = $db->prepare("SELECT peetu_item_id FROM bom_product WHERE finished_item_id=?");
                            $stmt->execute([(int)$prod['item_id']]);
                            $peetu_id = (int)$stmt->fetchColumn();
                        }

                        $stmt = $db->prepare("SELECT quantity FROM bom_product WHERE finished_item_id=? AND peetu_item_id=?");
                        $stmt->execute([(int)$prod['item_id'], $peetu_id]);
                        $units_per_peetu = (float)$stmt->fetchColumn();
                        if ($units_per_peetu<=0) throw new Exception("Invalid yield for finished → peetu.");

                        $actual_peetu_qty = $planned_qty / $units_per_peetu;

                        // RAW per peetu
                        $stmt = $db->prepare("
                            SELECT bp.raw_material_id, bp.category_id, bp.quantity AS per_peetu_qty, 
                                   COALESCE(i.name, c.name) AS raw_name
                            FROM bom_peetu bp
                            LEFT JOIN items i ON i.id = bp.raw_material_id
                            LEFT JOIN categories c ON c.id = bp.category_id
                            WHERE bp.peetu_item_id = ?
                        ");
                        $stmt->execute([$peetu_id]);
                        $raws = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        if (empty($raws)) throw new Exception("No Peetu recipe found (bom_peetu).");

                        foreach ($raws as $rw) {
                            $components[] = [
                                'raw_id'   => (int)($rw['raw_material_id'] ?: 0),
                                'category_id' => (int)($rw['category_id'] ?: 0),
                                'raw_name' => $rw['raw_name'],
                                'qty'      => (float)$rw['per_peetu_qty'] * $actual_peetu_qty
                            ];
                        }
                    }
                } else {
                    // semi_finished: RAW = per_peetu_raw * planned_qty
                    $stmt = $db->prepare("
                        SELECT bp.raw_material_id, bp.category_id, bp.quantity AS per_peetu_qty, 
                               COALESCE(i.name, c.name) AS raw_name
                        FROM bom_peetu bp
                        LEFT JOIN items i ON i.id = bp.raw_material_id
                        LEFT JOIN categories c ON c.id = bp.category_id
                        WHERE bp.peetu_item_id = ?
                    ");
                    $stmt->execute([(int)$prod['item_id']]);
                    $raws = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($raws)) throw new Exception("No Peetu recipe found (bom_peetu).");

                    foreach ($raws as $rw) {
                        $components[] = [
                            'raw_id'   => (int)($rw['raw_material_id'] ?: 0),
                            'category_id' => (int)($rw['category_id'] ?: 0),
                            'raw_name' => $rw['raw_name'],
                            'qty'      => (float)$rw['per_peetu_qty'] * $planned_qty
                        ];
                    }
                }

                // Availability check @ location
                foreach ($components as $c) {
                    if (!empty($c['category_id'])) {
                        // Category-based: check total stock across all items in category
                        // Includes items from both items.category_id AND item_categories junction table
                        $s = $db->prepare("
                            SELECT COALESCE(SUM(sl.quantity_in - sl.quantity_out),0)
                            FROM stock_ledger sl
                            WHERE sl.item_id IN (
                                SELECT DISTINCT i.id 
                                FROM items i
                                LEFT JOIN item_categories ic ON ic.item_id = i.id
                                WHERE (i.category_id = ? OR ic.category_id = ?)
                            ) AND sl.location_id=?
                        ");
                        $s->execute([$c['category_id'], $c['category_id'], (int)$prod['location_id']]);
                    } else {
                        // Regular raw material
                        $s = $db->prepare("
                            SELECT COALESCE(SUM(quantity_in - quantity_out),0)
                            FROM stock_ledger
                            WHERE item_id=? AND location_id=?
                        ");
                        $s->execute([$c['raw_id'], (int)$prod['location_id']]);
                    }
                    $have = (float)$s->fetchColumn();
                    if ($have + 1e-9 < $c['qty']) {
                        $need = number_format($c['qty'],3);
                        $haveS = number_format($have,3);
                        throw new Exception("Insufficient raw material: {$c['raw_name']}. Need {$need}, have {$haveS}");
                    }
                }

                // Consume RAW
                foreach ($components as $c) {
                    if (!empty($c['category_id'])) {
                        // Category-based: deduct from items in category using FIFO
                        $remaining = $c['qty'];
                        
                        // Get items in category with stock, ordered by oldest first (FIFO)
                        // Includes items from both items.category_id AND item_categories junction table
                        $items_stmt = $db->prepare("
                            SELECT i.id, i.name, COALESCE(SUM(sl.quantity_in - sl.quantity_out),0) AS stock
                            FROM items i
                            LEFT JOIN stock_ledger sl ON sl.item_id = i.id AND sl.location_id = ?
                            LEFT JOIN item_categories ic ON ic.item_id = i.id
                            WHERE (i.category_id = ? OR ic.category_id = ?)
                            GROUP BY i.id, i.name
                            HAVING stock > 0
                            ORDER BY i.id
                        ");
                        $items_stmt->execute([(int)$prod['location_id'], $c['category_id'], $c['category_id']]);
                        $category_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($category_items as $cat_item) {
                            if ($remaining <= 0) break;
                            
                            $item_id = (int)$cat_item['id'];
                            $available = (float)$cat_item['stock'];
                            $deduct = min($remaining, $available);
                            
                            // Get current balance
                            $s = $db->prepare("
                                SELECT COALESCE(SUM(quantity_in - quantity_out),0)
                                FROM stock_ledger
                                WHERE item_id=? AND location_id=?
                            ");
                            $s->execute([$item_id, (int)$prod['location_id']]);
                            $bal = (float)$s->fetchColumn();
                            $new_bal = $bal - $deduct;

                            // Insert stock ledger entry
                            $ins = $db->prepare("
                                INSERT INTO stock_ledger
                                  (item_id, location_id, transaction_type, reference_id, reference_no, transaction_date, quantity_out, balance, created_at)
                                VALUES (?, ?, 'production_out', ?, ?, ?, ?, ?, NOW())
                            ");
                            $ins->execute([$item_id, (int)$prod['location_id'], (int)$prod['id'], $prod['batch_no'], $prod['production_date'], $deduct, $new_bal]);

                            // Update item current_stock (GLOBAL - all locations)
                            updateItemCurrentStock($db, $item_id);
                            
                            $remaining -= $deduct;
                        }
                        
                        if ($remaining > 0.001) {
                            throw new Exception("Could not fully deduct category {$c['raw_name']}. Remaining: " . number_format($remaining, 3));
                        }
                    } else {
                        // Regular raw material
                        $s = $db->prepare("
                            SELECT COALESCE(SUM(quantity_in - quantity_out),0)
                            FROM stock_ledger
                            WHERE item_id=? AND location_id=?
                        ");
                        $s->execute([$c['raw_id'], (int)$prod['location_id']]);
                        $bal = (float)$s->fetchColumn();
                        $new_bal = $bal - $c['qty'];

                        $ins = $db->prepare("
                            INSERT INTO stock_ledger
                              (item_id, location_id, transaction_type, reference_id, reference_no, transaction_date, quantity_out, balance, created_at)
                            VALUES (?, ?, 'production_out', ?, ?, ?, ?, ?, NOW())
                        ");
                        $ins->execute([$c['raw_id'], (int)$prod['location_id'], (int)$prod['id'], $prod['batch_no'], $prod['production_date'], $c['qty'], $new_bal]);

                        // Update item current_stock (GLOBAL - all locations)
                        updateItemCurrentStock($db, $c['raw_id']);
                    }
                }

                // ========================================================================
                // Mark production as completed and initialize remaining quantities
                // DO NOT add production_in to stock_ledger here!
                // Stock will be added ONLY when trolley weights are verified.
                // ========================================================================
                
                // Calculate theoretical total weight
                $theoretical_weight_kg = $planned_qty * $unit_weight;
                
                // Update production record
                $u = $db->prepare("
                    UPDATE production 
                    SET actual_qty = ?,
                        status = 'completed',
                        remaining_qty = ?,
                        remaining_weight_kg = ?
                    WHERE id = ?
                ");
                $u->execute([$planned_qty, $planned_qty, $theoretical_weight_kg, $pid]);
                
                // Initialize wastage tracking record
                $stmt = $db->prepare("
                    INSERT INTO production_wastage (production_id, theoretical_units, total_actual_raw_units, total_actual_rounded_units, total_wastage_units, wastage_percentage, unit_type)
                    VALUES (?, ?, 0, 0, 0, 0, ?)
                    ON DUPLICATE KEY UPDATE theoretical_units = ?, unit_type = ?
                ");
                $stmt->execute([$pid, $planned_qty, $prod['unit_type'], $planned_qty, $prod['unit_type']]);

                $db->commit();
                $transaction_started = false;
                $success = "✅ Production completed! Raw materials consumed. Batch is ready for trolley transfer.";
                break;
            }

            case 'delete': {
                $pid = as_int($_POST['id'] ?? 0);
                if ($pid<=0) throw new Exception("Production ID is required for deletion");

                $db->beginTransaction();
                $transaction_started = true;

                $stmt = $db->prepare("SELECT status FROM production WHERE id=?");
                $stmt->execute([$pid]);
                $st = $stmt->fetchColumn();
                if ($st==='completed') throw new Exception("Cannot delete completed production orders.");

                $stmt = $db->prepare("DELETE FROM production WHERE id=?");
                $stmt->execute([$pid]);

                $db->commit();
                $transaction_started = false;
                $success = "Production order deleted.";
                break;
            }

            case 'complete_remaining': {
                // Record actual measured remaining weight and update remaining quantities
                // This does NOT finalize the batch - remaining can still be assigned to trolleys
                $pid = as_int($_POST['id'] ?? 0);
                if ($pid <= 0) throw new Exception("Production ID is required");
                
                $actual_weight = floatval($_POST['actual_remaining_weight'] ?? 0);
                if ($actual_weight < 0) throw new Exception("Actual remaining weight cannot be negative");
                
                $completion_reason = trim($_POST['completion_reason'] ?? 'Remaining weight verified');
                
                $db->beginTransaction();
                $transaction_started = true;
                
                // Get production details
                $stmt = $db->prepare("
                    SELECT p.*, i.name AS item_name,
                           COALESCE((SELECT product_unit_qty FROM bom_product WHERE finished_item_id = i.id LIMIT 1), 
                                    (SELECT finished_unit_qty FROM bom_direct WHERE finished_item_id = i.id LIMIT 1), 
                                    i.unit_weight_kg) as unit_weight_kg
                    FROM production p
                    JOIN items i ON i.id = p.item_id
                    WHERE p.id = ? AND p.status IN ('completed', 'partially_transferred')
                ");
                $stmt->execute([$pid]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$prod) {
                    throw new Exception("Production not found or not in correct status (must be completed or partially_transferred)");
                }
                
                $remaining_qty_old = (float)$prod['remaining_qty'];
                $remaining_weight_theoretical = (float)$prod['remaining_weight_kg'];
                $unit_weight_kg = (float)$prod['unit_weight_kg'];
                
                if ($remaining_qty_old <= 0) {
                    throw new Exception("No remaining quantity to update");
                }
                
                if ($unit_weight_kg <= 0) {
                    throw new Exception("Unit weight not configured for this item");
                }
                
                // Handle special case: when actual measured weight is 0, mark entire remaining as wastage
                if ($actual_weight == 0) {
                    // Mark entire remaining quantity as wastage
                    $actual_units_raw = 0;
                    $actual_units_rounded = 0;
                    $wastage_units = $remaining_qty_old; // All remaining becomes wastage
                    $weight_variance = 0 - $remaining_weight_theoretical;
                    
                    // Update completion reason to indicate wastage
                    if (empty($completion_reason) || $completion_reason == 'Remaining weight verified') {
                        $completion_reason = 'Complete wastage - no remaining weight found';
                    }
                    
                    $success_message = "✅ Batch completed with complete wastage. All remaining quantity (" . 
                                     number_format($remaining_qty_old, 3) . " pcs, " . 
                                     number_format($remaining_weight_theoretical, 3) . " kg) marked as wastage.";
                } else {
                    // Normal calculation for actual_weight > 0
                    // Calculate NEW remaining units from actual measured weight
                    $actual_units_raw = $actual_weight / $unit_weight_kg;
                    $actual_units_rounded = floor($actual_units_raw);
                    $wastage_units = $actual_units_raw - $actual_units_rounded;
                    $weight_variance = $actual_weight - $remaining_weight_theoretical;
                    
                    $qty_diff = $remaining_qty_old - $actual_units_rounded;
                    $diff_text = $qty_diff > 0 ? 
                        "reduced by " . number_format($qty_diff, 3) . " pcs" : 
                        "increased by " . number_format(abs($qty_diff), 3) . " pcs";
                    
                    $success_message = "✅ Remaining quantity updated. Theoretical: " . 
                                     number_format($remaining_qty_old, 3) . " pcs (" . 
                                     number_format($remaining_weight_theoretical, 3) . " kg). " . 
                                     "Actual measured: " . number_format($actual_weight, 3) . " kg (" . 
                                     number_format($actual_units_rounded, 3) . " pcs). " . 
                                     "Remaining quantity " . $diff_text . ".";
                }
                
                // The actual_units_rounded becomes the NEW remaining_qty
                $new_remaining_qty = $actual_units_rounded;
                $new_remaining_weight = $actual_weight;
                
                // Record the remaining weight measurement
                $stmt = $db->prepare("
                    INSERT INTO production_remaining_completion (
                        production_id, remaining_qty_before, remaining_weight_before,
                        actual_weight_measured, actual_units_raw, actual_units_rounded,
                        wastage_units, weight_variance, completion_reason, completed_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $pid,
                    $remaining_qty_old,
                    $remaining_weight_theoretical,
                    $actual_weight,
                    $actual_units_raw,
                    $actual_units_rounded,
                    $wastage_units,
                    $weight_variance,
                    $completion_reason,
                    6 // Current user ID
                ]);
                
                // Update production record with NEW actual remaining quantities
                // Set status to 'completed' after verification
                // This remaining can now be assigned to trolleys
                $stmt = $db->prepare("
                    UPDATE production 
                    SET remaining_qty = ?,
                        remaining_weight_kg = ?,
                        status = 'completed'
                    WHERE id = ?
                ");
                $stmt->execute([
                    $new_remaining_qty,
                    $new_remaining_weight,
                    $pid
                ]);
                
                $db->commit();
                $transaction_started = false;
                
                $success = $success_message;
                break;
            }

            default:
                throw new Exception("Invalid action");
        }

    } catch(PDOException $e) {
        if ($transaction_started && $db->inTransaction()) $db->rollback();
        $error = "Database error: ".$e->getMessage();
    } catch(Exception $e) {
        if ($transaction_started && $db->inTransaction()) $db->rollback();
        $error = $e->getMessage();
    }
}

/* ---------- Load table for page ---------- */
try {
    $stmt = $db->query("
        SELECT p.*, i.name AS item_name, i.code AS item_code, 
               CASE 
                   WHEN EXISTS (SELECT 1 FROM bom_direct WHERE finished_item_id = i.id) THEN 'pcs'
                   ELSE u.symbol 
               END AS unit_symbol,
               CASE WHEN EXISTS (SELECT 1 FROM bom_direct WHERE finished_item_id = i.id) THEN 1 ELSE 0 END AS is_bom_direct,
               COALESCE((SELECT product_unit_qty FROM bom_product WHERE finished_item_id = i.id LIMIT 1), 
                        (SELECT finished_unit_qty FROM bom_direct WHERE finished_item_id = i.id LIMIT 1), 
                        i.unit_weight_kg) as unit_weight_kg,
               l.name AS location_name,
               pi.name AS peetu_name,
               pw.total_actual_rounded_units, pw.total_wastage_units,
               prc.id IS NOT NULL AS verified
        FROM production p
        JOIN items i  ON i.id = p.item_id
        JOIN units u  ON u.id = i.unit_id
        JOIN locations l ON l.id = p.location_id
        LEFT JOIN items pi ON pi.id = p.peetu_item_id
        LEFT JOIN production_wastage pw ON pw.production_id = p.id
        LEFT JOIN production_remaining_completion prc ON prc.production_id = p.id
        ORDER BY p.production_date DESC, p.id DESC
    ");
    $productions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $productions = [];
    $error = "Error fetching productions: ".$e->getMessage();
}
?>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Production Management</h1>
            <p class="text-gray-600">Plan and track production batches</p>
        </div>
        <button class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600"
                onclick="openModal('createProductionModal')">Create Production Batch</button>
    </div>

    <?php if (!empty($success)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Status tiles -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-orange-100">
                    <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Pending Material</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p)=>$p['status']==='pending_material')); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Ready to Start</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p)=>$p['status']==='planned' || empty($p['status']))); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Materials Issued</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p)=>$p['status']==='materials_issued')); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">In Progress</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p)=>$p['status']==='in_progress')); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-teal-100">
                    <svg class="w-6 h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Ready for Trolley</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p)=>in_array($p['status'], ['completed', 'partially_transferred']))); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Fully Transferred</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p)=>$p['status']==='fully_transferred')); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Batch No</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Planned Qty</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actual Qty</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
            <?php foreach ($productions as $p): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($p['batch_no']); ?></td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        <div class="font-medium"><?php echo htmlspecialchars($p['item_name']); ?></div>
                        <div class="text-gray-500"><?php echo htmlspecialchars($p['item_code']); ?></div>
                        <?php if ($p['peetu_name']): ?>
                            <div class="text-xs text-blue-600 mt-1">Peetu: <?php echo htmlspecialchars($p['peetu_name']); ?><?php echo $p['peetu_qty'] ? " × ".number_format((float)$p['peetu_qty'],3) : ""; ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($p['location_name']); ?></td>
                    <td class="px-6 py-4 text-sm text-gray-900"><?php echo date('M d, Y', strtotime($p['production_date'])); ?></td>
                    <td class="px-6 py-4 text-sm text-gray-900 font-medium">
                        <?php 
                        $planned_qty = (float)$p['planned_qty'];
                        $unit_weight = (float)$p['unit_weight_kg'];
                        $unit_type = $p['unit_type'] ?? 'pcs';
                        
                        if ($unit_type === 'weight') {
                            // Weight-based: show total weight
                            $total_weight = $planned_qty;
                            echo number_format($total_weight, 3) . ' kg<br>';
                            echo '<span class="text-xs text-gray-600">(weight-based)</span>';
                        } elseif ($unit_type === 'bundles') {
                            // Bundle-based: show bundles
                            echo number_format($planned_qty, 0) . ' bundles';
                            if ($unit_weight > 0) {
                                $total_weight = $planned_qty * $unit_weight;
                                echo '<br><span class="text-xs text-gray-600">(' . number_format($total_weight, 3) . ' kg total)</span>';
                            }
                        } else {
                            // Piece-based (default): show pieces and total weight
                            echo number_format($planned_qty, 0) . ' ' . htmlspecialchars($p['unit_symbol']);
                            if ($unit_weight > 0) {
                                $total_weight = $planned_qty * $unit_weight;
                                echo '<br><span class="text-xs text-gray-600">(' . number_format($total_weight, 3) . ' kg total)</span>';
                            }
                        }
                        ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900 font-medium">
                        <?php 
                        if ($p['status'] === 'completed' || $p['status'] === 'partially_transferred' || $p['status'] === 'fully_transferred') {
                            $planned_qty = (float)$p['planned_qty'];
                            $remaining_qty = isset($p['remaining_qty']) ? (float)$p['remaining_qty'] : $planned_qty;
                            $remaining_weight_kg = isset($p['remaining_weight_kg']) ? (float)$p['remaining_weight_kg'] : 0;
                            $transferred_qty = isset($p['total_transferred_qty']) ? (float)$p['total_transferred_qty'] : 0;
                            $wastage = isset($p['total_wastage_units']) ? (float)$p['total_wastage_units'] : 0;
                            $unit_type = $p['unit_type'] ?? 'pcs';
                            
                            echo '<div class="space-y-1">';
                            
                            // Display planned quantity based on unit_type
                            if ($unit_type === 'weight') {
                                echo '<div>' . number_format($planned_qty, 3) . ' kg <span class="text-xs text-gray-500">(planned)</span></div>';
                            } elseif ($unit_type === 'bundles') {
                                echo '<div>' . number_format($planned_qty, 0) . ' bundles <span class="text-xs text-gray-500">(planned)</span></div>';
                            } else {
                                echo '<div>' . number_format($planned_qty, 0) . ' ' . htmlspecialchars($p['unit_symbol']) . ' <span class="text-xs text-gray-500">(planned)</span></div>';
                            }
                            
                            if ($transferred_qty > 0) {
                                // Display transferred quantity based on unit_type
                                if ($unit_type === 'weight') {
                                    echo '<div class="text-green-600">' . number_format($transferred_qty, 3) . ' kg <span class="text-xs">(transferred)</span></div>';
                                } elseif ($unit_type === 'bundles') {
                                    echo '<div class="text-green-600">' . number_format($transferred_qty, 0) . ' bundles <span class="text-xs">(transferred)</span></div>';
                                } else {
                                    echo '<div class="text-green-600">' . number_format($transferred_qty, 0) . ' ' . htmlspecialchars($p['unit_symbol']) . ' <span class="text-xs">(transferred)</span></div>';
                                }
                            }
                            
                            // Check remaining based on unit_type
                            $has_remaining = ($unit_type === 'weight') ? ($remaining_weight_kg > 0) : ($remaining_qty > 0);
                            if ($has_remaining && $p['status'] !== 'completed') {
                                $color = $p['status'] === 'partially_transferred' ? 'text-orange-600' : 'text-gray-600';
                                // Display remaining based on unit_type
                                if ($unit_type === 'weight') {
                                    echo '<div class="' . $color . '">' . number_format($remaining_weight_kg, 3) . ' kg <span class="text-xs">(remaining)</span></div>';
                                } elseif ($unit_type === 'bundles') {
                                    echo '<div class="' . $color . '">' . number_format($remaining_qty, 0) . ' bundles <span class="text-xs">(remaining)</span></div>';
                                } else {
                                    echo '<div class="' . $color . '">' . number_format($remaining_qty, 0) . ' ' . htmlspecialchars($p['unit_symbol']) . ' <span class="text-xs">(remaining)</span></div>';
                                }
                            }
                            
                            if ($wastage > 0) {
                                // Wastage always in same unit as transferred
                                $wastage_unit = $is_bom_direct ? 'kg' : htmlspecialchars($p['unit_symbol']);
                                echo '<div class="text-red-500 text-xs">Wastage: ' . number_format($wastage, 3) . ' ' . $wastage_unit . '</div>';
                            }
                            echo '</div>';
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <span class="<?php
                            $display_status = empty($p['status']) ? 'planned' : $p['status'];
                            echo $display_status==='fully_transferred' ? 'bg-green-100 text-green-800' :
                                 ($display_status==='partially_transferred' ? 'bg-orange-100 text-orange-800' :
                                  ($display_status==='completed' ? 'bg-teal-100 text-teal-800' :
                                   ($display_status==='in_progress' ? 'bg-blue-100 text-blue-800' :
                                    ($display_status==='pending_material' ? 'bg-orange-100 text-orange-800' :
                                     ($display_status==='materials_issued' ? 'bg-purple-100 text-purple-800' : 'bg-yellow-100 text-yellow-800')))));
                        ?> text-xs font-medium px-2.5 py-0.5 rounded capitalize"><?php echo str_replace('_',' ', empty($p['status']) ? 'planned' : $p['status']); ?></span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <?php $effective_status = empty($p['status']) ? 'planned' : $p['status']; ?>
                        <?php if ($effective_status==='planned' || $effective_status==='pending_material' || $effective_status==='materials_issued'): ?>
                          <form method="POST" class="inline">
                            <input type="hidden" name="action" value="start">
                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                            <button type="submit" class="text-blue-600 hover:text-blue-900">Start</button>
                          </form>
                          <?php if ($effective_status !== 'materials_issued'): ?>
                          <form method="POST" class="inline" onsubmit="return confirm('Delete this batch?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                          </form>
                          <?php endif; ?>
                        <?php elseif ($effective_status==='materials_issued'): ?>
                          <span class="text-gray-500 text-xs">Materials Issued</span>
                        <?php endif; ?>
                        
                        <?php if ($p['status']==='in_progress'): ?>
                          <button class="text-green-600 hover:text-green-900"
                                  onclick='completeProduction(<?php echo json_encode([
                                      'id'=>$p['id'],
                                      'batch_no'=>$p['batch_no'],
                                      'item_name'=>$p['item_name'],
                                      'item_code'=>$p['item_code'],
                                      'planned_qty'=>$p['planned_qty'],
                                      'unit_symbol'=>$p['unit_symbol']
                                  ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'>Complete</button>
                        <?php endif; ?>
                        
                        <?php if (in_array($p['status'], ['completed', 'partially_transferred', 'fully_transferred'])): ?>
                          <button class="text-blue-600 hover:text-blue-900"
                                  onclick='viewProductionDetails(<?php echo (int)$p['id']; ?>)'>View Details</button>
                        <?php endif; ?>
                        
                        <?php if (($p['status']==='completed' || $p['status']==='partially_transferred') && 
                                  ((float)$p['remaining_qty'] > 0 || (float)$p['remaining_weight_kg'] > 0) && 
                                  !$p['verified']): ?>
                          <button class="text-purple-600 hover:text-purple-900"
                                  onclick='completeRemaining(<?php echo json_encode([
                                      'id'=>$p['id'],
                                      'batch_no'=>$p['batch_no'],
                                      'item_name'=>$p['item_name'],
                                      'remaining_qty'=>$p['remaining_qty'],
                                      'remaining_weight_kg'=>$p['remaining_weight_kg'],
                                      'unit_weight_kg'=>$p['unit_weight_kg']
                                  ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'>Verify Remaining</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($productions)): ?>
                <tr><td colspan="8" class="px-6 py-6 text-center text-gray-500">No production batches found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Modal -->
<div id="createProductionModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
  <div class="relative top-20 mx-auto p-5 border w-[32rem] max-w-[95vw] shadow-lg rounded-md bg-white">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-lg font-bold text-gray-900">Create Production Batch</h3>
      <button onclick="closeModal('createProductionModal')" class="text-gray-400 hover:text-gray-600">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <form method="POST" class="space-y-4" onsubmit="return validateCreateForm()">
      <input type="hidden" name="action" value="create">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Batch Number</label>
        <input type="text" name="batch_no" id="cp_batch_no" required
               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"
               value="BATCH<?php echo str_pad(count($productions)+1,3,'0',STR_PAD_LEFT); ?>">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Item to Produce</label>
        <select name="item_id" id="cp_item_id" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"
                onchange="onItemChange()">
          <option value="">Select Item</option>
          <?php foreach ($ui_items as $it): ?>
            <option value="<?php echo (int)$it['id']; ?>"
                    data-type="<?php echo htmlspecialchars($it['type']); ?>"
                    data-unit="<?php echo htmlspecialchars($it['unit_symbol']); ?>">
              <?php echo htmlspecialchars($it['name'].' ('.$it['code'].')'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Shown only when finished selected -->
      <div id="cp_peetu_block" class="hidden space-y-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Peetu (recipe for this product)</label>
          <select name="peetu_item_id" id="cp_peetu_id"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"
                  onchange="recalcPlannedFromPeetu()">
            <option value="">Select Peetu</option>
          </select>
          <p class="text-xs text-gray-500 mt-1">1 Peetu produces a fixed number of finished units as per BOM Product.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Peetu Quantity</label>
          <input type="number" step="0.001" min="0" name="peetu_qty" id="cp_peetu_qty"
                 class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"
                 placeholder="0.000" oninput="recalcPlannedFromPeetu()">
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Production Location</label>
        <select name="location_id" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
          <option value="">Select Location</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?php echo (int)$loc['id']; ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Planned Quantity</label>
        <input type="number" step="0.001" name="planned_qty" id="cp_planned_qty"
               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"
               placeholder="0.000">
        <p id="cp_planned_hint" class="text-xs text-gray-500 mt-1"></p>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Production Date</label>
        <input type="date" name="production_date" required
               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"
               value="<?php echo date('Y-m-d'); ?>">
      </div>

      <div class="flex justify-end space-x-3 pt-2">
        <button type="button" onclick="closeModal('createProductionModal')" class="px-4 py-2 border border-gray-300 rounded-md">Cancel</button>
        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Create Batch</button>
      </div>
    </form>
  </div>
</div>

<!-- Production Details Modal -->
<div id="productionDetailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden z-50">
  <div class="relative top-10 mx-auto p-0 w-full max-w-5xl mb-10">
    <div class="bg-white rounded-lg shadow-2xl overflow-hidden">
      <!-- Header -->
      <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4 flex justify-between items-center">
        <h3 class="text-2xl font-bold text-white">Production Batch Details</h3>
        <button onclick="closeModal('productionDetailsModal')" class="text-blue-100 hover:text-white">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <div class="p-6 space-y-6">
        <!-- Production Batch Info Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
            <p class="text-xs font-medium text-blue-600 uppercase tracking-wide">Batch Number</p>
            <p class="text-2xl font-bold text-gray-900 mt-1" id="pd_batch">-</p>
          </div>
          <div class="bg-purple-50 p-4 rounded-lg border border-purple-200 md:col-span-2 lg:col-span-2">
            <p class="text-xs font-medium text-purple-600 uppercase tracking-wide">Item</p>
            <p class="text-lg font-bold text-gray-900 mt-1" id="pd_item">-</p>
          </div>
          <div class="bg-green-50 p-4 rounded-lg border border-green-200">
            <p class="text-xs font-medium text-green-600 uppercase tracking-wide">Status</p>
            <p class="text-lg font-bold text-gray-900 mt-1" id="pd_status">-</p>
          </div>
        </div>

        <!-- Quantities Section -->
        <div class="border-t pt-6">
          <h4 class="text-lg font-semibold text-gray-900 mb-4">Quantities</h4>
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 p-4 rounded-lg border border-yellow-200">
              <p class="text-xs font-medium text-yellow-700 uppercase tracking-wide">Planned</p>
              <p class="text-2xl font-bold text-yellow-900 mt-2" id="pd_planned">0</p>
            </div>
            <div class="bg-gradient-to-br from-green-50 to-green-100 p-4 rounded-lg border border-green-200">
              <p class="text-xs font-medium text-green-700 uppercase tracking-wide">Actual</p>
              <p class="text-2xl font-bold text-green-900 mt-2" id="pd_actual">0</p>
            </div>
            <div class="bg-gradient-to-br from-indigo-50 to-indigo-100 p-4 rounded-lg border border-indigo-200">
              <p class="text-xs font-medium text-indigo-700 uppercase tracking-wide">Location</p>
              <p class="text-lg font-bold text-indigo-900 mt-2" id="pd_location">-</p>
            </div>
            <div class="bg-gradient-to-br from-orange-50 to-orange-100 p-4 rounded-lg border border-orange-200">
              <p class="text-xs font-medium text-orange-700 uppercase tracking-wide">Date</p>
              <p class="text-lg font-bold text-orange-900 mt-2" id="pd_date">-</p>
            </div>
          </div>
        </div>

        <!-- Transfers to Store Section -->
        <div class="border-t pt-6">
          <h4 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Transfers to Store
          </h4>
          <div class="overflow-x-auto bg-gray-50 rounded-lg border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-100">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Trolley Batch</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Expected Weight</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Actual Weight</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Status</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Date</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200" id="pd_transfers">
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500 text-sm">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Remaining Verification Section -->
        <div class="border-t pt-6">
          <h4 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Remaining Verification
          </h4>
          <div id="pd_remaining" class="bg-gradient-to-r from-purple-50 to-pink-50 p-4 rounded-lg border border-purple-200">
            <p class="text-gray-600 text-center">No remaining verification completed</p>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex justify-end pt-4 border-t">
          <button onclick="closeModal('productionDetailsModal')" class="px-6 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors font-medium">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Complete Modal -->
<div id="completeProductionModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
  <div class="relative top-20 mx-auto p-5 border w-[32rem] max-w-[95vw] shadow-lg rounded-md bg-white">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-lg font-bold text-gray-900">Complete Production</h3>
      <button onclick="closeModal('completeProductionModal')" class="text-gray-400 hover:text-gray-600">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="complete">
      <input type="hidden" name="id" id="cm_id">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Batch</label>
        <input type="text" id="cm_batch" class="w-full px-3 py-2 border rounded-md bg-gray-100" readonly>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Item</label>
        <input type="text" id="cm_item" class="w-full px-3 py-2 border rounded-md bg-gray-100" readonly>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Planned Quantity</label>
        <input type="text" id="cm_planned" class="w-full px-3 py-2 border rounded-md bg-gray-100" readonly>
      </div>
      
      <div class="bg-blue-50 border border-blue-200 rounded-md p-3">
        <p class="text-sm text-blue-800">
          <svg class="w-4 h-4 inline mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
          This will consume raw materials and create finished stock for the planned quantity. Actual weight verification happens in the Trolley screen.
        </p>
      </div>
      
      <div class="flex justify-end space-x-3 pt-2">
        <button type="button" onclick="closeModal('completeProductionModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Complete</button>
      </div>
    </form>
  </div>
</div>

<script>
/* --- Data for client logic --- */
const UI_ITEMS = <?php echo json_encode($ui_items, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
// map finished_id -> [{peetu_id, peetu_name, units_per_peetu}]
const BOM_YIELD = <?php echo json_encode($bom_yield, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
// array of finished item IDs that have Direct BOM (can be made without Peetu)
const DIRECT_BOM_ITEMS = <?php echo json_encode($direct_bom_items, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

function onItemChange(){
  const sel = document.getElementById('cp_item_id');
  const type = sel.options[sel.selectedIndex]?.getAttribute('data-type') || '';
  const unit = sel.options[sel.selectedIndex]?.getAttribute('data-unit') || '';
  const planned = document.getElementById('cp_planned_qty');
  const hint = document.getElementById('cp_planned_hint');
  const fId = parseInt(sel.value,10);

  if (type === 'finished') {
    // Check if this is a Direct BOM item
    const isDirectBom = DIRECT_BOM_ITEMS.includes(fId);
    
    if (isDirectBom) {
      // Direct BOM: hide Peetu block, allow manual planned qty
      document.getElementById('cp_peetu_block').classList.add('hidden');
      planned.readOnly = false;
      planned.value = '';
      hint.textContent = 'This item can be produced directly from raw materials';
    } else {
      // Traditional Peetu-based production
      document.getElementById('cp_peetu_block').classList.remove('hidden');
      planned.readOnly = true;
      planned.value = '';
      hint.textContent = 'Planned qty = Peetu qty × (finished units per Peetu)';
      
      // populate peetu list
      const list = BOM_YIELD[fId] || [];
      const pSel = document.getElementById('cp_peetu_id');
      pSel.innerHTML = '<option value="">Select Peetu</option>';
      list.forEach(x=>{
        const opt = document.createElement('option');
        opt.value = x.peetu_id;
        opt.textContent = `${x.peetu_name} (${x.peetu_code||''}) - ${x.units_per_peetu} ${unit}/Peetu`;
        opt.setAttribute('data-units', x.units_per_peetu);
        pSel.appendChild(opt);
      });
    }
  } else {
    // semi-finished: hide peetu, allow manual planned qty
    document.getElementById('cp_peetu_block').classList.add('hidden');
    planned.readOnly = false;
    hint.textContent = '';
  }
}

function recalcPlannedFromPeetu(){
  const pSel = document.getElementById('cp_peetu_id');
  const up = parseFloat(pSel.options[pSel.selectedIndex]?.getAttribute('data-units') || '0');
  const qty = parseFloat(document.getElementById('cp_peetu_qty').value || '0');
  const planned = document.getElementById('cp_planned_qty');
  planned.value = (up>0 && qty>0) ? (up*qty).toFixed(3) : '';
}

function validateCreateForm(){
  const itemSel = document.getElementById('cp_item_id');
  const type = itemSel.options[itemSel.selectedIndex]?.getAttribute('data-type') || '';
  const fId = parseInt(itemSel.value,10);
  
  if (type === 'finished') {
    const isDirectBom = DIRECT_BOM_ITEMS.includes(fId);
    
    if (isDirectBom) {
      // Direct BOM validation: only need planned quantity
      if (!document.getElementById('cp_planned_qty').value || parseFloat(document.getElementById('cp_planned_qty').value)<=0) {
        alert('Enter a planned quantity > 0'); return false;
      }
    } else {
      // Traditional Peetu-based validation
      if (!document.getElementById('cp_peetu_id').value) { alert('Select a Peetu.'); return false; }
      if (!document.getElementById('cp_peetu_qty').value || parseFloat(document.getElementById('cp_peetu_qty').value)<=0) {
        alert('Enter a Peetu quantity > 0'); return false;
      }
      if (!document.getElementById('cp_planned_qty').value) {
        alert('Planned qty is not calculated.'); return false;
      }
    }
  } else {
    if (!document.getElementById('cp_planned_qty').value || parseFloat(document.getElementById('cp_planned_qty').value)<=0) {
      alert('Enter a planned quantity > 0'); return false;
    }
  }
  return true;
}

function completeProduction(p){
  document.getElementById('cm_id').value = p.id;
  document.getElementById('cm_batch').value = p.batch_no;
  document.getElementById('cm_item').value = `${p.item_name} (${p.item_code})`;
  document.getElementById('cm_planned').value = `${Number(p.planned_qty).toFixed(3)} ${p.unit_symbol}`;
  openModal('completeProductionModal');
}

function viewProductionDetails(productionId) {
  openModal('productionDetailsModal');
  
  // Fetch production details
  fetch(`/Jann%20Network/lakgovi-erp/api/production_details.php?id=${productionId}`)
    .then(response => response.json())
    .then(data => {
      if (data.error) {
        alert('Error: ' + data.error);
        return;
      }
      // Populate production info
      document.getElementById('pd_batch').textContent = data.production.batch_no;
      document.getElementById('pd_item').textContent = `${data.production.item_name} (${data.production.item_code})`;
      document.getElementById('pd_planned').innerHTML = `<span class="text-sm text-yellow-700">${Number(data.production.planned_qty).toFixed(3)}</span> <span class="text-sm text-yellow-600">${data.production.unit_symbol}</span>`;
      document.getElementById('pd_actual').innerHTML = `<span class="text-sm text-green-700">${Number(data.production.actual_qty).toFixed(3)}</span> <span class="text-sm text-green-600">${data.production.unit_symbol}</span>`;
      
      const statusEl = document.getElementById('pd_status');
      const statusClass = {
        'pending': 'bg-yellow-100 text-yellow-800',
        'started': 'bg-blue-100 text-blue-800',
        'completed': 'bg-green-100 text-green-800',
        'on_hold': 'bg-red-100 text-red-800'
      };
      statusEl.innerHTML = `<span class="px-3 py-1 rounded-full text-sm font-medium ${statusClass[data.production.status] || 'bg-gray-100 text-gray-800'}">${data.production.status.replace('_', ' ').toUpperCase()}</span>`;
      
      document.getElementById('pd_location').textContent = data.production.location_name || 'N/A';
      document.getElementById('pd_date').textContent = new Date(data.production.production_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
      
      // Populate transfers
      const transfersContainer = document.getElementById('pd_transfers');
      if (data.transfers && data.transfers.length > 0) {
        transfersContainer.innerHTML = data.transfers.map(transfer => {
          const statusColorMap = {
            'pending': 'bg-yellow-50 text-yellow-700',
            'completed': 'bg-green-50 text-green-700',
            'on_hold': 'bg-red-50 text-red-700'
          };
          return `
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3 text-sm font-medium text-gray-900">${transfer.trolley_batch}</td>
              <td class="px-4 py-3 text-sm text-gray-600">
                <span class="font-medium text-blue-600">${Number(transfer.expected_weight_kg).toFixed(3)}</span> kg
              </td>
              <td class="px-4 py-3 text-sm text-gray-600">
                <span class="font-medium text-green-600">${Number(transfer.actual_weight_kg).toFixed(3)}</span> kg
              </td>
              <td class="px-4 py-3 text-sm">
                <span class="px-2 py-1 rounded-full text-xs font-medium ${statusColorMap[transfer.status_text] || 'bg-gray-100 text-gray-700'}">
                  ${transfer.status_text.replace('_', ' ').toUpperCase()}
                </span>
              </td>
              <td class="px-4 py-3 text-sm text-gray-600">
                ${new Date(transfer.movement_date).toLocaleDateString()} ${new Date(transfer.movement_date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
              </td>
            </tr>
          `;
        }).join('');
      } else {
        transfersContainer.innerHTML = '<tr><td colspan="5" class="px-4 py-6 text-center text-gray-500 text-sm">No transfers found</td></tr>';
      }
      
      // Populate remaining completion
      const remainingContainer = document.getElementById('pd_remaining');
      if (data.remaining_completion) {
        remainingContainer.innerHTML = `
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded-lg border border-purple-200">
              <p class="text-xs font-medium text-purple-600 uppercase tracking-wide">Remaining Before</p>
              <p class="text-xl font-bold text-gray-900 mt-2">${Number(data.remaining_completion.remaining_qty_before).toFixed(3)} pcs</p>
              <p class="text-xs text-gray-600 mt-1">${Number(data.remaining_completion.remaining_weight_before).toFixed(3)} kg</p>
            </div>
            <div class="bg-white p-4 rounded-lg border border-blue-200">
              <p class="text-xs font-medium text-blue-600 uppercase tracking-wide">Measured</p>
              <p class="text-xl font-bold text-gray-900 mt-2">${Number(data.remaining_completion.actual_weight_measured).toFixed(3)} kg</p>
            </div>
            <div class="bg-white p-4 rounded-lg border border-green-200">
              <p class="text-xs font-medium text-green-600 uppercase tracking-wide">New Remaining</p>
              <p class="text-xl font-bold text-gray-900 mt-2">${Number(data.remaining_completion.actual_units_rounded).toFixed(3)} pcs</p>
            </div>
            <div class="bg-white p-4 rounded-lg border border-indigo-200">
              <p class="text-xs font-medium text-indigo-600 uppercase tracking-wide">Completed</p>
              <p class="text-lg font-bold text-gray-900 mt-2">${new Date(data.remaining_completion.completed_at).toLocaleDateString()}</p>
              <p class="text-xs text-gray-600 mt-1">${new Date(data.remaining_completion.completed_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</p>
            </div>
          </div>
        `;
      } else {
        remainingContainer.innerHTML = '<div class="bg-white p-4 rounded-lg border border-dashed border-gray-300 text-center text-gray-600"><p class="text-sm">No remaining verification completed</p></div>';
      }
    })
    .catch(error => {
      console.error('Error loading details:', error);
      alert('Error loading production details');
    });
}

function completeRemaining(p) {
  document.getElementById('cr_id').value = p.id;
  document.getElementById('cr_batch').textContent = p.batch_no;
  document.getElementById('cr_item').textContent = p.item_name;
  document.getElementById('cr_remaining_qty').textContent = Number(p.remaining_qty).toFixed(3);
  document.getElementById('cr_theoretical_weight').textContent = Number(p.remaining_weight_kg).toFixed(3);
  document.getElementById('cr_unit_weight').value = p.unit_weight_kg;
  document.getElementById('cr_actual_weight').value = Number(p.remaining_weight_kg).toFixed(3);
  updateRemainingCalculation();
  openModal('completeRemainingModal');
}

function updateRemainingCalculation() {
  const actualWeight = parseFloat(document.getElementById('cr_actual_weight').value) || 0;
  const unitWeight = parseFloat(document.getElementById('cr_unit_weight').value) || 1;
  
  if (unitWeight > 0) {
    const rawUnits = actualWeight / unitWeight;
    const roundedUnits = Math.floor(rawUnits);
    const wastage = rawUnits - roundedUnits;
    
    document.getElementById('cr_calculated_units').textContent = roundedUnits.toFixed(0);
    document.getElementById('cr_wastage').textContent = wastage.toFixed(3);
  }
}
</script>

<!-- Complete Remaining Batch Modal -->
<div id="completeRemainingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden z-50">
  <div class="relative top-20 mx-auto p-5 border w-[32rem] max-w-[95vw] shadow-lg rounded-md bg-white">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-lg font-bold text-gray-900">📏 Verify Remaining Weight</h3>
      <button onclick="closeModal('completeRemainingModal')" class="text-gray-400 hover:text-gray-600">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <form method="POST" class="space-y-4" onsubmit="return confirm('Update remaining quantity based on actual measured weight?')">
      <input type="hidden" name="action" value="complete_remaining">
      <input type="hidden" name="id" id="cr_id">
      <input type="hidden" id="cr_unit_weight">

      <div class="bg-blue-50 border border-blue-200 rounded-md p-4 space-y-2">
        <div class="flex justify-between">
          <span class="text-sm font-medium text-gray-700">Batch:</span>
          <span class="text-sm font-bold text-gray-900" id="cr_batch"></span>
        </div>
        <div class="flex justify-between">
          <span class="text-sm font-medium text-gray-700">Item:</span>
          <span class="text-sm font-bold text-gray-900" id="cr_item"></span>
        </div>
        <div class="flex justify-between">
          <span class="text-sm font-medium text-gray-700">Current Remaining:</span>
          <span class="text-sm font-bold text-purple-600" id="cr_remaining_qty"></span>
        </div>
        <div class="flex justify-between">
          <span class="text-sm font-medium text-gray-700">Theoretical Weight:</span>
          <span class="text-sm text-gray-600"><span id="cr_theoretical_weight"></span> kg</span>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Actual Measured Weight (kg) <span class="text-red-500">*</span></label>
        <input type="number" step="0.001" name="actual_remaining_weight" id="cr_actual_weight" required
               oninput="updateRemainingCalculation()"
               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500">
        <p class="text-xs text-gray-500 mt-1">Weigh the actual remaining batch and enter the measured weight</p>
      </div>

      <div class="bg-gray-50 border border-gray-200 rounded-md p-4 space-y-2">
        <div class="flex justify-between">
          <span class="text-sm font-medium text-gray-700">New Remaining Units (floor):</span>
          <span class="text-sm font-bold text-green-600" id="cr_calculated_units">0</span>
        </div>
        <div class="flex justify-between">
          <span class="text-sm font-medium text-gray-700">Rounding Difference:</span>
          <span class="text-sm font-bold text-orange-600" id="cr_wastage">0.000</span>
        </div>
      </div>

      <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3">
        <p class="text-sm text-yellow-800">
          <strong>Note:</strong> This updates the remaining quantity based on actual weight. 
          The remaining can still be assigned to trolleys and moved to store.
        </p>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Verification Reason (Optional)</label>
        <input type="text" name="completion_reason" 
               placeholder="e.g., Weight verification, Inventory audit, etc."
               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500">
      </div>

      <div class="flex justify-end space-x-3 pt-4">
        <button type="button" onclick="closeModal('completeRemainingModal')" 
                class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
        <button type="submit" 
                class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700">Update Remaining Quantity</button>
      </div>
    </form>
  </div>
</div>

</script>

<?php include 'footer.php'; ?>

