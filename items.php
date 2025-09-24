<?php
// items.php - Items management (raw materials, semi-finished, finished goods)
include 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    $stmt = $db->prepare("INSERT INTO items (code, name, type, unit_id) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$_POST['code'], $_POST['name'], $_POST['type'], $_POST['unit_id']]);
                    $success = "Item created successfully!";
                    break;
                    
                case 'update':
                    $stmt = $db->prepare("UPDATE items SET code = ?, name = ?, type = ?, unit_id = ? WHERE id = ?");
                    $stmt->execute([$_POST['code'], $_POST['name'], $_POST['type'], $_POST['unit_id'], $_POST['id']]);
                    $success = "Item updated successfully!";
                    break;
                    
                case 'delete':
                    $stmt = $db->prepare("DELETE FROM items WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $success = "Item deleted successfully!";
                    break;
            }
        }
    } catch(PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch items with units
try {
    $stmt = $db->query("
        SELECT i.*, u.name as unit_name, u.symbol as unit_symbol,
               (SELECT SUM(quantity_in - quantity_out) FROM stock_ledger WHERE item_id = i.id) as total_stock
        FROM items i 
        LEFT JOIN units u ON i.unit_id = u.id 
        ORDER BY i.type, i.name
    ");
    $items = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

// Fetch units for dropdown
try {
    $stmt = $db->query("SELECT * FROM units ORDER BY name");
    $units = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching units: " . $e->getMessage();
}

// Filter by type
$filter_type = $_GET['filter'] ?? 'all';
$filtered_items = $items;
if ($filter_type !== 'all') {
    $filtered_items = array_filter($items, function($item) use ($filter_type) {
        return $item['type'] === $filter_type;
    });
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Items Management</h1>
            <p class="text-gray-600">Manage raw materials, semi-finished, and finished goods</p>
        </div>
        <button onclick="openModal('createItemModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            Add New Item
        </button>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Filter Tabs -->
    <div class="bg-white shadow rounded-lg p-4">
        <div class="flex space-x-4">
            <a href="?filter=all" class="<?php echo $filter_type === 'all' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors">
                All Items (<?php echo count($items); ?>)
            </a>
            <a href="?filter=raw" class="<?php echo $filter_type === 'raw' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors">
                Raw Materials (<?php echo count(array_filter($items, fn($i) => $i['type'] === 'raw')); ?>)
            </a>
            <a href="?filter=semi_finished" class="<?php echo $filter_type === 'semi_finished' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors">
                Semi-Finished (Peettu) (<?php echo count(array_filter($items, fn($i) => $i['type'] === 'semi_finished')); ?>)
            </a>
            <a href="?filter=finished" class="<?php echo $filter_type === 'finished' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors">
                Finished Goods (<?php echo count(array_filter($items, fn($i) => $i['type'] === 'finished')); ?>)
            </a>
        </div>
    </div>

    <!-- Items Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Stock</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ledger Stock</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($filtered_items as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['code']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($item['name']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <span class="<?php 
                            echo $item['type'] === 'raw' ? 'bg-blue-100 text-blue-800' : 
                                ($item['type'] === 'semi_finished' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'); 
                        ?> text-xs font-medium px-2.5 py-0.5 rounded capitalize">
                            <?php echo str_replace('_', ' ', $item['type']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($item['unit_symbol']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 <?php echo $item['current_stock'] < 50 ? 'text-red-600 font-medium' : ''; ?>">
                        <?php echo number_format($item['current_stock'], 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo number_format($item['total_stock'] ?? 0, 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <button onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)" class="text-indigo-600 hover:text-indigo-900">Edit</button>
                        <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this item?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($filtered_items)): ?>
                <tr>
                    <td colspan="7" class="px-6 py-4 text-center text-gray-500">No items found. Create your first item!</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Item Modal -->
<div id="createItemModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add New Item</h3>
            <button onclick="closeModal('createItemModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Item Code</label>
                <input type="text" name="code" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="e.g., RM001">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Item Name</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="e.g., Wheat Flour">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select name="type" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Type</option>
                    <option value="raw">Raw Material</option>
                    <option value="semi_finished">Semi-Finished (Peettu)</option>
                    <option value="finished">Finished Good</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                <select name="unit_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Unit</option>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?php echo $unit['id']; ?>"><?php echo htmlspecialchars($unit['name'] . ' (' . $unit['symbol'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createItemModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Create Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div id="editItemModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Edit Item</h3>
            <button onclick="closeModal('editItemModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_item_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Item Code</label>
                <input type="text" name="code" id="edit_item_code" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Item Name</label>
                <input type="text" name="name" id="edit_item_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select name="type" id="edit_item_type" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Type</option>
                    <option value="raw">Raw Material</option>
                    <option value="semi_finished">Semi-Finished (Peettu)</option>
                    <option value="finished">Finished Good</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                <select name="unit_id" id="edit_item_unit_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Unit</option>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?php echo $unit['id']; ?>"><?php echo htmlspecialchars($unit['name'] . ' (' . $unit['symbol'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('editItemModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Update Item</button>
            </div>
        </form>
    </div>
</div>

<script>
function editItem(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_item_code').value = item.code;
    document.getElementById('edit_item_name').value = item.name;
    document.getElementById('edit_item_type').value = item.type;
    document.getElementById('edit_item_unit_id').value = item.unit_id;
    openModal('editItemModal');
}
</script>

<?php include 'footer.php'; ?>