<?php
// current_stock_report.php - Current Stock Report from manual stock entries
include 'header.php';

// Get filters
$item_filter = $_GET['item'] ?? '';
$location_filter = $_GET['location'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$category_filter = $_GET['category'] ?? '';

// Build WHERE clause for filters - always filter by manual_stock
$where_conditions = ["sl.transaction_type = 'manual_stock'"];
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

if ($category_filter) {
    $where_conditions[] = "i.type = ?";
    $params[] = $category_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Fetch manual stock entries from stock_ledger only
try {
    $stmt = $db->prepare("
        SELECT sl.*, i.name as item_name, i.code as item_code, i.type as item_type,
               u.symbol as unit_symbol, l.name as location_name,
               (SELECT COALESCE(SUM(sl2.quantity_in - sl2.quantity_out), 0) 
                FROM stock_ledger sl2 
                WHERE sl2.item_id = sl.item_id AND sl2.transaction_type = 'manual_stock') as calculated_stock
        FROM stock_ledger sl
        JOIN items i ON sl.item_id = i.id
        JOIN units u ON i.unit_id = u.id
        JOIN locations l ON sl.location_id = l.id
        $where_clause
        ORDER BY sl.transaction_date DESC, sl.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $stock_entries = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching stock data: " . $e->getMessage();
    $stock_entries = [];
}

// Fetch current stock summary - data only from stock_ledger filtered by manual_stock
try {
    $summary_where = ["sl.transaction_type = 'manual_stock'"];
    $summary_params = [];
    
    if ($category_filter) {
        $summary_where[] = "i.type = ?";
        $summary_params[] = $category_filter;
    }
    
    $summary_where_clause = 'WHERE ' . implode(' AND ', $summary_where);
    
    $stmt = $db->prepare("
        SELECT sl.item_id, i.name, i.code, i.type, u.symbol,
               COALESCE(SUM(sl.quantity_in), 0) as total_in,
               COALESCE(SUM(sl.quantity_out), 0) as total_out,
               COALESCE(SUM(sl.quantity_in - sl.quantity_out), 0) as current_stock,
               COUNT(sl.id) as entry_count,
               MAX(sl.transaction_date) as last_update
        FROM stock_ledger sl
        JOIN items i ON sl.item_id = i.id
        JOIN units u ON i.unit_id = u.id
        $summary_where_clause
        GROUP BY sl.item_id, i.name, i.code, i.type, u.symbol
        ORDER BY i.type, i.name
    ");
    $stmt->execute($summary_params);
    $stock_summary = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching stock summary: " . $e->getMessage();
    $stock_summary = [];
}

// Fetch items for filter dropdown
try {
    $stmt = $db->query("SELECT id, name, code FROM items ORDER BY name");
    $items = $stmt->fetchAll();
} catch(PDOException $e) {
    $items = [];
}

// Fetch locations for filter dropdown
try {
    $stmt = $db->query("SELECT id, name FROM locations ORDER BY name");
    $locations = $stmt->fetchAll();
} catch(PDOException $e) {
    $locations = [];
}

// Fetch item categories
try {
    $stmt = $db->query("SELECT DISTINCT type FROM items WHERE type IS NOT NULL AND type != '' ORDER BY type");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    $categories = [];
}

// Get view mode
$view_mode = $_GET['view'] ?? 'summary';

// Calculate totals for summary
$total_items = count($stock_summary);
$total_stock_value = 0;
$items_with_stock = 0;
foreach ($stock_summary as $item) {
    if ($item['current_stock'] > 0) {
        $items_with_stock++;
    }
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Manual Stock Report</h1>
            <p class="text-gray-600">View current stock levels from manual stock entries</p>
        </div>
        <!-- <div class="flex space-x-2">
            <a href="?view=summary" class="<?php echo $view_mode === 'summary' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors">
                Stock Summary
            </a>
            <a href="?view=details" class="<?php echo $view_mode === 'details' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors">
                Entry Details
            </a>
        </div> -->
    </div>

    <!-- Summary Cards -->
    <!-- <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">Total Items</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_items); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100 text-green-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">Items with Stock</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format($items_with_stock); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">Total Entries</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format(count($stock_entries)); ?></p>
                </div>
            </div>
        </div>
    </div> -->

    <!-- Error Messages -->
    <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">
            
            <?php if ($view_mode === 'details'): ?>
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <?php endif; ?>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category_filter === $cat ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($cat)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex items-end space-x-2">
                <button type="submit" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                    Filter
                </button>
                <a href="?view=<?php echo $view_mode; ?>" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-200 transition-colors">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <?php if ($view_mode === 'summary'): ?>
    <!-- Stock Summary Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-medium text-gray-900">Current Stock Summary</h3>
            <span class="text-sm text-gray-500">Data from manual stock entries</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Manual In</th>
                        
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Last Update</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($stock_summary)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-10 text-center text-gray-500">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                            </svg>
                            <p class="mt-2">No stock data found from manual stock entries</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($stock_summary as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($item['code']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($item['name']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                <?php echo htmlspecialchars(ucfirst($item['type'] ?? 'N/A')); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-green-600">
                            +<?php echo number_format($item['total_in'], 3); ?> <?php echo htmlspecialchars($item['symbol']); ?>
                        </td>
                        
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                            <?php echo $item['last_update'] ? date('M d, Y', strtotime($item['last_update'])) : 'N/A'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($view_mode === 'details'): ?>
    <!-- Entry Details Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-medium text-gray-900">Manual Stock Entry Details</h3>
            <span class="text-sm text-gray-500">Showing latest <?php echo count($stock_entries); ?> entries</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity In</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity Out</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Current Stock</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($stock_entries)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-10 text-center text-gray-500">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                            </svg>
                            <p class="mt-2">No manual stock entries found</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($stock_entries as $entry): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo date('M d, Y', strtotime($entry['transaction_date'])); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <div>
                                <div class="font-medium"><?php echo htmlspecialchars($entry['item_name']); ?></div>
                                <div class="text-gray-500 text-xs"><?php echo htmlspecialchars($entry['item_code']); ?></div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($entry['location_name']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo htmlspecialchars($entry['reference_no'] ?? '-'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <?php if ($entry['quantity_in'] > 0): ?>
                                <span class="text-green-600 font-medium">+<?php echo number_format($entry['quantity_in'], 3); ?> <?php echo htmlspecialchars($entry['unit_symbol']); ?></span>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <?php if ($entry['quantity_out'] > 0): ?>
                                <span class="text-red-600 font-medium">-<?php echo number_format($entry['quantity_out'], 3); ?> <?php echo htmlspecialchars($entry['unit_symbol']); ?></span>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                            <?php 
                            $stock = $entry['calculated_stock'];
                            $stock_class = $stock > 0 ? 'text-green-600' : ($stock < 0 ? 'text-red-600' : 'text-gray-600');
                            ?>
                            <span class="<?php echo $stock_class; ?>">
                                <?php echo number_format($stock, 3); ?> <?php echo htmlspecialchars($entry['unit_symbol']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                            <?php echo htmlspecialchars($entry['notes'] ?? '-'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
