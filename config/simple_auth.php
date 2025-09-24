<?php
// config/simple_auth.php - Simple Authentication class
class SimpleAuth {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
        
        // Start session if not already started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Login user with username/email and password
     */
    public function login($username, $password) {
        try {
            // Get user by username or email
            $stmt = $this->db->prepare("
                SELECT id, username, email, password_hash, full_name, role, is_active
                FROM admin_users 
                WHERE (username = ? OR email = ?) AND is_active = 1
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
            
            // Create session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['login_time'] = time();
            
            // Update last login
            $stmt = $this->db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            return [
                'success' => true, 
                'message' => 'Login successful',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role']
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed. Please try again.'];
        }
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
    
    /**
     * Get current user information
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role' => $_SESSION['role'],
            'email' => $_SESSION['email']
        ];
    }
    
    /**
     * Check if user has required role
     */
    public function hasRole($required_roles) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $user_role = $_SESSION['role'];
        
        if (is_string($required_roles)) {
            $required_roles = [$required_roles];
        }
        
        return in_array($user_role, $required_roles);
    }
    
    /**
     * Logout user
     */
    public function logout() {
        // Clear session
        $_SESSION = array();
        
        // Destroy session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    /**
     * Require authentication - redirect to login if not authenticated
     */
    public function requireAuth($required_roles = null) {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
        
        if ($required_roles && !$this->hasRole($required_roles)) {
            header('Location: login.php?message=unauthorized');
            exit;
        }
    }
    
    /**
     * Change password
     */
    public function changePassword($user_id, $current_password, $new_password) {
        try {
            // Get current password hash
            $stmt = $this->db->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($current_password, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }
            
            // Validate new password
            if (strlen($new_password) < 6) {
                return ['success' => false, 'message' => 'New password must be at least 6 characters long'];
            }
            
            // Update password
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$new_hash, $user_id]);
            
            return ['success' => true, 'message' => 'Password changed successfully'];
            
        } catch (Exception $e) {
            error_log("Password change error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to change password'];
        }
    }
}
?>