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
                $stmt = $db->prepare("SELECT id, type FROM items WHERE id=?");
                $stmt->execute([$item_id]);
                $itm = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$itm) throw new Exception("Selected item not found.");
                $item_type = $itm['type'];

                $planned_qty = 0.0;
                $peetu_item_id = null;
                $peetu_qty = null;

                if ($item_type === 'finished') {
                    // Check if this finished item has direct BOM first
                    $stmt = $db->prepare("SELECT COUNT(*) FROM bom_direct WHERE finished_item_id = ?");
                    $stmt->execute([$item_id]);
                    $has_direct_bom = (int)$stmt->fetchColumn() > 0;
                    
                    if ($has_direct_bom) {
                        // Direct BOM: finished product made directly from raw materials
                        // BOM LOGIC:
                        // - finished_unit_qty = weight per piece (e.g., 20 kg for 1 pc Papadam)
                        // - quantity = raw material needed for 1 piece
                        // - planned_qty entered by user = NUMBER OF PIECES (not weight)
                        // Example: User enters 1 pc → needs 2 kg Salt (as per BOM)
                        $planned_qty = as_float($_POST['planned_qty'] ?? 0);
                        if ($planned_qty <= 0) throw new Exception("Planned quantity must be greater than 0");
                        
                        // Validate RAW availability for direct production
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
                        if (empty($raws)) throw new Exception("No direct BOM recipe found for this finished item.");

                        foreach ($raws as $rw) {
                            // Calculate required raw material
                            // Formula: req = planned_qty (pieces) × per_unit_qty (raw per piece)
                            // Example: 1 pc × 2 kg Salt/pc = 2 kg Salt needed
                            $per_unit_qty = (float)$rw['per_unit_qty'];
                            $req = $planned_qty * $per_unit_qty;
                            
                            if ($rw['category_id']) {
                                // Category-based: sum stock across all items in category
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
                                $s->execute([(int)$rw['category_id'], (int)$rw['category_id'], $location_id]);
                            } else {
                                // Regular raw material
                                $s = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out),0)
                                    FROM stock_ledger
                                    WHERE item_id=? AND location_id=?
                                ");
                                $s->execute([(int)$rw['raw_material_id'], $location_id]);
                            }
                            $have = (float)$s->fetchColumn();
                            if ($have + 1e-9 < $req) {
                                $need = number_format($req, 3);
                                $haveStr = number_format($have, 3);
                                throw new Exception("Insufficient RAW: {$rw['raw_name']}. Need {$need}, have {$haveStr}");
                            }
                        }
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

                        // Validate RAW availability to make that many Peetu
                        //   Need: sum(raw per 1 peetu) * peetu_qty  (from bom_peetu)
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
                        if (empty($raws)) {
                            throw new Exception("No Peetu recipe found (bom_peetu) for the selected Peetu.");
                        }

                        foreach ($raws as $rw) {
                            $req = (float)$rw['per_peetu_qty'] * $peetu_qty;

                            if ($rw['category_id']) {
                                // Category-based: sum stock across all items in category
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
                                $s->execute([(int)$rw['category_id'], (int)$rw['category_id'], $location_id]);
                            } else {
                                // Regular raw material
                                $s = $db->prepare("
                                    SELECT COALESCE(SUM(quantity_in - quantity_out),0)
                                    FROM stock_ledger
                                    WHERE item_id=? AND location_id=?
                                ");
                                $s->execute([(int)$rw['raw_material_id'], $location_id]);
                            }
                            $have = (float)$s->fetchColumn();

                            if ($have + 1e-9 < $req) {
                                $need = number_format($req, 3);
                                $haveStr = number_format($have, 3);
                                throw new Exception("Insufficient RAW: {$rw['raw_name']}. Need {$need}, have {$haveStr}");
                            }
                        }
                    }
                } else {
                    // Semi-finished batch (straight from Peetu recipe)
                    $planned_qty = as_float($_POST['planned_qty'] ?? 0);
                    if ($planned_qty<=0) throw new Exception("Planned quantity must be greater than 0");
                    // Validate RAW availability for that many Peetu
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
                    if (empty($raws)) throw new Exception("No Peetu recipe found (bom_peetu) for this semi-finished item.");

                    foreach ($raws as $rw) {
                        $req = (float)$rw['per_peetu_qty'] * $planned_qty;
                        
                        if ($rw['category_id']) {
                            // Category-based: sum stock across all items in category
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
                            $s->execute([(int)$rw['category_id'], (int)$rw['category_id'], $location_id]);
                        } else {
                            // Regular raw material
                            $s = $db->prepare("
                                SELECT COALESCE(SUM(quantity_in - quantity_out),0)
                                FROM stock_ledger
                                WHERE item_id=? AND location_id=?
                            ");
                            $s->execute([(int)$rw['raw_material_id'], $location_id]);
                        }
                        $have = (float)$s->fetchColumn();
                        if ($have + 1e-9 < $req) {
                            $need = number_format($req, 3);
                            $haveStr = number_format($have, 3);
                            throw new Exception("Insufficient RAW: {$rw['raw_name']}. Need {$need}, have {$haveStr}");
                        }
                    }
                }

                $db->beginTransaction();
                $transaction_started = true;

                $stmt = $db->prepare("
                    INSERT INTO production (batch_no, item_id, location_id, planned_qty, production_date, status, peetu_item_id, peetu_qty)
                    VALUES (?, ?, ?, ?, ?, 'planned', ?, ?)
                ");
                $stmt->execute([$batch_no, $item_id, $location_id, $planned_qty, $production_date, $peetu_item_id, $peetu_qty]);

                $db->commit();
                $transaction_started = false;
                $success = "Production order created successfully!";
                break;
            }

            case 'start': {
                $pid = as_int($_POST['id'] ?? 0);
                if ($pid<=0) throw new Exception("Production ID is required to start production");

                $db->beginTransaction();
                $transaction_started = true;

                $stmt = $db->prepare("SELECT id, batch_no, status FROM production WHERE id=?");
                $stmt->execute([$pid]);
                $p = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$p) throw new Exception("Production record not found");
                if ($p['status']!=='planned') throw new Exception("Only planned batches can be started.");

                $stmt = $db->prepare("UPDATE production SET status='in_progress' WHERE id=?");
                $stmt->execute([$pid]);

                $db->commit();
                $transaction_started = false;
                $success = "Production batch {$p['batch_no']} started successfully!";
                break;
            }

            /* Complete
               - If finished: compute actual_peetu_qty = actual_qty / units_per_peetu (from bom_product with the chosen peetu)
                 then consume RAW = per_peetu_raw * actual_peetu_qty.
               - If semi_finished: consume RAW = per_peetu_raw * actual_qty.
               - Receive produced qty for item.
            */
            case 'complete': {
                $pid = as_int($_POST['id'] ?? 0);
                if ($pid<=0) throw new Exception("Production ID is required");

                $db->beginTransaction();
                $transaction_started = true;

                $stmt = $db->prepare("
                    SELECT p.*, i.name AS item_name, i.code AS item_code, i.type AS item_type,
                           l.name AS location_name
                    FROM production p
                    JOIN items i ON i.id = p.item_id
                    JOIN locations l ON l.id = p.location_id
                    WHERE p.id=? AND p.status='in_progress'
                ");
                $stmt->execute([$pid]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$prod) throw new Exception("Production not found or not in progress.");

                // Use planned_qty from the production record
                // Actual weight verification happens in trolley screen
                $actual_qty = (float)$prod['planned_qty'];
                if ($actual_qty <= 0) throw new Exception("Invalid planned quantity in production record.");

                // For Direct BOM items, check if we need to convert pieces to weight
                $finished_weight_kg = $actual_qty; // Default: assume planned_qty is already in correct unit
                $stmt = $db->prepare("SELECT finished_unit_qty FROM bom_direct WHERE finished_item_id = ? LIMIT 1");
                $stmt->execute([(int)$prod['item_id']]);
                $finished_unit_qty = $stmt->fetchColumn();
                if ($finished_unit_qty && (float)$finished_unit_qty > 0) {
                    // BOM exists with finished_unit_qty (weight per piece)
                    // actual_qty is number of pieces, convert to total weight
                    // Example: 1 pc × 20 kg/pc = 20 kg total weight
                    $finished_weight_kg = $actual_qty * (float)$finished_unit_qty;
                }

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
                        // - actual_qty = NUMBER OF PIECES from planned_qty
                        // Example: 1 pc × 2 kg Salt/pc = 2 kg Salt to deduct
                        foreach ($direct_raws as $rw) {
                            $per_unit_qty = (float)$rw['per_unit_qty'];
                            
                            // Calculate raw consumption based on number of pieces
                            // actual_qty is number of pieces from planned_qty
                            $raw_consumed = $actual_qty * $per_unit_qty;
                            
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

                        $actual_peetu_qty = $actual_qty / $units_per_peetu;

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
                    // semi_finished: RAW = per_peetu_raw * actual_qty
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
                            'qty'      => (float)$rw['per_peetu_qty'] * $actual_qty
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

                // Receive finished / semi-finished
                // Use finished_weight_kg for stock_ledger (total weight in kg)
                $s = $db->prepare("
                    SELECT COALESCE(SUM(quantity_in - quantity_out),0)
                    FROM stock_ledger
                    WHERE item_id=? AND location_id=?
                ");
                $s->execute([(int)$prod['item_id'], (int)$prod['location_id']]);
                $bal = (float)$s->fetchColumn();
                $new_bal = $bal + $finished_weight_kg;

                $ins = $db->prepare("
                    INSERT INTO stock_ledger
                      (item_id, location_id, transaction_type, reference_id, reference_no, transaction_date, quantity_in, balance, created_at)
                    VALUES (?, ?, 'production_in', ?, ?, ?, ?, ?, NOW())
                ");
                $ins->execute([(int)$prod['item_id'], (int)$prod['location_id'], (int)$prod['id'], $prod['batch_no'], $prod['production_date'], $finished_weight_kg, $new_bal]);

                // Update item current_stock (GLOBAL - all locations)
                updateItemCurrentStock($db, (int)$prod['item_id']);

                $u = $db->prepare("UPDATE production SET actual_qty=?, status='completed' WHERE id=?");
                $u->execute([$actual_qty, $pid]);

                $db->commit();
                $transaction_started = false;
                $success = "Production completed successfully!";
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
        SELECT p.*, i.name AS item_name, i.code AS item_code, u.symbol AS unit_symbol,
               COALESCE(bp.product_unit_qty, bd.finished_unit_qty, i.unit_weight_kg) as unit_weight_kg,
               l.name AS location_name,
               pi.name AS peetu_name
        FROM production p
        JOIN items i  ON i.id = p.item_id
        JOIN units u  ON u.id = i.unit_id
        JOIN locations l ON l.id = p.location_id
        LEFT JOIN items pi ON pi.id = p.peetu_item_id
        LEFT JOIN bom_product bp ON bp.finished_item_id = i.id
        LEFT JOIN bom_direct bd ON bd.finished_item_id = i.id
        GROUP BY p.id
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
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Planned</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p)=>$p['status']==='planned')); ?></p>
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
                <div class="p-3 rounded-full bg-green-100">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Completed</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($productions, fn($p)=>$p['status']==='completed')); ?></p>
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
                        
                        if ($unit_weight > 0) {
                            // Show pieces and total weight
                            $total_weight = $planned_qty * $unit_weight;
                            echo number_format($planned_qty, 0) . ' ' . htmlspecialchars($p['unit_symbol']) . '<br>';
                            echo '<span class="text-xs text-gray-600">(' . number_format($total_weight, 3) . ' kg total)</span>';
                        } else {
                            // No weight info, show quantity only
                            echo number_format($planned_qty, 3) . ' ' . htmlspecialchars($p['unit_symbol']);
                        }
                        ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900 font-medium">
                        <?php 
                        if ($p['actual_qty']) {
                            $actual_qty = (float)$p['actual_qty'];
                            $unit_weight = (float)$p['unit_weight_kg'];
                            
                            if ($unit_weight > 0) {
                                // Show pieces and total weight
                                $total_weight = $actual_qty * $unit_weight;
                                echo number_format($actual_qty, 0) . ' ' . htmlspecialchars($p['unit_symbol']) . '<br>';
                                echo '<span class="text-xs text-gray-600">(' . number_format($total_weight, 3) . ' kg total)</span>';
                            } else {
                                // No weight info, show quantity only
                                echo number_format($actual_qty, 3) . ' ' . htmlspecialchars($p['unit_symbol']);
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <span class="<?php
                            echo $p['status']==='completed' ? 'bg-green-100 text-green-800' :
                                 ($p['status']==='in_progress' ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800');
                        ?> text-xs font-medium px-2.5 py-0.5 rounded capitalize"><?php echo str_replace('_',' ',$p['status']); ?></span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <?php if ($p['status']==='planned'): ?>
                          <form method="POST" class="inline">
                            <input type="hidden" name="action" value="start">
                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                            <button type="submit" class="text-blue-600 hover:text-blue-900">Start</button>
                          </form>
                        <?php endif; ?>
                        <?php if ($p['status']==='completed'): ?>
                          <button class="text-blue-600 hover:text-blue-900"
                                  onclick='viewProductionDetails(<?php echo (int)$p['id']; ?>)'>View Details</button>
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
                        <?php if ($p['status']==='planned'): ?>
                          <form method="POST" class="inline" onsubmit="return confirm('Delete this batch?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                          </form>
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
  <div class="relative top-10 mx-auto p-6 border w-full max-w-4xl shadow-lg rounded-md bg-white mb-10">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-2xl font-bold text-gray-900">Production Details</h3>
      <button onclick="closeModal('productionDetailsModal')" class="text-gray-400 hover:text-gray-600">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    
    <div class="space-y-6">
      <!-- Production Batch Info -->
      <div class="grid grid-cols-2 gap-4 pb-4 border-b">
        <div>
          <p class="text-sm text-gray-600">Batch Number</p>
          <p class="text-lg font-semibold" id="pd_batch"></p>
        </div>
        <div>
          <p class="text-sm text-gray-600">Item</p>
          <p class="text-lg font-semibold" id="pd_item"></p>
        </div>
        <div>
          <p class="text-sm text-gray-600">Planned Quantity</p>
          <p class="text-lg font-semibold" id="pd_planned"></p>
        </div>
        <div>
          <p class="text-sm text-gray-600">Actual Quantity</p>
          <p class="text-lg font-semibold" id="pd_actual"></p>
        </div>
      </div>

      <!-- Repacking Tab -->
      <div class="border-t pt-4">
        <h4 class="text-lg font-bold text-gray-900 mb-3">Repacking</h4>
        <div id="pd_repacking" class="bg-gray-50 p-4 rounded-lg">
          <p class="text-sm text-gray-500">Loading repacking data...</p>
        </div>
      </div>

      <!-- Rolls Tab -->
      <div class="border-t pt-4">
        <h4 class="text-lg font-bold text-gray-900 mb-3">Rolls Production</h4>
        <div id="pd_rolls" class="bg-gray-50 p-4 rounded-lg">
          <p class="text-sm text-gray-500">Loading rolls data...</p>
        </div>
      </div>

      <!-- Close Button -->
      <div class="flex justify-end pt-4 border-t">
        <button onclick="closeModal('productionDetailsModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Close</button>
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
  
  // Fetch production details and related repacking/rolls data
  fetch(`/Jann%20Network/lakgovi-erp/api/production_details.php?id=${productionId}`)
    .then(response => response.json())
    .then(data => {
      // Populate production info
      document.getElementById('pd_batch').textContent = data.production.batch_no;
      document.getElementById('pd_item').textContent = `${data.production.item_name} (${data.production.item_code})`;
      document.getElementById('pd_planned').textContent = `${Number(data.production.planned_qty).toFixed(3)} ${data.production.unit_symbol}`;
      document.getElementById('pd_actual').textContent = `${Number(data.production.actual_qty).toFixed(3)} ${data.production.unit_symbol}`;
      
      // Populate repacking data
      if (data.repacking && data.repacking.length > 0) {
        let repackHTML = '<div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-200"><tr><th class="px-3 py-2 text-left">Code</th><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-right">Source Qty</th><th class="px-3 py-2 text-right">Packs Created</th></tr></thead><tbody>';
        data.repacking.forEach(r => {
          repackHTML += `<tr class="border-b"><td class="px-3 py-2">${r.batch_code}</td><td class="px-3 py-2">${new Date(r.batch_date).toLocaleDateString()}</td><td class="px-3 py-2"><span class="px-2 py-1 rounded text-xs font-semibold ${r.status === 'completed' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'}">${r.status}</span></td><td class="px-3 py-2 text-right">${Number(r.source_quantity).toFixed(3)}</td><td class="px-3 py-2 text-right font-bold">${r.bundle_quantity}</td></tr>`;
        });
        repackHTML += '</tbody></table></div>';
        document.getElementById('pd_repacking').innerHTML = repackHTML;
      } else {
        document.getElementById('pd_repacking').innerHTML = '<p class="text-sm text-gray-500">No repacking records found</p>';
      }
      
      // Populate rolls data
      if (data.rolls && data.rolls.length > 0) {
        let rollsHTML = '<div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-200"><tr><th class="px-3 py-2 text-left">Code</th><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Materials Used</th><th class="px-3 py-2 text-right">Rolls Created</th></tr></thead><tbody>';
        data.rolls.forEach(r => {
          rollsHTML += `<tr class="border-b"><td class="px-3 py-2">${r.batch_code}</td><td class="px-3 py-2">${new Date(r.batch_date).toLocaleDateString()}</td><td class="px-3 py-2"><span class="px-2 py-1 rounded text-xs font-semibold ${r.status === 'completed' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'}">${r.status}</span></td><td class="px-3 py-2">${r.total_materials_used} items</td><td class="px-3 py-2 text-right font-bold">${r.rolls_quantity}</td></tr>`;
        });
        rollsHTML += '</tbody></table></div>';
        document.getElementById('pd_rolls').innerHTML = rollsHTML;
      } else {
        document.getElementById('pd_rolls').innerHTML = '<p class="text-sm text-gray-500">No rolls records found</p>';
      }
    })
    .catch(error => {
      console.error('Error loading details:', error);
      document.getElementById('pd_repacking').innerHTML = '<p class="text-red-500">Error loading repacking data</p>';
      document.getElementById('pd_rolls').innerHTML = '<p class="text-red-500">Error loading rolls data</p>';
    });
}
</script>

<?php include 'footer.php'; ?>
