<?php
// stock_ledger.php - Stock Ledger and Reports
include 'header.php';

// Get filters
$item_filter = $_GET['item'] ?? '';
$location_filter = $_GET['location'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$transaction_type_filter = $_GET['transaction_type'] ?? '';

// Build WHERE clause for filters
$where_conditions = [];
$params = [];

if ($item_filter) {
    $where_conditions[] = "sl.item_id = ?";
    $params[] = $item_filter;
}

if ($location_filter) {
    $where_conditions[] = "sl.location_id = ?";
    $params[] = $location_filter;
}

if ($date_from) {
    $where_conditions[] = "sl.transaction_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "sl.transaction_date <= ?";
    $params[] = $date_to;
}

if ($transaction_type_filter) {
    $where_conditions[] = "sl.transaction_type = ?";
    $params[] = $transaction_type_filter;
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// Fetch stock ledger entries
try {
    $stmt = $db->prepare("
        SELECT sl.*, i.name as item_name, i.code as item_code, u.symbol as unit_symbol,
               l.name as location_name
        FROM stock_ledger sl
        JOIN items i ON sl.item_id = i.id
        JOIN units u ON i.unit_id = u.id
        JOIN locations l ON sl.location_id = l.id
        $where_clause
        ORDER BY sl.transaction_date DESC, sl.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $ledger_entries = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching stock ledger: " . $e->getMessage();
}

// Fetch current stock summary
try {
    $stmt = $db->query("
        SELECT i.id, i.name, i.code, i.type, u.symbol, 
               i.current_stock,
               COALESCE(SUM(sl.quantity_in - sl.quantity_out), 0) as ledger_stock,
               COUNT(sl.id) as transaction_count
        FROM items i
        LEFT JOIN units u ON i.unit_id = u.id
        LEFT JOIN stock_ledger sl ON i.id = sl.item_id
        GROUP BY i.id, i.name, i.code, i.type, u.symbol, i.current_stock
        ORDER BY i.name
    ");
    $stock_summary = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching stock summary: " . $e->getMessage();
}

// Fetch items for filter dropdown
try {
    $stmt = $db->query("SELECT id, name, code FROM items ORDER BY name");
    $items = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

// Fetch locations for filter dropdown
try {
    $stmt = $db->query("SELECT id, name FROM locations ORDER BY name");
    $locations = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching locations: " . $e->getMessage();
}

// Get view mode
$view_mode = $_GET['view'] ?? 'ledger';
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Stock Ledger & Reports</h1>
            <p class="text-gray-600">Monitor stock movements and current inventory levels</p>
        </div>
        <div class="flex space-x-2">
            <a href="?view=ledger" class="<?php echo $view_mode === 'ledger' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors">
                Transaction Ledger
            </a>
            <a href="?view=summary" class="<?php echo $view_mode === 'summary' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors">
                Stock Summary
            </a>
        </div>
    </div>

    <!-- Error Messages -->
    <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if ($view_mode === 'ledger'): ?>
    <!-- Filters for Ledger View -->
    <div class="bg-white rounded-lg shadow p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
            <input type="hidden" name="view" value="ledger">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Item</label>
                <select name="item" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">All Items</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?php echo $item['id']; ?>" <?php echo $item_filter == $item['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($item['name'] . ' (' . $item['code'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                <select name="location" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">All Locations</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($location['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Transaction Type</label>
                <select name="transaction_type" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">All Types</option>
                    <option value="grn" <?php echo $transaction_type_filter === 'grn' ? 'selected' : ''; ?>>GRN (In)</option>
                    <option value="mrn" <?php echo $transaction_type_filter === 'mrn' ? 'selected' : ''; ?>>MRN (Out)</option>
                    <option value="production_in" <?php echo $transaction_type_filter === 'production_in' ? 'selected' : ''; ?>>Production (In)</option>
                    <option value="production_out" <?php echo $transaction_type_filter === 'production_out' ? 'selected' : ''; ?>>Production (Out)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div class="flex items-end space-x-2">
                <button type="submit" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                    Filter
                </button>
                <a href="?view=ledger" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-200 transition-colors">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Stock Ledger Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Transaction History</h3>
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
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">In</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Out</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($ledger_entries as $entry): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('M d, Y', strtotime($entry['transaction_date'])); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <div>
                                <div class="font-medium"><?php echo htmlspecialchars($entry['item_name']); ?></div>
                                <div class="text-gray-500"><?php echo htmlspecialchars($entry['item_code']); ?></div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($entry['location_name']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="<?php 
                                echo $entry['transaction_type'] === 'grn' ? 'bg-green-100 text-green-800' : 
                                    ($entry['transaction_type'] === 'mrn' ? 'bg-red-100 text-red-800' : 
                                    ($entry['transaction_type'] === 'production_in' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800')); 
                            ?> text-xs font-medium px-2.5 py-0.5 rounded uppercase">
                                <?php echo str_replace('_', ' ', $entry['transaction_type']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($entry['reference_no']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <?php if ($entry['quantity_in'] > 0): ?>
                                <span class="text-green-600 font-medium">+<?php echo number_format($entry['quantity_in'], 3); ?> <?php echo $entry['unit_symbol']; ?></span>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <?php if ($entry['quantity_out'] > 0): ?>
                                <span class="text-red-600 font-medium">-<?php echo number_format($entry['quantity_out'], 3); ?> <?php echo $entry['unit_symbol']; ?></span>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                            <?php echo number_format($entry['balance'], 3); ?> <?php echo $entry['unit_symbol']; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($ledger_entries)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-4 text-center text-gray-500">No transactions found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($ledger_entries) >= 500): ?>
        <div class="px-4 py-3 bg-yellow-50 border-t border-yellow-200">
            <p class="text-sm text-yellow-800">Showing latest 500 transactions. Use filters to narrow down results.</p>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- Stock Summary View -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Current Stock Summary</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Current Stock</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ledger Stock</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Variance</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Transactions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($stock_summary as $stock): ?>
                    <?php 
                        $variance = $stock['current_stock'] - $stock['ledger_stock'];
                        $variance_class = abs($variance) > 0.001 ? 'text-red-600 font-medium' : 'text-gray-900';
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <div>
                                <div class="font-medium"><?php echo htmlspecialchars($stock['name']); ?></div>
                                <div class="text-gray-500"><?php echo htmlspecialchars($stock['code']); ?></div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="<?php 
                                echo $stock['type'] === 'raw' ? 'bg-blue-100 text-blue-800' : 
                                    ($stock['type'] === 'semi_finished' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'); 
                            ?> text-xs font-medium px-2.5 py-0.5 rounded capitalize">
                                <?php echo str_replace('_', ' ', $stock['type']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium <?php echo $stock['current_stock'] < 50 ? 'text-red-600' : 'text-gray-900'; ?>">
                            <?php echo number_format($stock['current_stock'], 3); ?> <?php echo $stock['symbol']; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                            <?php echo number_format($stock['ledger_stock'], 3); ?> <?php echo $stock['symbol']; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right <?php echo $variance_class; ?>">
                            <?php echo number_format($variance, 3); ?> <?php echo $stock['symbol']; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                            <a href="?view=ledger&item=<?php echo $stock['id']; ?>" class="text-primary hover:underline">
                                <?php echo $stock['transaction_count']; ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($stock_summary)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">No items found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Stock Alerts -->
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Stock Alerts</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-red-50 border border-red-200 rounded-md p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-red-800">Low Stock Items</p>
                        <p class="text-lg font-bold text-red-900"><?php echo count(array_filter($stock_summary, fn($s) => $s['current_stock'] < 50)); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-yellow-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-yellow-800">Stock Variances</p>
                        <p class="text-lg font-bold text-yellow-900"><?php echo count(array_filter($stock_summary, fn($s) => abs($s['current_stock'] - $s['ledger_stock']) > 0.001)); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-blue-50 border border-blue-200 rounded-md p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-blue-800">Total Items</p>
                        <p class="text-lg font-bold text-blue-900"><?php echo count($stock_summary); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>