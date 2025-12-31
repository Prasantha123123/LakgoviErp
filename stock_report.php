<?php
// stock_report.php - Comprehensive Stock Reports with Total Value Display

// Handle PDF export FIRST, before any output
if ((isset($_GET['download']) && $_GET['download'] === 'pdf') || (isset($_GET['print']) && $_GET['print'] === 'pdf')) {
    // Include database connection only
    require_once 'database.php';
    
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();
    
    // Get filters
    $location_filter = $_GET['location'] ?? '';
    $item_type_filter = $_GET['item_type'] ?? '';
    $low_stock_only = isset($_GET['low_stock']);
    $view_mode = $_GET['view'] ?? 'summary';
    
    // Production Report date filters
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $date_range = $_GET['range'] ?? '';
    
    // Set default dates if not provided
    if (empty($start_date) && empty($end_date)) {
        if ($date_range === 'month') {
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
        } else {
            // Default to today
            $start_date = $end_date = date('Y-m-d');
        }
    }
    
    // Production Report Configuration
    $PRODUCTION_TYPES = ['production_in', 'repack_in'];
    $TRANSFER_TYPES = ['transfer', 'production_out'];
    $RAW_CONSUMPTION_TYPES = ['production_out', 'mrn'];
    
    // Determine if this is for print or download
    $is_print_mode = isset($_GET['print']) && $_GET['print'] === 'pdf';
    
    // Constants
    $STORE_ID = 1;
    $PRODUCTION_ID = 2;
    
    try {
        // Database connection and data fetching for PDF
        $stmt = $db->query("SELECT id, name FROM locations ORDER BY name");
        $locations = $stmt->fetchAll();
        
        $location_map = [];
        foreach ($locations as $loc) { $location_map[(int)$loc['id']] = $loc['name']; }
        
        // Get summary statistics
        $stmt = $db->query("
            SELECT 
                COUNT(DISTINCT CASE WHEN stock_qty > 0 THEN i.id END) as total_items,
                COUNT(DISTINCT l.id) as total_locations,
                SUM(CASE WHEN stock_qty <= 10 AND stock_qty > 0 THEN 1 ELSE 0 END) as low_stock_items,
                SUM(CASE WHEN stock_qty > 0 THEN stock_qty * COALESCE(i.cost_price, 0) ELSE 0 END) as total_stock_value,
                SUM(CASE WHEN stock_qty > 0 THEN stock_qty ELSE 0 END) as total_quantity
            FROM items i
            CROSS JOIN locations l
            LEFT JOIN (
                SELECT 
                    item_id, 
                    location_id, 
                    COALESCE(SUM(quantity_in - quantity_out), 0) as stock_qty
                FROM stock_ledger 
                GROUP BY item_id, location_id
            ) sl ON i.id = sl.item_id AND l.id = sl.location_id
        ");
        $summary_stats = $stmt->fetch() ?: [
            'total_items' => 0, 'total_locations' => 0, 'low_stock_items' => 0,
            'total_stock_value' => 0, 'total_quantity' => 0
        ];
        
        // Get data based on view mode
        $location_summary = [];
        $type_value_analysis = [];
        $detailed_data_by_loc = [];
        $detailed_location_names = [];
        $stock_data = [];
        $recent_movements = [];
        $production_summary = ['total_production' => 0, 'total_trolley_movements' => 0, 'total_transfers' => 0, 'total_raw_consumed' => 0];
        $production_by_item = [];
        $trolley_movements_by_item = [];
        $transfers_by_item = [];
        $raw_consumed_by_item = [];
        $daily_breakdown = [];
        
        // Get type value analysis
        $stmt = $db->query("
            SELECT 
                i.type,
                COUNT(DISTINCT CASE WHEN stock_qty > 0 THEN i.id END) as item_count,
                SUM(CASE WHEN stock_qty > 0 THEN stock_qty ELSE 0 END) as total_quantity,
                SUM(CASE WHEN stock_qty > 0 THEN stock_qty * COALESCE(i.cost_price, 0) ELSE 0 END) as total_value,
                AVG(CASE WHEN stock_qty > 0 THEN COALESCE(i.cost_price, 0) ELSE NULL END) as avg_cost_price
            FROM items i
            LEFT JOIN (
                SELECT 
                    item_id, 
                    COALESCE(SUM(quantity_in - quantity_out), 0) as stock_qty
                FROM stock_ledger 
                GROUP BY item_id
            ) sl ON i.id = sl.item_id
            GROUP BY i.type
            ORDER BY total_value DESC
        ");
        $type_value_analysis = $stmt->fetchAll();
        
        // Get location summary
        $stmt = $db->query("
            SELECT 
                l.name,
                COUNT(DISTINCT CASE WHEN stock_qty > 0 THEN i.id END) as items,
                COUNT(DISTINCT CASE WHEN stock_qty > 0 AND i.type = 'raw' THEN i.id END) as raw,
                COUNT(DISTINCT CASE WHEN stock_qty > 0 AND i.type = 'semi_finished' THEN i.id END) as semi_finished,
                COUNT(DISTINCT CASE WHEN stock_qty > 0 AND i.type = 'finished' THEN i.id END) as finished,
                SUM(CASE WHEN stock_qty > 0 THEN stock_qty * COALESCE(i.cost_price, 0) ELSE 0 END) as total_value
            FROM locations l
            CROSS JOIN items i
            LEFT JOIN (
                SELECT 
                    item_id, 
                    location_id, 
                    COALESCE(SUM(quantity_in - quantity_out), 0) as stock_qty
                FROM stock_ledger 
                GROUP BY item_id, location_id
            ) sl ON i.id = sl.item_id AND l.id = sl.location_id
            GROUP BY l.id, l.name
            ORDER BY total_value DESC
        ");
        $location_summary = $stmt->fetchAll();
        
        // Get detailed data if needed
        if ($view_mode === 'detailed') {
            $locations_to_show = [];
            if ($location_filter !== '' && is_numeric($location_filter)) {
                $locations_to_show = [(int)$location_filter];
            } else {
                $locations_to_show = [$STORE_ID, $PRODUCTION_ID];
            }
            
            $extra_where = ["sl.stock_qty > 0"];
            $extra_params = [];
            if ($item_type_filter) { $extra_where[] = "i.type = ?"; $extra_params[] = $item_type_filter; }
            if ($low_stock_only) { $extra_where[] = "sl.stock_qty <= 10"; }
            $extra_where_sql = implode(' AND ', $extra_where);
            
            $sql = "
                SELECT 
                    i.id, i.code, i.name, i.type, 
                    COALESCE(i.cost_price, 0) as cost_price,
                    sl.stock_qty as current_stock,
                    (sl.stock_qty * COALESCE(i.cost_price, 0)) as stock_value,
                    u.symbol as unit_symbol
                FROM items i
                JOIN units u ON i.unit_id = u.id
                JOIN (
                    SELECT item_id, location_id, COALESCE(SUM(quantity_in - quantity_out), 0) as stock_qty
                    FROM stock_ledger 
                    GROUP BY item_id, location_id
                ) sl ON sl.item_id = i.id
                WHERE sl.location_id = ? AND $extra_where_sql
                ORDER BY i.name
            ";
            
            // Initialize arrays for repacking and rolls data
            $repacking_data_by_loc = [];
            $rolls_data_by_loc = [];
            $bundles_data_by_loc = [];
            
            foreach ($locations_to_show as $locId) {
                $params = array_merge([$locId], $extra_params);
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $detailed_data_by_loc[$locId] = $stmt->fetchAll();
                $detailed_location_names[$locId] = $location_map[$locId] ?? ("Location #" . $locId);
                
                // Get repacking records for this location - Using remaining_qty to prevent double counting
                $repack_sql = "
                    SELECT 
                        r.id, r.repack_code, r.repack_date as created_at,
                        i1.name as source_item_name, i1.code as source_item_code,
                        i2.name as repack_item_name, i2.code as repack_item_code,
                        r.source_quantity, r.repack_quantity, r.repack_unit_size,
                        r.remaining_qty,
                        (r.repack_quantity - r.remaining_qty) as consumed_qty,
                        u1.symbol as source_unit_symbol, u2.symbol as repack_unit_symbol,
                        r.remaining_qty as balance_packs,
                        CASE 
                            WHEN r.remaining_qty = 0 THEN 'Fully Consumed'
                            WHEN r.remaining_qty = r.repack_quantity THEN 'Available'
                            WHEN r.remaining_qty > 0 THEN 'Partially Consumed'
                            ELSE 'Over-consumed'
                        END as status
                    FROM repacking r
                    JOIN items i1 ON r.source_item_id = i1.id
                    JOIN items i2 ON r.repack_item_id = i2.id
                    JOIN units u1 ON r.source_unit_id = u1.id
                    JOIN units u2 ON r.repack_unit_id = u2.id
                    WHERE r.location_id = ?
                    ORDER BY r.repack_date DESC
                ";
                $stmt = $db->prepare($repack_sql);
                $stmt->execute([$locId]);
                $repacking_data_by_loc[$locId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get rolls records for this location
                $rolls_sql = "
                    SELECT 
                        rb.id, rb.batch_code, rb.created_at,
                        i.name as item_name, i.code as item_code,
                        rb.rolls_quantity, rb.status,
                        COUNT(rm.id) as material_count
                    FROM rolls_batches rb
                    JOIN items i ON rb.rolls_item_id = i.id
                    LEFT JOIN rolls_materials rm ON rb.id = rm.batch_id
                    WHERE rb.location_id = ?
                    GROUP BY rb.id, rb.batch_code, rb.created_at, i.name, i.code, rb.rolls_quantity, rb.status
                    ORDER BY rb.created_at DESC
                ";
                $stmt = $db->prepare($rolls_sql);
                $stmt->execute([$locId]);
                $rolls_data_by_loc[$locId] = $stmt->fetchAll();
                
                // Get bundles records for this location
                $bundles_sql = "
                    SELECT 
                        bn.id, bn.bundle_code, bn.bundle_date as created_at,
                        i1.name as source_item_name, i1.code as source_item_code,
                        i2.name as bundle_item_name, i2.code as bundle_item_code,
                        bn.source_quantity, bn.bundle_quantity, bn.packs_per_bundle,
                        u1.symbol as source_unit_symbol, u2.symbol as bundle_unit_symbol,
                        COUNT(bm.id) as material_count
                    FROM bundles bn
                    JOIN items i1 ON bn.source_item_id = i1.id
                    JOIN items i2 ON bn.bundle_item_id = i2.id
                    JOIN units u1 ON bn.source_unit_id = u1.id
                    JOIN units u2 ON bn.bundle_unit_id = u2.id
                    LEFT JOIN bundle_materials bm ON bn.id = bm.bundle_id
                    WHERE bn.location_id = ?
                    GROUP BY bn.id, bn.bundle_code, bn.bundle_date, i1.name, i1.code, i2.name, i2.code, bn.source_quantity, bn.bundle_quantity, bn.packs_per_bundle, u1.symbol, u2.symbol
                    ORDER BY bn.bundle_date DESC
                ";
                $stmt = $db->prepare($bundles_sql);
                $stmt->execute([$locId]);
                $bundles_data_by_loc[$locId] = $stmt->fetchAll();
            }
        }
        
        // Get value analysis data if needed
        if ($view_mode === 'value_analysis') {
            $v_where = ["sl_total.stock_qty > 0"];
            $v_params = [];
            if ($item_type_filter) { $v_where[] = "i.type = ?"; $v_params[] = $item_type_filter; }
            if ($low_stock_only) { $v_where[] = "sl_total.stock_qty <= 10"; }
            $v_where_sql = implode(' AND ', $v_where);
            
            $query = "
                SELECT 
                    i.id, i.code, i.name, i.type, 
                    COALESCE(i.cost_price, 0) as cost_price,
                    sl_total.stock_qty as current_stock,
                    (sl_total.stock_qty * COALESCE(i.cost_price, 0)) as stock_value,
                    u.symbol as unit_symbol
                FROM items i
                JOIN units u ON i.unit_id = u.id
                LEFT JOIN (
                    SELECT item_id, COALESCE(SUM(quantity_in - quantity_out), 0) as stock_qty
                    FROM stock_ledger 
                    GROUP BY item_id
                ) sl_total ON i.id = sl_total.item_id
                WHERE $v_where_sql
                ORDER BY stock_value DESC
            ";
            $stmt = $db->prepare($query);
            $stmt->execute($v_params);
            $stock_data = $stmt->fetchAll();
        }
        
        // Get recent movements if needed
        if ($view_mode === 'movements') {
            $stmt = $db->query("
                SELECT 
                    sl.transaction_date,
                    i.code as item_code,
                    i.name as item_name,
                    l.name as location_name,
                    sl.transaction_type,
                    sl.reference_no,
                    sl.quantity_in,
                    sl.quantity_out,
                    sl.balance,
                    u.symbol as unit_symbol
                FROM stock_ledger sl
                JOIN items i ON sl.item_id = i.id
                JOIN locations l ON sl.location_id = l.id
                JOIN units u ON i.unit_id = u.id
                WHERE sl.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ORDER BY sl.transaction_date DESC, sl.id DESC
                LIMIT 100
            ");
            $recent_movements = $stmt->fetchAll();
        }
        
        // Production Report Data for PDF
        if ($view_mode === 'production_report') {
            $PRODUCTION_TYPES = ['production_in', 'repack_in'];
            $TRANSFER_TYPES = ['transfer', 'production_out'];
            $RAW_CONSUMPTION_TYPES = ['production_out', 'mrn'];
            
            // Production quantity
            $production_types = "'" . implode("','", $PRODUCTION_TYPES) . "'";
            $stmt = $db->query("
                SELECT 
                    i.code as item_code,
                    i.name as item_name,
                    i.type as item_type,
                    u.symbol as unit_symbol,
                    SUM(sl.quantity_in) as total_produced,
                    COUNT(*) as transaction_count
                FROM stock_ledger sl
                JOIN items i ON sl.item_id = i.id
                JOIN units u ON i.unit_id = u.id
                WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                    AND sl.transaction_type IN ($production_types)
                    AND i.type IN ('finished', 'semi_finished')
                    AND sl.quantity_in > 0
                GROUP BY i.id, i.code, i.name, i.type, u.symbol
                ORDER BY total_produced DESC
            ");
            $production_data = $stmt->fetchAll();
            
            foreach ($production_data as $prod) {
                $production_by_item[] = $prod;
                $production_summary['total_production'] += $prod['total_produced'];
            }
            
            // Trolley movements for PDF
            $stmt = $db->query("
                SELECT 
                    i.code as item_code,
                    i.name as item_name,
                    l.name as location_name,
                    u.symbol as unit_symbol,
                    SUM(sl.quantity_in) as total_moved,
                    COUNT(*) as transaction_count,
                    sl.reference_no
                FROM stock_ledger sl
                JOIN items i ON sl.item_id = i.id
                JOIN locations l ON sl.location_id = l.id
                JOIN units u ON i.unit_id = u.id
                WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                    AND sl.transaction_type = 'production_in'
                    AND sl.reference_no LIKE 'TM%'
                    AND sl.quantity_in > 0
                GROUP BY i.id, l.id, i.code, i.name, l.name, u.symbol, sl.reference_no
                ORDER BY total_moved DESC
            ");
            $trolley_movements_by_item = $stmt->fetchAll();
            
            foreach ($trolley_movements_by_item as $trolley) {
                $production_summary['total_trolley_movements'] += $trolley['total_moved'];
            }
            
            // Transfer quantity
            $transfer_types = "'" . implode("','", $TRANSFER_TYPES) . "'";
            $stmt = $db->query("
                SELECT 
                    i.code as item_code,
                    i.name as item_name,
                    l.name as location_name,
                    u.symbol as unit_symbol,
                    SUM(COALESCE(sl.quantity_in, 0) + COALESCE(sl.quantity_out, 0)) as total_transferred,
                    COUNT(*) as transaction_count
                FROM stock_ledger sl
                JOIN items i ON sl.item_id = i.id
                JOIN locations l ON sl.location_id = l.id
                JOIN units u ON i.unit_id = u.id
                WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                    AND sl.transaction_type IN ($transfer_types)
                GROUP BY i.id, l.id, i.code, i.name, l.name, u.symbol
                ORDER BY total_transferred DESC
            ");
            $transfers_by_item = $stmt->fetchAll();
            
            foreach ($transfers_by_item as $trans) {
                $production_summary['total_transfers'] += $trans['total_transferred'];
            }
            
            // Raw material consumption
            $raw_types = "'" . implode("','", $RAW_CONSUMPTION_TYPES) . "'";
            $stmt = $db->query("
                SELECT 
                    i.code as item_code,
                    i.name as item_name,
                    l.name as location_name,
                    u.symbol as unit_symbol,
                    SUM(sl.quantity_out) as total_consumed,
                    COUNT(*) as transaction_count
                FROM stock_ledger sl
                JOIN items i ON sl.item_id = i.id
                JOIN locations l ON sl.location_id = l.id
                JOIN units u ON i.unit_id = u.id
                WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                    AND sl.transaction_type IN ($raw_types)
                    AND i.type = 'raw'
                    AND sl.quantity_out > 0
                GROUP BY i.id, l.id, i.code, i.name, l.name, u.symbol
                ORDER BY total_consumed DESC
            ");
            $raw_consumed_by_item = $stmt->fetchAll();
            
            foreach ($raw_consumed_by_item as $raw) {
                $production_summary['total_raw_consumed'] += $raw['total_consumed'];
            }
            
            // Daily breakdown
            $stmt = $db->query("
                SELECT 
                    DATE(sl.transaction_date) as report_date,
                    SUM(CASE WHEN sl.transaction_type IN ($production_types) AND i.type IN ('finished', 'semi_finished') AND sl.quantity_in > 0 
                             THEN sl.quantity_in ELSE 0 END) as daily_production,
                    SUM(CASE WHEN sl.transaction_type IN ($transfer_types) 
                             THEN COALESCE(sl.quantity_in, 0) + COALESCE(sl.quantity_out, 0) ELSE 0 END) as daily_transfers,
                    SUM(CASE WHEN sl.transaction_type IN ($raw_types) AND i.type = 'raw' AND sl.quantity_out > 0 
                             THEN sl.quantity_out ELSE 0 END) as daily_raw_consumed
                FROM stock_ledger sl
                JOIN items i ON sl.item_id = i.id
                WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                GROUP BY DATE(sl.transaction_date)
                ORDER BY report_date DESC
            ");
            $daily_breakdown = $stmt->fetchAll();
        }
        
        // Generate PDF HTML
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Stock Report</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
                h1 { text-align: center; color: #333; margin-bottom: 20px; }
                h2 { color: #555; font-size: 16px; margin: 15px 0 8px 0; }
                table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 11px; }
                th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
                th { background-color: #f2f2f2; font-weight: bold; }
                .text-right { text-align: right; }
                .summary-box { background: #f9f9f9; padding: 15px; margin: 10px 0; border-radius: 5px; }
                .low-stock { background-color: #ffebee; }
                .normal-stock { background-color: #e8f5e8; }
            </style>
        </head>
        <body>
            <h1>Stock Report - ' . ucfirst(str_replace('_', ' ', $view_mode)) . ' View</h1>
            <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
            
            <div class="summary-box">
                <h2>Summary Statistics</h2>
                <p><strong>Total Stock Value:</strong> Rs. ' . number_format($summary_stats['total_stock_value'], 2) . '</p>
                <p><strong>Total Items:</strong> ' . $summary_stats['total_items'] . '</p>
                <p><strong>Total Quantity:</strong> ' . number_format($summary_stats['total_quantity'], 3) . '</p>
                <p><strong>Low Stock Items:</strong> ' . $summary_stats['low_stock_items'] . '</p>
            </div>';
            
        if ($view_mode === 'summary') {
            $html .= '<h2>Location Summary</h2>
                <table>
                    <tr>
                        <th>Location</th>
                        <th>Total Items</th>
                        <th>Raw Materials</th>
                        <th>Semi-Finished</th>
                        <th>Finished Goods</th>
                        <th class="text-right">Total Value</th>
                    </tr>';
            
            foreach ($location_summary as $location) {
                $html .= '<tr>
                    <td>' . htmlspecialchars($location['name']) . '</td>
                    <td class="text-right">' . $location['items'] . '</td>
                    <td class="text-right">' . $location['raw'] . '</td>
                    <td class="text-right">' . $location['semi_finished'] . '</td>
                    <td class="text-right">' . $location['finished'] . '</td>
                    <td class="text-right">Rs. ' . number_format($location['total_value'], 2) . '</td>
                </tr>';
            }
            $html .= '</table>';
        }
        
        if ($view_mode === 'detailed') {
            foreach ($detailed_data_by_loc as $locationId => $items) {
                $location_name = $detailed_location_names[$locationId] ?? 'Location #'.$locationId;
                $html .= '<h2>Location: ' . htmlspecialchars($location_name) . '</h2>';
                $html .= '<table>
                    <tr>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Stock</th>
                        <th>Unit Cost</th>
                        <th>Stock Value</th>
                        <th>Status</th>
                    </tr>';
                
                foreach ($items as $item) {
                    $status = $item['current_stock'] <= 10 ? 'Low Stock' : 'Normal';
                    $status_class = $item['current_stock'] <= 10 ? 'low-stock' : 'normal-stock';
                    $html .= '<tr class="' . $status_class . '">
                        <td>' . htmlspecialchars($item['name'] . ' (' . $item['code'] . ')') . '</td>
                        <td>' . str_replace('_', ' ', $item['type']) . '</td>
                        <td class="text-right">' . number_format($item['current_stock'], 3) . ' ' . $item['unit_symbol'] . '</td>
                        <td class="text-right">Rs. ' . number_format($item['cost_price'], 2) . '</td>
                        <td class="text-right">Rs. ' . number_format($item['stock_value'], 2) . '</td>
                        <td>' . $status . '</td>
                    </tr>';
                }
                $html .= '</table>';
                
                // Add Repacking Records for this location - Only show items with remaining_qty > 0
                if ((int)$locationId == $PRODUCTION_ID && !empty($repacking_data_by_loc[$locationId])) {
                    $repacking_available = array_filter($repacking_data_by_loc[$locationId], function($item) {
                        return floatval($item['remaining_qty']) > 0;
                    });
                    
                    if (!empty($repacking_available)) {
                        $html .= '<h3>Repacking Records</h3>
                            <table>
                                <tr>
                                    <th>Repack Code</th>
                                    <th>Date</th>
                                    <th>Source Item</th>
                                    <th>Source Qty</th>
                                    <th>Repack Item</th>
                                    <th>Packs Created</th>
                                    <th>Status</th>
                                </tr>';
                        
                        foreach ($repacking_available as $repack) {
                            $status = $repack['consumed_qty'] > 0 ? 'Used in Bundle' : 'Not Used';
                            $html .= '<tr>
                                <td>' . htmlspecialchars($repack['repack_code']) . '</td>
                                <td>' . date('M d, Y', strtotime($repack['created_at'])) . '</td>
                                <td>' . htmlspecialchars($repack['source_item_name'] . ' (' . $repack['source_item_code'] . ')') . '</td>
                                <td class="text-right">' . number_format($repack['source_quantity'], 3) . ' ' . htmlspecialchars($repack['source_unit_symbol']) . '</td>
                                <td>' . htmlspecialchars($repack['repack_item_name'] . ' (' . $repack['repack_item_code'] . ')') . '</td>
                                <td class="text-right">' . number_format($repack['repack_quantity'], 3) . '</td>
                                <td>' . htmlspecialchars($status) . '</td>
                            </tr>';
                        }
                        $html .= '</table>';
                    }
                }
                
                // Add Rolls Records for this location
                if ((int)$locationId == $PRODUCTION_ID && !empty($rolls_data_by_loc[$locationId])) {
                    $html .= '<h3>Rolls Records</h3>
                        <table>
                            <tr>
                                <th>Batch Code</th>
                                <th>Date</th>
                                <th>Rolls Item</th>
                                <th>Materials</th>
                                <th>Rolls Qty</th>
                                <th>Status</th>
                            </tr>';
                    
                    foreach ($rolls_data_by_loc[$locationId] as $rolls) {
                        $html .= '<tr>
                            <td>' . htmlspecialchars($rolls['batch_code']) . '</td>
                            <td>' . date('M d, Y', strtotime($rolls['created_at'])) . '</td>
                            <td>' . htmlspecialchars($rolls['item_name'] . ' (' . $rolls['item_code'] . ')') . '</td>
                            <td class="text-right">' . $rolls['material_count'] . ' item(s)</td>
                            <td class="text-right">' . number_format($rolls['rolls_quantity'], 0) . '</td>
                            <td>' . ucfirst(htmlspecialchars($rolls['status'])) . '</td>
                        </tr>';
                    }
                    $html .= '</table>';
                }
                
                // Add Bundle Records for this location
                if ((int)$locationId == $PRODUCTION_ID && !empty($bundles_data_by_loc[$locationId])) {
                    $html .= '<h3>Bundle Records</h3>
                        <table>
                            <tr>
                                <th>Bundle Code</th>
                                <th>Date</th>
                                <th>Source Item</th>
                                <th>Source Qty</th>
                                <th>Bundle Item</th>
                                <th>Bundles Created</th>
                                <th>Packs/Bundle</th>
                            </tr>';
                    
                    foreach ($bundles_data_by_loc[$locationId] as $bundle) {
                        $html .= '<tr>
                            <td>' . htmlspecialchars($bundle['bundle_code']) . '</td>
                            <td>' . date('M d, Y', strtotime($bundle['created_at'])) . '</td>
                            <td>' . htmlspecialchars($bundle['source_item_name'] . ' (' . $bundle['source_item_code'] . ')') . '</td>
                            <td class="text-right">' . number_format($bundle['source_quantity'], 3) . ' ' . htmlspecialchars($bundle['source_unit_symbol']) . '</td>
                            <td>' . htmlspecialchars($bundle['bundle_item_name'] . ' (' . $bundle['bundle_item_code'] . ')') . '</td>
                            <td class="text-right">' . number_format($bundle['bundle_quantity'], 0) . '</td>
                            <td class="text-right">' . htmlspecialchars($bundle['packs_per_bundle']) . ' packs</td>
                        </tr>';
                    }
                    $html .= '</table>';
                }
            }
        }
        
        if ($view_mode === 'value_analysis') {
            $html .= '<h2>Value Analysis (Highest Value First)</h2>
                <table>
                    <tr>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Stock</th>
                        <th>Unit Cost</th>
                        <th>Stock Value</th>
                        <th>Status</th>
                    </tr>';
            
            foreach ($stock_data as $item) {
                $status = $item['current_stock'] <= 10 ? 'Low Stock' : 'Normal';
                $status_class = $item['current_stock'] <= 10 ? 'low-stock' : 'normal-stock';
                $html .= '<tr class="' . $status_class . '">
                    <td>' . htmlspecialchars($item['name'] . ' (' . $item['code'] . ')') . '</td>
                    <td>' . str_replace('_', ' ', $item['type']) . '</td>
                    <td class="text-right">' . number_format($item['current_stock'], 3) . ' ' . $item['unit_symbol'] . '</td>
                    <td class="text-right">Rs. ' . number_format($item['cost_price'], 2) . '</td>
                    <td class="text-right">Rs. ' . number_format($item['stock_value'], 2) . '</td>
                    <td>' . $status . '</td>
                </tr>';
            }
            $html .= '</table>';
        }
        
        if ($view_mode === 'movements') {
            $html .= '<h2>Recent Movements (Last 30 Days)</h2>
                <table>
                    <tr>
                        <th>Date</th>
                        <th>Item</th>
                        <th>Location</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Qty In</th>
                        <th>Qty Out</th>
                        <th>Balance</th>
                    </tr>';
            
            foreach ($recent_movements as $mv) {
                $html .= '<tr>
                    <td>' . htmlspecialchars(date('Y-m-d', strtotime($mv['transaction_date']))) . '</td>
                    <td>' . htmlspecialchars($mv['item_name'] . ' (' . $mv['item_code'] . ')') . '</td>
                    <td>' . htmlspecialchars($mv['location_name']) . '</td>
                    <td>' . htmlspecialchars($mv['transaction_type']) . '</td>
                    <td>' . htmlspecialchars($mv['reference_no'] ?? '-') . '</td>
                    <td class="text-right">' . ($mv['quantity_in'] > 0 ? number_format($mv['quantity_in'], 3) : '-') . '</td>
                    <td class="text-right">' . ($mv['quantity_out'] > 0 ? number_format($mv['quantity_out'], 3) : '-') . '</td>
                    <td class="text-right">' . number_format($mv['balance'], 3) . '</td>
                </tr>';
            }
            $html .= '</table>';
        }
        
        if ($view_mode === 'production_report') {
            $html .= '<h2>Production Report (' . $start_date . ' to ' . $end_date . ')</h2>';
            
            $table_type = $_GET['table_type'] ?? 'all';
            
            if ($table_type === 'all') {
                // Summary totals
                $html .= '<div class="summary-box">
                    <h3>Production Summary</h3>
                    <p><strong>Total Production:</strong> ' . number_format($production_summary['total_production'], 2) . ' units</p>
                    <p><strong>Total Trolley Movements:</strong> ' . number_format($production_summary['total_trolley_movements'], 2) . ' units</p>
                    <p><strong>Total Transfers:</strong> ' . number_format($production_summary['total_transfers'], 2) . ' units</p>
                    <p><strong>Total Raw Material Used:</strong> ' . number_format($production_summary['total_raw_consumed'], 2) . ' units</p>
                </div>';
            }
            
            // Production by item
            if (($table_type === 'all' || $table_type === 'production') && !empty($production_by_item)) {
                $html .= '<h3>Production by Finished Item</h3>
                    <table>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Type</th>
                            <th>Quantity Produced</th>
                            <th>Unit</th>
                            <th>Transactions</th>
                        </tr>';
                
                foreach ($production_by_item as $item) {
                    $html .= '<tr>
                        <td>' . htmlspecialchars($item['item_code']) . '</td>
                        <td>' . htmlspecialchars($item['item_name']) . '</td>
                        <td>' . ucfirst(str_replace('_', ' ', $item['item_type'])) . '</td>
                        <td class="text-right">' . number_format($item['total_quantity'], 3) . '</td>
                        <td>' . htmlspecialchars($item['unit_symbol']) . '</td>
                        <td class="text-right">' . $item['transaction_count'] . '</td>
                    </tr>';
                }
                $html .= '</table>';
            }
            
            // Trolley movements table
            if (($table_type === 'all' || $table_type === 'trolley') && !empty($trolley_movements_by_item)) {
                $html .= '<h3>Trolley Movements</h3>
                    <table>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Location</th>
                            <th>Reference</th>
                            <th>Quantity Moved</th>
                            <th>Unit</th>
                        </tr>';
                
                foreach ($trolley_movements_by_item as $item) {
                    $html .= '<tr>
                        <td>' . htmlspecialchars($item['item_code']) . '</td>
                        <td>' . htmlspecialchars($item['item_name']) . '</td>
                        <td>' . htmlspecialchars($item['location_name']) . '</td>
                        <td>' . htmlspecialchars($item['reference_no']) . '</td>
                        <td class="text-right">' . number_format($item['total_moved'], 3) . '</td>
                        <td>' . htmlspecialchars($item['unit_symbol']) . '</td>
                    </tr>';
                }
                $html .= '</table>';
            }
            
            // Raw material consumption
            if (($table_type === 'all' || $table_type === 'raw_consumption') && !empty($raw_consumed_by_item)) {
                $html .= '<h3>Raw Material Consumption</h3>
                    <table>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Location</th>
                            <th>Quantity Consumed</th>
                            <th>Unit</th>
                            <th>Transactions</th>
                        </tr>';
                
                foreach ($raw_consumed_by_item as $item) {
                    $html .= '<tr>
                        <td>' . htmlspecialchars($item['item_code']) . '</td>
                        <td>' . htmlspecialchars($item['item_name']) . '</td>
                        <td>' . htmlspecialchars($item['location_name']) . '</td>
                        <td class="text-right">' . number_format($item['total_consumed'], 3) . '</td>
                        <td>' . htmlspecialchars($item['unit_symbol']) . '</td>
                        <td class="text-right">' . $item['transaction_count'] . '</td>
                    </tr>';
                }
                $html .= '</table>';
            }
            
            // Transfers
            if (($table_type === 'all' || $table_type === 'transfers') && !empty($transfers_by_item)) {
                $html .= '<h3>Transfers Between Locations</h3>
                    <table>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Location</th>
                            <th>Quantity Transferred</th>
                            <th>Unit</th>
                            <th>Transactions</th>
                        </tr>';
                
                foreach ($transfers_by_item as $item) {
                    $html .= '<tr>
                        <td>' . htmlspecialchars($item['item_code']) . '</td>
                        <td>' . htmlspecialchars($item['item_name']) . '</td>
                        <td>' . htmlspecialchars($item['location_name']) . '</td>
                        <td class="text-right">' . number_format($item['total_transferred'], 3) . '</td>
                        <td>' . htmlspecialchars($item['unit_symbol']) . '</td>
                        <td class="text-right">' . $item['transaction_count'] . '</td>
                    </tr>';
                }
                $html .= '</table>';
            }
            
            // Daily breakdown
            if (($table_type === 'all' || $table_type === 'daily_breakdown') && !empty($daily_breakdown)) {
                $html .= '<h3>Daily Breakdown</h3>
                    <table>
                        <tr>
                            <th>Date</th>
                            <th>Production</th>
                            <th>Trolley Movements</th>
                            <th>Transfers</th>
                            <th>Raw Material Used</th>
                        </tr>';
                
                foreach ($daily_breakdown as $day) {
                    $html .= '<tr>
                        <td>' . htmlspecialchars($day['report_date']) . '</td>
                        <td class="text-right">' . number_format($day['daily_production'], 2) . '</td>
                        <td class="text-right">' . number_format($day['daily_trolley_movements'], 2) . '</td>
                        <td class="text-right">' . number_format($day['daily_transfers'], 2) . '</td>
                        <td class="text-right">' . number_format($day['daily_raw_consumed'], 2) . '</td>
                    </tr>';
                }
                $html .= '</table>';
            }
        }
        
        $html .= '</body></html>';
        
        // Add print JavaScript for print mode
        if ($is_print_mode) {
            // Insert print script before closing body tag
            $html = str_replace('</body>', '
            <script>
                window.onload = function() {
                    window.print();
                    // Optional: close window after printing (uncomment if needed)
                    // window.onafterprint = function() { window.close(); };
                };
            </script>
            </body>', $html);
        }
        
        // Set headers based on mode
        header('Content-Type: text/html; charset=UTF-8');
        
        if ($is_print_mode) {
            // For print mode, use inline disposition
            header('Content-Disposition: inline; filename="stock_report_' . $view_mode . '_' . date('Y-m-d') . '.html"');
        } else {
            // For download mode, use attachment disposition
            header('Content-Disposition: attachment; filename="stock_report_' . $view_mode . '_' . date('Y-m-d') . '.html"');
        }
        
        // Output the HTML
        echo $html;
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: text/plain');
        echo "Error generating PDF: " . $e->getMessage();
        exit;
    }
}

include 'header.php';

// Get filters
$location_filter = $_GET['location'] ?? '';
$item_type_filter = $_GET['item_type'] ?? '';
$low_stock_only = isset($_GET['low_stock']);
$view_mode = $_GET['view'] ?? 'summary'; // summary, detailed, movements, value_analysis, production_report

// Production Report date filters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$date_range = $_GET['range'] ?? '';

// Pagination parameters - separate for each table type
$production_page = isset($_GET['production_page']) ? max(1, intval($_GET['production_page'])) : 1;
$trolley_page = isset($_GET['trolley_page']) ? max(1, intval($_GET['trolley_page'])) : 1;
$raw_page = isset($_GET['raw_page']) ? max(1, intval($_GET['raw_page'])) : 1;
$transfers_page = isset($_GET['transfers_page']) ? max(1, intval($_GET['transfers_page'])) : 1;
$daily_page = isset($_GET['daily_page']) ? max(1, intval($_GET['daily_page'])) : 1;
$limit = 10; // Items per page
$production_offset = ($production_page - 1) * $limit;
$trolley_offset = ($trolley_page - 1) * $limit;
$raw_offset = ($raw_page - 1) * $limit;
$transfers_offset = ($transfers_page - 1) * $limit;
$daily_offset = ($daily_page - 1) * $limit;
$table_type = $_GET['table_type'] ?? ''; // For individual table PDF export

// Set default dates if not provided
if (empty($start_date) && empty($end_date)) {
    if ($date_range === 'month') {
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
    } else {
        // Default to today
        $start_date = $end_date = date('Y-m-d');
    }
}

// Constants (adjust if your IDs differ)
$STORE_ID = 1;
$PRODUCTION_ID = 2;

// Production Report Configuration  
$PRODUCTION_TYPES = ['production_in', 'repack_in'];  // Types that indicate production
$TROLLEY_TYPES = ['production_in'];  // Trolley movements (production_in with TM reference)
$TRANSFER_TYPES = ['transfer', 'production_out'];     // Types that indicate transfers
$RAW_CONSUMPTION_TYPES = ['production_out', 'mrn'];  // Types that indicate raw material usage

// Initialize variables to prevent errors
$summary_stats = [
    'total_items' => 0,
    'total_locations' => 0,
    'low_stock_items' => 0,
    'total_stock_value' => 0,
    'total_quantity' => 0
];
$location_summary = [];
$type_summary = ['raw' => 0, 'semi_finished' => 0, 'finished' => 0];
$value_summary = ['raw' => 0, 'semi_finished' => 0, 'finished' => 0];
$stock_data = []; // used for value_analysis view
$detailed_data_by_loc = []; // used for detailed view: [location_id => rows]
$detailed_location_names = []; // [location_id => name]
$repacking_data_by_loc = []; // used for detailed view: [location_id => rows]
$rolls_data_by_loc = []; // used for detailed view: [location_id => rows]
$bundles_data_by_loc = []; // used for detailed view: [location_id => rows]
$recent_movements = [];

try {
    // Get DB handle (from header) and all locations for filters & names
    $stmt = $db->query("SELECT id, name FROM locations ORDER BY name");
    $locations = $stmt->fetchAll();

    $location_map = [];
    foreach ($locations as $loc) { $location_map[(int)$loc['id']] = $loc['name']; }
    $detailed_location_names[$STORE_ID] = $location_map[$STORE_ID] ?? 'Store';
    $detailed_location_names[$PRODUCTION_ID] = $location_map[$PRODUCTION_ID] ?? 'Production Floor';

    // Calculate total stock value and other summary statistics using stock_ledger data
    $stmt = $db->query("
        SELECT 
            COUNT(DISTINCT CASE WHEN stock_qty > 0 THEN i.id END) as total_items,
            COUNT(DISTINCT l.id) as total_locations,
            SUM(CASE WHEN stock_qty <= 10 AND stock_qty > 0 THEN 1 ELSE 0 END) as low_stock_items,
            SUM(CASE WHEN stock_qty > 0 THEN stock_qty * COALESCE(i.cost_price, 0) ELSE 0 END) as total_stock_value,
            SUM(CASE WHEN stock_qty > 0 THEN stock_qty ELSE 0 END) as total_quantity
        FROM items i
        CROSS JOIN locations l
        LEFT JOIN (
            SELECT 
                item_id, 
                location_id, 
                COALESCE(SUM(quantity_in - quantity_out), 0) as stock_qty
            FROM stock_ledger 
            GROUP BY item_id, location_id
        ) sl ON i.id = sl.item_id AND l.id = sl.location_id
    ");
    $result = $stmt->fetch();
    if ($result) {
        $summary_stats = [
            'total_items' => (int)$result['total_items'],
            'total_locations' => (int)$result['total_locations'], 
            'low_stock_items' => (int)$result['low_stock_items'],
            'total_stock_value' => (float)$result['total_stock_value'],
            'total_quantity' => (float)$result['total_quantity']
        ];
    }

    // Get value summary by item type using stock_ledger data
    $stmt = $db->query("
        SELECT 
            i.type,
            COUNT(DISTINCT CASE WHEN stock_qty > 0 THEN i.id END) as item_count,
            SUM(CASE WHEN stock_qty > 0 THEN stock_qty ELSE 0 END) as total_quantity,
            SUM(CASE WHEN stock_qty > 0 THEN stock_qty * COALESCE(i.cost_price, 0) ELSE 0 END) as total_value,
            AVG(CASE WHEN stock_qty > 0 THEN COALESCE(i.cost_price, 0) ELSE NULL END) as avg_cost_price
        FROM items i
        LEFT JOIN (
            SELECT 
                item_id, 
                COALESCE(SUM(quantity_in - quantity_out), 0) as stock_qty
            FROM stock_ledger 
            GROUP BY item_id
        ) sl ON i.id = sl.item_id
        GROUP BY i.type
        ORDER BY total_value DESC
    ");
    $type_value_analysis = $stmt->fetchAll();

    // ---------- DATA FOR DETAILED VIEW: produce two separate tables ----------
    if ($view_mode === 'detailed') {
        // Which locations to show? Default = Store + Production; if filter chosen then only that one.
        $locations_to_show = [];
        if ($location_filter !== '' && is_numeric($location_filter)) {
            $locations_to_show = [(int)$location_filter];
        } else {
            $locations_to_show = [$STORE_ID, $PRODUCTION_ID];
        }

        // Build WHERE fragment shared by both queries (type + low stock)
        $extra_where = [];
        $extra_params = [];
        if ($item_type_filter) { $extra_where[] = "i.type = ?"; $extra_params[] = $item_type_filter; }
        if ($low_stock_only)  { $extra_where[] = "sl.stock_qty <= 10"; }
        $extra_where_sql = implode(' AND ', $extra_where);
        $extra_where_sql = ($extra_where_sql ? ' AND ' . $extra_where_sql : '');

        // Prepared query (per location)
        $sql = "
            SELECT 
                i.id, i.code, i.name, i.type, 
                COALESCE(i.cost_price, 0) as cost_price,
                sl.stock_qty as current_stock,
                (sl.stock_qty * COALESCE(i.cost_price, 0)) as stock_value,
                u.symbol as unit_symbol
            FROM items i
            JOIN units u ON i.unit_id = u.id
            JOIN (
                SELECT item_id, location_id, COALESCE(SUM(quantity_in - quantity_out), 0) as stock_qty
                FROM stock_ledger 
                GROUP BY item_id, location_id
            ) sl ON sl.item_id = i.id AND sl.location_id = ?
            WHERE sl.stock_qty > 0
              $extra_where_sql
            ORDER BY i.name
        ";

        // Initialize arrays for repacking and rolls data
        $repacking_data_by_loc = [];
        $rolls_data_by_loc = [];
        
        foreach ($locations_to_show as $locId) {
            $params = array_merge([$locId], $extra_params);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $detailed_data_by_loc[$locId] = $stmt->fetchAll();
            if (!isset($detailed_location_names[$locId])) {
                $detailed_location_names[$locId] = $location_map[$locId] ?? ("Location #" . $locId);
            }
            
            // Get repacking records for this location - Using remaining_qty to prevent double counting
            $repack_sql = "
                SELECT 
                    r.id, r.repack_code, r.repack_date as created_at,
                    i1.name as source_item_name, i1.code as source_item_code,
                    i2.name as repack_item_name, i2.code as repack_item_code,
                    r.source_quantity, r.repack_quantity, r.repack_unit_size,
                    r.remaining_qty,
                    (r.repack_quantity - r.remaining_qty) as consumed_qty,
                    u1.symbol as source_unit_symbol, u2.symbol as repack_unit_symbol,
                    r.remaining_qty as balance_packs,
                    CASE 
                        WHEN r.remaining_qty = 0 THEN 'Fully Consumed'
                        WHEN r.remaining_qty = r.repack_quantity THEN 'Available'
                        WHEN r.remaining_qty > 0 THEN 'Partially Consumed'
                        ELSE 'Over-consumed'
                    END as status
                FROM repacking r
                JOIN items i1 ON r.source_item_id = i1.id
                JOIN items i2 ON r.repack_item_id = i2.id
                JOIN units u1 ON r.source_unit_id = u1.id
                JOIN units u2 ON r.repack_unit_id = u2.id
                WHERE r.location_id = ?
                ORDER BY r.repack_date DESC
            ";
            $stmt = $db->prepare($repack_sql);
            $stmt->execute([$locId]);
            $repacking_data_by_loc[$locId] = $stmt->fetchAll();
            
            // Get rolls records for this location
            $rolls_sql = "
                SELECT 
                    rb.id, rb.batch_code, rb.created_at,
                    i.name as item_name, i.code as item_code,
                    rb.rolls_quantity, rb.status,
                    COUNT(rm.id) as material_count
                FROM rolls_batches rb
                JOIN items i ON rb.rolls_item_id = i.id
                LEFT JOIN rolls_materials rm ON rb.id = rm.batch_id
                WHERE rb.location_id = ?
                GROUP BY rb.id, rb.batch_code, rb.created_at, i.name, i.code, rb.rolls_quantity, rb.status
                ORDER BY rb.created_at DESC
            ";
            $stmt = $db->prepare($rolls_sql);
            $stmt->execute([$locId]);
            $rolls_data_by_loc[$locId] = $stmt->fetchAll();
            
            // Get bundles records for this location
            $bundles_sql = "
                SELECT 
                    bn.id, bn.bundle_code, bn.bundle_date as created_at,
                    i1.name as source_item_name, i1.code as source_item_code,
                    i2.name as bundle_item_name, i2.code as bundle_item_code,
                    bn.source_quantity, bn.bundle_quantity, bn.packs_per_bundle,
                    u1.symbol as source_unit_symbol, u2.symbol as bundle_unit_symbol,
                    COUNT(bm.id) as material_count
                FROM bundles bn
                JOIN items i1 ON bn.source_item_id = i1.id
                JOIN items i2 ON bn.bundle_item_id = i2.id
                JOIN units u1 ON bn.source_unit_id = u1.id
                JOIN units u2 ON bn.bundle_unit_id = u2.id
                LEFT JOIN bundle_materials bm ON bn.id = bm.bundle_id
                WHERE bn.location_id = ?
                GROUP BY bn.id, bn.bundle_code, bn.bundle_date, i1.name, i1.code, i2.name, i2.code, bn.source_quantity, bn.bundle_quantity, bn.packs_per_bundle, u1.symbol, u2.symbol
                ORDER BY bn.bundle_date DESC
            ";
            $stmt = $db->prepare($bundles_sql);
            $stmt->execute([$locId]);
            $bundles_data_by_loc[$locId] = $stmt->fetchAll();
        }
    }

    // ---------- DATA FOR VALUE ANALYSIS VIEW (aggregate across all locations) ----------
    if ($view_mode === 'value_analysis') {
        $v_where = [];
        $v_params = [];
        $v_where[] = "sl_total.stock_qty > 0";
        if ($item_type_filter) { $v_where[] = "i.type = ?"; $v_params[] = $item_type_filter; }
        if ($low_stock_only)  { $v_where[] = "sl_total.stock_qty <= 10"; }
        $v_where_sql = implode(' AND ', $v_where);

        $query = "
            SELECT 
                i.id, i.code, i.name, i.type, 
                COALESCE(i.cost_price, 0) as cost_price,
                sl_total.stock_qty as current_stock,
                (sl_total.stock_qty * COALESCE(i.cost_price, 0)) as stock_value,
                u.symbol as unit_symbol
            FROM items i
            JOIN units u ON i.unit_id = u.id
            LEFT JOIN (
                SELECT item_id, COALESCE(SUM(quantity_in - quantity_out), 0) as stock_qty
                FROM stock_ledger 
                GROUP BY item_id
            ) sl_total ON i.id = sl_total.item_id
            WHERE $v_where_sql
            ORDER BY stock_value DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute($v_params);
        $stock_data = $stmt->fetchAll();
    }

    // Get recent movements for movements view
    if ($view_mode === 'movements') {
        $stmt = $db->query("
            SELECT 
                sl.transaction_date,
                i.code as item_code,
                i.name as item_name,
                l.name as location_name,
                sl.transaction_type,
                sl.reference_no,
                sl.quantity_in,
                sl.quantity_out,
                sl.balance,
                u.symbol as unit_symbol
            FROM stock_ledger sl
            JOIN items i ON sl.item_id = i.id
            JOIN locations l ON sl.location_id = l.id
            JOIN units u ON i.unit_id = u.id
            WHERE sl.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY sl.transaction_date DESC, sl.id DESC
            LIMIT 100
        ");
        $recent_movements = $stmt->fetchAll();
    }
    
    // Production Report Data
    $production_summary = [
        'total_production' => 0,
        'total_trolley_movements' => 0,
        'total_transfers' => 0,
        'total_raw_consumed' => 0
    ];
    $production_by_item = [];
    $trolley_movements_by_item = [];
    $transfers_by_item = [];
    $raw_consumed_by_item = [];
    $daily_breakdown = [];
    
    // Pagination variables
    $production_total_pages = 0;
    $trolley_total_pages = 0;
    $transfer_total_pages = 0;
    $raw_total_pages = 0;
    $daily_total_pages = 0;
    
    if ($view_mode === 'production_report') {
        // Initialize pagination variables
        $production_total_records = 0;
        $production_total_pages = 0;
        $trolley_total_records = 0;
        $trolley_total_pages = 0;
        $transfers_total_records = 0;
        $transfers_total_pages = 0;
        $raw_consumption_total_records = 0;
        $raw_consumption_total_pages = 0;
        $daily_breakdown_total_records = 0;
        $daily_breakdown_total_pages = 0;
        
        // Production quantity (finished goods produced) - excluding trolley movements
        $production_types = "'" . implode("','", $PRODUCTION_TYPES) . "'";
        
        // Get total count for pagination
        $stmt = $db->query("
            SELECT COUNT(DISTINCT CONCAT(i.id, '_', DATE(sl.transaction_date))) as total_count
            FROM stock_ledger sl
            JOIN items i ON sl.item_id = i.id
            WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                AND sl.transaction_type IN ($production_types)
                AND i.type IN ('finished', 'semi_finished')
                AND sl.quantity_in > 0
                AND (sl.reference_no NOT LIKE 'TM%' OR sl.reference_no IS NULL)
        ");
        $production_total_count = $stmt->fetch()['total_count'];
        $production_total_records = $production_total_count;
        $production_total_pages = ceil($production_total_count / $limit);
        
        $stmt = $db->query("
            SELECT 
                i.code as item_code,
                i.name as item_name,
                i.type as item_type,
                u.symbol as unit_symbol,
                SUM(sl.quantity_in) as total_produced,
                COUNT(*) as transaction_count,
                DATE(sl.transaction_date) as production_date
            FROM stock_ledger sl
            JOIN items i ON sl.item_id = i.id
            JOIN units u ON i.unit_id = u.id
            WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                AND sl.transaction_type IN ($production_types)
                AND i.type IN ('finished', 'semi_finished')
                AND sl.quantity_in > 0
                AND (sl.reference_no NOT LIKE 'TM%' OR sl.reference_no IS NULL)
            GROUP BY i.id, i.code, i.name, i.type, u.symbol, DATE(sl.transaction_date)
            ORDER BY total_produced DESC, production_date DESC
            LIMIT $limit OFFSET $production_offset
        ");
        $production_data = $stmt->fetchAll();
        
        // Aggregate production by item
        foreach ($production_data as $prod) {
            $key = $prod['item_code'];
            if (!isset($production_by_item[$key])) {
                $production_by_item[$key] = [
                    'item_code' => $prod['item_code'],
                    'item_name' => $prod['item_name'],
                    'item_type' => $prod['item_type'],
                    'unit_symbol' => $prod['unit_symbol'],
                    'total_quantity' => 0,
                    'transaction_count' => 0
                ];
            }
            $production_by_item[$key]['total_quantity'] += $prod['total_produced'];
            $production_by_item[$key]['transaction_count'] += $prod['transaction_count'];
            $production_summary['total_production'] += $prod['total_produced'];
        }
        
        // Trolley movements (production_in with TM reference)
        $stmt = $db->query("
            SELECT COUNT(*) as total_count
            FROM stock_ledger sl
            JOIN items i ON sl.item_id = i.id
            WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                AND sl.transaction_type = 'production_in'
                AND sl.reference_no LIKE 'TM%'
                AND sl.quantity_in > 0
        ");
        $trolley_total_count = $stmt->fetch()['total_count'];
        $trolley_total_records = $trolley_total_count;
        $trolley_total_pages = ceil($trolley_total_count / $limit);
        
        $stmt = $db->query("
            SELECT 
                i.code as item_code,
                i.name as item_name,
                l.name as location_name,
                u.symbol as unit_symbol,
                SUM(sl.quantity_in) as total_moved,
                COUNT(*) as transaction_count,
                sl.reference_no
            FROM stock_ledger sl
            JOIN items i ON sl.item_id = i.id
            JOIN locations l ON sl.location_id = l.id
            JOIN units u ON i.unit_id = u.id
            WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                AND sl.transaction_type = 'production_in'
                AND sl.reference_no LIKE 'TM%'
                AND sl.quantity_in > 0
            GROUP BY i.id, l.id, i.code, i.name, l.name, u.symbol, sl.reference_no
            ORDER BY total_moved DESC
            LIMIT $limit OFFSET $trolley_offset
        ");
        $trolley_data = $stmt->fetchAll();
        
        foreach ($trolley_data as $trolley) {
            $trolley_movements_by_item[] = $trolley;
            $production_summary['total_trolley_movements'] += $trolley['total_moved'];
        }
        
        // Transfer quantity
        $transfer_types = "'" . implode("','", $TRANSFER_TYPES) . "'";
        
        $stmt = $db->query("
            SELECT COUNT(*) as total_count
            FROM stock_ledger sl
            JOIN items i ON sl.item_id = i.id
            WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                AND sl.transaction_type IN ($transfer_types)
        ");
        $transfer_total_count = $stmt->fetch()['total_count'];
        $transfers_total_records = $transfer_total_count;
        $transfers_total_pages = ceil($transfer_total_count / $limit);
        
        $stmt = $db->query("
            SELECT 
                i.code as item_code,
                i.name as item_name,
                l.name as location_name,
                u.symbol as unit_symbol,
                SUM(COALESCE(sl.quantity_in, 0) + COALESCE(sl.quantity_out, 0)) as total_transferred,
                COUNT(*) as transaction_count
            FROM stock_ledger sl
            JOIN items i ON sl.item_id = i.id
            JOIN locations l ON sl.location_id = l.id
            JOIN units u ON i.unit_id = u.id
            WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                AND sl.transaction_type IN ($transfer_types)
            GROUP BY i.id, l.id, i.code, i.name, l.name, u.symbol
            ORDER BY total_transferred DESC
            LIMIT $limit OFFSET $transfers_offset
        ");
        $transfer_data = $stmt->fetchAll();
        
        foreach ($transfer_data as $trans) {
            $transfers_by_item[] = $trans;
            $production_summary['total_transfers'] += $trans['total_transferred'];
        }
        
        // Raw material consumption
        $raw_types = "'" . implode("','", $RAW_CONSUMPTION_TYPES) . "'";
        
        $stmt = $db->query("
            SELECT COUNT(*) as total_count
            FROM stock_ledger sl
            JOIN items i ON sl.item_id = i.id
            WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                AND sl.transaction_type IN ($raw_types)
                AND i.type = 'raw'
                AND sl.quantity_out > 0
        ");
        $raw_total_count = $stmt->fetch()['total_count'];
        $raw_consumption_total_records = $raw_total_count;
        $raw_consumption_total_pages = ceil($raw_total_count / $limit);
        
        $stmt = $db->query("
            SELECT 
                i.code as item_code,
                i.name as item_name,
                l.name as location_name,
                u.symbol as unit_symbol,
                SUM(sl.quantity_out) as total_consumed,
                COUNT(*) as transaction_count
            FROM stock_ledger sl
            JOIN items i ON sl.item_id = i.id
            JOIN locations l ON sl.location_id = l.id
            JOIN units u ON i.unit_id = u.id
            WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
                AND sl.transaction_type IN ($raw_types)
                AND i.type = 'raw'
                AND sl.quantity_out > 0
            GROUP BY i.id, l.id, i.code, i.name, l.name, u.symbol
            ORDER BY total_consumed DESC
            LIMIT $limit OFFSET $raw_offset
        ");
        $raw_consumption_data = $stmt->fetchAll();
        
        foreach ($raw_consumption_data as $raw) {
            $raw_consumed_by_item[] = $raw;
            $production_summary['total_raw_consumed'] += $raw['total_consumed'];
        }
        
        // Daily breakdown for the selected period
        $stmt = $db->query("
            SELECT COUNT(DISTINCT DATE(sl.transaction_date)) as total_count
            FROM stock_ledger sl
            WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
        ");
        $daily_total_count = $stmt->fetch()['total_count'];
        $daily_breakdown_total_records = $daily_total_count;
        $daily_breakdown_total_pages = ceil($daily_total_count / $limit);
        
        $stmt = $db->query("
            SELECT 
                DATE(sl.transaction_date) as report_date,
                SUM(CASE WHEN sl.transaction_type IN ($production_types) AND i.type IN ('finished', 'semi_finished') AND sl.quantity_in > 0 AND (sl.reference_no NOT LIKE 'TM%' OR sl.reference_no IS NULL)
                         THEN sl.quantity_in ELSE 0 END) as daily_production,
                SUM(CASE WHEN sl.transaction_type = 'production_in' AND sl.reference_no LIKE 'TM%' AND sl.quantity_in > 0
                         THEN sl.quantity_in ELSE 0 END) as daily_trolley_movements,
                SUM(CASE WHEN sl.transaction_type IN ($transfer_types) 
                         THEN COALESCE(sl.quantity_in, 0) + COALESCE(sl.quantity_out, 0) ELSE 0 END) as daily_transfers,
                SUM(CASE WHEN sl.transaction_type IN ($raw_types) AND i.type = 'raw' AND sl.quantity_out > 0 
                         THEN sl.quantity_out ELSE 0 END) as daily_raw_consumed
            FROM stock_ledger sl
            JOIN items i ON sl.item_id = i.id
            WHERE sl.transaction_date BETWEEN '$start_date' AND '$end_date'
            GROUP BY DATE(sl.transaction_date)
            ORDER BY report_date DESC
            LIMIT $limit OFFSET $daily_offset
        ");
        $daily_breakdown = $stmt->fetchAll();
    }

    // Get location-wise summary using stock_ledger data
    $stmt = $db->query("
        SELECT 
            l.name,
            COUNT(DISTINCT CASE WHEN stock_qty > 0 THEN i.id END) as items,
            COUNT(DISTINCT CASE WHEN stock_qty > 0 AND i.type = 'raw' THEN i.id END) as raw,
            COUNT(DISTINCT CASE WHEN stock_qty > 0 AND i.type = 'semi_finished' THEN i.id END) as semi_finished,
            COUNT(DISTINCT CASE WHEN stock_qty > 0 AND i.type = 'finished' THEN i.id END) as finished,
            SUM(CASE WHEN stock_qty > 0 THEN stock_qty * COALESCE(i.cost_price, 0) ELSE 0 END) as total_value
        FROM locations l
        CROSS JOIN items i
        LEFT JOIN (
            SELECT 
                item_id, 
                location_id, 
                COALESCE(SUM(quantity_in - quantity_out), 0) as stock_qty
            FROM stock_ledger 
            GROUP BY item_id, location_id
        ) sl ON i.id = sl.item_id AND l.id = sl.location_id
        GROUP BY l.id, l.name
        ORDER BY total_value DESC
    ");
    $location_summary = $stmt->fetchAll();

} catch(PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">📊 Advanced Stock Report</h1>
            <p class="text-gray-600">Comprehensive inventory analysis with value insights</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <select onchange="location.href='?view=<?php echo $view_mode; ?>&location=' + this.value + '&item_type=<?php echo $item_type_filter; ?>' + '<?php echo $low_stock_only ? '&low_stock=1' : ''; ?>'" class="px-3 py-2 border border-gray-300 rounded-md">
                <option value="">All Locations (detailed shows Store + Production)</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($location['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select onchange="location.href='?view=<?php echo $view_mode; ?>&location=<?php echo $location_filter; ?>&item_type=' + this.value + '<?php echo $low_stock_only ? '&low_stock=1' : ''; ?>'" class="px-3 py-2 border border-gray-300 rounded-md">
                <option value="">All Types</option>
                <option value="raw" <?php echo $item_type_filter === 'raw' ? 'selected' : ''; ?>>Raw Materials</option>
                <option value="semi_finished" <?php echo $item_type_filter === 'semi_finished' ? 'selected' : ''; ?>>Semi-Finished</option>
                <option value="finished" <?php echo $item_type_filter === 'finished' ? 'selected' : ''; ?>>Finished Goods</option>
            </select>
            <select onchange="location.href='?view=' + this.value + '&location=<?php echo $location_filter; ?>&item_type=<?php echo $item_type_filter; ?>' + '<?php echo $low_stock_only ? '&low_stock=1' : ''; ?>' + '&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>'" class="px-3 py-2 border border-gray-300 rounded-md">
                <option value="summary" <?php echo $view_mode === 'summary' ? 'selected' : ''; ?>>Summary View</option>
                <option value="detailed" <?php echo $view_mode === 'detailed' ? 'selected' : ''; ?>>Detailed View</option>
                <option value="value_analysis" <?php echo $view_mode === 'value_analysis' ? 'selected' : ''; ?>>💰 Value Analysis</option>
                <option value="movements" <?php echo $view_mode === 'movements' ? 'selected' : ''; ?>>Recent Movements</option>
                <option value="production_report" <?php echo $view_mode === 'production_report' ? 'selected' : ''; ?>>📊 Production Report</option>
            </select>

            <!-- PDF Export buttons (preserves current filters) -->
            <button
                onclick="location.href='?view=<?php echo urlencode($view_mode); ?><?php echo $location_filter!=='' ? '&location='.urlencode($location_filter) : ''; ?><?php echo $item_type_filter!=='' ? '&item_type='.urlencode($item_type_filter) : ''; ?><?php echo $low_stock_only ? '&low_stock=1' : ''; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&download=pdf'"
                class="bg-rose-600 text-white px-4 py-2 rounded-md hover:bg-rose-700 transition-colors">
                ⬇️ Download PDF
            </button>
            
            <button
                onclick="window.open('?view=<?php echo urlencode($view_mode); ?><?php echo $location_filter!=='' ? '&location='.urlencode($location_filter) : ''; ?><?php echo $item_type_filter!=='' ? '&item_type='.urlencode($item_type_filter) : ''; ?><?php echo $low_stock_only ? '&low_stock=1' : ''; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&print=pdf', '_blank')"
                class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition-colors">
                🖨️ Print PDF
            </button>

            <button onclick="exportToCSV()" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors">
                📊 Export CSV
            </button>
        </div>
    </div>

    <!-- Date Filters (for Production Report) -->
    <?php if ($view_mode === 'production_report'): ?>
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex flex-col md:flex-row gap-4 items-end">
            <div class="flex-1">
                <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div class="flex-1">
                <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div class="flex gap-2">
                <button onclick="setDateRange('today')" class="bg-blue-100 text-blue-800 px-4 py-2 rounded-md hover:bg-blue-200 transition-colors text-sm">
                    Today
                </button>
                <button onclick="setDateRange('month')" class="bg-green-100 text-green-800 px-4 py-2 rounded-md hover:bg-green-200 transition-colors text-sm">
                    This Month
                </button>
                <button onclick="applyDateFilter()" class="bg-primary text-white px-6 py-2 rounded-md hover:bg-primary-dark transition-colors">
                    Apply Filter
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Error Message -->
    <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- 💰 MAIN VALUE HIGHLIGHT CARD -->
    <div class="bg-gradient-to-r from-emerald-500 to-teal-600 p-8 rounded-2xl shadow-lg text-white">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold mb-2">💰 Total Current Stock Value</h2>
                <p class="text-emerald-100 text-lg">Complete inventory valuation at current cost prices</p>
            </div>
            <div class="text-right">
                <div class="text-4xl font-bold">Rs. <?php echo number_format($summary_stats['total_stock_value'], 2); ?></div>
                <div class="text-emerald-100 text-sm mt-1">
                    <?php echo number_format($summary_stats['total_quantity'], 3); ?> total units
                </div>
                <div class="text-emerald-100 text-sm">
                    Average: Rs. <?php echo $summary_stats['total_quantity'] > 0 ? number_format($summary_stats['total_stock_value'] / $summary_stats['total_quantity'], 2) : '0.00'; ?> per unit
                </div>
            </div>
        </div>
    </div>

    <!-- Key Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Total Items</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format($summary_stats['total_items']); ?></p>
                    <p class="text-xs text-gray-500">Across <?php echo $summary_stats['total_locations']; ?> locations</p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-red-100">
                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.68 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Low Stock Alert</p>
                    <p class="text-2xl font-bold text-red-600"><?php echo number_format($summary_stats['low_stock_items']); ?></p>
                    <p class="text-xs text-gray-500">Items below 10 units</p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Total Quantity</p>
                    <p class="text-2xl font-bold text-green-600"><?php echo number_format($summary_stats['total_quantity'], 0); ?></p>
                    <p class="text-xs text-gray-500">Units in stock</p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Avg. Item Value</p>
                    <p class="text-2xl font-bold text-purple-600">Rs. <?php echo $summary_stats['total_items'] > 0 ? number_format($summary_stats['total_stock_value'] / $summary_stats['total_items'], 2) : '0.00'; ?></p>
                    <p class="text-xs text-gray-500">Per item average</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Value Breakdown by Item Type -->
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">💎 Stock Value by Category</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php foreach ($type_value_analysis as $type): ?>
                <?php 
                    $type_color = $type['type'] === 'raw' ? 'blue' : ($type['type'] === 'semi_finished' ? 'yellow' : 'green');
                    $percentage = $summary_stats['total_stock_value'] > 0 ? ($type['total_value'] / $summary_stats['total_stock_value']) * 100 : 0;
                ?>
                <div class="bg-<?php echo $type_color; ?>-50 border border-<?php echo $type_color; ?>-200 rounded-lg p-4">
                    <div class="flex justify-between items-start mb-2">
                        <h4 class="font-medium text-<?php echo $type_color; ?>-800 capitalize">
                            <?php echo str_replace('_', ' ', $type['type']); ?>
                        </h4>
                        <span class="text-xs bg-<?php echo $type_color; ?>-100 text-<?php echo $type_color; ?>-800 px-2 py-1 rounded">
                            <?php echo number_format($percentage, 1); ?>%
                        </span>
                    </div>
                    <div class="space-y-1">
                        <div class="text-2xl font-bold text-<?php echo $type_color; ?>-600">
                            Rs. <?php echo number_format($type['total_value'], 2); ?>
                        </div>
                        <div class="text-sm text-<?php echo $type_color; ?>-700">
                            <?php echo number_format($type['item_count']); ?> items • <?php echo number_format($type['total_quantity'], 0); ?> units
                        </div>
                        <div class="text-xs text-<?php echo $type_color; ?>-600">
                            Avg: Rs. <?php echo number_format($type['avg_cost_price'], 2); ?> per unit
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Low Stock Items Alert (if any) -->
    <?php if ($summary_stats['low_stock_items'] > 0): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-6">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.68 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-lg font-medium text-red-800">⚠️ Low Stock Alert</h3>
                <p class="text-red-700 mt-1">
                    You have <strong><?php echo $summary_stats['low_stock_items']; ?> items</strong> below 10 units. 
                    <a href="?view=detailed&low_stock=1" class="underline font-medium">Click here to view low stock items</a>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- SUMMARY VIEW (unchanged) -->
    <?php if ($view_mode === 'summary'): ?>
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">📍 Stock Summary by Location</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Raw</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Semi-Finished</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Finished</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">💰 Total Value</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($location_summary as $location): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($location['name']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                            <?php echo number_format($location['items']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-medium">
                                <?php echo number_format($location['raw']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                            <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-xs font-medium">
                                <?php echo number_format($location['semi_finished']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                            <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs font-medium">
                                <?php echo number_format($location['finished']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-bold">
                            Rs. <?php echo number_format($location['total_value'], 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- DETAILED VIEW: TWO TABLES (Store & Production) or single if location selected -->
    <?php if ($view_mode === 'detailed'): ?>
    
    <!-- INFO BOX: Comprehensive Report Description -->
    <div class="bg-blue-50 border-l-4 border-blue-500 p-6 mb-6 rounded-r-lg">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-6 w-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800 mb-1">📋 Detailed Stock Inventory Report</h3>
                <p class="text-sm text-blue-700">
                    This report displays <strong>all available Raw Materials, Finished Goods, and Semi-Finished Items</strong> currently held in the <strong>Store and Production Floor</strong> with detailed quantity and value breakdown. Each item shows current stock quantity, unit cost, total stock value, and availability status.
                </p>
            </div>
        </div>
    </div>
    
        <?php foreach ($detailed_data_by_loc as $locId => $rows): ?>
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">🔍 Detailed Stock Report — <?php echo htmlspecialchars($detailed_location_names[$locId] ?? ('Location #' . $locId)); ?></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Current Stock</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Cost</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">💰 Stock Value</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($rows as $item): ?>
                        <?php $is_low = ($item['current_stock'] <= 10); ?>
                        <tr class="hover:bg-gray-50 <?php echo $is_low ? 'bg-red-50' : ''; ?>">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($item['code']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    <?php echo $item['type'] === 'raw' ? 'bg-blue-100 text-blue-800' : 
                                        ($item['type'] === 'semi_finished' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'); ?>">
                                    <?php echo str_replace('_', ' ', $item['type']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                                <div class="<?php echo $is_low ? 'text-red-600' : 'text-gray-900'; ?>">
                                    <?php echo number_format($item['current_stock'], 3); ?>
                                </div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($item['unit_symbol']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                <div class="text-gray-900">Rs. <?php echo number_format($item['cost_price'], 2); ?></div>
                                <div class="text-xs text-gray-500">per <?php echo htmlspecialchars($item['unit_symbol']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold">
                                <div class="text-lg text-green-600">Rs. <?php echo number_format($item['stock_value'], 2); ?></div>
                                <?php if ($summary_stats['total_stock_value'] > 0): ?>
                                    <div class="text-xs text-gray-500">
                                        <?php echo number_format(($item['stock_value'] / $summary_stats['total_stock_value']) * 100, 1); ?>% of total
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <?php if ($is_low): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">⚠️ Low Stock</span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">✅ Normal</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (empty($rows)): ?>
                <div class="text-center py-8">
                    <div class="text-gray-400 text-lg">🔭</div>
                    <p class="text-gray-500 mt-2">No stock data found for this location</p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Repacking Records -->
            <?php if (!empty($repacking_data_by_loc[$locId])): ?>
            <div class="mt-6 px-6 py-4 border-t border-gray-200">
                <h4 class="text-md font-medium text-gray-900 mb-4">📦 Repacking Records (<?php echo htmlspecialchars($detailed_location_names[$locId] ?? 'Location'); ?>)</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Repack Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source Item</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Source Qty</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Repack Item</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Packs Created</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance Packs</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php 
                            $repacking_available = array_filter($repacking_data_by_loc[$locId], function($item) {
                                return floatval($item['remaining_qty']) > 0;
                            });
                            if (empty($repacking_available)): 
                            ?>
                            <tr>
                                <td colspan="8" class="px-6 py-4 text-center text-gray-500">No repacking records with available stock</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($repacking_available as $repack): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                    <?php echo htmlspecialchars($repack['repack_code']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo date('M d, Y', strtotime($repack['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($repack['source_item_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($repack['source_item_code']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    <div class="text-gray-900 font-medium"><?php echo number_format($repack['source_quantity'], 3); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($repack['source_unit_symbol']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($repack['repack_item_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($repack['repack_item_code']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    <div class="text-gray-900 font-medium"><?php echo number_format($repack['repack_quantity'], 3); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($repack['repack_unit_symbol']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    <?php 
                                    $balance = floatval($repack['balance_packs']);
                                    $total = floatval($repack['repack_quantity']);
                                    $percentage = $total > 0 ? ($balance / $total) * 100 : 0;
                                    
                                    if ($percentage >= 80) {
                                        $color_class = 'text-green-600';
                                    } elseif ($percentage >= 50) {
                                        $color_class = 'text-yellow-600';
                                    } elseif ($percentage > 0) {
                                        $color_class = 'text-orange-600';
                                    } else {
                                        $color_class = 'text-gray-600';
                                    }
                                    ?>
                                    <div class="font-medium <?php echo $color_class; ?>"><?php echo number_format($balance, 0); ?></div>
                                    <?php if ($repack['consumed_qty'] > 0): ?>
                                        <div class="text-xs text-gray-500">Used: <?php echo number_format($repack['consumed_qty'], 0); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php if ($repack['consumed_qty'] > 0): ?>
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            ✅ Used in Bundle
                                        </span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-600">
                                            ⚪ Not Used
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Rolls Records -->
            <?php if (!empty($rolls_data_by_loc[$locId])): ?>
            <div class="mt-6 px-6 py-4 border-t border-gray-200">
                <h4 class="text-md font-medium text-gray-900 mb-4">🎯 Rolls Records (<?php echo htmlspecialchars($detailed_location_names[$locId] ?? 'Location'); ?>)</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rolls Item</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Materials</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Rolls Qty</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($rolls_data_by_loc[$locId] as $rolls): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-purple-600">
                                    <?php echo htmlspecialchars($rolls['batch_code']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo date('M d, Y', strtotime($rolls['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($rolls['item_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($rolls['item_code']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                    <?php echo $rolls['material_count']; ?> item(s)
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                                    <?php echo number_format($rolls['rolls_quantity'], 0); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php echo $rolls['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <?php echo ucfirst($rolls['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Bundles Records -->
            <?php if (!empty($bundles_data_by_loc[$locId])): ?>
            <div class="mt-6 px-6 py-4 border-t border-gray-200">
                <h4 class="text-md font-medium text-gray-900 mb-4">📦 Bundle Records (<?php echo htmlspecialchars($detailed_location_names[$locId] ?? 'Location'); ?>)</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bundle Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source Item</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Source Qty</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bundle Item</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Bundles Created</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Packs/Bundle</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($bundles_data_by_loc[$locId] as $bundle): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-green-600">
                                    <?php echo htmlspecialchars($bundle['bundle_code']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo date('M d, Y', strtotime($bundle['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($bundle['source_item_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($bundle['source_item_code']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    <div class="text-gray-900 font-medium"><?php echo number_format($bundle['source_quantity'], 3); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($bundle['source_unit_symbol']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($bundle['bundle_item_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($bundle['bundle_item_code']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    <div class="text-gray-900 font-medium"><?php echo number_format($bundle['bundle_quantity'], 0); ?></div>
                                    <div class="text-xs text-gray-500">bundles</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="px-2 py-1 inline-flex text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                        <?php echo htmlspecialchars($bundle['packs_per_bundle']); ?> packs
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (empty($detailed_data_by_loc)): ?>
        <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
            No locations selected / no data to show.
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- VALUE ANALYSIS VIEW -->
    <?php if ($view_mode === 'value_analysis'): ?>
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">💰 Stock Value Analysis (Highest Value First)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Current Stock</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Cost</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">💰 Stock Value</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($stock_data as $item): ?>
                        <?php $is_low = ($item['current_stock'] <= 10); ?>
                        <tr class="hover:bg-gray-50 <?php echo $is_low ? 'bg-red-50' : ''; ?>">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($item['code']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    <?php echo $item['type'] === 'raw' ? 'bg-blue-100 text-blue-800' : 
                                        ($item['type'] === 'semi_finished' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'); ?>">
                                    <?php echo str_replace('_', ' ', $item['type']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                                <div class="<?php echo $is_low ? 'text-red-600' : 'text-gray-900'; ?>">
                                    <?php echo number_format($item['current_stock'], 3); ?>
                                </div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($item['unit_symbol']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                <div class="text-gray-900">Rs. <?php echo number_format($item['cost_price'], 2); ?></div>
                                <div class="text-xs text-gray-500">per <?php echo htmlspecialchars($item['unit_symbol']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold">
                                <div class="text-lg text-green-600">Rs. <?php echo number_format($item['stock_value'], 2); ?></div>
                                <?php if ($summary_stats['total_stock_value'] > 0): ?>
                                    <div class="text-xs text-gray-500">
                                        <?php echo number_format(($item['stock_value'] / $summary_stats['total_stock_value']) * 100, 1); ?>% of total
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <?php if ($is_low): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                        ⚠️ Low Stock
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                        ✅ Normal
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($stock_data)): ?>
            <div class="text-center py-8">
                <div class="text-gray-400 text-lg">🔭</div>
                <p class="text-gray-500 mt-2">No stock data found</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- PRODUCTION REPORT VIEW -->
    <?php if ($view_mode === 'production_report'): ?>
    
    <!-- Production Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-green-50 border border-green-200 rounded-lg p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-green-600">Total Production</p>
                    <p class="text-2xl font-bold text-green-900"><?php echo number_format($production_summary['total_production'], 2); ?></p>
                    <p class="text-xs text-green-700">Finished goods produced</p>
                </div>
            </div>
        </div>
        
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-yellow-600">Trolley Movements</p>
                    <p class="text-2xl font-bold text-yellow-900"><?php echo number_format($production_summary['total_trolley_movements'], 2); ?></p>
                    <p class="text-xs text-yellow-700">Items moved via trolley</p>
                </div>
            </div>
        </div>
        
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-blue-600">Total Transfers</p>
                    <p class="text-2xl font-bold text-blue-900"><?php echo number_format($production_summary['total_transfers'], 2); ?></p>
                    <p class="text-xs text-blue-700">Between locations</p>
                </div>
            </div>
        </div>
        
        <div class="bg-orange-50 border border-orange-200 rounded-lg p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-orange-100">
                    <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-orange-600">Raw Material Used</p>
                    <p class="text-2xl font-bold text-orange-900"><?php echo number_format($production_summary['total_raw_consumed'], 2); ?></p>
                    <p class="text-xs text-orange-700">Raw materials consumed</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Production by Item -->
    <?php if ($production_total_records > 0): ?>
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">🏭 Production by Finished Item</h3>
                    <p class="text-sm text-gray-600">Period: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></p>
                </div>
                <button onclick="window.open('?view=production_report&table_type=production&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&download=pdf', '_blank')" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 transition-colors text-sm">
                    📄 Export PDF
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <?php if (!empty($production_by_item)): ?>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity Produced</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Transactions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($production_by_item as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($item['item_code']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                <?php echo $item['item_type'] === 'finished' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $item['item_type'])); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="text-lg font-bold text-green-600"><?php echo number_format($item['total_quantity'], 3); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($item['unit_symbol']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                            <?php echo $item['transaction_count']; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="px-6 py-8 text-center text-gray-500">
                <p>No production data found for page <?php echo $production_page; ?>.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination for Production Items -->
        <?php if ($production_total_records > 0): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
            <div class="text-sm text-gray-700">
                Showing <?php echo (($production_page - 1) * $limit + 1); ?> to <?php echo min($production_page * $limit, $production_total_records); ?> of <?php echo $production_total_records; ?> production items (Page <?php echo $production_page; ?>)
            </div>
            <div class="flex space-x-1">
                <?php if ($production_page > 1): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&production_page=<?php echo $production_page-1; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $production_page-2); $i <= min($production_total_pages, $production_page+2); $i++): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&production_page=<?php echo $i; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="<?php echo $i == $production_page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> px-3 py-2 rounded-md text-sm"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($production_page < $production_total_pages): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&production_page=<?php echo $production_page+1; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Trolley Movements -->
    <?php if ($trolley_total_records > 0): ?>
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">🚚 Trolley Movements</h3>
                    <p class="text-sm text-gray-600">Period: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></p>
                </div>
                <button onclick="window.open('?view=production_report&table_type=trolley&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&download=pdf', '_blank')" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 transition-colors text-sm">
                    📄 Export PDF
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity Moved</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Transactions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($trolley_movements_by_item as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($item['item_code']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($item['location_name']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-blue-600">
                            <?php echo htmlspecialchars($item['reference_no']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="text-lg font-bold text-yellow-600"><?php echo number_format($item['total_moved'], 3); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($item['unit_symbol']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                            <?php echo $item['transaction_count']; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination for Trolley Movements -->
        <?php if ($trolley_total_records > 0): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
            <div class="text-sm text-gray-700">
                Showing <?php echo (($trolley_page - 1) * $limit + 1); ?> to <?php echo min($trolley_page * $limit, $trolley_total_records); ?> of <?php echo $trolley_total_records; ?> trolley movements (Page <?php echo $trolley_page; ?>)
            </div>
            <div class="flex space-x-1">
                <?php if ($trolley_page > 1): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&trolley_page=<?php echo $trolley_page-1; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $trolley_page-2); $i <= min($trolley_total_pages, $trolley_page+2); $i++): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&trolley_page=<?php echo $i; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="<?php echo $i == $trolley_page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> px-3 py-2 rounded-md text-sm"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($trolley_page < $trolley_total_pages): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&trolley_page=<?php echo $trolley_page+1; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Raw Material Consumption -->
    <?php if ($raw_consumption_total_records > 0): ?>
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">🔴 Raw Material Consumption</h3>
                    <p class="text-sm text-gray-600">Period: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></p>
                </div>
                <button onclick="window.open('?view=production_report&table_type=raw_consumption&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&download=pdf', '_blank')" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 transition-colors text-sm">
                    📄 Export PDF
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <?php if (!empty($raw_consumed_by_item)): ?>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Raw Material</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity Used</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Transactions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($raw_consumed_by_item as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($item['item_code']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($item['location_name']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="text-lg font-bold text-orange-600"><?php echo number_format($item['total_consumed'], 3); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($item['unit_symbol']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                            <?php echo $item['transaction_count']; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="px-6 py-8 text-center text-gray-500">
                <p>No raw material consumption data found for page <?php echo $raw_page; ?>.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination for Raw Material Consumption -->
        <?php if ($raw_consumption_total_records > 0): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
            <div class="text-sm text-gray-700">
                Showing <?php echo (($raw_page - 1) * $limit + 1); ?> to <?php echo min($raw_page * $limit, $raw_consumption_total_records); ?> of <?php echo $raw_consumption_total_records; ?> raw materials (Page <?php echo $raw_page; ?>)
            </div>
            <div class="flex space-x-1">
                <?php if ($raw_page > 1): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&raw_page=<?php echo $raw_page-1; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $raw_page-2); $i <= min($raw_consumption_total_pages, $raw_page+2); $i++): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&raw_page=<?php echo $i; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="<?php echo $i == $raw_page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> px-3 py-2 rounded-md text-sm"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($raw_page < $raw_consumption_total_pages): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&raw_page=<?php echo $raw_page+1; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Transfers -->
    <?php if ($transfers_total_records > 0): ?>
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">🔄 Transfers Between Locations</h3>
                    <p class="text-sm text-gray-600">Period: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></p>
                </div>
                <button onclick="window.open('?view=production_report&table_type=transfers&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&download=pdf', '_blank')" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 transition-colors text-sm">
                    📄 Export PDF
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity Transferred</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Transactions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($transfers_by_item as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($item['item_code']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($item['location_name']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="text-lg font-bold text-blue-600"><?php echo number_format($item['total_transferred'], 3); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($item['unit_symbol']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                            <?php echo $item['transaction_count']; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination for Transfers -->
        <?php if ($transfers_total_records > 0): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
            <div class="text-sm text-gray-700">
                Showing <?php echo (($transfers_page - 1) * $limit + 1); ?> to <?php echo min($transfers_page * $limit, $transfers_total_records); ?> of <?php echo $transfers_total_records; ?> transfers (Page <?php echo $transfers_page; ?>)
            </div>
            <div class="flex space-x-1">
                <?php if ($transfers_page > 1): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&transfers_page=<?php echo $transfers_page-1; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $transfers_page-2); $i <= min($transfers_total_pages, $transfers_page+2); $i++): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&transfers_page=<?php echo $i; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="<?php echo $i == $transfers_page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> px-3 py-2 rounded-md text-sm"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($transfers_page < $transfers_total_pages): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&transfers_page=<?php echo $transfers_page+1; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $daily_page > 1 ? '&daily_page='.$daily_page : ''; ?>" 
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Daily Breakdown -->
    <?php if ($daily_breakdown_total_records > 1): ?>
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">📅 Daily Breakdown</h3>
                    <p class="text-sm text-gray-600">Period: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></p>
                </div>
                <button onclick="window.open('?view=production_report&table_type=daily_breakdown&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&download=pdf', '_blank')" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 transition-colors text-sm">
                    📄 Export PDF
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Production</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Trolley Movements</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Transfers</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Raw Material Used</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($daily_breakdown as $day): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo date('M d, Y', strtotime($day['report_date'])); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-green-600 font-medium"><?php echo number_format($day['daily_production'], 2); ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-yellow-600 font-medium"><?php echo number_format($day['daily_trolley_movements'], 2); ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-blue-600 font-medium"><?php echo number_format($day['daily_transfers'], 2); ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-orange-600 font-medium"><?php echo number_format($day['daily_raw_consumed'], 2); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination for Daily Breakdown -->
        <?php if ($daily_breakdown_total_records > 0): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
            <div class="text-sm text-gray-700">
                Showing <?php echo (($daily_page - 1) * $limit + 1); ?> to <?php echo min($daily_page * $limit, $daily_breakdown_total_records); ?> of <?php echo $daily_breakdown_total_records; ?> days (Page <?php echo $daily_page; ?>)
            </div>
            <div class="flex space-x-1">
                <?php if ($daily_page > 1): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&daily_page=<?php echo $daily_page-1; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?>" 
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $daily_page-2); $i <= min($daily_breakdown_total_pages, $daily_page+2); $i++): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&daily_page=<?php echo $i; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?>" 
                   class="<?php echo $i == $daily_page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> px-3 py-2 rounded-md text-sm"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($daily_page < $daily_breakdown_total_pages): ?>
                <a href="stock_report.php?view=production_report&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?><?php echo $date_range ? '&range='.urlencode($date_range) : ''; ?>&daily_page=<?php echo $daily_page+1; ?><?php echo $production_page > 1 ? '&production_page='.$production_page : ''; ?><?php echo $trolley_page > 1 ? '&trolley_page='.$trolley_page : ''; ?><?php echo $raw_page > 1 ? '&raw_page='.$raw_page : ''; ?><?php echo $transfers_page > 1 ? '&transfers_page='.$transfers_page : ''; ?>" 
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Empty state if no data -->
    <?php if (empty($production_by_item) && empty($trolley_movements_by_item) && empty($raw_consumed_by_item) && empty($transfers_by_item)): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center">
        <div class="text-gray-400 text-6xl mb-4">📊</div>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No Production Data Found</h3>
        <p class="text-gray-500 mb-4">No production activities found for the selected date range.</p>
        <p class="text-sm text-gray-400">Try selecting a different date range or check if there are production transactions in the system.</p>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>

    <?php if ($view_mode === 'movements'): ?>
    <!-- Recent Movements -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">📋 Recent Stock Movements (Last 30 Days)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity In</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity Out</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($recent_movements as $movement): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo date('M d, Y', strtotime($movement['transaction_date'])); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($movement['item_name']); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($movement['item_code']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($movement['location_name']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                <?php 
                                    $type_colors = [
                                        'grn' => 'bg-green-100 text-green-800',
                                        'mrn' => 'bg-blue-100 text-blue-800', 
                                        'return' => 'bg-indigo-100 text-indigo-800',
                                        'production_in' => 'bg-purple-100 text-purple-800',
                                        'production_out' => 'bg-orange-100 text-orange-800',
                                        'trolley' => 'bg-yellow-100 text-yellow-800',
                                        'opening_stock' => 'bg-gray-100 text-gray-800',
                                        'transfer_in' => 'bg-teal-100 text-teal-800',
                                        'transfer_out' => 'bg-red-100 text-red-800'
                                    ];
                                    echo $type_colors[$movement['transaction_type']] ?? 'bg-gray-100 text-gray-800';
                                ?>">
                                <?php echo strtoupper(str_replace('_', ' ', $movement['transaction_type'])); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 font-medium">
                            <?php echo htmlspecialchars($movement['reference_no']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <?php if ($movement['quantity_in'] > 0): ?>
                                <span class="text-green-600 font-medium">+<?php echo number_format($movement['quantity_in'], 3); ?></span>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($movement['unit_symbol']); ?></div>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <?php if ($movement['quantity_out'] > 0): ?>
                                <span class="text-red-600 font-medium">-<?php echo number_format($movement['quantity_out'], 3); ?></span>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($movement['unit_symbol']); ?></div>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                            <?php echo number_format($movement['balance'], 3); ?>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($movement['unit_symbol']); ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (empty($recent_movements)): ?>
            <div class="text-center py-8">
                <div class="text-gray-400 text-lg">📋</div>
                <p class="text-gray-500 mt-2">No recent movements found</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
// Export functions
function exportToCSV() {
    let csvContent = "data:text/csv;charset=utf-8,";

    <?php if ($view_mode === 'detailed'): ?>
    csvContent += "Location,Item Name,Item Code,Type,Current Stock,Unit Cost,Stock Value,Status\n";
    <?php foreach ($detailed_data_by_loc as $locId => $rows): 
          $locName = addslashes($detailed_location_names[$locId] ?? ("Location #".$locId));
          foreach ($rows as $item): ?>
    csvContent += "<?php echo $locName; ?>,<?php echo addslashes($item['name']); ?>,<?php echo $item['code']; ?>,<?php echo $item['type']; ?>,<?php echo $item['current_stock']; ?>,<?php echo $item['cost_price']; ?>,<?php echo $item['stock_value']; ?>,<?php echo ($item['current_stock'] <= 10 ? 'Low Stock' : 'Normal'); ?>\n";
    <?php endforeach; endforeach; ?>

    <?php elseif ($view_mode === 'value_analysis'): ?>
    csvContent += "Item Name,Item Code,Type,Current Stock,Unit Cost,Stock Value,Status\n";
    <?php foreach ($stock_data as $item): ?>
    csvContent += "<?php echo addslashes($item['name']); ?>,<?php echo $item['code']; ?>,<?php echo $item['type']; ?>,<?php echo $item['current_stock']; ?>,<?php echo $item['cost_price']; ?>,<?php echo $item['stock_value']; ?>,<?php echo ($item['current_stock'] <= 10 ? 'Low Stock' : 'Normal'); ?>\n";
    <?php endforeach; ?>

    <?php elseif ($view_mode === 'movements'): ?>
    csvContent += "Date,Item Name,Item Code,Location,Type,Reference,Quantity In,Quantity Out,Balance,Unit\n";
    <?php foreach ($recent_movements as $movement): ?>
    csvContent += "<?php echo $movement['transaction_date']; ?>,<?php echo addslashes($movement['item_name']); ?>,<?php echo $movement['item_code']; ?>,<?php echo addslashes($movement['location_name']); ?>,<?php echo $movement['transaction_type']; ?>,<?php echo $movement['reference_no']; ?>,<?php echo $movement['quantity_in']; ?>,<?php echo $movement['quantity_out']; ?>,<?php echo $movement['balance']; ?>,<?php echo $movement['unit_symbol']; ?>\n";
    <?php endforeach; ?>

    <?php else: ?>
    csvContent += "Location,Total Items,Raw Materials,Semi-Finished,Finished Goods,Total Value\n";
    <?php foreach ($location_summary as $location): ?>
    csvContent += "<?php echo addslashes($location['name']); ?>,<?php echo $location['items']; ?>,<?php echo $location['raw']; ?>,<?php echo $location['semi_finished']; ?>,<?php echo $location['finished']; ?>,<?php echo $location['total_value']; ?>\n";
    <?php endforeach; ?>
    <?php endif; ?>

    // Add summary at the end
    csvContent += "\n--- SUMMARY ---\n";
    csvContent += "Total Stock Value,Rs. <?php echo number_format($summary_stats['total_stock_value'], 2); ?>\n";
    csvContent += "Total Items,<?php echo $summary_stats['total_items']; ?>\n";
    csvContent += "Total Quantity,<?php echo number_format($summary_stats['total_quantity'], 3); ?>\n";
    csvContent += "Low Stock Items,<?php echo $summary_stats['low_stock_items']; ?>\n";

    var encodedUri = encodeURI(csvContent);
    var link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "stock_report_with_values_<?php echo date('Y-m-d'); ?>.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Date filter functions for Production Report
function setDateRange(type) {
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    const today = new Date();
    
    if (type === 'today') {
        const todayStr = today.toISOString().split('T')[0];
        startDate.value = todayStr;
        endDate.value = todayStr;
    } else if (type === 'month') {
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        startDate.value = firstDay.toISOString().split('T')[0];
        endDate.value = lastDay.toISOString().split('T')[0];
    }
}

function applyDateFilter() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    
    if (!startDate || !endDate) {
        alert('Please select both start and end dates.');
        return;
    }
    
    if (startDate > endDate) {
        alert('Start date must be before or equal to end date.');
        return;
    }
    
    // Build URL with current filters and new dates
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('start_date', startDate);
    currentUrl.searchParams.set('end_date', endDate);
    
    // Redirect to updated URL
    window.location.href = currentUrl.toString();
}

// Print styles
window.addEventListener('beforeprint', function() {
    document.body.classList.add('print-mode');
});

window.addEventListener('afterprint', function() {
    document.body.classList.remove('print-mode');
});
</script>

<style>
@media print {
    .print-mode {
        -webkit-print-color-adjust: exact;
        color-adjust: exact;
    }
    .no-print { display: none !important; }
    .bg-gradient-to-r { background: #059669 !important; color: white !important; }
    .shadow-lg, .shadow { box-shadow: none !important; border: 1px solid #e5e7eb !important; }
    .rounded-lg, .rounded-2xl { border-radius: 8px !important; }
    body { font-size: 12px !important; }
    .text-4xl { font-size: 24px !important; }
    .text-2xl { font-size: 16px !important; }
    .text-lg { font-size: 14px !important; }
}
</style>

<?php include 'footer.php'; ?>
