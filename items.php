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
                    // Plus support for multiple categories via item_categories junction table
                    $db->beginTransaction();
                    
                    $stmt = $db->prepare("INSERT INTO items (code, name, type, unit_id, category_id, using_category) VALUES (?, ?, ?, ?, ?, ?)");
                    $primary_category = ($_POST['category_id'] ?? null) !== '' ? $_POST['category_id'] : null;
                    $stmt->execute([
                        $_POST['code'],
                        $_POST['name'],
                        $_POST['type'],
                        $_POST['unit_id'],
                        $primary_category,
                        isset($_POST['using_category']) ? 1 : 0
                    ]);
                    $item_id = $db->lastInsertId();
                    
                    // Save multiple categories if provided
                    if (!empty($_POST['category_ids']) && is_array($_POST['category_ids'])) {
                        $stmt_cat = $db->prepare("INSERT IGNORE INTO item_categories (item_id, category_id) VALUES (?, ?)");
                        foreach ($_POST['category_ids'] as $cat_id) {
                            if ($cat_id !== '') {
                                $stmt_cat->execute([$item_id, $cat_id]);
                            }
                        }
                    }
                    
                    $db->commit();
                    $success = "Item created successfully!";
                    break;

                case 'update':
                    // now also updates category_id (nullable) and using_category flag
                    // Plus support for multiple categories via item_categories junction table
                    $db->beginTransaction();
                    
                    $stmt = $db->prepare("UPDATE items SET code = ?, name = ?, type = ?, unit_id = ?, category_id = ?, using_category = ? WHERE id = ?");
                    $primary_category = ($_POST['category_id'] ?? null) !== '' ? $_POST['category_id'] : null;
                    $stmt->execute([
                        $_POST['code'],
                        $_POST['name'],
                        $_POST['type'],
                        $_POST['unit_id'],
                        $primary_category,
                        isset($_POST['using_category']) ? 1 : 0,
                        $_POST['id']
                    ]);
                    
                    // Update multiple categories
                    // First delete existing mappings
                    $stmt_del = $db->prepare("DELETE FROM item_categories WHERE item_id = ?");
                    $stmt_del->execute([$_POST['id']]);
                    
                    // Then insert new ones
                    if (!empty($_POST['category_ids']) && is_array($_POST['category_ids'])) {
                        $stmt_cat = $db->prepare("INSERT IGNORE INTO item_categories (item_id, category_id) VALUES (?, ?)");
                        foreach ($_POST['category_ids'] as $cat_id) {
                            if ($cat_id !== '') {
                                $stmt_cat->execute([$_POST['id'], $cat_id]);
                            }
                        }
                    }
                    
                    $db->commit();
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
            ) AS total_stock,
            (
                SELECT GROUP_CONCAT(cat.name SEPARATOR ', ')
                FROM item_categories ic
                JOIN categories cat ON cat.id = ic.category_id
                WHERE ic.item_id = i.id
            ) AS all_categories
        FROM items i 
        LEFT JOIN units u ON i.unit_id = u.id
        LEFT JOIN categories c ON c.id = i.category_id
        ORDER BY i.type, i.name
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Update current_stock to match ledger stock
    foreach ($items as $item) {
        $ledger_stock = (float)($item['total_stock'] ?? 0);
        if ((float)$item['current_stock'] != $ledger_stock) {
            $upd = $db->prepare("UPDATE items SET current_stock = ? WHERE id = ?");
            $upd->execute([$ledger_stock, $item['id']]);
        }
    }
    
    // Fetch all category IDs for each item (for edit modal)
    foreach ($items as &$item) {
        $stmt_cats = $db->prepare("SELECT category_id FROM item_categories WHERE item_id = ?");
        $stmt_cats->execute([$item['id']]);
        $item['category_ids'] = $stmt_cats->fetchAll(PDO::FETCH_COLUMN);
    }
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
$filter_category = $_GET['category'] ?? 'all';
$search_query = trim($_GET['search'] ?? '');
$filtered_items = $items;

// Filter by type
if ($filter_type !== 'all') {
    $filtered_items = array_filter($filtered_items, function($item) use ($filter_type) {
        return $item['type'] === $filter_type;
    });
}

// Filter by category
if ($filter_category !== 'all' && $filter_category !== '') {
    $filtered_items = array_filter($filtered_items, function($item) use ($filter_category) {
        // Check primary category
        if ($item['category_id'] == $filter_category) {
            return true;
        }
        // Check if item has multiple categories
        if (!empty($item['category_ids']) && in_array($filter_category, $item['category_ids'])) {
            return true;
        }
        return false;
    });
}

// Filter by search query
if ($search_query !== '') {
    $filtered_items = array_filter($filtered_items, function($item) use ($search_query) {
        return stripos($item['name'], $search_query) !== false || 
               stripos($item['code'], $search_query) !== false ||
               stripos($item['all_categories'] ?? '', $search_query) !== false;
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
    <div class="bg-white shadow rounded-lg p-4 space-y-4">
        <!-- Type Filter Tabs -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex flex-wrap gap-2">
                <a href="?filter=all&category=<?php echo htmlspecialchars($filter_category); ?>" class="<?php echo $filter_type === 'all' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors text-sm">
                    All Items (<?php echo count($items); ?>)
                </a>
                <a href="?filter=raw&category=<?php echo htmlspecialchars($filter_category); ?>" class="<?php echo $filter_type === 'raw' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors text-sm">
                    Raw Materials (<?php echo count(array_filter($items, fn($i) => $i['type'] === 'raw')); ?>)
                </a>
                <a href="?filter=semi_finished&category=<?php echo htmlspecialchars($filter_category); ?>" class="<?php echo $filter_type === 'semi_finished' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors text-sm">
                    Semi-Finished (Peettu) (<?php echo count(array_filter($items, fn($i) => $i['type'] === 'semi_finished')); ?>)
                </a>
                <a href="?filter=finished&category=<?php echo htmlspecialchars($filter_category); ?>" class="<?php echo $filter_type === 'finished' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-4 py-2 rounded-md transition-colors text-sm">
                    Finished Goods (<?php echo count(array_filter($items, fn($i) => $i['type'] === 'finished')); ?>)
                </a>
            </div>
        </div>

        <!-- Category Filter Dropdown -->
        <div class="flex flex-col md:flex-row gap-3 items-start md:items-center">
            <label class="font-medium text-gray-700 flex items-center gap-2">
                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                </svg>
                Filter by Category:
            </label>
            <select id="categoryFilter" class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary" onchange="updateCategoryFilter()">
                <option value="all">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat['id']); ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <script>
            function updateCategoryFilter() {
                const categoryFilter = document.getElementById('categoryFilter').value;
                const filterType = '<?php echo htmlspecialchars($filter_type); ?>';
                const searchQuery = '<?php echo htmlspecialchars($search_query); ?>';
                let url = '?filter=' + filterType + '&category=' + encodeURIComponent(categoryFilter);
                if (searchQuery) {
                    url += '&search=' + encodeURIComponent(searchQuery);
                }
                window.location.href = url;
            }
            </script>
        </div>

        <!-- Search Bar -->
        <div class="flex items-center">
            <form method="GET" class="flex gap-2 items-center w-full" id="itemSearchForm">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter_type); ?>">
                <input type="hidden" name="category" value="<?php echo htmlspecialchars($filter_category); ?>">
                <input type="text" name="search" id="itemSearchInput" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search by name, code, or category..." class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" autocomplete="off" />
                <button type="submit" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">Search</button>
                <?php if ($search_query !== ''): ?>
                    <a href="?filter=<?php echo htmlspecialchars($filter_type); ?>&category=<?php echo htmlspecialchars($filter_category); ?>" class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 transition-colors">Clear</a>
                <?php endif; ?>
            </form>
        </div>
            <script>
            // Auto-submit search form on input
            document.addEventListener('DOMContentLoaded', function() {
                var searchInput = document.getElementById('itemSearchInput');
                var searchForm = document.getElementById('itemSearchForm');
                var timeout = null;
                
                // Refocus search input if there's an active search (after page reload)
                if (searchInput && '<?php echo htmlspecialchars($search_query); ?>' !== '') {
                    searchInput.focus();
                    // Position cursor at the end of the text
                    searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
                }
                
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
                    <td class="px-6 py-4 text-sm text-gray-900">
                        <?php if (!empty($item['all_categories'])): ?>
                            <div class="flex flex-wrap gap-1">
                                <?php foreach (explode(', ', $item['all_categories']) as $cat): ?>
                                    <span class="bg-purple-100 text-purple-800 text-xs font-medium px-2 py-0.5 rounded"><?php echo htmlspecialchars($cat); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-gray-400">—</span>
                        <?php endif; ?>
                    </td>
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Primary Category</label>
                <select name="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">No Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">Main category for this item</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Additional Categories (Multi-select)</label>
                <div class="border border-gray-300 rounded-md p-2 max-h-32 overflow-y-auto">
                    <?php foreach ($categories as $cat): ?>
                        <label class="flex items-center py-1 hover:bg-gray-50 px-2 rounded">
                            <input type="checkbox" name="category_ids[]" value="<?php echo (int)$cat['id']; ?>" class="form-checkbox">
                            <span class="ml-2 text-sm text-gray-700"><?php echo htmlspecialchars($cat['name']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 mt-1">Select all categories this item belongs to</p>
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Primary Category</label>
                <select name="category_id" id="edit_item_category_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">No Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">Main category for this item</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Additional Categories (Multi-select)</label>
                <div id="edit_categories_list" class="border border-gray-300 rounded-md p-2 max-h-32 overflow-y-auto">
                    <?php foreach ($categories as $cat): ?>
                        <label class="flex items-center py-1 hover:bg-gray-50 px-2 rounded">
                            <input type="checkbox" name="category_ids[]" value="<?php echo (int)$cat['id']; ?>" class="form-checkbox edit-category-checkbox">
                            <span class="ml-2 text-sm text-gray-700"><?php echo htmlspecialchars($cat['name']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 mt-1">Select all categories this item belongs to</p>
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
    // Set primary category in edit modal
    var catSel = document.getElementById('edit_item_category_id');
    if (catSel) catSel.value = item.category_id ? item.category_id : '';
    
    // Set multiple categories checkboxes
    var checkboxes = document.querySelectorAll('.edit-category-checkbox');
    checkboxes.forEach(function(cb) {
        cb.checked = false; // Clear all first
    });
    if (item.category_ids && Array.isArray(item.category_ids)) {
        item.category_ids.forEach(function(catId) {
            checkboxes.forEach(function(cb) {
                if (parseInt(cb.value) === parseInt(catId)) {
                    cb.checked = true;
                }
            });
        });
    }
    
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
