<?php
// Session'ı HTML output'tan önce başlat
if (!isset($_SESSION)) {
    session_start();
}

// CSRF token oluştur
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Mevcut kurulum kontrolü
$isInstalled = false;
$configFiles = [
    '../config/config.php',
    '../config/database.php'
];

// Config dosyalarının varlığını kontrol et
foreach ($configFiles as $file) {
    if (file_exists($file)) {
        $isInstalled = true;
        break;
    }
}

// SQLite veritabanı kontrolü
if (file_exists('../database/zoom_meetings.db')) {
    $isInstalled = true;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isInstalled ? 'Sistem Yönetimi' : 'Kurulum'; ?> - Zoom Toplantı Yönetim Sistemi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-success: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-dark: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
            --glass-bg: rgba(255, 255, 255, 0.15);
            --glass-border: rgba(255, 255, 255, 0.2);
            --shadow-glow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            min-height: 100vh;
            position: relative;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><radialGradient id="a" cx="50%" cy="50%"><stop offset="0%" stop-color="%23fff" stop-opacity="0.1"/><stop offset="100%" stop-color="%23fff" stop-opacity="0"/></radialGradient></defs><circle cx="200" cy="200" r="150" fill="url(%23a)"/><circle cx="800" cy="300" r="200" fill="url(%23a)"/><circle cx="600" cy="800" r="180" fill="url(%23a)"/></svg>');
            pointer-events: none;
            opacity: 0.3;
        }
        
        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow-glow);
        }
        
        .step-indicator {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        
        .step-active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            transform: scale(1.2);
            box-shadow: 0 10px 25px rgba(79, 172, 254, 0.4);
        }
        
        .step-active::before {
            opacity: 1;
        }
        
        .step-completed {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            box-shadow: 0 10px 25px rgba(72, 187, 120, 0.4);
        }
        
        .form-step {
            display: none;
            animation: fadeInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .form-step.active {
            display: block;
        }
        
        @keyframes fadeInUp {
            from { 
                opacity: 0; 
                transform: translateY(30px);
            }
            to { 
                opacity: 1; 
                transform: translateY(0);
            }
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 15px 35px rgba(79, 172, 254, 0.4);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #a855f7 0%, #e879f9 100%);
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(168, 85, 247, 0.3);
        }
        
        .input-field {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        
        .input-field:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.2);
        }
        
        .loading-spinner {
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top: 3px solid #4facfe;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: none;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .installation-step .w-6 {
            transition: all 0.3s ease;
        }
        
        .installation-step.active .w-6 {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            transform: scale(1.1);
        }
        
        .installation-step.completed .w-6 {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }
        
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
    </style>
</head>
<body>
    <div class="container mx-auto px-4 py-8 relative z-10">
        <div class="max-w-4xl mx-auto">
            <!-- Existing Installation Check -->
            <?php if ($isInstalled): ?>
            <div id="existing-installation" class="glass-card rounded-3xl p-8 shadow-2xl mb-8">
                <div class="text-center">
                    <div class="w-24 h-24 mx-auto mb-6 bg-gradient-to-br from-yellow-400 to-red-500 rounded-2xl flex items-center justify-center shadow-2xl">
                        <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    </div>
                    <h2 class="text-3xl font-bold text-white mb-4">Mevcut Kurulum Tespit Edildi</h2>
                    <p class="text-white opacity-80 mb-8">Sistem zaten kurulmuş görünüyor. Ne yapmak istiyorsunuz?</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                        <!-- Migration Option -->
                        <div class="bg-white bg-opacity-10 backdrop-blur-lg border border-white border-opacity-20 rounded-2xl p-6 hover:bg-opacity-20 transition-all cursor-pointer" onclick="window.startMigration()">
                            <div class="w-16 h-16 mx-auto mb-4 bg-blue-500 rounded-xl flex items-center justify-center">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-white mb-2">Migration Çalıştır</h3>
                            <p class="text-white opacity-70 text-sm">Mevcut veritabanını güncelle</p>
                            
                            <!-- Migration Controls -->
                            <div class="mt-4 hidden" id="migration-controls">
                                <button type="button" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-semibold transition-all" onclick="window.startMigration()" id="migrate-btn">
                                    <span id="migrate-text">🔄 Migration Çalıştır</span>
                                    <div class="loading-spinner inline-block ml-2" id="migrate-spinner" style="display: none;"></div>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Reinstall Option -->
                        <div class="bg-white bg-opacity-10 backdrop-blur-lg border border-white border-opacity-20 rounded-2xl p-6 hover:bg-opacity-20 transition-all cursor-pointer" onclick="window.showReinstallModal()">
                            <div class="w-16 h-16 mx-auto mb-4 bg-red-500 rounded-xl flex items-center justify-center">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-white mb-2">Yeniden Kur</h3>
                            <p class="text-white opacity-70 text-sm">Tüm verileri sil ve temiz başla</p>
                            
                            <!-- Reinstall Controls -->
                            <div class="mt-4 hidden" id="reinstall-controls">
                                <button type="button" class="w-full bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-semibold transition-all" onclick="window.showReinstallModal()" id="reinstall-btn">
                                    <span id="reinstall-text">🔄 Yeniden Kur</span>
                                    <div class="loading-spinner inline-block ml-2" id="reinstall-spinner" style="display: none;"></div>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Continue Option -->
                        <div class="bg-white bg-opacity-10 backdrop-blur-lg border border-white border-opacity-20 rounded-2xl p-6 hover:bg-opacity-20 transition-all cursor-pointer" onclick="window.location.href='../dashboard.php'">
                            <div class="w-16 h-16 mx-auto mb-4 bg-green-500 rounded-xl flex items-center justify-center">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-white mb-2">Sisteme Git</h3>
                            <p class="text-white opacity-70 text-sm">Mevcut sistemi kullan</p>
                        </div>
                        
                        <!-- Clean Config Option -->
                        <div class="bg-white bg-opacity-10 backdrop-blur-lg border border-white border-opacity-20 rounded-2xl p-6 hover:bg-opacity-20 transition-all cursor-pointer" onclick="window.showCleanConfigModal()">
                            <div class="w-16 h-16 mx-auto mb-4 bg-orange-500 rounded-xl flex items-center justify-center">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-white mb-2">Ayar Dosyaları Sil</h3>
                            <p class="text-white opacity-70 text-sm">Config dosyalarını temizle</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Modals - Available in both states -->
            <!-- Reinstall Confirmation Modal -->
            <div id="reinstall-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
                <div class="glass-card rounded-3xl p-8 max-w-md w-full mx-4">
                    <div class="text-center">
                        <div class="w-16 h-16 mx-auto mb-4 bg-red-500 rounded-full flex items-center justify-center">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-4">DİKKAT: Yeniden Kurulum</h3>
                        <p class="text-white opacity-80 mb-6">
                            Bu işlem tüm mevcut verileri kalıcı olarak silecektir. Bu işlem geri alınamaz!
                        </p>
                        <div class="mb-6">
                            <label class="block text-white text-sm font-semibold mb-3">Onay için "YENIDEN_KUR_ONAYI" yazın:</label>
                            <input type="text" id="confirmation-code" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" placeholder="YENIDEN_KUR_ONAYI">
                        </div>
                        <div class="flex space-x-4">
                            <button type="button" class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-6 py-3 rounded-xl font-semibold transition-all flex-1" onclick="window.hideReinstallModal()">
                                İptal
                            </button>
                            <button type="button" class="bg-red-500 hover:bg-red-600 text-white px-6 py-3 rounded-xl font-semibold transition-all flex-1" onclick="window.confirmReinstall()">
                                Yeniden Kur
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Clean Config Confirmation Modal -->
            <div id="clean-config-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
                <div class="glass-card rounded-3xl p-8 max-w-md w-full mx-4">
                    <div class="text-center">
                        <div class="w-16 h-16 mx-auto mb-4 bg-orange-500 rounded-full flex items-center justify-center">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-4">DİKKAT: Ayar Dosyaları Temizleme</h3>
                        <p class="text-white opacity-80 mb-6">
                            Bu işlem sadece config dosyalarını silecektir. Veritabanı verileriniz korunacak. Sistem hiç kurulummamış gibi görünecek.
                        </p>
                        <div class="mb-6">
                            <label class="block text-white text-sm font-semibold mb-3">Onay için "CONFIG_SIL" yazın:</label>
                            <input type="text" id="clean-confirmation-code" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" placeholder="CONFIG_SIL">
                        </div>
                        <div class="flex space-x-4">
                            <button type="button" class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-6 py-3 rounded-xl font-semibold transition-all flex-1" onclick="window.hideCleanConfigModal()">
                                İptal
                            </button>
                            <button type="button" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-3 rounded-xl font-semibold transition-all flex-1" onclick="window.confirmCleanConfig()">
                                Temizle
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$isInstalled): ?>

            <!-- Header -->
            <div class="text-center mb-12" id="main-header">
                <div class="inline-flex items-center justify-center w-24 h-24 bg-white bg-opacity-20 backdrop-blur-lg rounded-2xl mb-6 shadow-2xl">
                    <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <h1 class="text-5xl font-bold text-white mb-6 tracking-tight">
                    Zoom Toplantı Yönetim Sistemi
                </h1>
                <p class="text-xl text-white opacity-90 max-w-2xl mx-auto leading-relaxed">
                    Modern ve kullanıcı dostu toplantı yönetimi için kurulum sihirbazınıza hoş geldiniz
                </p>
            </div>

            <!-- Progress Steps -->
            <div class="flex justify-center mb-12">
                <div class="flex items-center space-x-6">
                    <div class="step-indicator step-active w-14 h-14 rounded-full flex items-center justify-center font-bold text-lg" data-step="1">1</div>
                    <div class="w-16 h-1 bg-white bg-opacity-30 rounded-full"></div>
                    <div class="step-indicator w-14 h-14 rounded-full flex items-center justify-center font-bold text-lg bg-white bg-opacity-20 text-white" data-step="2">2</div>
                    <div class="w-16 h-1 bg-white bg-opacity-30 rounded-full"></div>
                    <div class="step-indicator w-14 h-14 rounded-full flex items-center justify-center font-bold text-lg bg-white bg-opacity-20 text-white" data-step="3">3</div>
                    <div class="w-16 h-1 bg-white bg-opacity-30 rounded-full"></div>
                    <div class="step-indicator w-14 h-14 rounded-full flex items-center justify-center font-bold text-lg bg-white bg-opacity-20 text-white" data-step="4">4</div>
                    <div class="w-16 h-1 bg-white bg-opacity-30 rounded-full"></div>
                    <div class="step-indicator w-14 h-14 rounded-full flex items-center justify-center font-bold text-lg bg-white bg-opacity-20 text-white" data-step="5">5</div>
                </div>
            </div>

            <!-- Installation Form -->
            <div class="glass-card rounded-3xl p-8 shadow-2xl">
                <form id="installationForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="install">
                    <!-- Step 1: Hoş Geldiniz -->
                    <div class="form-step active" data-step="1">
                        <div class="text-center">
                            <div class="w-32 h-32 mx-auto mb-8 bg-gradient-to-br from-blue-400 to-purple-500 rounded-3xl flex items-center justify-center shadow-2xl">
                                <svg class="w-16 h-16 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                            <h2 class="text-4xl font-bold text-white mb-6">Hoş Geldiniz! 🎉</h2>
                            
                            <div class="bg-white bg-opacity-10 backdrop-blur-lg border border-white border-opacity-20 rounded-2xl p-6 mb-8">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-blue-300" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3 text-left">
                                        <p class="text-blue-100 leading-relaxed">
                                            Bu kurulum sihirbazı sizin için Zoom Toplantı Yönetim Sistemi'ni kuracak. 
                                            Kurulum işlemi yaklaşık 5 dakika sürecektir.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-left mb-8 bg-white bg-opacity-5 backdrop-blur-lg rounded-2xl p-6">
                                <h3 class="text-2xl font-semibold text-white mb-6 text-center">Sistem Özellikleri</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="flex items-center text-white">
                                        <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center mr-4">
                                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span>Zoom API entegrasyonu</span>
                                    </div>
                                    <div class="flex items-center text-white">
                                        <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center mr-4">
                                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span>Birim bazlı yetkilendirme</span>
                                    </div>
                                    <div class="flex items-center text-white">
                                        <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center mr-4">
                                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span>Akıllı çakışma kontrolü</span>
                                    </div>
                                    <div class="flex items-center text-white">
                                        <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center mr-4">
                                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span>Modern responsive tasarım</span>
                                    </div>
                                    <div class="flex items-center text-white">
                                        <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center mr-4">
                                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span>Detaylı raporlama</span>
                                    </div>
                                    <div class="flex items-center text-white">
                                        <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center mr-4">
                                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span>Güvenli yapı</span>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="button" class="btn-primary text-white px-12 py-4 rounded-xl font-semibold text-lg shadow-2xl" onclick="window.nextStep()">
                                <span class="relative z-10">Kuruluma Başla</span>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Veritabanı Ayarları -->
                    <div class="form-step" data-step="2">
                        <div class="text-center mb-8">
                            <div class="w-24 h-24 mx-auto mb-6 bg-gradient-to-br from-green-400 to-blue-500 rounded-2xl flex items-center justify-center shadow-2xl">
                                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                </svg>
                            </div>
                            <h2 class="text-3xl font-bold text-white mb-4">Veritabanı Ayarları</h2>
                            <p class="text-white opacity-80">Veritabanı bağlantı bilgilerinizi girin</p>
                        </div>
                        
                        <div class="mb-6">
                            <label class="block text-white text-sm font-semibold mb-3">Veritabanı Türü</label>
                            <select id="db_type" name="db_type" class="input-field w-full px-4 py-3 rounded-xl text-white focus:outline-none" onchange="window.toggleDbFields()">
                                <option value="mysql">MySQL</option>
                                <option value="sqlite">SQLite</option>
                            </select>
                        </div>


                        <div id="mysql_fields">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-white text-sm font-semibold mb-3">Sunucu Adresi</label>
                                    <input type="text" id="db_host" name="db_host" value="localhost" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" required>
                                </div>
                                <div>
                                    <label class="block text-white text-sm font-semibold mb-3">Port</label>
                                    <input type="number" id="db_port" name="db_port" value="3306" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" required>
                                </div>
                            </div>

                            <div class="mb-6">
                                <label class="block text-white text-sm font-semibold mb-3">
                                    Veritabanı Adı
                                    <span class="text-red-300">*</span>
                                </label>
                                <input type="text" id="db_name" name="db_name" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" placeholder="my_database_name" required>
                                <p class="text-white opacity-70 text-sm mt-2">
                                    ⚠️ Shared hosting kullanıyorsanız hosting sağlayıcınızın size verdiği veritabanı adını girin
                                </p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-white text-sm font-semibold mb-3">Kullanıcı Adı</label>
                                    <input type="text" id="db_username" name="db_username" value="root" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" required>
                                </div>
                                <div>
                                    <label class="block text-white text-sm font-semibold mb-3">Şifre</label>
                                    <input type="password" id="db_password" name="db_password" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" placeholder="••••••••">
                                </div>
                            </div>

                            <!-- Database Creation Option -->
                            <div class="mb-6">
                                <div class="bg-white bg-opacity-10 backdrop-blur-lg border border-white border-opacity-20 rounded-2xl p-6">
                                    <div class="flex items-center justify-between mb-4">
                                        <div class="flex items-center">
                                            <div class="w-12 h-12 bg-orange-500 rounded-xl flex items-center justify-center mr-4">
                                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                                </svg>
                                            </div>
                                            <div>
                                                <h3 class="text-white font-semibold text-lg">Veritabanı Oluşturma</h3>
                                                <p class="text-white opacity-70 text-sm">Veritabanı otomatik olarak oluşturulsun mu?</p>
                                            </div>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" id="auto_create_db" name="auto_create_db" class="sr-only peer" checked onchange="window.toggleAutoCreateDb()">
                                            <div class="w-11 h-6 bg-white bg-opacity-30 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-600"></div>
                                        </label>
                                    </div>
                                    
                                    <div id="auto-create-info" class="space-y-3">
                                        <div class="text-white text-sm">
                                            <div class="flex items-start">
                                                <svg class="w-5 h-5 text-green-400 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                </svg>
                                                <div>
                                                    <p class="font-semibold text-green-300 mb-1">Otomatik Oluşturma AÇIK</p>
                                                    <p class="opacity-80">Sistem belirttiğiniz veritabanını oluşturmaya çalışacak. Lokal sunucular ve VPS'ler için uygundur.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div id="manual-create-info" class="hidden space-y-3">
                                        <div class="text-white text-sm">
                                            <div class="flex items-start">
                                                <svg class="w-5 h-5 text-yellow-400 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                                </svg>
                                                <div>
                                                    <p class="font-semibold text-yellow-300 mb-1">Manuel Veritabanı KAPALI</p>
                                                    <p class="opacity-80">Veritabanı zaten mevcut olduğu varsayılır. Shared hosting için idealdir. Hosting sağlayıcınızdan aldığınız veritabanı adını kullanın.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="sqlite_fields" style="display: none;">
                            <div class="bg-white bg-opacity-10 backdrop-blur-lg border border-white border-opacity-20 rounded-2xl p-6">
                                <div class="flex items-center mb-3">
                                    <svg class="w-6 h-6 text-green-400 mr-3" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5 3a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V5a2 2 0 00-2-2H5zm0 2h10v7h-2l-1-2H8l-1 2H5V5z" clip-rule="evenodd"></path>
                                    </svg>
                                    <h3 class="text-white font-semibold text-lg">SQLite Veritabanı</h3>
                                </div>
                                <p class="text-white opacity-80 mb-3">
                                    SQLite veritabanı dosyası otomatik olarak oluşturulacak:
                                </p>
                                <div class="bg-white bg-opacity-20 rounded-lg p-3 font-mono text-center">
                                    <span class="text-green-300 font-semibold">database/zoom_meetings.sqlite</span>
                                </div>
                                <p class="text-white opacity-70 text-sm mt-2">
                                    ✅ Kurulum gerektirmez<br>
                                    ✅ Hızlı ve kolay<br>
                                    ✅ Küçük projeler için ideal
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:justify-between space-y-4 sm:space-y-0 sm:space-x-4">
                            <button type="button" class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-6 py-3 rounded-xl font-semibold transition-all" onclick="window.prevStep()">
                                ← Geri
                            </button>
                            <div class="flex space-x-4">
                                <button type="button" class="btn-secondary text-white px-6 py-3 rounded-xl font-semibold" onclick="window.testDbConnection()" id="test-db-btn">
                                    <span id="test-db-text">🔍 Bağlantıyı Test Et</span>
                                    <div class="loading-spinner inline-block ml-2" id="test-db-spinner"></div>
                                </button>
                                <button type="button" class="btn-primary text-white px-6 py-3 rounded-xl font-semibold" onclick="window.nextStep()" id="db-next-btn" disabled>
                                    İleri →
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Admin Kullanıcı -->
                    <div class="form-step" data-step="3">
                        <div class="text-center mb-8">
                            <div class="w-24 h-24 mx-auto mb-6 bg-gradient-to-br from-purple-400 to-pink-500 rounded-2xl flex items-center justify-center shadow-2xl">
                                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                            <h2 class="text-3xl font-bold text-white mb-4">Yönetici Hesabı</h2>
                            <p class="text-white opacity-80">Sistem yöneticisi bilgilerini girin</p>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label class="block text-white text-sm font-semibold mb-3">Ad</label>
                                <input type="text" id="admin_name" name="admin_name" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" placeholder="Admin" required>
                            </div>
                            <div>
                                <label class="block text-white text-sm font-semibold mb-3">Soyad</label>
                                <input type="text" id="admin_surname" name="admin_surname" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" placeholder="Kullanıcı" required>
                            </div>
                        </div>

                        <div class="mb-6">
                            <label class="block text-white text-sm font-semibold mb-3">E-posta Adresi</label>
                            <input type="email" id="admin_email" name="admin_email" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" placeholder="admin@firma.com" required>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label class="block text-white text-sm font-semibold mb-3">Şifre</label>
                                <input type="password" id="admin_password" name="admin_password" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" placeholder="••••••••" required>
                            </div>
                            <div>
                                <label class="block text-white text-sm font-semibold mb-3">Şifre Tekrar</label>
                                <input type="password" id="admin_password_confirm" name="admin_password_confirm" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" placeholder="••••••••" required>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:justify-between space-y-4 sm:space-y-0 sm:space-x-4">
                            <button type="button" class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-6 py-3 rounded-xl font-semibold transition-all" onclick="window.prevStep()">
                                ← Geri
                            </button>
                            <button type="button" class="btn-primary text-white px-6 py-3 rounded-xl font-semibold" onclick="window.nextStep()">
                                İleri →
                            </button>
                        </div>
                    </div>

                    <!-- Step 4: Sistem Ayarları -->
                    <div class="form-step" data-step="4">
                        <div class="text-center mb-8">
                            <div class="w-24 h-24 mx-auto mb-6 bg-gradient-to-br from-yellow-400 to-orange-500 rounded-2xl flex items-center justify-center shadow-2xl">
                                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </div>
                            <h2 class="text-3xl font-bold text-white mb-4">Sistem Ayarları</h2>
                            <p class="text-white opacity-80">Temel sistem yapılandırmasını tamamlayın</p>
                        </div>
                        
                        <div class="mb-6">
                            <label class="block text-white text-sm font-semibold mb-3">Site Başlığı</label>
                            <input type="text" id="site_title" name="site_title" value="Zoom Toplantı Yönetim Sistemi" class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-white placeholder-opacity-60 focus:outline-none" required>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label class="block text-white text-sm font-semibold mb-3">Çalışma Başlangıç Saati</label>
                                <input type="time" id="work_start" name="work_start" value="09:00" class="input-field w-full px-4 py-3 rounded-xl text-white focus:outline-none" required>
                            </div>
                            <div>
                                <label class="block text-white text-sm font-semibold mb-3">Çalışma Bitiş Saati</label>
                                <input type="time" id="work_end" name="work_end" value="18:00" class="input-field w-full px-4 py-3 rounded-xl text-white focus:outline-none" required>
                            </div>
                        </div>

                        <div class="mb-6">
                            <label class="block text-white text-sm font-semibold mb-3">Varsayılan Zaman Dilimi</label>
                            <select id="timezone" name="timezone" class="input-field w-full px-4 py-3 rounded-xl text-white focus:outline-none">
                                <option value="Europe/Istanbul" selected>Turkey Time (UTC+3)</option>
                                <option value="Europe/London">London Time (UTC+0)</option>
                                <option value="America/New_York">New York Time (UTC-5)</option>
                                <option value="Asia/Tokyo">Tokyo Time (UTC+9)</option>
                            </select>
                        </div>

                        <!-- Sample Data Section -->
                        <div class="mb-8">
                            <div class="bg-white bg-opacity-10 backdrop-blur-lg border border-white border-opacity-20 rounded-2xl p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 bg-blue-500 rounded-xl flex items-center justify-center mr-4">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 class="text-white font-semibold text-lg">Test Verileri</h3>
                                            <p class="text-white opacity-70 text-sm">Sistemi hemen test etmek için örnek veriler</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="sample_data" name="sample_data" class="sr-only peer" checked onchange="window.toggleSampleDataInfo()">
                                        <div class="w-11 h-6 bg-white bg-opacity-30 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                    </label>
                                </div>
                                
                                <div id="sample-data-info" class="space-y-4">
                                    <div class="text-white text-sm mb-4">
                                        <p class="mb-3 opacity-90">
                                            <strong>📊 Test verileri neler içerir?</strong>
                                        </p>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div class="bg-white bg-opacity-5 rounded-lg p-3">
                                                <div class="flex items-center mb-2">
                                                    <svg class="w-4 h-4 text-green-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    <span class="font-semibold text-green-300">Test Kullanıcıları</span>
                                                </div>
                                                <ul class="text-xs opacity-80 ml-6 space-y-1">
                                                    <li>• Ahmet Yılmaz (Geliştirici)</li>
                                                    <li>• Ayşe Kaya (İnsan Kaynakları)</li>
                                                    <li>• Mehmet Demir (Pazarlama)</li>
                                                    <li>• Fatma Öz (IT Destek)</li>
                                                </ul>
                                            </div>
                                            
                                            <div class="bg-white bg-opacity-5 rounded-lg p-3">
                                                <div class="flex items-center mb-2">
                                                    <svg class="w-4 h-4 text-blue-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v8a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2H4zm2 6a2 2 0 104 0 2 2 0 00-4 0zm8 0a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    <span class="font-semibold text-blue-300">Birimler</span>
                                                </div>
                                                <ul class="text-xs opacity-80 ml-6 space-y-1">
                                                    <li>• IT Birimi (limit: 15/hafta)</li>
                                                    <li>• İnsan Kaynakları (limit: 10/hafta)</li>
                                                    <li>• Pazarlama (limit: 12/hafta)</li>
                                                    <li>• Muhasebe (limit: 8/hafta)</li>
                                                </ul>
                                            </div>
                                            
                                            <div class="bg-white bg-opacity-5 rounded-lg p-3">
                                                <div class="flex items-center mb-2">
                                                    <svg class="w-4 h-4 text-purple-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    <span class="font-semibold text-purple-300">Toplantılar</span>
                                                </div>
                                                <ul class="text-xs opacity-80 ml-6 space-y-1">
                                                    <li>• Bekleyen talepler (5 adet)</li>
                                                    <li>• Onaylanmış toplantılar (8 adet)</li>
                                                    <li>• Geçmiş toplantılar (12 adet)</li>
                                                    <li>• Reddedilen talepler (2 adet)</li>
                                                </ul>
                                            </div>
                                            
                                            <div class="bg-white bg-opacity-5 rounded-lg p-3">
                                                <div class="flex items-center mb-2">
                                                    <svg class="w-4 h-4 text-yellow-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    <span class="font-semibold text-yellow-300">Zoom Hesapları</span>
                                                </div>
                                                <ul class="text-xs opacity-80 ml-6 space-y-1">
                                                    <li>• 3 adet test Zoom hesabı</li>
                                                    <li>• Farklı hesap tipleri (Basic, Pro)</li>
                                                    <li>• Eşzamanlı toplantı destekleri</li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-4 p-3 bg-yellow-400 bg-opacity-20 border border-yellow-400 border-opacity-30 rounded-lg">
                                            <div class="flex items-start">
                                                <svg class="w-5 h-5 text-yellow-300 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                                </svg>
                                                <div>
                                                    <p class="text-yellow-200 text-xs font-semibold">Öneri:</p>
                                                    <p class="text-yellow-100 text-xs mt-1">
                                                        İlk kez kurulum yapıyorsanız test verilerini yüklemenizi öneririz. 
                                                        Bu sayede sistemi hemen test edebilir ve nasıl çalıştığını görebilirsiniz.
                                                        Daha sonra gerçek verilerinizi ekleyebilirsiniz.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="sample-data-disabled" class="hidden">
                                    <div class="text-center py-4">
                                        <svg class="w-12 h-12 text-white opacity-50 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                                        </svg>
                                        <p class="text-white opacity-70 text-sm">
                                            Test verileri yüklenmeyecek. Sistem boş olarak kurulacak.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:justify-between space-y-4 sm:space-y-0 sm:space-x-4">
                            <button type="button" class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-6 py-3 rounded-xl font-semibold transition-all" onclick="window.prevStep()">
                                ← Geri
                            </button>
                            <button type="button" class="btn-primary text-white px-6 py-3 rounded-xl font-semibold" onclick="window.nextStep()">
                                İleri →
                            </button>
                        </div>
                    </div>

                    <!-- Step 5: Kurulum -->
                    <div class="form-step" data-step="5">
                        <div class="text-center mb-8">
                            <div class="w-24 h-24 mx-auto mb-6 bg-gradient-to-br from-green-400 to-emerald-500 rounded-2xl flex items-center justify-center shadow-2xl">
                                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                            <h2 class="text-3xl font-bold text-white mb-4">Kurulum Başlıyor</h2>
                            <p class="text-white opacity-80">Sistem kurulumu tamamlanıyor...</p>
                        </div>
                        
                        <div id="installation-progress" class="mb-8">
                            <div class="space-y-6">
                                <div class="installation-step" data-step="config">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-white bg-opacity-20 mr-4 flex items-center justify-center">
                                            <span class="step-number text-sm text-white font-bold">1</span>
                                            <svg class="step-check w-5 h-5 text-white hidden" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span class="text-white font-semibold">Yapılandırma dosyaları oluşturuluyor...</span>
                                    </div>
                                </div>
                                
                                <div class="installation-step" data-step="database">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-white bg-opacity-20 mr-4 flex items-center justify-center">
                                            <span class="step-number text-sm text-white font-bold">2</span>
                                            <svg class="step-check w-5 h-5 text-white hidden" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span class="text-white font-semibold">Veritabanı tabloları oluşturuluyor...</span>
                                    </div>
                                </div>
                                
                                <div class="installation-step" data-step="admin">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-white bg-opacity-20 mr-4 flex items-center justify-center">
                                            <span class="step-number text-sm text-white font-bold">3</span>
                                            <svg class="step-check w-5 h-5 text-white hidden" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span class="text-white font-semibold">Yönetici hesabı oluşturuluyor...</span>
                                    </div>
                                </div>
                                
                                <div class="installation-step" data-step="sample">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-white bg-opacity-20 mr-4 flex items-center justify-center">
                                            <span class="step-number text-sm text-white font-bold">4</span>
                                            <svg class="step-check w-5 h-5 text-white hidden" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span class="text-white font-semibold">Örnek veriler ekleniyor...</span>
                                    </div>
                                </div>
                                
                                <div class="installation-step" data-step="security">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-white bg-opacity-20 mr-4 flex items-center justify-center">
                                            <span class="step-number text-sm text-white font-bold">5</span>
                                            <svg class="step-check w-5 h-5 text-white hidden" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <span class="text-white font-semibold">Güvenlik ayarları yapılandırılıyor...</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="installation-result" class="hidden">
                            <div class="bg-white bg-opacity-10 backdrop-blur-lg border border-white border-opacity-20 rounded-2xl p-8 mb-8">
                                <div class="flex items-center mb-6 justify-center">
                                    <div class="w-16 h-16 bg-green-500 rounded-full flex items-center justify-center mr-4">
                                        <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <h3 class="text-2xl font-bold text-white">Kurulum Başarılı! 🎉</h3>
                                </div>
                                <p class="text-white text-center mb-6 text-lg">
                                    Zoom Toplantı Yönetim Sistemi başarıyla kuruldu. Artık sistemi kullanmaya başlayabilirsiniz.
                                </p>
                                <div class="text-center text-white">
                                    <p class="mb-2"><strong>Yönetici E-posta:</strong> <span id="result-admin-email" class="font-mono bg-white bg-opacity-20 px-2 py-1 rounded"></span></p>
                                    <p><strong>Giriş Adresi:</strong> <a href="../login.php" class="text-blue-300 hover:text-blue-200 underline font-semibold">Sisteme Giriş Yap</a></p>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:justify-between space-y-4 sm:space-y-0 sm:space-x-4">
                            <button type="button" class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-6 py-3 rounded-xl font-semibold transition-all" onclick="window.prevStep()" id="install-prev-btn">
                                ← Geri
                            </button>
                            <button type="button" class="btn-primary text-white px-8 py-3 rounded-xl font-semibold" onclick="window.startInstallation()" id="install-btn">
                                <span id="install-text">🚀 Kurulumu Başlat</span>
                                <div class="loading-spinner inline-block ml-2" id="install-spinner"></div>
                            </button>
                            <a href="../login.php" class="btn-primary text-white px-8 py-3 rounded-xl font-semibold text-center hidden shadow-2xl" id="finish-btn">
                                🎯 Sisteme Giriş Yap
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- JavaScript - Available in both states -->
    <script>
        // CSRF Token
        const CSRF_TOKEN = '<?php echo $csrfToken; ?>';
        
        // Migration functionality - MUST be defined first
        window.startMigration = function() {
            const migrateBtn = document.getElementById('migrate-btn');
            const migrateText = document.getElementById('migrate-text');
            const migrateSpinner = document.getElementById('migrate-spinner');
            
            if (migrateBtn && migrateText && migrateSpinner) {
                migrateBtn.disabled = true;
                migrateText.textContent = 'Migration çalışıyor...';
                migrateSpinner.style.display = 'inline-block';
                
                // Redirect to migration page
                setTimeout(() => {
                    window.location.href = '../admin/migrate-start-urls.php';
                }, 500);
            } else {
                // Direct redirect if elements not found
                window.location.href = '../admin/migrate-start-urls.php';
            }
        };
        
        // Modal functions - available in both installed and not installed states
        window.showReinstallModal = function() {
            const modal = document.getElementById('reinstall-modal');
            if (modal) {
                modal.style.display = 'flex';
            }
        };

        window.hideReinstallModal = function() {
            const modal = document.getElementById('reinstall-modal');
            if (modal) {
                modal.style.display = 'none';
            }
            const confirmationInput = document.getElementById('confirmation-code');
            if (confirmationInput) {
                confirmationInput.value = '';
            }
        };

        window.confirmReinstall = function() {
            const confirmationCode = document.getElementById('confirmation-code').value;
            if (confirmationCode === 'YENIDEN_KUR_ONAYI') {
                // Create a form to submit the reinstall request
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'process.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'reinstall';
                form.appendChild(actionInput);
                
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = CSRF_TOKEN;
                form.appendChild(csrfInput);
                
                document.body.appendChild(form);
                form.submit();
            } else {
                alert('Lütfen onay kodunu doğru girin: YENIDEN_KUR_ONAYI');
            }
        };

        window.showCleanConfigModal = function() {
            const modal = document.getElementById('clean-config-modal');
            if (modal) {
                modal.style.display = 'flex';
            }
        };

        window.hideCleanConfigModal = function() {
            const modal = document.getElementById('clean-config-modal');
            if (modal) {
                modal.style.display = 'none';
            }
            const confirmationInput = document.getElementById('clean-confirmation-code');
            if (confirmationInput) {
                confirmationInput.value = '';
            }
        };

        window.confirmCleanConfig = function() {
            const confirmationCode = document.getElementById('clean-confirmation-code').value;
            if (confirmationCode === 'CONFIG_SIL') {
                // Create a form to submit the clean config request
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'process.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'clean_config';
                form.appendChild(actionInput);
                
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = CSRF_TOKEN;
                form.appendChild(csrfInput);
                
                document.body.appendChild(form);
                form.submit();
            } else {
                alert('Lütfen onay kodunu doğru girin: CONFIG_SIL');
            }
        };

        // Installation functions (if needed)
        let currentStep = 1;
        const totalSteps = 5;

        window.nextStep = function() {
            if (currentStep < totalSteps) {
                // Hide current step
                const currentStepElement = document.querySelector(`.form-step[data-step="${currentStep}"]`);
                if (currentStepElement) {
                    currentStepElement.classList.remove('active');
                }
                
                // Show next step
                currentStep++;
                const nextStepElement = document.querySelector(`.form-step[data-step="${currentStep}"]`);
                if (nextStepElement) {
                    nextStepElement.classList.add('active');
                }
                
                // Update progress indicators
                updateProgressIndicators();
                
                // Auto-start installation on final step
                if (currentStep === totalSteps) {
                    setTimeout(() => {
                        startInstallation();
                    }, 500);
                }
            }
        };

        window.prevStep = function() {
            if (currentStep > 1) {
                // Hide current step
                const currentStepElement = document.querySelector(`.form-step[data-step="${currentStep}"]`);
                if (currentStepElement) {
                    currentStepElement.classList.remove('active');
                }
                
                // Show previous step
                currentStep--;
                const prevStepElement = document.querySelector(`.form-step[data-step="${currentStep}"]`);
                if (prevStepElement) {
                    prevStepElement.classList.add('active');
                }
                
                // Update progress indicators
                updateProgressIndicators();
            }
        };

        window.updateProgressIndicators = function() {
            for (let i = 1; i <= totalSteps; i++) {
                const indicator = document.querySelector(`.step-indicator[data-step="${i}"]`);
                if (indicator) {
                    indicator.classList.remove('step-active', 'step-completed');
                    
                    if (i === currentStep) {
                        indicator.classList.add('step-active');
                    } else if (i < currentStep) {
                        indicator.classList.add('step-completed');
                    }
                }
            }
        };

        window.toggleDbFields = function() {
            const dbType = document.getElementById('db_type');
            const mysqlFields = document.getElementById('mysql_fields');
            const sqliteFields = document.getElementById('sqlite_fields');
            
            if (dbType && mysqlFields && sqliteFields) {
                if (dbType.value === 'mysql') {
                    mysqlFields.style.display = 'block';
                    sqliteFields.style.display = 'none';
                } else {
                    mysqlFields.style.display = 'none';
                    sqliteFields.style.display = 'block';
                }
            }
        };

        window.testDbConnection = function() {
            const testBtn = document.getElementById('test-db-btn');
            const testText = document.getElementById('test-db-text');
            const testSpinner = document.getElementById('test-db-spinner');
            const nextBtn = document.getElementById('db-next-btn');
            
            if (testBtn && testText && testSpinner) {
                testBtn.disabled = true;
                testText.textContent = 'Test ediliyor...';
                testSpinner.style.display = 'inline-block';
                
                // Collect database connection data
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('action', 'test_db');
                formData.append('db_type', document.getElementById('db_type').value);
                formData.append('db_host', document.getElementById('db_host').value);
                formData.append('db_port', document.getElementById('db_port').value);
                formData.append('db_name', document.getElementById('db_name').value);
                formData.append('db_username', document.getElementById('db_username').value);
                formData.append('db_password', document.getElementById('db_password').value);
                formData.append('auto_create_db', document.getElementById('auto_create_db').checked ? '1' : '0');
                
                // Send AJAX request to test database connection
                fetch('process.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    testBtn.disabled = false;
                    testSpinner.style.display = 'none';
                    
                    if (data.success) {
                        testText.textContent = '✅ Bağlantı Başarılı';
                        testText.classList.add('text-green-400');
                        testText.classList.remove('text-red-400');
                        if (nextBtn) {
                            nextBtn.disabled = false;
                        }
                    } else {
                        testText.textContent = '❌ Bağlantı Başarısız';
                        testText.classList.add('text-red-400');
                        testText.classList.remove('text-green-400');
                        if (nextBtn) {
                            nextBtn.disabled = true;
                        }
                        
                        // Show error message
                        if (data.message) {
                            alert('Veritabanı Bağlantı Hatası: ' + data.message);
                        }
                    }
                    
                    // Reset after 5 seconds
                    setTimeout(() => {
                        testText.textContent = '🔍 Bağlantıyı Test Et';
                        testText.classList.remove('text-green-400', 'text-red-400');
                    }, 5000);
                })
                .catch(error => {
                    testBtn.disabled = false;
                    testSpinner.style.display = 'none';
                    testText.textContent = '❌ Test Hatası';
                    testText.classList.add('text-red-400');
                    console.error('Database test error:', error);
                    alert('Veritabanı test edilirken bir hata oluştu.');
                    
                    // Reset after 3 seconds
                    setTimeout(() => {
                        testText.textContent = '🔍 Bağlantıyı Test Et';
                        testText.classList.remove('text-red-400');
                    }, 3000);
                });
            }
        };

        window.toggleSampleDataInfo = function() {
            const checkbox = document.getElementById('sample_data');
            const infoDiv = document.getElementById('sample-data-info');
            const disabledDiv = document.getElementById('sample-data-disabled');
            
            if (checkbox && infoDiv && disabledDiv) {
                if (checkbox.checked) {
                    infoDiv.style.display = 'block';
                    disabledDiv.style.display = 'none';
                } else {
                    infoDiv.style.display = 'none';
                    disabledDiv.style.display = 'block';
                }
            }
        };

        window.toggleAutoCreateDb = function() {
            const checkbox = document.getElementById('auto_create_db');
            const autoInfo = document.getElementById('auto-create-info');
            const manualInfo = document.getElementById('manual-create-info');
            
            if (checkbox && autoInfo && manualInfo) {
                if (checkbox.checked) {
                    autoInfo.style.display = 'block';
                    manualInfo.style.display = 'none';
                } else {
                    autoInfo.style.display = 'none';
                    manualInfo.style.display = 'block';
                }
            }
        };

        window.startInstallation = function() {
            const installBtn = document.getElementById('install-btn');
            const installText = document.getElementById('install-text');
            const installSpinner = document.getElementById('install-spinner');
            const prevBtn = document.getElementById('install-prev-btn');
            const form = document.getElementById('installationForm');
            
            if (installBtn && installText && installSpinner) {
                installBtn.disabled = true;
                installText.textContent = 'Kuruluyor...';
                installSpinner.style.display = 'inline-block';
                if (prevBtn) {
                    prevBtn.disabled = true;
                }
                
                // Start visual installation simulation
                window.simulateInstallation();
                
                // Submit the form via AJAX after animation completes
                setTimeout(() => {
                    window.submitInstallationForm();
                }, 7500); // Wait for all animation steps to complete
            }
        };
        
        window.submitInstallationForm = function() {
            const form = document.getElementById('installationForm');
            if (!form) return;
            
            // Collect form data
            const formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('action', 'install');
            
            // Add all form fields
            const fields = [
                'db_type', 'db_host', 'db_port', 'db_name', 'db_username', 'db_password', 'auto_create_db',
                'admin_name', 'admin_surname', 'admin_email', 'admin_password',
                'admin_password_confirm', 'site_title', 'work_start', 'work_end',
                'timezone', 'sample_data'
            ];
            
            fields.forEach(fieldName => {
                const field = document.getElementById(fieldName);
                if (field) {
                    if (field.type === 'checkbox') {
                        formData.append(fieldName, field.checked ? '1' : '0');
                    } else {
                        formData.append(fieldName, field.value);
                    }
                }
            });
            
            // Send AJAX request
            fetch('process.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.showInstallationResult(data);
                } else {
                    alert('Kurulum Hatası: ' + (data.message || 'Bilinmeyen hata'));
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Installation Error:', error);
                alert('Kurulum sırasında bir hata oluştu. Lütfen tekrar deneyin.');
                window.location.reload();
            });
        };

        window.simulateInstallation = function() {
            const steps = ['config', 'database', 'admin', 'sample', 'security'];
            let currentStepIndex = 0;
            
            function processStep() {
                if (currentStepIndex < steps.length) {
                    const stepElement = document.querySelector(`.installation-step[data-step="${steps[currentStepIndex]}"]`);
                    if (stepElement) {
                        stepElement.classList.add('active');
                        
                        setTimeout(() => {
                            stepElement.classList.remove('active');
                            stepElement.classList.add('completed');
                            
                            const stepNumber = stepElement.querySelector('.step-number');
                            const stepCheck = stepElement.querySelector('.step-check');
                            
                            if (stepNumber) stepNumber.style.display = 'none';
                            if (stepCheck) stepCheck.style.display = 'block';
                            
                            currentStepIndex++;
                            setTimeout(processStep, 500);
                        }, 1500);
                    } else {
                        currentStepIndex++;
                        setTimeout(processStep, 100);
                    }
                } else {
                    // Installation complete
                    window.showInstallationResult();
                }
            }
            
            processStep();
        };

        window.showInstallationResult = function(data) {
            const progressDiv = document.getElementById('installation-progress');
            const resultDiv = document.getElementById('installation-result');
            const installBtn = document.getElementById('install-btn');
            const finishBtn = document.getElementById('finish-btn');
            
            if (progressDiv) progressDiv.style.display = 'none';
            if (resultDiv) resultDiv.classList.remove('hidden');
            if (installBtn) installBtn.style.display = 'none';
            if (finishBtn) finishBtn.classList.remove('hidden');
            
            // Set admin email in result from response data
            const resultEmail = document.getElementById('result-admin-email');
            if (resultEmail && data && data.data && data.data.admin_email) {
                resultEmail.textContent = data.data.admin_email;
            } else {
                // Fallback to form input if response data is not available
                const adminEmailInput = document.getElementById('admin_email');
                if (adminEmailInput && resultEmail) {
                    resultEmail.textContent = adminEmailInput.value || 'admin@example.com';
                }
            }
        };

        // Auto-generate database name
        window.updateDbName = function() {
            const now = new Date();
            const timestamp = now.getFullYear() +
                             String(now.getMonth() + 1).padStart(2, '0') +
                             String(now.getDate()).padStart(2, '0') + '_' +
                             String(now.getHours()).padStart(2, '0') +
                             String(now.getMinutes()).padStart(2, '0') +
                             String(now.getSeconds()).padStart(2, '0');
            
            const dbNameElement = document.getElementById('auto-db-name');
            if (dbNameElement) {
                dbNameElement.textContent = `zoom_meetings_${timestamp}`;
            }
        };

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            window.updateDbName();
            
            // Update database name every second
            setInterval(window.updateDbName, 1000);
            
            // Close modals on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    window.hideReinstallModal();
                    window.hideCleanConfigModal();
                }
            });
        });
    </script>
</body>
</html>