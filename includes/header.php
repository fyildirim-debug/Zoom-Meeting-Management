<?php
// Session ve auth kontrolü
if (!isLoggedIn()) {
    redirect('login.php');
}

// Session validation (kullanıcı durumu kontrolü dahil)
if (!validateSession()) {
    redirect('login.php');
}

$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    
    <!-- TailwindCSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            /* Theme colors */
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #f093fb;
            --secondary-dark: #ed64a6;
            --accent: #4fd1c7;
            --success: #48bb78;
            --warning: #ed8936;
            --error: #f56565;
            --info: #4299e1;
            
            /* Background gradients */
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-success: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            --gradient-warning: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            
            /* Glass morphism */
            --glass-bg: rgba(255, 255, 255, 0.25);
            --glass-border: rgba(255, 255, 255, 0.18);
            
            /* Light theme colors */
            --bg-primary: #f7fafc;
            --bg-secondary: #ffffff;
            --bg-tertiary: #edf2f7;
            --text-primary: #2d3748;
            --text-secondary: #4a5568;
            --text-muted: #718096;
            --border-color: #e2e8f0;
            --shadow: rgba(0, 0, 0, 0.1);
        }
        
        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            transition: all 0.3s ease;
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
        
        /* Glass card effect */
        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            box-shadow: 0 8px 32px 0 var(--shadow);
        }
        
        /* Custom buttons */
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: var(--gradient-secondary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(240, 147, 251, 0.3);
        }
        
        .btn-success {
            background: var(--gradient-success);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(72, 187, 120, 0.3);
        }
        
                    /* Header styles */
            .header {
                background: var(--bg-secondary);
                border-bottom: 1px solid var(--border-color);
                box-shadow: 0 2px 10px var(--shadow);
                position: sticky;
                top: 0;
                z-index: 1000;
            }
            
            /* Search styles */
            .line-clamp-1 {
                overflow: hidden;
                display: -webkit-box;
                -webkit-line-clamp: 1;
                -webkit-box-orient: vertical;
            }
        
        /* Dropdown styles */
        .dropdown {
            position: relative;
        }
        
        .dropdown-content {
            position: absolute;
            right: 0;
            top: 100%;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px var(--shadow);
            min-width: 200px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
        }
        
        .dropdown.active .dropdown-content {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        /* Badge styles */
        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-success {
            background-color: #48bb78;
            color: white;
        }
        
        .badge-warning {
            background-color: #ed8936;
            color: white;
        }
        
        .badge-error {
            background-color: #f56565;
            color: white;
        }
        
        .badge-info {
            background-color: #4299e1;
            color: white;
        }
        
        /* Animation classes */
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .animate-slide-in {
            animation: slideIn 0.5s ease-out;
        }
        
        .animate-bounce-in {
            animation: bounceIn 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes bounceIn {
            0% {
                opacity: 0;
                transform: scale(0.3);
            }
            50% {
                opacity: 1;
                transform: scale(1.05);
            }
            70% {
                transform: scale(0.9);
            }
            100% {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        /* Loading spinner */
        .loading-spinner {
            border: 2px solid transparent;
            border-top: 2px solid var(--primary);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Toast notification */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            color: white;
            font-weight: 600;
            box-shadow: 0 10px 25px var(--shadow);
            z-index: 99999;
            transform: translateX(400px);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .toast.show {
            transform: translateX(0);
        }
        
        .toast-success {
            background: var(--gradient-success);
        }
        
        .toast-error {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
        }
        
        .toast-warning {
            background: var(--gradient-warning);
        }
        
        .toast-info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
        }
        
        /* Form Inputs */
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        .form-error {
            color: var(--error);
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
        
        /* Modal Styles - Fixed positioning and centering */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-content {
            background: var(--bg-secondary);
            border-radius: 1rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.95);
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active .modal-content {
            transform: scale(1);
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: between;
        }
        
        .modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
            flex: 1;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            background-color: var(--bg-tertiary);
            color: var(--text-primary);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }
        
        /* Confirm Dialog */
        .confirm-dialog {
            max-width: 400px;
        }
        
        .confirm-dialog .modal-body {
            text-align: center;
        }
        
        .confirm-icon {
            width: 4rem;
            height: 4rem;
            margin: 0 auto 1rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .confirm-icon.warning {
            background-color: rgba(251, 191, 36, 0.1);
            color: #f59e0b;
        }
        
        .confirm-icon.danger {
            background-color: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .confirm-icon.success {
            background-color: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }
        
        .confirm-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .confirm-message {
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }
        
        /* Button variants */
        .btn-outline {
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--text-secondary);
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-outline:hover {
            background: var(--bg-tertiary);
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.3);
        }
        
        .btn-warning {
            background: var(--gradient-warning);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(237, 137, 54, 0.3);
        }
        
        /* Table Styles */
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th,
        .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .table th {
            background-color: var(--bg-tertiary);
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .table tbody tr:hover {
            background-color: var(--bg-tertiary);
        }
        
        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            border: 1px solid;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        
        .alert i {
            margin-right: 0.75rem;
            font-size: 1.125rem;
        }
        
        .alert-success {
            background-color: #f0f9ff;
            border-color: #22c55e;
            color: #15803d;
        }
        
        .alert-error {
            background-color: #fef2f2;
            border-color: #ef4444;
            color: #dc2626;
        }
        
        .alert-warning {
            background-color: #fffbeb;
            border-color: #f59e0b;
            color: #d97706;
        }
        
        .alert-info {
            background-color: #eff6ff;
            border-color: #3b82f6;
            color: #1d4ed8;
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .header {
                padding: 0.5rem 1rem;
            }
            
            .dropdown-content {
                right: -1rem;
                left: -1rem;
                min-width: auto;
            }
            
            .modal-content {
                width: 95%;
                margin: 1rem;
            }
            
            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1rem;
            }
        }
    </style>
    
    <!-- Additional page styles -->
    <?php if (isset($additionalStyles)): ?>
        <?php echo $additionalStyles; ?>
    <?php endif; ?>
    
    <!-- JavaScript Config -->
    <script>
        window.APP_CONFIG = {
            csrf_token: '<?php echo generateCSRFToken(); ?>',
            user_id: <?php echo $currentUser['id']; ?>,
            base_url: '<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']); ?>',
            api_base_path: '<?php 
                // Ana dizinin base URL'ini al
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $script_dir = dirname($_SERVER['SCRIPT_NAME']);
                
                if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
                    // Admin sayfasından ana dizine git
                    $base_path = dirname($script_dir);
                    echo $protocol . '://' . $host . $base_path . '/api/';
                } else {
                    echo 'api/';
                }
            ?>'
        };
    </script>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="flex items-center justify-between h-16 px-6">
            <!-- Logo & Menu Toggle -->
            <div class="flex items-center space-x-4">
                <!-- Mobile menu toggle -->
                <button
                    id="mobile-menu-toggle"
                    class="md:hidden p-2 rounded-lg hover:bg-gray-100 transition-colors"
                    onclick="toggleSidebar()"
                >
                    <i class="fas fa-bars text-gray-600"></i>
                </button>
                
                <!-- Logo -->
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-video text-white text-sm"></i>
                    </div>
                    <h1 class="text-xl font-bold text-gray-800 hidden sm:block">
                        <?php echo APP_NAME; ?>
                    </h1>
                </div>
            </div>
            
            <!-- Search Bar (Desktop) -->
            <div class="hidden lg:flex flex-1 max-w-lg mx-8">
                <div class="relative w-full">
                    <input
                        type="text"
                        placeholder="Toplantı ara..."
                        class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        id="global-search"
                        autocomplete="off"
                    >
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    
                    <!-- Search Results Dropdown -->
                    <div id="search-results" class="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-lg mt-1 hidden z-50 max-h-80 overflow-y-auto">
                        <!-- Loading state -->
                        <div id="search-loading" class="p-4 text-center text-gray-500 hidden">
                            <i class="fas fa-spinner fa-spin mr-2"></i>
                            Aranıyor...
                        </div>
                        
                        <!-- No results -->
                        <div id="search-no-results" class="p-4 text-center text-gray-500 hidden">
                            <i class="fas fa-search mr-2"></i>
                            Sonuç bulunamadı
                        </div>
                        
                        <!-- Results container -->
                        <div id="search-results-list"></div>
                    </div>
                </div>
            </div>
            
            <!-- Mobile Search Button -->
            <div class="lg:hidden">
                <button
                    id="mobile-search-toggle"
                    class="p-2 rounded-lg hover:bg-gray-100 transition-colors"
                    onclick="toggleMobileSearch()"
                >
                    <i class="fas fa-search text-gray-600"></i>
                </button>
            </div>

            <!-- Header Actions -->
            <div class="flex items-center space-x-4">
                <!-- User Dropdown -->
                <div class="relative dropdown">
                    <button
                        class="flex items-center space-x-3 p-2 rounded-lg hover:bg-gray-100 transition-colors"
                        onclick="toggleDropdown('user')"
                    >
                        <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center">
                            <span class="text-white text-sm font-bold">
                                <?php echo strtoupper(substr($currentUser['name'], 0, 1)); ?>
                            </span>
                        </div>
                        <div class="hidden sm:block text-left">
                            <p class="text-sm font-medium text-gray-800">
                                <?php echo $currentUser['name'] . ' ' . $currentUser['surname']; ?>
                            </p>
                            <p class="text-xs text-gray-500">
                                <?php echo $currentUser['role'] === 'admin' ? 'Yönetici' : 'Kullanıcı'; ?>
                            </p>
                        </div>
                        <i class="fas fa-chevron-down text-gray-400"></i>
                    </button>
                    
                    <div class="dropdown-content" id="user-dropdown">
                        <div class="p-4 border-b border-gray-200">
                            <p class="font-semibold text-gray-800">
                                <?php echo $currentUser['name'] . ' ' . $currentUser['surname']; ?>
                            </p>
                            <p class="text-sm text-gray-500"><?php echo $currentUser['email']; ?></p>
                        </div>
                        
                        <a href="<?php echo (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../profile.php' : 'profile.php'; ?>" class="flex items-center px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-user mr-3 text-gray-400"></i>
                            Profil Ayarları
                        </a>
                        
                        <a href="<?php echo (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../admin/settings.php' : 'admin/settings.php'; ?>" class="flex items-center px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-cog mr-3 text-gray-400"></i>
                            Sistem Ayarları
                        </a>
                        
                        <div class="border-t border-gray-200"></div>
                        
                        <a href="<?php echo (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../logout.php' : 'logout.php'; ?>" class="flex items-center px-4 py-3 text-sm text-red-600 hover:bg-red-50">
                            <i class="fas fa-sign-out-alt mr-3"></i>
                            Çıkış Yap
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Mobile Search Bar -->
        <div id="mobile-search" class="lg:hidden border-t border-gray-200 px-6 py-3 hidden">
            <div class="relative">
                <input
                    type="text"
                    placeholder="Toplantı ara..."
                    class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    id="mobile-global-search"
                    autocomplete="off"
                >
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                
                <!-- Mobile Search Results Dropdown -->
                <div id="mobile-search-results" class="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-lg mt-1 hidden z-50 max-h-80 overflow-y-auto">
                    <!-- Loading state -->
                    <div id="mobile-search-loading" class="p-4 text-center text-gray-500 hidden">
                        <i class="fas fa-spinner fa-spin mr-2"></i>
                        Aranıyor...
                    </div>
                    
                    <!-- No results -->
                    <div id="mobile-search-no-results" class="p-4 text-center text-gray-500 hidden">
                        <i class="fas fa-search mr-2"></i>
                        Sonuç bulunamadı
                    </div>
                    
                    <!-- Results container -->
                    <div id="mobile-search-results-list"></div>
                </div>
            </div>
        </div>
    </header>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed top-4 right-4 z-[9999] space-y-2"></div>

    <!-- Page Wrapper -->
    <div class="flex min-h-screen bg-gray-50">