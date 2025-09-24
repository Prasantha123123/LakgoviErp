<?php
// dashboard.php - Main dashboard with overview stats
include 'header.php';

// Get dashboard statistics
try {
    // Count totals
    $stats = [];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM items");
    $stats['total_items'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM suppliers");
    $stats['total_suppliers'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM grn WHERE status = 'pending'");
    $stats['pending_grn'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM production WHERE status IN ('planned', 'in_progress')");
    $stats['active_production'] = $stmt->fetch()['total'];
    
    // Get low stock items
    $stmt = $db->query("
        SELECT i.name, i.current_stock, u.symbol, 
               (SELECT SUM(quantity_in - quantity_out) FROM stock_ledger WHERE item_id = i.id) as ledger_stock
        FROM items i 
        JOIN units u ON i.unit_id = u.id 
        WHERE i.current_stock < 50 
        ORDER BY i.current_stock ASC 
        LIMIT 10
    ");
    $low_stock_items = $stmt->fetchAll();
    
    // Get recent transactions
    $stmt = $db->query("
        SELECT sl.transaction_type, sl.reference_no, sl.transaction_date, sl.quantity_in, sl.quantity_out,
               i.name as item_name, l.name as location_name
        FROM stock_ledger sl
        JOIN items i ON sl.item_id = i.id
        JOIN locations l ON sl.location_id = l.id
        ORDER BY sl.created_at DESC
        LIMIT 10
    ");
    $recent_transactions = $stmt->fetchAll();

} catch(PDOException $e) {
    $error = "Error fetching dashboard data: " . $e->getMessage();
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Dashboard</h1>
        <p class="text-gray-600">Welcome to Factory ERP System - Overview of your operations</p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-lg shadow hover:shadow-md transition-shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Total Items</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats['total_items']; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow hover:shadow-md transition-shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Total Suppliers</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats['total_suppliers']; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow hover:shadow-md transition-shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Pending GRN</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats['pending_grn']; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow hover:shadow-md transition-shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Active Production</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats['active_production']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Low Stock Alert -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-900">Low Stock Alert</h2>
                    <span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded">
                        <?php echo count($low_stock_items); ?> items
                    </span>
                </div>
                <div class="space-y-3">
                    <?php if (empty($low_stock_items)): ?>
                        <p class="text-gray-500 text-center py-4">All items are well stocked!</p>
                    <?php else: ?>
                        <?php foreach ($low_stock_items as $item): ?>
                            <div class="flex justify-between items-center p-3 bg-red-50 rounded-md">
                                <div>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($item['name']); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm text-red-600 font-medium">
                                        <?php echo number_format($item['current_stock'], 2); ?> <?php echo $item['symbol']; ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="pt-2">
                            <a href="items.php" class="text-sm text-primary hover:underline">View all items →</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-900">Recent Transactions</h2>
                </div>
                <div class="space-y-3">
                    <?php if (empty($recent_transactions)): ?>
                        <p class="text-gray-500 text-center py-4">No transactions yet.</p>
                    <?php else: ?>
                        <?php foreach ($recent_transactions as $trans): ?>
                            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-md">
                                <div>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($trans['item_name']); ?></p>
                                    <p class="text-sm text-gray-600">
                                        <?php echo strtoupper($trans['transaction_type']); ?> - <?php echo $trans['reference_no']; ?>
                                    </p>
                                    <p class="text-xs text-gray-500"><?php echo $trans['location_name']; ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-medium <?php echo $trans['quantity_in'] > 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php 
                                        if ($trans['quantity_in'] > 0) {
                                            echo '+' . number_format($trans['quantity_in'], 2);
                                        } else {
                                            echo '-' . number_format($trans['quantity_out'], 2);
                                        }
                                        ?>
                                    </p>
                                    <p class="text-xs text-gray-500"><?php echo $trans['transaction_date']; ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="pt-2">
                            <a href="stock_ledger.php" class="text-sm text-primary hover:underline">View full ledger →</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            <a href="grn.php" class="flex flex-col items-center p-4 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors">
                <svg class="w-8 h-8 text-blue-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
                <span class="text-sm font-medium text-gray-700">New GRN</span>
            </a>
            <a href="mrn.php" class="flex flex-col items-center p-4 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
                <svg class="w-8 h-8 text-red-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <span class="text-sm font-medium text-gray-700">New MRN</span>
            </a>
            <a href="production.php" class="flex flex-col items-center p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors">
                <svg class="w-8 h-8 text-purple-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span class="text-sm font-medium text-gray-700">Production</span>
            </a>
            <a href="items.php" class="flex flex-col items-center p-4 bg-green-50 rounded-lg hover:bg-green-100 transition-colors">
                <svg class="w-8 h-8 text-green-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                <span class="text-sm font-medium text-gray-700">Items</span>
            </a>
            <a href="bom.php" class="flex flex-col items-center p-4 bg-yellow-50 rounded-lg hover:bg-yellow-100 transition-colors">
                <svg class="w-8 h-8 text-yellow-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span class="text-sm font-medium text-gray-700">BOM</span>
            </a>
            <a href="stock_ledger.php" class="flex flex-col items-center p-4 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition-colors">
                <svg class="w-8 h-8 text-indigo-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span class="text-sm font-medium text-gray-700">Stock Report</span>
            </a>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>