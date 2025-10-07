<?php
// admin_users.php - Simple User Management (Admin only)
include 'header.php';

// Check if user has admin privileges
$auth->requireAuth(['super_admin', 'admin']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    // Validate input
                    $username = trim($_POST['username']);
                    $email = trim($_POST['email']);
                    $full_name = trim($_POST['full_name']);
                    $password = $_POST['password'];
                    $role = $_POST['role'];
                    
                    if (strlen($password) < 6) {
                        throw new Exception("Password must be at least 6 characters long");
                    }
                    
                    // Check if username or email already exists
                    $stmt = $db->prepare("SELECT id FROM admin_users WHERE username = ? OR email = ?");
                    $stmt->execute([$username, $email]);
                    if ($stmt->fetch()) {
                        throw new Exception("Username or email already exists");
                    }
                    
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    $stmt = $db->prepare("INSERT INTO admin_users (username, email, full_name, password_hash, role) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $email, $full_name, $password_hash, $role]);
                    
                    $success = "User created successfully!";
                    break;
                    
                case 'update':
                    $user_id = $_POST['user_id'];
                    $username = trim($_POST['username']);
                    $email = trim($_POST['email']);
                    $full_name = trim($_POST['full_name']);
                    $role = $_POST['role'];
                    $is_active = isset($_POST['is_active']) ? 1 : 0;
                    
                    // Check if username or email already exists for other users
                    $stmt = $db->prepare("SELECT id FROM admin_users WHERE (username = ? OR email = ?) AND id != ?");
                    $stmt->execute([$username, $email, $user_id]);
                    if ($stmt->fetch()) {
                        throw new Exception("Username or email already exists");
                    }
                    
                    $stmt = $db->prepare("UPDATE admin_users SET username = ?, email = ?, full_name = ?, role = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$username, $email, $full_name, $role, $is_active, $user_id]);
                    
                    $success = "User updated successfully!";
                    break;
                    
                case 'reset_password':
                    $user_id = $_POST['user_id'];
                    $new_password = $_POST['new_password'];
                    
                    if (strlen($new_password) < 6) {
                        throw new Exception("Password must be at least 6 characters long");
                    }
                    
                    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    $stmt = $db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$password_hash, $user_id]);
                    
                    $success = "Password reset successfully!";
                    break;
                    
                case 'delete':
                    $user_id = $_POST['user_id'];
                    
                    // Prevent self-deletion
                    if ($user_id == $_SESSION['user_id']) {
                        throw new Exception("You cannot delete your own account");
                    }
                    
                    $stmt = $db->prepare("DELETE FROM admin_users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    
                    $success = "User deleted successfully!";
                    break;
            }
        }
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch all users
try {
    $stmt = $db->query("SELECT * FROM admin_users ORDER BY created_at DESC");
    $users = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching users: " . $e->getMessage();
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">User Management</h1>
            <p class="text-gray-600">Manage system users and their permissions</p>
        </div>
        <button onclick="openModal('createUserModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
            </svg>
            Add New User
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

    <!-- Users Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Login</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($users as $user): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-primary rounded-full flex items-center justify-center text-white font-medium">
                                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                <div class="text-sm text-gray-500">@<?php echo htmlspecialchars($user['username']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo htmlspecialchars($user['email']); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="<?php 
                            echo $user['role'] === 'super_admin' ? 'bg-purple-100 text-purple-800' : 
                                ($user['role'] === 'admin' ? 'bg-blue-100 text-blue-800' : 
                                ($user['role'] === 'manager' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800')); 
                        ?> text-xs font-medium px-2.5 py-0.5 rounded capitalize">
                            <?php echo str_replace('_', ' ', $user['role']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="<?php echo $user['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?> text-xs font-medium px-2.5 py-0.5 rounded">
                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never'; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <button onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)" class="text-indigo-600 hover:text-indigo-900">Edit</button>
                        <button onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" class="text-yellow-600 hover:text-yellow-900">Reset Password</button>
                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                            <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this user?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">No users found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create User Modal -->
<div id="createUserModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add New User</h3>
            <button onclick="closeModal('createUserModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                <input type="text" name="full_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required minlength="6" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Minimum 6 characters</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                <select name="role" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Select Role</option>
                    <?php if ($_SESSION['role'] === 'super_admin'): ?>
                        <option value="super_admin">Super Admin</option>
                    <?php endif; ?>
                    <option value="admin">Admin</option>
                    <option value="manager">Manager</option>
                    <option value="operator">Operator</option>
                </select>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createUserModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Edit User</h3>
            <button onclick="closeModal('editUserModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                <input type="text" name="full_name" id="edit_full_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" id="edit_username" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" id="edit_email" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                <select name="role" id="edit_role" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <?php if ($_SESSION['role'] === 'super_admin'): ?>
                        <option value="super_admin">Super Admin</option>
                    <?php endif; ?>
                    <option value="admin">Admin</option>
                    <option value="manager">Manager</option>
                    <option value="operator">Operator</option>
                </select>
            </div>
            <div class="flex items-center">
                <input type="checkbox" name="is_active" id="edit_is_active" class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                <label for="edit_is_active" class="ml-2 block text-sm text-gray-900">
                    Active User
                </label>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('editUserModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Update User</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Reset Password</h3>
            <button onclick="closeModal('resetPasswordModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" id="reset_username" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <input type="password" name="new_password" required minlength="6" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Minimum 6 characters</p>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('resetPasswordModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-md hover:bg-yellow-700">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function editUser(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_role').value = user.role;
    document.getElementById('edit_is_active').checked = user.is_active == 1;
    openModal('editUserModal');
}

function resetPassword(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username').value = username;
    openModal('resetPasswordModal');
}
</script>

<?php include 'footer.php'; ?>