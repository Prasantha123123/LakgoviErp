<?php
ini_set('display_errors', 1);
   error_reporting(E_ALL);
// login.php - Simple login page
require_once 'database.php';
require_once 'config/simple_auth.php';

// Initialize database and auth
$database = new Database();
$db = $database->getConnection();
$auth = new SimpleAuth($db);

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';
$success_message = '';

// Handle logout message
if (isset($_GET['message'])) {
    switch ($_GET['message']) {
        case 'logged_out':
            $success_message = 'You have been logged out successfully';
            break;
        case 'unauthorized':
            $error_message = 'You do not have permission to access that page';
            break;
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password';
    } else {
        $result = $auth->login($username, $password);
        
        if ($result['success']) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error_message = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Lakgovi ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3B82F6',
                        secondary: '#1F2937'
                    }
                }
            }
        }
    </script>
    <style>
        .login-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
    </style>
</head>
<body class="login-bg min-h-screen flex items-center justify-center px-4">
    <div class="max-w-md w-full">
        <!-- Logo and Company Name -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-white rounded-full shadow-lg mb-4">
                <svg class="w-8 h-8 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-white mb-2">Lakgovi ERP</h1>
            <p class="text-blue-100">Manufacturing Management System</p>
        </div>

        <!-- Login Form -->
        <div class="glass-effect rounded-lg shadow-xl p-8">
            <div class="mb-6">
                <h2 class="text-2xl font-bold text-white text-center">Welcome Back</h2>
                <p class="text-blue-100 text-center mt-2">Sign in to access your dashboard</p>
            </div>

            <!-- Error/Success Messages -->
            <?php if ($error_message): ?>
                <div class="bg-red-500 bg-opacity-10 border border-red-400 text-red-100 px-4 py-3 rounded mb-4">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="bg-green-500 bg-opacity-10 border border-green-400 text-green-100 px-4 py-3 rounded mb-4">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-blue-100 mb-2">Username or Email</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </div>
                        <input type="text" name="username" id="username" 
                               class="w-full pl-10 pr-3 py-3 bg-white bg-opacity-10 border border-white border-opacity-20 rounded-md text-white placeholder-blue-200 focus:outline-none focus:ring-2 focus:ring-white focus:ring-opacity-50 focus:border-transparent"
                               placeholder="Enter username or email"
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                               required>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-blue-100 mb-2">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </div>
                        <input type="password" name="password" id="password"
                               class="w-full pl-10 pr-3 py-3 bg-white bg-opacity-10 border border-white border-opacity-20 rounded-md text-white placeholder-blue-200 focus:outline-none focus:ring-2 focus:ring-white focus:ring-opacity-50 focus:border-transparent"
                               placeholder="Enter password"
                               required>
                    </div>
                </div>

                <button type="submit" 
                        class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                    </svg>
                    Sign In
                </button>
            </form>
        </div>

        <!-- Default Credentials Info -->
        <div class="mt-6 text-center">
            <div class="glass-effect rounded-lg p-4">
                <p class="text-blue-100 text-sm mb-2">
                    <strong>Default Login Credentials:</strong>
                </p>
                <div class="text-blue-200 text-xs space-y-1">
                    <div>
                        <strong>Admin:</strong> 
                        <code class="bg-black bg-opacity-20 px-1 rounded">admin</code> / 
                        <code class="bg-black bg-opacity-20 px-1 rounded">admin123</code>
                    </div>
                    <div>
                        <strong>Manager:</strong> 
                        <code class="bg-black bg-opacity-20 px-1 rounded">manager</code> / 
                        <code class="bg-black bg-opacity-20 px-1 rounded">admin123</code>
                    </div>
                    <div>
                        <strong>Operator:</strong> 
                        <code class="bg-black bg-opacity-20 px-1 rounded">operator</code> / 
                        <code class="bg-black bg-opacity-20 px-1 rounded">admin123</code>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-8 text-center">
            <p class="text-blue-200 text-sm">
                © 2025 Lakgovi Manufacturing. All rights reserved.
            </p>
        </div>
    </div>

    <script>
        // Auto-focus username field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').focus();
        });
    </script>
</body>
</html>