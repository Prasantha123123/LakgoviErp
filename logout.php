<?php
// logout.php - Simple logout handler
require_once 'database.php';
require_once 'config/simple_auth.php';

// Initialize database and auth
$database = new Database();
$db = $database->getConnection();
$auth = new SimpleAuth($db);

// Perform logout
$auth->logout();

// Redirect to login page with logout message
header('Location: login.php?message=logged_out');
exit;
?>