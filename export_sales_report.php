<?php
/**
 * Export Sales Report - PDF and Excel (CSV)
 * Supports: total, customer, item reports
 */

require_once 'database.php';
require_once 'report_functions.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Initialize database
$database = new Database();
$db = $database->getConnection();

// Get parameters
$reportType = $_GET['report'] ?? 'total';
$format = $_GET['format'] ?? 'pdf';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$customerId = $_GET['customer_id'] ?? '';
$status = $_GET['status'] ?? 'confirmed';
$paymentStatus = $_GET['payment_status'] ?? '';

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    die('Invalid date_from');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    die('Invalid date_to');
}

// Build filters
$filters = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'customer_id' => $customerId,
    'status' => $status,
    'payment_status' => $paymentStatus
];

// Get report data
switch ($reportType) {
    case 'customer':
        $reportData = getCustomerWiseSalesReport($db, $filters);
        $reportTitle = 'Customer-wise Sales Report';
        $filename = 'customer_wise_sales_report';
        break;
    case 'item':
        $reportData = getItemWiseSalesReport($db, $filters);
        $reportTitle = 'Item-wise Sales Report';
        $filename = 'item_wise_sales_report';
        break;
    default:
        $reportData = getTotalSalesReport($db, $filters);
        $reportTitle = 'Total Sales Report';
        $filename = 'total_sales_report';
        break;
}

$filterSummary = getFilterSummary($filters, $db);
$filename .= '_' . date('Ymd', strtotime($dateFrom)) . '_' . date('Ymd', strtotime($dateTo));

// Export based on format
if ($format === 'excel') {
    exportToCSV($reportType, $reportData, $reportTitle, $filterSummary, $filename);
} else {
    exportToPDF($reportType, $reportData, $reportTitle, $filterSummary, $filename);
}

/**
 * Export to CSV (Excel compatible)
 */
function exportToCSV($reportType, $reportData, $reportTitle, $filterSummary, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Company Header
    fputcsv($output, ['Lakgovi ERP']);
    fputcsv($output, [$reportTitle]);
    fputcsv($output, [$filterSummary]);
    fputcsv($output, ['Generated: ' . date('d/m/Y H:i:s')]);
    fputcsv($output, []); // Empty row
    
    if ($reportType === 'total') {
        // Summary section
        $summary = $reportData['summary'];
        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Invoices', $summary['total_invoices']]);
        fputcsv($output, ['Total Sales', number_format($summary['total_sales'], 2)]);
        fputcsv($output, ['Total Paid', number_format($summary['total_paid'], 2)]);
        fputcsv($output, ['Total Balance', number_format($summary['total_balance'], 2)]);
        fputcsv($output, []);
        fputcsv($output, ['By Payment Status']);
        fputcsv($output, ['Paid', $summary['by_payment_status']['paid']['count'], number_format($summary['by_payment_status']['paid']['amount'], 2)]);
        fputcsv($output, ['Partial', $summary['by_payment_status']['partial']['count'], number_format($summary['by_payment_status']['partial']['amount'], 2)]);
        fputcsv($output, ['Unpaid', $summary['by_payment_status']['unpaid']['count'], number_format($summary['by_payment_status']['unpaid']['amount'], 2)]);
        fputcsv($output, []);
        
        // Detail table
        fputcsv($output, ['INVOICE DETAILS']);
        fputcsv($output, ['Invoice No', 'Date', 'Customer', 'Total Amount', 'Paid Amount', 'Balance', 'Payment Status']);
        
        foreach ($reportData['rows'] as $row) {
            fputcsv($output, [
                $row['invoice_no'],
                date('d/m/Y', strtotime($row['invoice_date'])),
                $row['customer_name'],
                number_format($row['total_amount'], 2),
                number_format($row['paid_amount'], 2),
                number_format($row['balance_amount'], 2),
                ucfirst($row['payment_status'])
            ]);
        }
        
        // Totals row
        fputcsv($output, []);
        fputcsv($output, [
            'TOTAL', '', '',
            number_format($summary['total_sales'], 2),
            number_format($summary['total_paid'], 2),
            number_format($summary['total_balance'], 2),
            ''
        ]);
        
    } elseif ($reportType === 'customer') {
        // Headers
        fputcsv($output, ['Customer Code', 'Customer Name', 'No. of Invoices', 'Total Sales', 'Total Paid', 'Total Balance']);
        
        foreach ($reportData['rows'] as $row) {
            fputcsv($output, [
                $row['customer_code'],
                $row['customer_name'],
                $row['invoices_count'],
                number_format($row['total_sales'], 2),
                number_format($row['total_paid'], 2),
                number_format($row['total_balance'], 2)
            ]);
        }
        
        // Totals
        $totals = $reportData['totals'];
        fputcsv($output, []);
        fputcsv($output, [
            'TOTAL', '',
            $totals['invoices_count'],
            number_format($totals['total_sales'], 2),
            number_format($totals['total_paid'], 2),
            number_format($totals['total_balance'], 2)
        ]);
        
    } else { // item
        // Headers
        fputcsv($output, ['Item Code', 'Item Name', 'Total Qty', 'Total Sales', 'Avg. Price', 'Invoices']);
        
        foreach ($reportData['rows'] as $row) {
            fputcsv($output, [
                $row['item_code'],
                $row['item_name'],
                number_format($row['total_qty'], 2),
                number_format($row['total_sales'], 2),
                number_format($row['avg_price'], 2),
                $row['invoices_count']
            ]);
        }
        
        // Totals
        $totals = $reportData['totals'];
        fputcsv($output, []);
        fputcsv($output, [
            'TOTAL', '',
            number_format($totals['total_qty'], 2),
            number_format($totals['total_sales'], 2),
            '-',
            $totals['invoices_count']
        ]);
    }
    
    fclose($output);
    exit;
}

/**
 * Export to PDF using Dompdf
 */
function exportToPDF($reportType, $reportData, $reportTitle, $filterSummary, $filename) {
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . htmlspecialchars($reportTitle) . '</title>
        <style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { font-size: 10px; margin: 20px; }
            .header { text-align: center; margin-bottom: 20px; }
            .header h1 { margin: 0; font-size: 18px; color: #333; }
            .header h2 { margin: 5px 0; font-size: 14px; color: #666; }
            .header p { margin: 3px 0; font-size: 9px; color: #888; }
            .summary-cards { margin-bottom: 20px; }
            .summary-card { display: inline-block; width: 23%; background: #f8f9fa; border: 1px solid #ddd; padding: 10px; margin: 0 1%; text-align: center; box-sizing: border-box; }
            .summary-card .label { font-size: 9px; color: #666; }
            .summary-card .value { font-size: 14px; font-weight: bold; color: #333; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th { background: #3B82F6; color: white; padding: 8px 5px; text-align: left; font-size: 9px; }
            th.right { text-align: right; }
            th.center { text-align: center; }
            td { padding: 6px 5px; border-bottom: 1px solid #eee; font-size: 9px; }
            td.right { text-align: right; }
            td.center { text-align: center; }
            tr:nth-child(even) { background: #f9f9f9; }
            .totals-row td { font-weight: bold; background: #e5e7eb; border-top: 2px solid #333; }
            .status-paid { background: #dcfce7; color: #166534; padding: 2px 6px; border-radius: 10px; font-size: 8px; }
            .status-partial { background: #fef9c3; color: #854d0e; padding: 2px 6px; border-radius: 10px; font-size: 8px; }
            .status-unpaid { background: #fee2e2; color: #991b1b; padding: 2px 6px; border-radius: 10px; font-size: 8px; }
            .summary-table { width: 100%; margin-bottom: 20px; }
            .summary-table td { padding: 5px 10px; }
            .summary-section { margin-bottom: 20px; }
            .summary-section h3 { margin: 0 0 10px 0; font-size: 12px; color: #333; border-bottom: 2px solid #3B82F6; padding-bottom: 5px; }
            .payment-status-box { display: inline-block; width: 30%; background: #f0f0f0; padding: 8px; margin: 0 1%; text-align: center; border-radius: 5px; }
            .payment-status-box.paid { background: #dcfce7; }
            .payment-status-box.partial { background: #fef9c3; }
            .payment-status-box.unpaid { background: #fee2e2; }
        </style>
    </head>
    <body>';
    
    // Header
    $html .= '<div class="header">
        <h1>Lakgovi ERP</h1>
        <h2>' . htmlspecialchars($reportTitle) . '</h2>
        <p>' . htmlspecialchars($filterSummary) . '</p>
        <p>Generated: ' . date('d/m/Y H:i:s') . '</p>
    </div>';
    
    if ($reportType === 'total') {
        $summary = $reportData['summary'];
        
        // Summary section
        $html .= '<div class="summary-section">
            <h3>Summary</h3>
            <table class="summary-table" style="width: 100%;">
                <tr>
                    <td style="width: 25%; background: #eff6ff; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Total Invoices</div>
                        <div style="font-size: 16px; font-weight: bold;">' . number_format($summary['total_invoices']) . '</div>
                    </td>
                    <td style="width: 25%; background: #f0fdf4; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Total Sales</div>
                        <div style="font-size: 16px; font-weight: bold;">Rs. ' . number_format($summary['total_sales'], 2) . '</div>
                    </td>
                    <td style="width: 25%; background: #faf5ff; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Total Paid</div>
                        <div style="font-size: 16px; font-weight: bold;">Rs. ' . number_format($summary['total_paid'], 2) . '</div>
                    </td>
                    <td style="width: 25%; background: #fef2f2; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Total Balance</div>
                        <div style="font-size: 16px; font-weight: bold;">Rs. ' . number_format($summary['total_balance'], 2) . '</div>
                    </td>
                </tr>
            </table>
        </div>';
        
        // Payment status breakdown
        $html .= '<div class="summary-section">
            <h3>By Payment Status</h3>
            <table style="width: 100%;">
                <tr>
                    <td style="width: 33%; background: #dcfce7; padding: 8px; text-align: center;">
                        <div style="font-weight: bold;">Paid</div>
                        <div>' . $summary['by_payment_status']['paid']['count'] . ' invoices</div>
                        <div>Rs. ' . number_format($summary['by_payment_status']['paid']['amount'], 2) . '</div>
                    </td>
                    <td style="width: 33%; background: #fef9c3; padding: 8px; text-align: center;">
                        <div style="font-weight: bold;">Partial</div>
                        <div>' . $summary['by_payment_status']['partial']['count'] . ' invoices</div>
                        <div>Rs. ' . number_format($summary['by_payment_status']['partial']['amount'], 2) . '</div>
                    </td>
                    <td style="width: 33%; background: #fee2e2; padding: 8px; text-align: center;">
                        <div style="font-weight: bold;">Unpaid</div>
                        <div>' . $summary['by_payment_status']['unpaid']['count'] . ' invoices</div>
                        <div>Rs. ' . number_format($summary['by_payment_status']['unpaid']['amount'], 2) . '</div>
                    </td>
                </tr>
            </table>
        </div>';
        
        // Invoice details table
        $html .= '<div class="summary-section">
            <h3>Invoice Details (' . count($reportData['rows']) . ' records)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Invoice No</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th class="right">Total Amount</th>
                        <th class="right">Paid Amount</th>
                        <th class="right">Balance</th>
                        <th class="center">Status</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($reportData['rows'] as $row) {
            $statusClass = $row['payment_status'] === 'paid' ? 'status-paid' : 
                          ($row['payment_status'] === 'partial' ? 'status-partial' : 'status-unpaid');
            
            $html .= '<tr>
                <td>' . htmlspecialchars($row['invoice_no']) . '</td>
                <td>' . date('d/m/Y', strtotime($row['invoice_date'])) . '</td>
                <td>' . htmlspecialchars($row['customer_name']) . '</td>
                <td class="right">' . number_format($row['total_amount'], 2) . '</td>
                <td class="right">' . number_format($row['paid_amount'], 2) . '</td>
                <td class="right">' . number_format($row['balance_amount'], 2) . '</td>
                <td class="center"><span class="' . $statusClass . '">' . ucfirst($row['payment_status']) . '</span></td>
            </tr>';
        }
        
        $html .= '<tr class="totals-row">
                <td colspan="3"><strong>TOTAL</strong></td>
                <td class="right">' . number_format($summary['total_sales'], 2) . '</td>
                <td class="right">' . number_format($summary['total_paid'], 2) . '</td>
                <td class="right">' . number_format($summary['total_balance'], 2) . '</td>
                <td></td>
            </tr>';
        
        $html .= '</tbody></table></div>';
        
    } elseif ($reportType === 'customer') {
        $totals = $reportData['totals'];
        
        // Summary
        $html .= '<div class="summary-section">
            <h3>Summary</h3>
            <table style="width: 100%;">
                <tr>
                    <td style="width: 25%; background: #eff6ff; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Total Customers</div>
                        <div style="font-size: 16px; font-weight: bold;">' . count($reportData['rows']) . '</div>
                    </td>
                    <td style="width: 25%; background: #f0fdf4; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Total Invoices</div>
                        <div style="font-size: 16px; font-weight: bold;">' . number_format($totals['invoices_count']) . '</div>
                    </td>
                    <td style="width: 25%; background: #faf5ff; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Total Sales</div>
                        <div style="font-size: 16px; font-weight: bold;">Rs. ' . number_format($totals['total_sales'], 2) . '</div>
                    </td>
                    <td style="width: 25%; background: #fef2f2; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Total Balance</div>
                        <div style="font-size: 16px; font-weight: bold;">Rs. ' . number_format($totals['total_balance'], 2) . '</div>
                    </td>
                </tr>
            </table>
        </div>';
        
        $html .= '<div class="summary-section">
            <h3>Customer-wise Breakdown</h3>
            <table>
                <thead>
                    <tr>
                        <th>Customer Code</th>
                        <th>Customer Name</th>
                        <th class="center">Invoices</th>
                        <th class="right">Total Sales</th>
                        <th class="right">Total Paid</th>
                        <th class="right">Balance</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($reportData['rows'] as $row) {
            $html .= '<tr>
                <td>' . htmlspecialchars($row['customer_code']) . '</td>
                <td>' . htmlspecialchars($row['customer_name']) . '</td>
                <td class="center">' . $row['invoices_count'] . '</td>
                <td class="right">' . number_format($row['total_sales'], 2) . '</td>
                <td class="right">' . number_format($row['total_paid'], 2) . '</td>
                <td class="right">' . number_format($row['total_balance'], 2) . '</td>
            </tr>';
        }
        
        $html .= '<tr class="totals-row">
                <td colspan="2"><strong>TOTAL</strong></td>
                <td class="center">' . $totals['invoices_count'] . '</td>
                <td class="right">' . number_format($totals['total_sales'], 2) . '</td>
                <td class="right">' . number_format($totals['total_paid'], 2) . '</td>
                <td class="right">' . number_format($totals['total_balance'], 2) . '</td>
            </tr>';
        
        $html .= '</tbody></table></div>';
        
    } else { // item
        $totals = $reportData['totals'];
        
        // Summary
        $html .= '<div class="summary-section">
            <h3>Summary</h3>
            <table style="width: 100%;">
                <tr>
                    <td style="width: 25%; background: #eff6ff; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Items Sold</div>
                        <div style="font-size: 16px; font-weight: bold;">' . count($reportData['rows']) . '</div>
                    </td>
                    <td style="width: 25%; background: #f0fdf4; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Total Quantity</div>
                        <div style="font-size: 16px; font-weight: bold;">' . number_format($totals['total_qty'], 2) . '</div>
                    </td>
                    <td style="width: 25%; background: #faf5ff; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Total Sales</div>
                        <div style="font-size: 16px; font-weight: bold;">Rs. ' . number_format($totals['total_sales'], 2) . '</div>
                    </td>
                    <td style="width: 25%; background: #fef2f2; padding: 10px; text-align: center;">
                        <div style="font-size: 9px; color: #666;">Invoices</div>
                        <div style="font-size: 16px; font-weight: bold;">' . number_format($totals['invoices_count']) . '</div>
                    </td>
                </tr>
            </table>
        </div>';
        
        $html .= '<div class="summary-section">
            <h3>Item-wise Breakdown</h3>
            <table>
                <thead>
                    <tr>
                        <th>Item Code</th>
                        <th>Item Name</th>
                        <th class="right">Total Qty</th>
                        <th class="right">Total Sales</th>
                        <th class="right">Avg. Price</th>
                        <th class="center">Invoices</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($reportData['rows'] as $row) {
            $html .= '<tr>
                <td>' . htmlspecialchars($row['item_code']) . '</td>
                <td>' . htmlspecialchars($row['item_name']) . '</td>
                <td class="right">' . number_format($row['total_qty'], 2) . '</td>
                <td class="right">' . number_format($row['total_sales'], 2) . '</td>
                <td class="right">' . number_format($row['avg_price'], 2) . '</td>
                <td class="center">' . $row['invoices_count'] . '</td>
            </tr>';
        }
        
        $html .= '<tr class="totals-row">
                <td colspan="2"><strong>TOTAL</strong></td>
                <td class="right">' . number_format($totals['total_qty'], 2) . '</td>
                <td class="right">' . number_format($totals['total_sales'], 2) . '</td>
                <td class="right">-</td>
                <td class="center">' . $totals['invoices_count'] . '</td>
            </tr>';
        
        $html .= '</tbody></table></div>';
    }
    
    $html .= '</body></html>';
    
    // Generate PDF
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Output PDF
    $dompdf->stream($filename . '.pdf', ['Attachment' => true]);
    exit;
}
?>
