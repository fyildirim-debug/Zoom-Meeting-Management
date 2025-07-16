<?php
$pageTitle = 'Zoom Hesapları';
require_once '../config/config.php';
require_once '../config/auth.php';

requireLogin();
if (!isAdmin()) {
    redirect('../dashboard.php');
}

$currentUser = getCurrentUser();

// Zoom hesap işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Güvenlik token hatası. Sayfayı yenileyin ve tekrar deneyin.';
        $messageType = 'error';
    } elseif (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'add_account':
                $result = addZoomAccount($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'edit_account':
                $result = editZoomAccount($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'delete_account':
                $result = deleteZoomAccount($_POST['account_id']);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'toggle_status':
                $result = toggleAccountStatus($_POST['account_id']);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'test_connection':
                $result = testZoomConnection($_POST['account_id']);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'migrate':
                $result = runDatabaseMigrations();
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'api_test':
                $result = runZoomAPITestCase($_POST['account_id'], $_POST['test_type']);
                header('Content-Type: application/json');
                echo json_encode($result);
                exit;
                break;
                
            default:
                $message = 'Geçersiz işlem.';
                $messageType = 'error';
                break;
        }
    }
}

// Zoom hesaplarını listele
try {
    $stmt = $pdo->query("
        SELECT za.*, 
               COUNT(m.id) as total_meetings,
               COUNT(CASE WHEN m.date >= CURDATE() - INTERVAL 7 DAY THEN 1 END) as weekly_meetings,
               COUNT(CASE WHEN m.date >= CURDATE() AND m.status = 'approved' THEN 1 END) as upcoming_meetings
        FROM zoom_accounts za
        LEFT JOIN meetings m ON za.id = m.zoom_account_id
        GROUP BY za.id
        ORDER BY za.status DESC, za.email
    ");
    $zoomAccounts = $stmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("Zoom accounts page error: " . $e->getMessage(), 'error');
    $zoomAccounts = [];
}

// Helper functions
function addZoomAccount($data) {
    global $pdo;
    
    try {
        // Email kontrolü
        $stmt = $pdo->prepare("SELECT id FROM zoom_accounts WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Bu e-posta adresi zaten kayıtlı.'];
        }
        
        // Name alanını email'den türet
        $name = explode('@', $data['email'])[0];
        $name = ucfirst(str_replace('.', ' ', $name)) . ' Zoom Hesabı';
        
        // Account ID'yi unique oluştur
        $accountId = 'acc_' . uniqid();
        
        $stmt = $pdo->prepare("
            INSERT INTO zoom_accounts (name, email, api_key, api_secret, account_id, account_type, max_concurrent_meetings, status, client_id, client_secret, webhook_secret, webhook_verification)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $name,
            $data['email'],
            $data['client_id'], // api_key alanına client_id'yi koyuyoruz geriye uyumluluk için
            $data['client_secret'], // api_secret alanına client_secret'i koyuyoruz
            $data['account_id'], // Bu artık gerçek Zoom Account ID
            $data['account_type'],
            (int)$data['max_concurrent_meetings'],
            $data['client_id'],
            $data['client_secret'],
            $data['webhook_secret'] ?? '',
            $data['webhook_verification'] ?? ''
        ]);
        
        if ($result) {
            writeLog("New Zoom account added: " . $data['email'], 'info');
            return ['success' => true, 'message' => 'Zoom hesabı başarıyla eklendi.'];
        } else {
            return ['success' => false, 'message' => 'Zoom hesabı eklenirken veritabanı hatası oluştu.'];
        }
        
    } catch (Exception $e) {
        writeLog("Add Zoom account error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Zoom hesabı eklenirken hata oluştu: ' . $e->getMessage()];
    }
}

function editZoomAccount($data) {
    global $pdo;
    
    try {
        // Email kontrolü (mevcut hesap dışında)
        $stmt = $pdo->prepare("SELECT id FROM zoom_accounts WHERE email = ? AND id != ?");
        $stmt->execute([$data['email'], $data['account_id']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Bu e-posta adresi zaten kayıtlı.'];
        }
        
        // Name alanını email'den türet
        $name = explode('@', $data['email'])[0];
        $name = ucfirst(str_replace('.', ' ', $name)) . ' Zoom Hesabı';
        
        $stmt = $pdo->prepare("
            UPDATE zoom_accounts
            SET name = ?, email = ?, api_key = ?, api_secret = ?, account_id = ?, account_type = ?, max_concurrent_meetings = ?, client_id = ?, client_secret = ?, webhook_secret = ?, webhook_verification = ?
            WHERE id = ?
        ");
        $result = $stmt->execute([
            $name,
            $data['email'],
            $data['client_id'], // api_key alanına client_id'yi koyuyoruz
            $data['client_secret'], // api_secret alanına client_secret'i koyuyoruz
            $data['account_id_field'], // Bu gerçek Zoom Account ID
            $data['account_type'],
            (int)$data['max_concurrent_meetings'],
            $data['client_id'],
            $data['client_secret'],
            $data['webhook_secret'] ?? '',
            $data['webhook_verification'] ?? '',
            $data['account_id'] // Bu database ID'si
        ]);
        
        if ($result) {
            writeLog("Zoom account updated: " . $data['email'], 'info');
            return ['success' => true, 'message' => 'Zoom hesabı başarıyla güncellendi.'];
        } else {
            return ['success' => false, 'message' => 'Zoom hesabı güncellenirken veritabanı hatası oluştu.'];
        }
        
    } catch (Exception $e) {
        writeLog("Edit Zoom account error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Zoom hesabı güncellenirken hata oluştu: ' . $e->getMessage()];
    }
}

function deleteZoomAccount($accountId) {
    global $pdo;
    
    try {
        // Aktif toplantı kontrolü
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM meetings 
            WHERE zoom_account_id = ? AND status = 'approved' AND date >= CURDATE()
        ");
        $stmt->execute([$accountId]);
        $activeMeetings = $stmt->fetchColumn();
        
        if ($activeMeetings > 0) {
            return ['success' => false, 'message' => 'Bu hesapta aktif toplantılar var. Önce toplantıları başka hesaba taşıyın.'];
        }
        
        $stmt = $pdo->prepare("DELETE FROM zoom_accounts WHERE id = ?");
        $result = $stmt->execute([$accountId]);
        
        if ($result) {
            writeLog("Zoom account deleted: ID " . $accountId, 'info');
            return ['success' => true, 'message' => 'Zoom hesabı başarıyla silindi.'];
        }
        
    } catch (Exception $e) {
        writeLog("Delete Zoom account error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Zoom hesabı silinirken hata oluştu.'];
    }
}

function toggleAccountStatus($accountId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE zoom_accounts 
            SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END 
            WHERE id = ?
        ");
        $result = $stmt->execute([$accountId]);
        
        if ($result) {
            writeLog("Zoom account status toggled: ID " . $accountId, 'info');
            return ['success' => true, 'message' => 'Hesap durumu güncellendi.'];
        }
        
    } catch (Exception $e) {
        writeLog("Toggle Zoom account status error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Hesap durumu güncellenirken hata oluştu.'];
    }
}

function testZoomConnection($accountId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM zoom_accounts WHERE id = ?");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        
        if (!$account) {
            return ['success' => false, 'message' => 'Hesap bulunamadı.'];
        }
        
        // OAuth credentials kontrolü - önce yeni alanları kontrol et, yoksa eski alanları kullan
        $clientId = !empty($account['client_id']) ? $account['client_id'] : $account['api_key'];
        $clientSecret = !empty($account['client_secret']) ? $account['client_secret'] : $account['api_secret'];
        
        if (empty($clientId) || empty($clientSecret) || empty($account['account_id'])) {
            return ['success' => false, 'message' => 'OAuth bilgileri eksik: Client ID, Client Secret ve Account ID gerekli.'];
        }
        
        // Gerçek Zoom API bağlantı testi
        try {
            require_once '../includes/ZoomAPI.php';
            $zoomAPI = new ZoomAPI($clientId, $clientSecret, $account['account_id']);
            $testResult = $zoomAPI->testConnection();
            
            if ($testResult['success']) {
                // Test başarılı olarak işaretle
                $currentDateTime = date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("UPDATE zoom_accounts SET last_test_at = ? WHERE id = ?");
                $stmt->execute([$currentDateTime, $accountId]);
                
                writeLog("Zoom connection tested successfully: " . $account['email'], 'info');
                return ['success' => true, 'message' => 'Zoom API bağlantısı başarılı! ' . $testResult['message']];
            } else {
                writeLog("Zoom connection test failed: " . $account['email'] . " - " . $testResult['message'], 'error');
                return ['success' => false, 'message' => 'Zoom API bağlantısı başarısız: ' . $testResult['message']];
            }
            
        } catch (Exception $apiException) {
            writeLog("Zoom API test error: " . $apiException->getMessage(), 'error');
            return ['success' => false, 'message' => 'Zoom API hatası: ' . $apiException->getMessage()];
        }
        
    } catch (Exception $e) {
        writeLog("Test Zoom connection error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Bağlantı testi sırasında hata oluştu: ' . $e->getMessage()];
    }
}

// API Test function
function runZoomAPITestCase($accountId, $testType) {
    global $pdo;
    
    try {
        // Hesap bilgilerini al
        $stmt = $pdo->prepare("SELECT * FROM zoom_accounts WHERE id = ? AND status = 'active'");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        
        if (!$account) {
            return [
                'success' => false,
                'message' => 'Hesap bulunamadı veya aktif değil',
                'timestamp' => date('c')
            ];
        }
        
        // OAuth credentials kontrolü
        $clientId = !empty($account['client_id']) ? $account['client_id'] : $account['api_key'];
        $clientSecret = !empty($account['client_secret']) ? $account['client_secret'] : $account['api_secret'];
        
        if (empty($clientId) || empty($clientSecret) || empty($account['account_id'])) {
            return [
                'success' => false,
                'message' => 'OAuth bilgileri eksik: Client ID, Client Secret ve Account ID gerekli',
                'timestamp' => date('c')
            ];
        }
        
        // ZoomAPI instance oluştur
        require_once '../includes/ZoomAPI.php';
        $zoomAPI = new ZoomAPI($clientId, $clientSecret, $account['account_id']);
        
        $result = [
            'success' => false,
            'message' => '',
            'data' => null,
            'timestamp' => date('c'),
            'test_type' => $testType,
            'account_email' => $account['email']
        ];
        
        switch ($testType) {
            case 'connection':
                $testResult = $zoomAPI->testConnection();
                $result['success'] = $testResult['success'];
                $result['message'] = $testResult['message'];
                $result['data'] = $testResult['data'] ?? null;
                break;
                
            case 'user_info':
                $testResult = $zoomAPI->makeRequest('/users/me');
                if ($testResult['success']) {
                    $result['success'] = true;
                    $result['message'] = 'Kullanıcı bilgileri başarıyla alındı';
                    $result['data'] = $testResult['data'];
                } else {
                    $result['message'] = 'Kullanıcı bilgileri alınamadı';
                }
                break;
                
            case 'account_info':
                $testResult = $zoomAPI->getAccountInfo();
                $result['success'] = $testResult['success'];
                $result['message'] = $testResult['success'] ? 'Hesap bilgileri başarıyla alındı' : $testResult['message'];
                $result['data'] = $testResult['data'] ?? null;
                break;
                
            case 'meetings_list':
                $testResult = $zoomAPI->listUserMeetings('me', 'scheduled');
                $result['success'] = $testResult['success'];
                $result['message'] = $testResult['success'] ? 'Toplantı listesi başarıyla alındı' : $testResult['message'];
                $result['data'] = $testResult['data'] ?? null;
                break;
                
            case 'create_test_meeting':
                $meetingData = [
                    'title' => 'API Test Toplantısı - ' . date('Y-m-d H:i:s'),
                    'description' => 'Bu toplantı API test amaçlı oluşturulmuştur. Güvenle silinebilir.',
                    'date' => date('Y-m-d'),
                    'start_time' => date('H:i:s', strtotime('+1 hour')),
                    'end_time' => date('H:i:s', strtotime('+2 hours')),
                    'host_email' => $account['email']
                ];
                
                $testResult = $zoomAPI->createMeeting($meetingData);
                $result['success'] = $testResult['success'];
                $result['message'] = $testResult['message'];
                $result['data'] = $testResult['data'] ?? null;
                
                if ($testResult['success']) {
                    writeLog("Test meeting created: " . $testResult['data']['meeting_id'], 'info');
                }
                break;
                
            default:
                $result['message'] = 'Geçersiz test türü: ' . $testType;
                break;
        }
        
        // Test sonucunu logla
        writeLog("Zoom API Test - {$testType} for account {$account['email']}: " . ($result['success'] ? 'SUCCESS' : 'FAILED'), 'info');
        
        return $result;
        
    } catch (Exception $e) {
        writeLog("Zoom API Test Error: " . $e->getMessage(), 'error');
        return [
            'success' => false,
            'message' => 'Test sırasında hata oluştu: ' . $e->getMessage(),
            'error_details' => $e->getTraceAsString(),
            'timestamp' => date('c')
        ];
    }
}

// Database migration function
function runDatabaseMigrations() {
    global $pdo;
    
    try {
        $migrationLog = [];
        $migrationLog[] = "Veritabani migrationlari kontrol ediliyor...";
        
        // Determine database type
        $dbType = defined('DB_TYPE') ? DB_TYPE : 'mysql';
        
        // Check and add missing columns to zoom_accounts table
        try {
            if ($dbType === 'mysql') {
                $stmt = $pdo->query("DESCRIBE zoom_accounts");
            } else {
                $stmt = $pdo->query("PRAGMA table_info(zoom_accounts)");
            }
            $existingColumns = [];
            
            if ($dbType === 'mysql') {
                $existingColumns = array_column($stmt->fetchAll(), 'Field');
            } else {
                $existingColumns = array_column($stmt->fetchAll(), 'name');
            }
            
            $zoomColumns = [
                'name' => 'VARCHAR(255) NOT NULL DEFAULT "Zoom Hesabı"',
                'email' => 'VARCHAR(255) NOT NULL DEFAULT ""',
                'account_id' => 'VARCHAR(255) NOT NULL DEFAULT ""',
                'account_type' => ($dbType === 'mysql' ? "ENUM('basic', 'pro', 'business') DEFAULT 'basic'" : "VARCHAR(20) DEFAULT 'basic'"),
                'max_concurrent_meetings' => 'INTEGER DEFAULT 1',
                'last_test_at' => 'DATETIME NULL'
            ];
            
            foreach ($zoomColumns as $column => $definition) {
                if (!in_array($column, $existingColumns)) {
                    if ($column === 'name') {
                        $pdo->exec("ALTER TABLE zoom_accounts ADD COLUMN $column $definition");
                    } elseif ($column === 'email') {
                        $pdo->exec("ALTER TABLE zoom_accounts ADD COLUMN $column $definition");
                    } elseif ($column === 'account_id') {
                        $pdo->exec("ALTER TABLE zoom_accounts ADD COLUMN $column $definition");
                    } else {
                        $pdo->exec("ALTER TABLE zoom_accounts ADD COLUMN $column $definition");
                    }
                    $migrationLog[] = "zoom_accounts.$column eklendi";
                }
            }
            
            // Update empty name fields
            $stmt = $pdo->query("SELECT COUNT(*) FROM zoom_accounts WHERE name = '' OR name IS NULL");
            $emptyNameCount = $stmt->fetchColumn();
            if ($emptyNameCount > 0) {
                $pdo->exec("UPDATE zoom_accounts SET name = CONCAT('Zoom Hesabı - ', email) WHERE name = '' OR name IS NULL");
                $migrationLog[] = "Boş name alanları güncellendi ($emptyNameCount adet)";
            }
            
            // Update empty account_id fields
            $stmt = $pdo->query("SELECT COUNT(*) FROM zoom_accounts WHERE account_id = '' OR account_id IS NULL");
            $emptyAccountIdCount = $stmt->fetchColumn();
            if ($emptyAccountIdCount > 0) {
                $pdo->exec("UPDATE zoom_accounts SET account_id = CONCAT('acc_', id, '_', UNIX_TIMESTAMP()) WHERE account_id = '' OR account_id IS NULL");
                $migrationLog[] = "Boş account_id alanları güncellendi ($emptyAccountIdCount adet)";
            }
            
            // Update empty email fields
            $stmt = $pdo->query("SELECT COUNT(*) FROM zoom_accounts WHERE email = '' OR email IS NULL");
            $emptyEmailCount = $stmt->fetchColumn();
            if ($emptyEmailCount > 0) {
                $pdo->exec("UPDATE zoom_accounts SET email = CONCAT('zoom', id, '@company.com') WHERE email = '' OR email IS NULL");
                $migrationLog[] = "Boş email alanları güncellendi ($emptyEmailCount adet)";
            }
            
        } catch (Exception $e) {
            $migrationLog[] = "Zoom accounts tablosu kontrol hatasi: " . $e->getMessage();
        }
        
        $migrationLog[] = "Migrationlar tamamlandi!";
        writeLog("Database migrations completed: " . implode(', ', $migrationLog), 'info');
        return ['success' => true, 'message' => 'Migrationlar başarıyla tamamlandı!', 'log' => $migrationLog];
        
    } catch (Exception $e) {
        writeLog("Migration error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Migration hatası: ' . $e->getMessage()];
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content flex-1 p-6">
    <div class="max-w-7xl mx-auto">
        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Zoom Hesapları</h1>
                <p class="mt-2 text-gray-600">Zoom hesaplarını ve API ayarlarını yönetin</p>
            </div>
            <div class="mt-4 sm:mt-0">
                <button onclick="openAddAccountModal()" class="btn-primary">
                    <i class="fas fa-plus mr-2"></i>
                    Yeni Hesap
                </button>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> mb-6">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Toplam Hesap</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo count($zoomAccounts); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-camera text-blue-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Aktif Hesap</p>
                        <p class="text-3xl font-bold text-green-600">
                            <?php echo count(array_filter($zoomAccounts, function($a) { return $a['status'] === 'active'; })); ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-circle text-green-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Toplam Kapasite</p>
                        <p class="text-3xl font-bold text-purple-600">
                            <?php echo array_sum(array_column($zoomAccounts, 'max_concurrent_meetings')); ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-users text-purple-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Bu Hafta</p>
                        <p class="text-3xl font-bold text-orange-600">
                            <?php echo array_sum(array_column($zoomAccounts, 'weekly_meetings')); ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar-week text-orange-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Zoom Accounts Table -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Zoom Hesap Listesi</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hesap</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tip</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kapasite</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kullanım</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durum</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Son Test</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($zoomAccounts as $account): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-video text-blue-600"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($account['email']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                API Key: <?php echo substr($account['api_key'], 0, 8) . '...'; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                        <?php echo $account['account_type'] === 'pro' ? 'bg-purple-100 text-purple-800' : 
                                                  ($account['account_type'] === 'business' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'); ?>">
                                        <?php 
                                        $types = ['basic' => 'Basic', 'pro' => 'Pro', 'business' => 'Business'];
                                        echo $types[$account['account_type']] ?? ucfirst($account['account_type']); 
                                        ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <div class="flex items-center">
                                        <span class="font-medium"><?php echo $account['max_concurrent_meetings']; ?></span>
                                        <span class="text-gray-500 ml-1">eşzamanlı</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <div>Toplam: <span class="font-medium"><?php echo $account['total_meetings']; ?></span></div>
                                        <div>Bu hafta: <span class="font-medium"><?php echo $account['weekly_meetings']; ?></span></div>
                                        <div>Yaklaşan: <span class="font-medium text-blue-600"><?php echo $account['upcoming_meetings']; ?></span></div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $account['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $account['status'] === 'active' ? 'Aktif' : 'Pasif'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php if ($account['last_test_at']): ?>
                                        <?php echo formatDate($account['last_test_at']); ?>
                                    <?php else: ?>
                                        <span class="text-gray-400">Hiç test edilmedi</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <button onclick="editAccount(<?php echo htmlspecialchars(json_encode($account)); ?>)" 
                                                class="text-blue-600 hover:text-blue-900" title="Düzenle">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="test_connection">
                                            <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                            <button type="submit" class="text-green-600 hover:text-green-900" title="Bağlantı Testi">
                                                <i class="fas fa-plug"></i>
                                            </button>
                                        </form>
                                        
                                        <button onclick="toggleStatusConfirm(<?php echo $account['id']; ?>, '<?php echo $account['status']; ?>', '<?php echo htmlspecialchars($account['email']); ?>')"
                                                class="text-yellow-600 hover:text-yellow-900" title="Durumu Değiştir">
                                            <i class="fas fa-toggle-<?php echo $account['status'] === 'active' ? 'on' : 'off'; ?>"></i>
                                        </button>
                                        
                                        <?php if ($account['upcoming_meetings'] == 0): ?>
                                            <form method="POST" style="display: inline;"
                                                  onsubmit="return confirm('Bu hesabı silmek istediğinize emin misiniz?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="action" value="delete_account">
                                                <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-900" title="Sil">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-gray-400" title="Aktif toplantılar var">
                                                <i class="fas fa-trash"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if (empty($zoomAccounts)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-camera text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-500">Henüz Zoom hesabı eklenmemiş.</p>
                        <button onclick="openAddAccountModal()" class="btn-primary mt-4">
                            <i class="fas fa-plus mr-2"></i>
                            İlk Hesabı Ekle
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- API Test Section -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 mt-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">🧪 Zoom API Test Alanı</h3>
                <p class="text-sm text-gray-600 mt-1">Zoom hesaplarınızı ve API bağlantılarını gerçek zamanlı olarak test edin</p>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Test Controls -->
                    <div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Test Edilecek Hesap</label>
                            <select id="testAccountSelect" class="form-select">
                                <option value="">Hesap seçin...</option>
                                <?php foreach ($zoomAccounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>"
                                            data-email="<?php echo htmlspecialchars($account['email']); ?>"
                                            data-client-id="<?php echo htmlspecialchars($account['client_id'] ?: $account['api_key']); ?>"
                                            data-account-id="<?php echo htmlspecialchars($account['account_id']); ?>">
                                        <?php echo htmlspecialchars($account['email']); ?>
                                        <?php if ($account['name']): ?>
                                            - <?php echo htmlspecialchars($account['name']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Test Türü</label>
                            <select id="testTypeSelect" class="form-select">
                                <option value="connection">🔗 Bağlantı Testi (OAuth Token)</option>
                                <option value="user_info">👤 Kullanıcı Bilgileri (/users/me)</option>
                                <option value="account_info">🏢 Hesap Bilgileri (/accounts/me)</option>
                                <option value="meetings_list">📅 Toplantı Listesi (/users/me/meetings)</option>
                                <option value="create_test_meeting">➕ Test Toplantısı Oluştur</option>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <button id="runTestBtn" onclick="runZoomAPITest()" class="btn-primary w-full">
                                <i class="fas fa-play mr-2"></i>
                                Test Çalıştır
                            </button>
                        </div>
                        
                        <div class="text-xs text-gray-500 bg-gray-50 p-3 rounded">
                            <p><strong>Not:</strong> Bu testler gerçek Zoom API endpoint'lerini kullanır.</p>
                            <p>• "Test Toplantısı Oluştur" gerçek bir toplantı oluşturur (silinebilir)</p>
                            <p>• Diğer testler sadece bilgi okur, değişiklik yapmaz</p>
                        </div>
                    </div>
                    
                    <!-- Test Results -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Test Sonucu</label>
                        <div id="testResults" class="bg-gray-50 border border-gray-200 rounded-lg p-4 h-80 overflow-y-auto font-mono text-sm">
                            <div class="text-gray-500 text-center py-8">
                                Test sonuçları burada görünecek...
                            </div>
                        </div>
                        
                        <div class="mt-4 flex space-x-2">
                            <button onclick="clearTestResults()" class="btn-secondary text-sm">
                                <i class="fas fa-trash mr-1"></i>
                                Temizle
                            </button>
                            <button onclick="copyTestResults()" class="btn-secondary text-sm">
                                <i class="fas fa-copy mr-1"></i>
                                Kopyala
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Account Modal -->
<div id="addAccountModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Yeni Zoom Hesabı Ekle</h3>
            </div>
            
            <form method="POST" class="p-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="add_account">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">E-posta Adresi</label>
                    <input type="email" name="email" required class="form-input">
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Account ID</label>
                    <input type="text" name="account_id" required class="form-input" placeholder="VpV8nqkuTW-O2TM9vZVsxg">
                    <p class="text-sm text-gray-500 mt-1">Zoom Server-to-Server OAuth Account ID</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Client ID</label>
                    <input type="text" name="client_id" required class="form-input" placeholder="fe_CAWDTNOOBsjss7RkA">
                    <p class="text-sm text-gray-500 mt-1">Zoom OAuth Client ID</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Client Secret</label>
                    <input type="password" name="client_secret" required class="form-input" placeholder="3iKxExz2mKNImnpAqyFasjCPVsOoYOf7">
                    <p class="text-sm text-gray-500 mt-1">Zoom OAuth Client Secret</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Webhook Secret Token (İsteğe bağlı)</label>
                    <input type="password" name="webhook_secret" class="form-input" placeholder="rd12HGmSRpWtxd3TAhW4Lg">
                    <p class="text-sm text-gray-500 mt-1">Event notifications için webhook secret token</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Webhook Verification Token (İsteğe bağlı)</label>
                    <input type="text" name="webhook_verification" class="form-input" placeholder="ESIq0OeqT6ywsb7CTnrj5A">
                    <p class="text-sm text-gray-500 mt-1">Webhook doğrulama token'ı</p>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Hesap Tipi</label>
                        <select name="account_type" required class="form-select">
                            <option value="basic">Basic</option>
                            <option value="pro">Pro</option>
                            <option value="business">Business</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Maksimum Eşzamanlı Toplantı</label>
                        <input type="number" name="max_concurrent_meetings" min="1" max="100" value="1" required class="form-input">
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeAddAccountModal()" class="btn-secondary">İptal</button>
                    <button type="submit" class="btn-primary">Hesap Ekle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Account Modal -->
<div id="editAccountModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Zoom Hesabı Düzenle</h3>
            </div>
            
            <form method="POST" class="p-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="edit_account">
                <input type="hidden" name="account_id" id="edit_account_id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">E-posta Adresi</label>
                    <input type="email" name="email" id="edit_email" required class="form-input">
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Account ID</label>
                    <input type="text" name="account_id_field" id="edit_account_id_field" required class="form-input">
                    <p class="text-sm text-gray-500 mt-1">Zoom Server-to-Server OAuth Account ID</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Client ID</label>
                    <input type="text" name="client_id" id="edit_client_id" required class="form-input">
                    <p class="text-sm text-gray-500 mt-1">Zoom OAuth Client ID</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Client Secret</label>
                    <input type="password" name="client_secret" id="edit_client_secret" required class="form-input">
                    <p class="text-sm text-gray-500 mt-1">Zoom OAuth Client Secret</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Webhook Secret Token (İsteğe bağlı)</label>
                    <input type="password" name="webhook_secret" id="edit_webhook_secret" class="form-input">
                    <p class="text-sm text-gray-500 mt-1">Event notifications için webhook secret token</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Webhook Verification Token (İsteğe bağlı)</label>
                    <input type="text" name="webhook_verification" id="edit_webhook_verification" class="form-input">
                    <p class="text-sm text-gray-500 mt-1">Webhook doğrulama token'ı</p>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Hesap Tipi</label>
                        <select name="account_type" id="edit_account_type" required class="form-select">
                            <option value="basic">Basic</option>
                            <option value="pro">Pro</option>
                            <option value="business">Business</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Maksimum Eşzamanlı Toplantı</label>
                        <input type="number" name="max_concurrent_meetings" id="edit_max_concurrent_meetings" min="1" max="100" required class="form-input">
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeEditAccountModal()" class="btn-secondary">İptal</button>
                    <button type="submit" class="btn-primary">Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // API Test Functions
    function runZoomAPITest() {
        const accountId = document.getElementById('testAccountSelect').value;
        const testType = document.getElementById('testTypeSelect').value;
        
        if (!accountId) {
            alert('Lütfen test edilecek hesabı seçin.');
            return;
        }
        
        const runBtn = document.getElementById('runTestBtn');
        runBtn.disabled = true;
        runBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Test çalışıyor...';
        
        // Test sonuç alanını temizle ve loading göster
        const resultsDiv = document.getElementById('testResults');
        resultsDiv.innerHTML = '<div class="text-blue-600"><i class="fas fa-spinner fa-spin mr-2"></i>Test çalışıyor...</div>';
        
        // AJAX request
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `csrf_token=${encodeURIComponent('<?php echo generateCSRFToken(); ?>')}&action=api_test&account_id=${accountId}&test_type=${testType}`
        })
        .then(response => response.json())
        .then(data => {
            displayTestResults(data, testType);
        })
        .catch(error => {
            displayTestResults({
                success: false,
                message: 'Network error: ' + error.message,
                timestamp: new Date().toISOString()
            }, testType);
        })
        .finally(() => {
            runBtn.disabled = false;
            runBtn.innerHTML = '<i class="fas fa-play mr-2"></i>Test Çalıştır';
        });
    }
    
    function displayTestResults(data, testType) {
        const resultsDiv = document.getElementById('testResults');
        const timestamp = new Date().toLocaleString('tr-TR');
        
        let resultHtml = `<div class="test-result mb-4">`;
        resultHtml += `<div class="font-bold text-sm text-gray-600 mb-2">[${timestamp}] ${getTestTypeLabel(testType)}</div>`;
        
        if (data.success) {
            resultHtml += `<div class="text-green-600 mb-2"><i class="fas fa-check-circle mr-1"></i>BAŞARILI</div>`;
        } else {
            resultHtml += `<div class="text-red-600 mb-2"><i class="fas fa-times-circle mr-1"></i>BAŞARISIZ</div>`;
        }
        
        resultHtml += `<div class="text-sm text-gray-700 mb-2">${data.message || 'Mesaj yok'}</div>`;
        
        if (data.data) {
            resultHtml += `<details class="mt-2">`;
            resultHtml += `<summary class="cursor-pointer text-blue-600 text-sm">📊 Detay Veriler</summary>`;
            resultHtml += `<pre class="mt-2 p-2 bg-gray-100 text-xs overflow-auto max-h-40">${JSON.stringify(data.data, null, 2)}</pre>`;
            resultHtml += `</details>`;
        }
        
        if (data.error_details) {
            resultHtml += `<details class="mt-2">`;
            resultHtml += `<summary class="cursor-pointer text-red-600 text-sm">❌ Hata Detayları</summary>`;
            resultHtml += `<pre class="mt-2 p-2 bg-red-50 text-xs text-red-700 overflow-auto max-h-40">${data.error_details}</pre>`;
            resultHtml += `</details>`;
        }
        
        resultHtml += `</div><hr class="my-2">`;
        
        // Yeni sonucu en üste ekle
        resultsDiv.innerHTML = resultHtml + resultsDiv.innerHTML;
    }
    
    function getTestTypeLabel(testType) {
        const labels = {
            'connection': '🔗 Bağlantı Testi',
            'user_info': '👤 Kullanıcı Bilgileri',
            'account_info': '🏢 Hesap Bilgileri',
            'meetings_list': '📅 Toplantı Listesi',
            'create_test_meeting': '➕ Test Toplantısı'
        };
        return labels[testType] || testType;
    }
    
    function clearTestResults() {
        document.getElementById('testResults').innerHTML = '<div class="text-gray-500 text-center py-8">Test sonuçları burada görünecek...</div>';
    }
    
    function copyTestResults() {
        const resultsDiv = document.getElementById('testResults');
        const textContent = resultsDiv.innerText;
        
        navigator.clipboard.writeText(textContent).then(() => {
            // Geçici success mesajı göster
            const originalHtml = resultsDiv.innerHTML;
            resultsDiv.innerHTML = '<div class="text-green-600 text-center py-8"><i class="fas fa-check mr-2"></i>Test sonuçları panoya kopyalandı!</div>';
            setTimeout(() => {
                resultsDiv.innerHTML = originalHtml;
            }, 2000);
        }).catch(err => {
            alert('Kopyalama hatası: ' + err.message);
        });
    }

    function openAddAccountModal() {
        document.getElementById('addAccountModal').classList.remove('hidden');
    }
    
    function closeAddAccountModal() {
        document.getElementById('addAccountModal').classList.add('hidden');
    }
    
    function editAccount(account) {
        document.getElementById('edit_account_id').value = account.id;
        document.getElementById('edit_email').value = account.email;
        document.getElementById('edit_account_id_field').value = account.account_id;
        document.getElementById('edit_client_id').value = account.client_id || account.api_key;
        document.getElementById('edit_client_secret').value = account.client_secret || account.api_secret;
        document.getElementById('edit_webhook_secret').value = account.webhook_secret || '';
        document.getElementById('edit_webhook_verification').value = account.webhook_verification || '';
        document.getElementById('edit_account_type').value = account.account_type;
        document.getElementById('edit_max_concurrent_meetings').value = account.max_concurrent_meetings;
        
        document.getElementById('editAccountModal').classList.remove('hidden');
    }
    
    function closeEditAccountModal() {
        document.getElementById('editAccountModal').classList.add('hidden');
    }
    
    function toggleStatusConfirm(accountId, currentStatus, email) {
        // Create form and submit directly without confirmation
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href;
        
        // Create CSRF token input
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo generateCSRFToken(); ?>';
        form.appendChild(csrfInput);
        
        // Create action input
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'toggle_status';
        form.appendChild(actionInput);
        
        // Create account_id input
        const accountInput = document.createElement('input');
        accountInput.type = 'hidden';
        accountInput.name = 'account_id';
        accountInput.value = accountId;
        form.appendChild(accountInput);
        
        document.body.appendChild(form);
        form.submit();
    }
    
    // Close modals when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target.id === 'addAccountModal') {
            closeAddAccountModal();
        }
        if (e.target.id === 'editAccountModal') {
            closeEditAccountModal();
        }
    });
</script>

<?php include '../includes/footer.php'; ?>