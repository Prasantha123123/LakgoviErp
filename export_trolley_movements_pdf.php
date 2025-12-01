<?php
/**
 * export_trolley_movements_pdf.php
 * 
 * Generates a comprehensive PDF report of trolley movements with optional filtering.
 * Supports filters: from_date, to_date, status, trolley_no
 * 
 * Usage: GET export_trolley_movements_pdf.php?from_date=2024-01-01&to_date=2024-01-31&status=completed
 * Output: PDF file download
 */

require_once 'database.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // ========================================================================
    // Build Query Filters
    // ========================================================================
    $filters = [];
    $filterParams = [];
    $filterDisplay = [];
    
    if (!empty($_GET['from_date'])) {
        $filters[] = "DATE(tm.movement_date) >= ?";
        $filterParams[] = $_GET['from_date'];
        $filterDisplay[] = "From: " . date('d M Y', strtotime($_GET['from_date']));
    }
    if (!empty($_GET['to_date'])) {
        $filters[] = "DATE(tm.movement_date) <= ?";
        $filterParams[] = $_GET['to_date'];
        $filterDisplay[] = "To: " . date('d M Y', strtotime($_GET['to_date']));
    }
    if (!empty($_GET['status'])) {
        $filters[] = "tm.status = ?";
        $filterParams[] = $_GET['status'];
        $filterDisplay[] = "Status: " . ucfirst(str_replace('_', ' ', $_GET['status']));
    }
    if (!empty($_GET['trolley_no'])) {
        $filters[] = "t.trolley_no LIKE ?";
        $filterParams[] = '%' . $_GET['trolley_no'] . '%';
        $filterDisplay[] = "Trolley: " . $_GET['trolley_no'];
    }
    
    $whereClause = !empty($filters) ? "WHERE " . implode(" AND ", $filters) : "";
    
    // ========================================================================
    // Fetch All Movements (No Pagination for PDF)
    // ========================================================================
    $stmt = $db->prepare("
        SELECT tm.id, tm.movement_no, tm.trolley_id, tm.production_id,
               tm.from_location_id, tm.to_location_id, tm.movement_date,
               tm.expected_weight_kg, tm.actual_weight_kg,
               tm.expected_units, tm.actual_units,
               tm.status, tm.verified_at, tm.created_at,
               t.trolley_no, t.trolley_name,
               fl.name as from_location, tl.name as to_location,
               COALESCE(SUM(ti.expected_quantity), 0) as total_expected_qty,
               COALESCE(SUM(ti.actual_quantity), 0) as total_actual_qty
        FROM trolley_movements tm
        JOIN trolleys t ON tm.trolley_id = t.id
        JOIN locations fl ON tm.from_location_id = fl.id
        JOIN locations tl ON tm.to_location_id = tl.id
        LEFT JOIN trolley_items ti ON tm.id = ti.movement_id
        $whereClause
        GROUP BY tm.id, tm.movement_no, tm.trolley_id, tm.production_id,
                 tm.from_location_id, tm.to_location_id, tm.movement_date,
                 tm.expected_weight_kg, tm.actual_weight_kg,
                 tm.expected_units, tm.actual_units,
                 tm.status, tm.verified_at, tm.created_at,
                 t.trolley_no, t.trolley_name,
                 fl.name, tl.name
        ORDER BY tm.movement_date DESC, tm.created_at DESC
    ");
    $stmt->execute($filterParams);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ========================================================================
    // Build HTML Content for PDF
    // ========================================================================
    $html = '<?xml version="1.0" encoding="UTF-8"?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            * {
                margin: 0;
                padding: 0;
            }
            body {
                font-family: Arial, sans-serif;
                font-size: 11px;
                color: #333;
                background: white;
            }
            .page-header {
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid #2563eb;
            }
            .report-title {
                font-size: 24px;
                font-weight: bold;
                color: #1e40af;
                margin-bottom: 5px;
            }
            .report-subtitle {
                font-size: 12px;
                color: #666;
                margin-bottom: 10px;
            }
            .filter-info {
                background: #f0f9ff;
                border: 1px solid #bfdbfe;
                padding: 8px 12px;
                border-radius: 4px;
                margin-bottom: 15px;
                font-size: 10px;
            }
            .filter-info strong {
                color: #1e40af;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            table.movements-table {
                margin-top: 15px;
            }
            th {
                background: #f3f4f6;
                color: #1f2937;
                font-weight: bold;
                padding: 8px;
                text-align: left;
                border: 1px solid #d1d5db;
                font-size: 10px;
            }
            td {
                padding: 8px;
                border: 1px solid #e5e7eb;
                vertical-align: top;
            }
            tr:nth-child(even) {
                background: #f9fafb;
            }
            tr.movement-row:hover {
                background: #f0f9ff;
            }
            .status-completed {
                background: #dcfce7;
                color: #166534;
                padding: 2px 6px;
                border-radius: 3px;
                font-weight: bold;
            }
            .status-pending {
                background: #fef3c7;
                color: #92400e;
                padding: 2px 6px;
                border-radius: 3px;
                font-weight: bold;
            }
            .status-in_transit {
                background: #e9d5ff;
                color: #6b21a8;
                padding: 2px 6px;
                border-radius: 3px;
                font-weight: bold;
            }
            .status-verified {
                background: #dbeafe;
                color: #1e40af;
                padding: 2px 6px;
                border-radius: 3px;
                font-weight: bold;
            }
            .status-rejected {
                background: #fee2e2;
                color: #991b1b;
                padding: 2px 6px;
                border-radius: 3px;
                font-weight: bold;
            }
            .number-right {
                text-align: right;
            }
            .summary-row {
                background: #f3f4f6;
                font-weight: bold;
            }
            .page-footer {
                margin-top: 20px;
                padding-top: 10px;
                border-top: 1px solid #d1d5db;
                text-align: center;
                font-size: 9px;
                color: #666;
            }
            .page-break {
                page-break-after: always;
            }
        </style>
    </head>
    <body>';
    
    // Header
    $html .= '
    <div class="page-header">
        <div class="report-title">📋 Trolley Movements Report</div>
        <div class="report-subtitle">Generated on ' . date('d M Y H:i:s') . '</div>';
    
    if (!empty($filterDisplay)) {
        $html .= '<div class="filter-info">';
        $html .= '<strong>Applied Filters:</strong> ' . implode(' | ', $filterDisplay);
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    // Summary Statistics
    $totalMovements = count($movements);
    $totalExpectedQty = array_sum(array_column($movements, 'total_expected_qty'));
    $totalActualQty = array_sum(array_column($movements, 'total_actual_qty'));
    $totalExpectedWeight = array_sum(array_column($movements, 'expected_weight_kg'));
    $totalActualWeight = array_sum(array_column($movements, 'actual_weight_kg'));
    
    $html .= '
    <table>
        <tr class="summary-row">
            <td>Total Movements: <strong>' . $totalMovements . '</strong></td>
            <td>Total Expected Qty: <strong>' . number_format($totalExpectedQty, 3) . '</strong> units</td>
            <td>Total Actual Qty: <strong>' . number_format($totalActualQty, 3) . '</strong> units</td>
            <td>Expected Weight: <strong>' . number_format($totalExpectedWeight, 2) . '</strong> kg</td>
            <td>Actual Weight: <strong>' . number_format($totalActualWeight, 2) . '</strong> kg</td>
        </tr>
    </table>';
    
    // Movements Table
    $html .= '
    <table class="movements-table">
        <thead>
            <tr>
                <th>Movement #</th>
                <th>Date & Time</th>
                <th>Trolley</th>
                <th>From → To</th>
                <th>Status</th>
                <th class="number-right">Expected Qty</th>
                <th class="number-right">Actual Qty</th>
                <th class="number-right">Expected Wt (kg)</th>
                <th class="number-right">Actual Wt (kg)</th>
            </tr>
        </thead>
        <tbody>';
    
    if (empty($movements)) {
        $html .= '
        <tr>
            <td colspan="9" style="text-align: center; padding: 15px;">
                No movements found matching the selected filters
            </td>
        </tr>';
    } else {
        foreach ($movements as $movement) {
            $statusClass = 'status-' . str_replace(' ', '_', $movement['status']);
            $statusLabel = ucfirst(str_replace('_', ' ', $movement['status']));
            $moveDate = date('d M Y H:i', strtotime($movement['movement_date']));
            $expectedQty = number_format($movement['total_expected_qty'] ?? 0, 3);
            $actualQty = number_format($movement['total_actual_qty'] ?? 0, 3);
            $expectedWeight = number_format($movement['expected_weight_kg'] ?? 0, 2);
            $actualWeight = number_format($movement['actual_weight_kg'] ?? 0, 2);
            
            $html .= '
        <tr class="movement-row">
            <td>' . htmlspecialchars($movement['movement_no']) . '</td>
            <td>' . $moveDate . '</td>
            <td>' . htmlspecialchars($movement['trolley_no']) . '</td>
            <td>' . htmlspecialchars($movement['from_location']) . ' → ' . htmlspecialchars($movement['to_location']) . '</td>
            <td><span class="' . $statusClass . '">' . $statusLabel . '</span></td>
            <td class="number-right">' . $expectedQty . '</td>
            <td class="number-right">' . ($movement['actual_units'] ? $actualQty : '—') . '</td>
            <td class="number-right">' . $expectedWeight . '</td>
            <td class="number-right">' . ($movement['actual_weight_kg'] ? $actualWeight : '—') . '</td>
        </tr>';
        }
    }
    
    $html .= '
        </tbody>
    </table>';
    
    // Summary Totals Row
    $html .= '
    <table>
        <tr class="summary-row">
            <td colspan="5" style="text-align: right;"><strong>TOTALS:</strong></td>
            <td class="number-right"><strong>' . number_format($totalExpectedQty, 3) . '</strong></td>
            <td class="number-right"><strong>' . number_format($totalActualQty, 3) . '</strong></td>
            <td class="number-right"><strong>' . number_format($totalExpectedWeight, 2) . '</strong></td>
            <td class="number-right"><strong>' . number_format($totalActualWeight, 2) . '</strong></td>
        </tr>
    </table>';
    
    // Footer
    $html .= '
    <div class="page-footer">
        <p>This is an automatically generated report. Please verify accuracy before using for official purposes.</p>
    </div>
    </body>
    </html>';
    
    // ========================================================================
    // Generate PDF using DOMPDF
    // ========================================================================
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isFontSubsettingEnabled', true);
    $options->set('defaultFont', 'Arial');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    
    // Output PDF
    $filename = 'trolley_movements_' . date('Y-m-d_His') . '.pdf';
    $dompdf->stream($filename, array("Attachment" => false));
    
} catch (Exception $e) {
    header('Content-Type: text/plain');
    http_response_code(500);
    echo "Error generating PDF report:\n\n";
    echo $e->getMessage() . "\n\n";
    echo $e->getTraceAsString();
}
?>
