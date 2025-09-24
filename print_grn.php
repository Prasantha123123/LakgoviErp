<?php
// print_grn.php - Print-friendly GRN view
require_once 'database.php';
require_once 'config/simple_auth.php';

$grn_id = $_GET['grn_id'] ?? null;
if (!$grn_id) {
    die('GRN ID is required');
}

// Fetch GRN details (reuse the enhanced query from get_grn_details.php)
try {
    $stmt = $db->prepare("
        SELECT g.*, 
               s.name as supplier_name, 
               s.code as supplier_code,
               s.contact as supplier_contact,
               s.address as supplier_address,
               po.po_no, 
               po.status as po_status,
               po.po_date,
               u.username as created_by_name
        FROM grn g
        LEFT JOIN suppliers s ON g.supplier_id = s.id
        LEFT JOIN purchase_orders po ON g.po_id = po.id
        LEFT JOIN admin_users u ON g.created_by = u.id
        WHERE g.id = ?
    ");
    $stmt->execute([$grn_id]);
    $grn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$grn) {
        die('GRN not found');
    }
    
    // Get GRN items
    $stmt = $db->prepare("
        SELECT gi.*, 
               i.name as item_name, 
               i.code as item_code, 
               i.type as item_type,
               u.symbol as unit_symbol,
               l.name as location_name,
               (gi.quantity * gi.rate) as line_total
        FROM grn_items gi
        JOIN items i ON gi.item_id = i.id
        JOIN units u ON i.unit_id = u.id
        JOIN locations l ON gi.location_id = l.id
        WHERE gi.grn_id = ?
        ORDER BY gi.id
    ");
    $stmt->execute([$grn_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

// Helper functions
function formatCurrency($amount) {
    return 'රු.' . number_format($amount, 2);
}

function formatDate($date) {
    return date('d M Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GRN <?php echo htmlspecialchars($grn['grn_no']); ?> - Print View</title>
    <style>
        /* Print-optimized styles */
        @media print {
            @page {
                margin: 0.5in;
                size: A4;
            }
            
            .no-print {
                display: none !important;
            }
            
            body {
                font-size: 12px;
                line-height: 1.4;
                color: #000;
                background: #fff;
            }
            
            .page-break {
                page-break-before: always;
            }
        }
        
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #fff;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .document-title {
            font-size: 18px;
            font-weight: bold;
            margin-top: 15px;
        }
        
        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .info-box {
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
        }
        
        .info-box h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 13px;
        }
        
        .info-label {
            font-weight: bold;
            color: #666;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 12px;
        }
        
        .items-table th,
        .items-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .items-table th {
            background-color: #f5f5f5;
            font-weight: bold;
            text-align: center;
        }
        
        .items-table .text-right {
            text-align: right;
        }
        
        .items-table .text-center {
            text-align: center;
        }
        
        .total-row {
            background-color: #f9f9f9;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 40px;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 30px;
            font-size: 12px;
        }
        
        .signature-box {
            border-top: 1px solid #333;
            padding-top: 10px;
            text-align: center;
        }
        
        .print-info {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-completed {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #3b82f6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .print-button:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <!-- Print Button -->
    <button class="print-button no-print" onclick="window.print()">🖨️ Print</button>
    
    <!-- Header -->
    <div class="header">
        <div class="company-name">Your Company Name</div>
        <div style="font-size: 14px; color: #666;">Factory ERP System</div>
        <div class="document-title">GOODS RECEIPT NOTE</div>
    </div>
    
    <!-- GRN Information -->
    <div class="info-section">
        <div class="info-box">
            <h3>GRN Information</h3>
            <div class="info-row">
                <span class="info-label">GRN Number:</span>
                <span><?php echo htmlspecialchars($grn['grn_no']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date:</span>
                <span><?php echo formatDate($grn['grn_date']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Status:</span>
                <span class="status-badge status-<?php echo $grn['status']; ?>">
                    <?php echo ucfirst($grn['status']); ?>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Created By:</span>
                <span><?php echo htmlspecialchars($grn['created_by_name'] ?? 'N/A'); ?></span>
            </div>
            <?php if ($grn['po_no']): ?>
            <div class="info-row">
                <span class="info-label">PO Number:</span>
                <span><?php echo htmlspecialchars($grn['po_no']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="info-box">
            <h3>Supplier Information</h3>
            <div class="info-row">
                <span class="info-label">Name:</span>
                <span><?php echo htmlspecialchars($grn['supplier_name'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Code:</span>
                <span><?php echo htmlspecialchars($grn['supplier_code'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Contact:</span>
                <span><?php echo htmlspecialchars($grn['supplier_contact'] ?? 'N/A'); ?></span>
            </div>
            <?php if ($grn['supplier_address']): ?>
            <div class="info-row">
                <span class="info-label">Address:</span>
                <span><?php echo htmlspecialchars($grn['supplier_address']); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th>S.No</th>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Location</th>
                <th class="text-right">Quantity</th>
                <th>Unit</th>
                <th class="text-right">Rate</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $serial = 1;
            $total_amount = 0;
            foreach ($items as $item): 
                $total_amount += $item['line_total'];
            ?>
            <tr>
                <td class="text-center"><?php echo $serial++; ?></td>
                <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                <td><?php echo htmlspecialchars($item['location_name']); ?></td>
                <td class="text-right"><?php echo number_format($item['quantity'], 3); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($item['unit_symbol']); ?></td>
                <td class="text-right"><?php echo formatCurrency($item['rate']); ?></td>
                <td class="text-right"><?php echo formatCurrency($item['line_total']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="7" class="text-right"><strong>TOTAL AMOUNT:</strong></td>
                <td class="text-right"><strong><?php echo formatCurrency($total_amount); ?></strong></td>
            </tr>
        </tfoot>
    </table>
    
    <!-- Notes -->
    <?php if ($grn['notes']): ?>
    <div style="margin-bottom: 20px;">
        <h3 style="margin-bottom: 10px; font-size: 14px;">Notes:</h3>
        <div style="border: 1px solid #ddd; padding: 10px; background: #f9f9f9; border-radius: 3px;">
            <?php echo nl2br(htmlspecialchars($grn['notes'])); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Signatures -->
    <div class="footer">
        <div class="signature-box">
            <div>Received By</div>
            <div style="margin-top: 20px;">____________________</div>
            <div style="margin-top: 5px; font-size: 10px;">Name & Signature</div>
        </div>
        
        <div class="signature-box">
            <div>Checked By</div>
            <div style="margin-top: 20px;">____________________</div>
            <div style="margin-top: 5px; font-size: 10px;">Name & Signature</div>
        </div>
        
        <div class="signature-box">
            <div>Approved By</div>
            <div style="margin-top: 20px;">____________________</div>
            <div style="margin-top: 5px; font-size: 10px;">Name & Signature</div>
        </div>
    </div>
    
    <!-- Print Information -->
    <div class="print-info">
        Printed on: <?php echo date('d M Y H:i:s'); ?> | 
        Total Items: <?php echo count($items); ?> | 
        Total Amount: <?php echo formatCurrency($total_amount); ?>
    </div>
    
    <script>
        // Auto-print when page loads (optional)
        // window.onload = function() {
        //     window.print();
        // };
        
        // Close window after printing
        window.onafterprint = function() {
            // window.close();
        };
    </script>
</body>
</html>