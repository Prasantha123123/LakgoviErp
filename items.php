<?php
// items.php - Items management (raw materials, semi-finished, finished goods)
include 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    // now also saves category_id (nullable) and using_category flag
                    $stmt = $db->prepare("INSERT INTO items (code, name, type, unit_id, category_id, using_category) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['code'],
                        $_POST['name'],
                        $_POST['type'],
                        $_POST['unit_id'],
                        ($_POST['category_id'] ?? null) !== '' ? $_POST['category_id'] : null,
                        isset($_POST['using_category']) ? 1 : 0
                    ]);
                    $success = "Item created successfully!";
                    break;

                case 'update':
                    // now also updates category_id (nullable) and using_category flag
                    $stmt = $db->prepare("UPDATE items SET code = ?, name = ?, type = ?, unit_id = ?, category_id = ?, using_category = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['code'],
                        $_POST['name'],
                        $_POST['type'],
                        $_POST['unit_id'],
                        ($_POST['category_id'] ?? null) !== '' ? $_POST['category_id'] : null,
                        isset($_POST['using_category']) ? 1 : 0,
                        $_POST['id']
                    ]);
                    $success = "Item updated successfully!";
                    break;

                case 'delete':
                    $stmt = $db->prepare("DELETE FROM items WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $success = "Item deleted successfully!";
                    break;

                // Categories CRUD (unchanged)
                case 'create_category':
                    $stmt = $db->prepare("INSERT INTO categories (name) VALUES (?)");
                    $stmt->execute([$_POST['category_name']]);
                    $success = "Category created successfully!";
                    break;

                case 'update_category':
                    $stmt = $db->prepare("UPDATE categories SET name = ? WHERE id = ?");
                    $stmt->execute([$_POST['category_name'], (int)$_POST['id']]);
                    $success = "Category updated successfully!";
                    break;

                case 'delete_category':
                    $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
                    $stmt->execute([(int)$_POST['id']]);
                    $success = "Category deleted successfully!";
                    break;
            }
        }
    } catch(PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch items with units + category name + using_category flag
try {
    $stmt = $db->query("
        SELECT 
            i.*, 
            COALESCE(i.current_stock, 0) AS current_stock,
            u.name   AS unit_name, 
            u.symbol AS unit_symbol,
            c.name   AS category_name,
            (
                SELECT SUM(quantity_in - quantity_out) 
                FROM stock_ledger 
                WHERE item_id = i.id
            ) AS total_stock
        FROM items i 
        LEFT JOIN units u ON i.unit_id = u.id
        LEFT JOIN categories c ON c.id = i.category_id
        ORDER BY i.type, i.name
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
    $items = [];
}

// Fetch units for dropdown
try {
    $stmt = $db->query("SELECT * FROM units ORDER BY name");
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "Error fetching units: " . $e->getMessage();
    $units = [];
}

// Fetch categories for dropdown/listing
try {
    $stmt = $db->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "Error fetching categories: " . $e->getMessage();
    $categories = [];
}

// Filter by type

$filter_type = $_GET['filter'] ?? 'all';
$search_query = trim($_GET['search'] ?? '');
$filtered_items = $items;
if ($filter_type !== 'all') {
    $filtered_items = array_filter($filtered_items, function($item) use ($filter_type) {
        return $item['type'] === $filter_type;
    });
}
if ($search_query !== '') {
    $filtered_items = array_filter($filtered_items, function($item) use ($search_query) {
        return stripos($item['name'], $search_query) !== false || stripos($item['code'], $search_query) !== false;
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
        <div class="flex gap-2">
            <button onclick="openModal('createItemModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                Add New Item
            </button>
            <button onclick="openModal('categoriesModal')" class="bg-gray-800 text-white px-4 py-2 rounded-md hover:bg-gray-900 transition-colors">
                Categories
            </button>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>


    <!-- Filter Tabs & Search -->
    <div class="bg-white shadow rounded-lg p-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
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
            <form method="GET" class="flex gap-2 items-center" id="itemSearchForm">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter_type); ?>">
                <input type="text" name="search" id="itemSearchInput" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search by name or code..." class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" autocomplete="off" />
                <button type="submit" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">Search</button>
            </form>
            <script>
            // Auto-submit search form on input
            document.addEventListener('DOMContentLoaded', function() {
                var searchInput = document.getElementById('itemSearchInput');
                var searchForm = document.getElementById('itemSearchForm');
                var timeout = null;
                if (searchInput && searchForm) {
                    searchInput.addEventListener('input', function() {
                        clearTimeout(timeout);
                        timeout = setTimeout(function() {
                            searchForm.submit();
                        }, 300); // debounce for 300ms
                    });
                }
            });
            </script>
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
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th><!-- NEW -->
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
                            <?php echo htmlspecialchars(str_replace('_', ' ', $item['type'])); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($item['category_name'] ?? '—'); ?></td><!-- NEW -->
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($item['unit_symbol'] ?? ''); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 <?php echo (float)$item['current_stock'] < 50 ? 'text-red-600 font-medium' : ''; ?>">
                        <?php echo number_format((float)$item['current_stock'], 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo number_format((float)($item['total_stock'] ?? 0), 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <button onclick="editItem(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>)" class="text-indigo-600 hover:text-indigo-900">Edit</button>
                        <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this item?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($filtered_items)): ?>
                <tr>
                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">No items found. Create your first item!</td>
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
                        <option value="<?php echo (int)$unit['id']; ?>"><?php echo htmlspecialchars($unit['name'] . ' (' . $unit['symbol'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select name="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">No Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="inline-flex items-center mt-2">
                    <input type="checkbox" name="using_category" value="1" class="form-checkbox">
                    <span class="ml-2 text-sm text-gray-700">Use Category for BOM (raw material)</span>
                </label>
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
                        <option value="<?php echo (int)$unit['id']; ?>"><?php echo htmlspecialchars($unit['name'] . ' (' . $unit['symbol'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select name="category_id" id="edit_item_category_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">No Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="inline-flex items-center mt-2">
                    <input type="checkbox" name="using_category" id="edit_item_using_category" value="1" class="form-checkbox">
                    <span class="ml-2 text-sm text-gray-700">Use Category for BOM (raw material)</span>
                </label>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('editItemModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Update Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Categories Modal (unchanged from your last version) -->
<div id="categoriesModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-16 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Categories</h3>
            <button onclick="closeModal('categoriesModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Create Category -->
        <form method="POST" class="mb-6">
            <input type="hidden" name="action" value="create_category">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Category Name</label>
                    <input type="text" name="category_name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                           placeholder="e.g., Flour">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                        Add
                    </button>
                </div>
            </div>
        </form>

        <!-- Categories Table -->
        <div class="bg-white border rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach (($categories ?? []) as $cat): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm text-gray-700"><?php echo (int)$cat['id']; ?></td>
                        <td class="px-6 py-3 text-sm text-gray-900">
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </td>
                        <td class="px-6 py-3 text-right text-sm space-x-3">
                            <button type="button"
                                    class="text-indigo-600 hover:text-indigo-900 cat-edit-btn"
                                    data-id="<?php echo (int)$cat['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                Edit
                            </button>
                            <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this category?')">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="3" class="px-6 py-4 text-center text-gray-500">No categories yet. Add your first one above.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Hidden Edit Category Panel -->
        <div id="editCategoryPanel" class="mt-4 hidden">
            <form method="POST" class="bg-gray-50 border rounded-md p-4">
                <input type="hidden" name="action" value="update_category">
                <input type="hidden" name="id" id="edit_category_id">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Edit Category Name</label>
                        <input type="text" name="category_name" id="edit_category_name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="button" onclick="closeEditCategory()"
                                class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-100">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Save</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="flex justify-end mt-4">
            <button onclick="closeModal('categoriesModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Close</button>
        </div>
    </div>
</div>

<script>
// Safe helpers
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('hidden');
}
function confirmDelete(msg) {
    return confirm(msg || 'Are you sure?');
}

function editItem(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_item_code').value = item.code;
    document.getElementById('edit_item_name').value = item.name;
    document.getElementById('edit_item_type').value = item.type;
    document.getElementById('edit_item_unit_id').value = item.unit_id;
    // Set category in edit modal
    var catSel = document.getElementById('edit_item_category_id');
    if (catSel) catSel.value = item.category_id ? item.category_id : '';
    // Set using_category checkbox
    var usingCat = document.getElementById('edit_item_using_category');
    if (usingCat) usingCat.checked = item.using_category == 1;
    openModal('editItemModal');
}

// Category modal helpers
function openEditCategory(id, name) {
    var panel = document.getElementById('editCategoryPanel');
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_category_name').value = name;
    if (panel) panel.classList.remove('hidden');
    openModal('categoriesModal');
}
function closeEditCategory() {
    var panel = document.getElementById('editCategoryPanel');
    if (panel) panel.classList.add('hidden');
    var idEl = document.getElementById('edit_category_id');
    var nameEl = document.getElementById('edit_category_name');
    if (idEl) idEl.value = '';
    if (nameEl) nameEl.value = '';
}

// Delegated click for category Edit buttons
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.cat-edit-btn');
    if (!btn) return;
    var id = btn.getAttribute('data-id');
    var name = btn.getAttribute('data-name');
    openEditCategory(id, name);
});
</script>

<?php include 'footer.php'; ?>
