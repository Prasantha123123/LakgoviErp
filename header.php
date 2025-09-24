<?php
// header.php - Simple header with authentication
require_once 'database.php';
require_once 'config/simple_auth.php';

// Initialize database and auth
$database = new Database();
$db = $database->getConnection();
$auth = new SimpleAuth($db);

// Require authentication for all pages except login
$current_file = basename($_SERVER['PHP_SELF']);
if ($current_file !== 'login.php') {
    $auth->requireAuth();
}

// Get current user information
$current_user = $auth->getCurrentUser();

// Get current page for navigation highlighting
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lakgovi ERP - <?php echo ucfirst(str_replace('_', ' ', $current_page)); ?></title>
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
        /* Dropdown styling with proper hover behavior */
        .dropdown {
            position: relative;
        }
        
        .dropdown-menu {
            position: absolute;
            left: 0;
            top: 100%;
            margin-top: 0;
            z-index: 1000;
            display: none;
            min-width: 12rem;
        }
        
        /* Show dropdown on hover with proper timing */
        .dropdown:hover .dropdown-menu {
            display: block;
        }
        
        /* Keep dropdown visible when hovering over the menu itself */
        .dropdown-menu:hover {
            display: block;
        }
        
        /* Add a small bridge to prevent gaps */
        .dropdown::before {
            content: '';
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            height: 4px;
            background: transparent;
            z-index: 999;
        }
        
        /* Active dropdown styling */
        .dropdown.active .dropdown-menu {
            display: block;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            .dropdown-menu {
                position: static;
                display: none;
                box-shadow: none;
                margin-top: 0;
            }
            
            .dropdown.mobile-open .dropdown-menu {
                display: block;
            }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-8">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-primary rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-xl font-bold text-gray-900">Lakgovi ERP</h1>
                            <p class="text-xs text-gray-500">Manufacturing System</p>
                        </div>
                    </div>
                    
                    <div class="hidden md:flex items-center space-x-1">
                        <a href="dashboard.php" class="<?php echo $current_page == 'dashboard' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> px-3 py-2 rounded-md text-sm font-medium transition-colors">
                            Dashboard
                        </a>
                        
                        <!-- Master Data Dropdown -->
                        <div class="relative dropdown" onmouseenter="showDropdown(this)" onmouseleave="hideDropdown(this)">
                            <button class="text-gray-700 hover:bg-gray-100 px-3 py-2 rounded-md text-sm font-medium transition-colors flex items-center dropdown-button">
                                Master Data
                                <svg class="w-4 h-4 ml-1 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div class="dropdown-menu bg-white rounded-md shadow-lg ring-1 ring-black ring-opacity-5">
                                <div class="py-1">
                                    <a href="units.php" class="<?php echo $current_page == 'units' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-4 py-2 text-sm transition-colors">Units</a>
                                    <a href="items.php" class="<?php echo $current_page == 'items' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-4 py-2 text-sm transition-colors">Items</a>
                                    <a href="suppliers.php" class="<?php echo $current_page == 'suppliers' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-4 py-2 text-sm transition-colors">Suppliers</a>
                                    <a href="opening_stock.php" class="<?php echo $current_page == 'suppliers' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-4 py-2 text-sm transition-colors">Opening Stock</a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Transactions Dropdown -->
                        <div class="relative dropdown" onmouseenter="showDropdown(this)" onmouseleave="hideDropdown(this)">
                            <button class="text-gray-700 hover:bg-gray-100 px-3 py-2 rounded-md text-sm font-medium transition-colors flex items-center dropdown-button">
                                Transactions
                                <svg class="w-4 h-4 ml-1 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div class="dropdown-menu bg-white rounded-md shadow-lg ring-1 ring-black ring-opacity-5">
                                <div class="py-1">
                                    <a href="purchase_orders.php" class="<?php echo $current_page == 'purchase_orders' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-4 py-2 text-sm transition-colors">Purchase Orders</a>
                                    <a href="grn.php" class="<?php echo $current_page == 'grn' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-4 py-2 text-sm transition-colors">GRN (Goods Receipt)</a>
                                    <a href="mrn.php" class="<?php echo $current_page == 'mrn' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-4 py-2 text-sm transition-colors">MRN (Material Request)</a>
                                </div>
                            </div>
                        </div>
                        
                        <a href="bom.php" class="<?php echo $current_page == 'bom' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> px-3 py-2 rounded-md text-sm font-medium transition-colors">
                            BOM
                        </a>
                        <a href="production.php" class="<?php echo $current_page == 'production' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> px-3 py-2 rounded-md text-sm font-medium transition-colors">
                            Production
                        </a>
                        <a href="trolley.php" class="<?php echo $current_page == 'trolley' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> px-3 py-2 rounded-md text-sm font-medium transition-colors">
                            Trolley
                        </a>
                        
                        
                        <!-- Reports Dropdown -->
                        <div class="relative dropdown" onmouseenter="showDropdown(this)" onmouseleave="hideDropdown(this)">
                            <button class="text-gray-700 hover:bg-gray-100 px-3 py-2 rounded-md text-sm font-medium transition-colors flex items-center dropdown-button">
                                Reports
                                <svg class="w-4 h-4 ml-1 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div class="dropdown-menu bg-white rounded-md shadow-lg ring-1 ring-black ring-opacity-5">
                                <div class="py-1">
                                    <a href="stock_ledger.php" class="<?php echo $current_page == 'stock_ledger' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-4 py-2 text-sm transition-colors">
                                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                        Stock Ledger
                                    </a>
                                    <a href="stock_report.php" class="<?php echo $current_page == 'stock_report' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-4 py-2 text-sm transition-colors">
                                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                        </svg>
                                        Advanced Stock Report
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- User Menu -->
                <div class="flex items-center space-x-4">
                    <!-- User Dropdown -->
                    <div class="relative dropdown" onmouseenter="showDropdown(this)" onmouseleave="hideDropdown(this)">
                        <button class="flex items-center space-x-2 text-gray-700 hover:bg-gray-100 px-3 py-2 rounded-md transition-colors dropdown-button">
                            <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center text-white text-sm font-medium">
                                <?php echo strtoupper(substr($current_user['full_name'] ?? 'U', 0, 1)); ?>
                            </div>
                            <div class="hidden md:block text-left">
                                <p class="text-sm font-medium"><?php echo htmlspecialchars($current_user['full_name'] ?? 'User'); ?></p>
                                <p class="text-xs text-gray-500 capitalize"><?php echo htmlspecialchars($current_user['role'] ?? 'user'); ?></p>
                            </div>
                            <svg class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div class="dropdown-menu bg-white rounded-md shadow-lg ring-1 ring-black ring-opacity-5 right-0">
                            <div class="py-1">
                                <div class="px-4 py-2 text-xs text-gray-500 border-b">
                                    Signed in as<br>
                                    <span class="font-medium text-gray-900"><?php echo htmlspecialchars($current_user['username'] ?? 'Unknown'); ?></span>
                                </div>
                                <?php if ($auth->hasRole(['super_admin', 'admin'])): ?>
                                <a href="admin_users.php" class="text-gray-700 hover:bg-gray-100 block px-4 py-2 text-sm transition-colors">
                                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/>
                                    </svg>
                                    User Management
                                </a>
                                <?php endif; ?>
                                <div class="border-t border-gray-100"></div>
                                <a href="logout.php" class="text-red-600 hover:bg-red-50 block px-4 py-2 text-sm transition-colors">
                                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Mobile menu (hidden by default) -->
        <div id="mobileMenu" class="md:hidden hidden border-t border-gray-200">
            <div class="px-2 pt-2 pb-3 space-y-1">
                <a href="dashboard.php" class="<?php echo $current_page == 'dashboard' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium">Dashboard</a>
                <a href="items.php" class="<?php echo $current_page == 'items' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium">Items</a>
                <a href="suppliers.php" class="<?php echo $current_page == 'suppliers' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium">Suppliers</a>
                <a href="purchase_orders.php" class="<?php echo $current_page == 'purchase_orders' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium">Purchase Orders</a>
                <a href="grn.php" class="<?php echo $current_page == 'grn' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium">GRN</a>
                <a href="mrn.php" class="<?php echo $current_page == 'mrn' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium">MRN</a>
                <a href="production.php" class="<?php echo $current_page == 'production' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium">Production</a>
                <a href="stock_ledger.php" class="<?php echo $current_page == 'stock_ledger' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium">Stock Ledger</a>
                <a href="stock_report.php" class="<?php echo $current_page == 'stock_report' ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium">Advanced Stock Report</a>
            </div>
        </div>
    </nav>

    <!-- Main Content Container -->
    <div class="max-w-7xl mx-auto px-4 py-6">
    
    <script>
        let dropdownTimeout;
        
        // Show dropdown with slight delay
        function showDropdown(element) {
            clearTimeout(dropdownTimeout);
            const dropdown = element.querySelector('.dropdown-menu');
            const arrow = element.querySelector('svg');
            
            if (dropdown) {
                dropdown.style.display = 'block';
                element.classList.add('active');
            }
            
            if (arrow) {
                arrow.style.transform = 'rotate(180deg)';
            }
        }
        
        // Hide dropdown with delay to allow mouse movement
        function hideDropdown(element) {
            dropdownTimeout = setTimeout(() => {
                const dropdown = element.querySelector('.dropdown-menu');
                const arrow = element.querySelector('svg');
                
                if (dropdown) {
                    dropdown.style.display = 'none';
                    element.classList.remove('active');
                }
                
                if (arrow) {
                    arrow.style.transform = 'rotate(0deg)';
                }
            }, 150); // 150ms delay to allow smooth mouse movement
        }
        
        // Mobile menu toggle
        function toggleMobileMenu() {
            const mobileMenu = document.getElementById('mobileMenu');
            mobileMenu.classList.toggle('hidden');
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const dropdowns = document.querySelectorAll('.dropdown');
            dropdowns.forEach(dropdown => {
                if (!dropdown.contains(event.target)) {
                    hideDropdown(dropdown);
                }
            });
        });
        
        // Handle mobile dropdown toggles
        function toggleMobileDropdown(element) {
            element.classList.toggle('mobile-open');
        }
        
        // Add mobile menu button
        document.addEventListener('DOMContentLoaded', function() {
            const nav = document.querySelector('nav .flex.justify-between');
            if (nav && !document.getElementById('mobileMenuButton')) {
                const mobileButton = document.createElement('button');
                mobileButton.id = 'mobileMenuButton';
                mobileButton.className = 'md:hidden text-gray-700 hover:bg-gray-100 p-2 rounded-md';
                mobileButton.innerHTML = `
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                `;
                mobileButton.onclick = toggleMobileMenu;
                nav.appendChild(mobileButton);
            }
            
            // Convert desktop dropdowns to click-based on mobile
            if (window.innerWidth <= 768) {
                const dropdowns = document.querySelectorAll('.dropdown');
                dropdowns.forEach(dropdown => {
                    const button = dropdown.querySelector('.dropdown-button');
                    if (button) {
                        button.onclick = function(e) {
                            e.preventDefault();
                            toggleMobileDropdown(dropdown);
                        };
                    }
                });
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                // Reset mobile dropdowns on desktop
                const dropdowns = document.querySelectorAll('.dropdown.mobile-open');
                dropdowns.forEach(dropdown => {
                    dropdown.classList.remove('mobile-open');
                });
            }
        });
    </script>