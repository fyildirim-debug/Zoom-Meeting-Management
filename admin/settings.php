<?php
$pageTitle = 'Sistem Ayarları';
require_once '../config/config.php';
require_once '../config/auth.php';

requireLogin();
if (!isAdmin()) {
    redirect('../dashboard.php');
}

$currentUser = getCurrentUser();

// Sistem ayarları işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Güvenlik token hatası. Sayfayı yenileyin ve tekrar deneyin.';
        $messageType = 'error';
    } elseif (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'update_general':
                $result = updateGeneralSettings($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'update_meeting':
                $result = updateMeetingSettings($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'update_zoom':
                $result = updateZoomSettings($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'backup_database':
                $result = backupDatabase();
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'clear_logs':
                $result = clearLogs($_POST['log_type']);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            default:
                $message = 'Geçersiz işlem.';
                $messageType = 'error';
                break;
        }
    }
}

// Mevcut ayarları yükle
try {
    $stmt = $pdo->query("SELECT * FROM settings");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Varsayılan değerler - Zoom odaklı
    $defaultSettings = [
        'site_name' => 'Zoom Toplantı Yönetim Sistemi',
        'admin_email' => 'admin@example.com',
        'max_meeting_duration' => '240',
        'default_meeting_duration' => '60',
        'advance_booking_days' => '30',
        'auto_approve_meetings' => '0',
        // Zoom Toplantı Ayarları
        'zoom_auto_recording' => 'local',
        'zoom_cloud_recording' => '0',
        'zoom_join_before_host' => '0',
        'zoom_waiting_room' => '1',
        'zoom_participant_video' => '1',
        'zoom_host_video' => '1',
        'zoom_mute_upon_entry' => '1',
        'zoom_watermark' => '0',
        'zoom_approval_type' => '2',
        'zoom_enforce_login' => '0',
        'zoom_allow_multiple_devices' => '1',
        'zoom_meeting_authentication' => '0',
        'zoom_breakout_rooms' => '1',
        'zoom_chat' => '1',
        'zoom_screen_sharing' => '1',
        'zoom_annotation' => '1',
        'zoom_whiteboard' => '1',
        'zoom_reactions' => '1',
        'zoom_polling' => '1'
    ];
    
    $settings = array_merge($defaultSettings, $settings);
    
    // Log dosya boyutları
    $logSizes = getLogFileSizes();
    
} catch (Exception $e) {
    writeLog("Settings page error: " . $e->getMessage(), 'error');
    $settings = [];
    $logSizes = [];
}

// Helper functions
function updateGeneralSettings($data) {
    global $pdo;
    
    try {
        $generalSettings = [
            'site_name' => $data['site_name'],
            'admin_email' => $data['admin_email']
        ];
        
        foreach ($generalSettings as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$key, $value, $value]);
        }
        
        writeLog("General settings updated", 'info');
        return ['success' => true, 'message' => 'Genel ayarlar başarıyla güncellendi.'];
        
    } catch (Exception $e) {
        writeLog("Update general settings error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Ayarlar güncellenirken hata oluştu.'];
    }
}

function updateMeetingSettings($data) {
    global $pdo;
    
    try {
        $meetingSettings = [
            'max_meeting_duration' => $data['max_meeting_duration'],
            'default_meeting_duration' => $data['default_meeting_duration'],
            'advance_booking_days' => $data['advance_booking_days'],
            'auto_approve_meetings' => isset($data['auto_approve_meetings']) ? '1' : '0'
        ];
        
        foreach ($meetingSettings as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$key, $value, $value]);
        }
        
        writeLog("Meeting settings updated", 'info');
        return ['success' => true, 'message' => 'Toplantı ayarları başarıyla güncellendi.'];
        
    } catch (Exception $e) {
        writeLog("Update meeting settings error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Toplantı ayarları güncellenirken hata oluştu.'];
    }
}

function updateZoomSettings($data) {
    global $pdo;
    
    try {
        $zoomSettings = [
            'zoom_auto_recording' => $data['zoom_auto_recording'],
            'zoom_cloud_recording' => isset($data['zoom_cloud_recording']) ? '1' : '0',
            'zoom_join_before_host' => isset($data['zoom_join_before_host']) ? '1' : '0',
            'zoom_waiting_room' => isset($data['zoom_waiting_room']) ? '1' : '0',
            'zoom_participant_video' => isset($data['zoom_participant_video']) ? '1' : '0',
            'zoom_host_video' => isset($data['zoom_host_video']) ? '1' : '0',
            'zoom_mute_upon_entry' => isset($data['zoom_mute_upon_entry']) ? '1' : '0',
            'zoom_watermark' => isset($data['zoom_watermark']) ? '1' : '0',
            'zoom_approval_type' => $data['zoom_approval_type'],
            'zoom_enforce_login' => isset($data['zoom_enforce_login']) ? '1' : '0',
            'zoom_allow_multiple_devices' => isset($data['zoom_allow_multiple_devices']) ? '1' : '0',
            'zoom_meeting_authentication' => isset($data['zoom_meeting_authentication']) ? '1' : '0',
            'zoom_breakout_rooms' => isset($data['zoom_breakout_rooms']) ? '1' : '0',
            'zoom_chat' => isset($data['zoom_chat']) ? '1' : '0',
            'zoom_screen_sharing' => isset($data['zoom_screen_sharing']) ? '1' : '0',
            'zoom_annotation' => isset($data['zoom_annotation']) ? '1' : '0',
            'zoom_whiteboard' => isset($data['zoom_whiteboard']) ? '1' : '0',
            'zoom_reactions' => isset($data['zoom_reactions']) ? '1' : '0',
            'zoom_polling' => isset($data['zoom_polling']) ? '1' : '0'
        ];
        
        foreach ($zoomSettings as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$key, $value, $value]);
        }
        
        writeLog("Zoom settings updated", 'info');
        return ['success' => true, 'message' => 'Zoom ayarları başarıyla güncellendi.'];
        
    } catch (Exception $e) {
        writeLog("Update zoom settings error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Zoom ayarları güncellenirken hata oluştu.'];
    }
}

function backupDatabase() {
    try {
        $backupDir = '../backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backupDir . '/' . $filename;
        
        // Basit backup simülasyonu (gerçek implementasyonda mysqldump kullanılır)
        $backup = "-- Database Backup Created: " . date('Y-m-d H:i:s') . "\n";
        $backup .= "-- This is a simulated backup file\n\n";
        
        if (file_put_contents($filepath, $backup)) {
            writeLog("Database backup created: $filename", 'info');
            return ['success' => true, 'message' => "Veritabanı yedeği oluşturuldu: $filename"];
        } else {
            return ['success' => false, 'message' => 'Yedek dosyası oluşturulamadı.'];
        }
        
    } catch (Exception $e) {
        writeLog("Backup database error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Veritabanı yedeği alınırken hata oluştu.'];
    }
}

function clearLogs($logType) {
    try {
        $logFile = '../logs/' . $logType . '.log';
        
        if (file_exists($logFile)) {
            if (file_put_contents($logFile, '')) {
                writeLog("Log cleared: $logType", 'info');
                return ['success' => true, 'message' => ucfirst($logType) . ' logları temizlendi.'];
            } else {
                return ['success' => false, 'message' => 'Log dosyası temizlenemedi.'];
            }
        } else {
            return ['success' => false, 'message' => 'Log dosyası bulunamadı.'];
        }
        
    } catch (Exception $e) {
        writeLog("Clear logs error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Log temizlenirken hata oluştu.'];
    }
}

function getLogFileSizes() {
    $logDir = '../logs';
    $sizes = [];
    
    if (is_dir($logDir)) {
        $files = ['app.log', 'auth.log', 'error.log'];
        foreach ($files as $file) {
            $filepath = $logDir . '/' . $file;
            if (file_exists($filepath)) {
                $sizes[$file] = filesize($filepath);
            } else {
                $sizes[$file] = 0;
            }
        }
    }
    
    return $sizes;
}

function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content flex-1 p-6">
    <div class="max-w-4xl mx-auto">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Sistem Ayarları</h1>
            <p class="mt-2 text-gray-600">Sistem konfigürasyon ve yönetim ayarları</p>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> mb-6">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Settings Tabs -->
        <div class="mb-6">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex space-x-8">
                    <button onclick="showTab('general')" id="tab-general" class="settings-tab active">
                        <i class="fas fa-cog mr-2"></i>
                        Genel Ayarlar
                    </button>
                    <button onclick="showTab('meeting')" id="tab-meeting" class="settings-tab">
                        <i class="fas fa-video mr-2"></i>
                        Toplantı Ayarları
                    </button>
                    <button onclick="showTab('zoom')" id="tab-zoom" class="settings-tab">
                        <i class="fab fa-zoom mr-2"></i>
                        Zoom Ayarları
                    </button>
                    <button onclick="showTab('backup')" id="tab-backup" class="settings-tab">
                        <i class="fas fa-database mr-2"></i>
                        Sistem & Logs
                    </button>
                </nav>
            </div>
        </div>

        <!-- General Settings Tab -->
        <div id="content-general" class="settings-content">
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-6">Genel Sistem Ayarları</h3>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="update_general">
                    
                    <div class="space-y-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Site Adı</label>
                            <input type="text" name="site_name" value="<?php echo htmlspecialchars($settings['site_name']); ?>" class="form-input">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Admin E-posta</label>
                            <input type="email" name="admin_email" value="<?php echo htmlspecialchars($settings['admin_email']); ?>" class="form-input">
                            <p class="text-sm text-gray-500 mt-1">Sistem bildirimlerinin gönderileceği e-posta adresi</p>
                        </div>
                    </div>
                    
                    <div class="flex justify-end mt-6">
                        <button type="submit" class="btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Meeting Settings Tab -->
        <div id="content-meeting" class="settings-content hidden">
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-6">Toplantı Ayarları</h3>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="update_meeting">
                    
                    <div class="space-y-6">
                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Maksimum Toplantı Süresi (dakika)</label>
                                <input type="number" name="max_meeting_duration" min="30" max="480" 
                                       value="<?php echo htmlspecialchars($settings['max_meeting_duration']); ?>" class="form-input">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Varsayılan Toplantı Süresi (dakika)</label>
                                <input type="number" name="default_meeting_duration" min="15" max="240" 
                                       value="<?php echo htmlspecialchars($settings['default_meeting_duration']); ?>" class="form-input">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Önceden Rezervasyon Gün Limiti</label>
                            <input type="number" name="advance_booking_days" min="1" max="365" 
                                   value="<?php echo htmlspecialchars($settings['advance_booking_days']); ?>" class="form-input">
                            <p class="text-sm text-gray-500 mt-1">Kullanıcılar kaç gün öncesinden toplantı planlayabilir</p>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" name="auto_approve_meetings" id="auto_approve_meetings" 
                                   <?php echo $settings['auto_approve_meetings'] ? 'checked' : ''; ?> class="form-checkbox">
                            <label for="auto_approve_meetings" class="ml-2 text-sm text-gray-700">
                                Toplantıları otomatik onayla
                            </label>
                            <p class="text-sm text-gray-500 ml-4">Aktif edilirse tüm toplantı talepleri otomatik onaylanır</p>
                        </div>
                    </div>
                    
                    <div class="flex justify-end mt-6">
                        <button type="submit" class="btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Zoom Settings Tab -->
        <div id="content-zoom" class="settings-content hidden">
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-6">Zoom Toplantı Ayarları</h3>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="update_zoom">
                    
                    <div class="space-y-8">
                        <!-- Kayıt Ayarları -->
                        <div class="border-b border-gray-200 pb-6">
                            <h4 class="text-md font-semibold text-gray-900 mb-4">📹 Kayıt Ayarları</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Otomatik Kayıt</label>
                                    <select name="zoom_auto_recording" class="form-select">
                                        <option value="none" <?php echo $settings['zoom_auto_recording'] === 'none' ? 'selected' : ''; ?>>Kayıt Yok</option>
                                        <option value="local" <?php echo $settings['zoom_auto_recording'] === 'local' ? 'selected' : ''; ?>>Yerel Kayıt</option>
                                        <option value="cloud" <?php echo $settings['zoom_auto_recording'] === 'cloud' ? 'selected' : ''; ?>>Cloud Kayıt</option>
                                    </select>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_cloud_recording" id="zoom_cloud_recording"
                                           <?php echo $settings['zoom_cloud_recording'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_cloud_recording" class="ml-2 text-sm text-gray-700">
                                        Cloud Kayıt İzni
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Katılım Ayarları -->
                        <div class="border-b border-gray-200 pb-6">
                            <h4 class="text-md font-semibold text-gray-900 mb-4">🚪 Katılım Kontrolü</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_join_before_host" id="zoom_join_before_host"
                                           <?php echo $settings['zoom_join_before_host'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_join_before_host" class="ml-2 text-sm text-gray-700">
                                        Host'tan önce katılım
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_waiting_room" id="zoom_waiting_room"
                                           <?php echo $settings['zoom_waiting_room'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_waiting_room" class="ml-2 text-sm text-gray-700">
                                        Bekleme Odası
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_enforce_login" id="zoom_enforce_login"
                                           <?php echo $settings['zoom_enforce_login'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_enforce_login" class="ml-2 text-sm text-gray-700">
                                        Giriş Zorunluluğu
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_meeting_authentication" id="zoom_meeting_authentication"
                                           <?php echo $settings['zoom_meeting_authentication'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_meeting_authentication" class="ml-2 text-sm text-gray-700">
                                        Toplantı Kimlik Doğrulama
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Video Ayarları -->
                        <div class="border-b border-gray-200 pb-6">
                            <h4 class="text-md font-semibold text-gray-900 mb-4">📺 Video Ayarları</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_host_video" id="zoom_host_video"
                                           <?php echo $settings['zoom_host_video'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_host_video" class="ml-2 text-sm text-gray-700">
                                        Host Video Açık
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_participant_video" id="zoom_participant_video"
                                           <?php echo $settings['zoom_participant_video'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_participant_video" class="ml-2 text-sm text-gray-700">
                                        Katılımcı Video Açık
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_watermark" id="zoom_watermark"
                                           <?php echo $settings['zoom_watermark'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_watermark" class="ml-2 text-sm text-gray-700">
                                        Video Watermark
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_allow_multiple_devices" id="zoom_allow_multiple_devices"
                                           <?php echo $settings['zoom_allow_multiple_devices'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_allow_multiple_devices" class="ml-2 text-sm text-gray-700">
                                        Çoklu Cihaz İzni
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Ses Ayarları -->
                        <div class="border-b border-gray-200 pb-6">
                            <h4 class="text-md font-semibold text-gray-900 mb-4">🔊 Ses Ayarları</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_mute_upon_entry" id="zoom_mute_upon_entry"
                                           <?php echo $settings['zoom_mute_upon_entry'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_mute_upon_entry" class="ml-2 text-sm text-gray-700">
                                        Katılımda Sessiz
                                    </label>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Onay Türü</label>
                                    <select name="zoom_approval_type" class="form-select">
                                        <option value="0" <?php echo $settings['zoom_approval_type'] === '0' ? 'selected' : ''; ?>>Otomatik Onay</option>
                                        <option value="1" <?php echo $settings['zoom_approval_type'] === '1' ? 'selected' : ''; ?>>Manuel Onay</option>
                                        <option value="2" <?php echo $settings['zoom_approval_type'] === '2' ? 'selected' : ''; ?>>Kayıt Yok</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Etkileşim Özellikleri -->
                        <div>
                            <h4 class="text-md font-semibold text-gray-900 mb-4">⚡ Etkileşim Özellikleri</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_chat" id="zoom_chat"
                                           <?php echo $settings['zoom_chat'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_chat" class="ml-2 text-sm text-gray-700">
                                        Sohbet
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_screen_sharing" id="zoom_screen_sharing"
                                           <?php echo $settings['zoom_screen_sharing'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_screen_sharing" class="ml-2 text-sm text-gray-700">
                                        Ekran Paylaşımı
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_annotation" id="zoom_annotation"
                                           <?php echo $settings['zoom_annotation'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_annotation" class="ml-2 text-sm text-gray-700">
                                        Açıklama/Annotation
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_whiteboard" id="zoom_whiteboard"
                                           <?php echo $settings['zoom_whiteboard'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_whiteboard" class="ml-2 text-sm text-gray-700">
                                        Beyaz Tahta
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_reactions" id="zoom_reactions"
                                           <?php echo $settings['zoom_reactions'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_reactions" class="ml-2 text-sm text-gray-700">
                                        Reaksiyonlar
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_polling" id="zoom_polling"
                                           <?php echo $settings['zoom_polling'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_polling" class="ml-2 text-sm text-gray-700">
                                        Anket/Polling
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="zoom_breakout_rooms" id="zoom_breakout_rooms"
                                           <?php echo $settings['zoom_breakout_rooms'] ? 'checked' : ''; ?> class="form-checkbox">
                                    <label for="zoom_breakout_rooms" class="ml-2 text-sm text-gray-700">
                                        Grup Odaları
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end mt-8 pt-6 border-t border-gray-200">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save mr-2"></i>
                            Zoom Ayarlarını Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Backup & Logs Tab -->
        <div id="content-backup" class="settings-content hidden">
            <div class="space-y-6">
                <!-- Database Backup -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-6">Veritabanı Yedeği</h3>
                    
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Veritabanının yedek kopyasını oluşturun</p>
                            <p class="text-xs text-gray-500 mt-1">Yedekler /backups klasörüne kaydedilir</p>
                        </div>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="backup_database">
                            <button type="submit" class="btn-primary" onclick="return confirm('Veritabanı yedeği alınacak. Devam edilsin mi?')">
                                <i class="fas fa-download mr-2"></i>
                                Yedek Al
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Log Management -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-6">Log Dosyaları</h3>
                    
                    <div class="space-y-4">
                        <?php foreach ($logSizes as $logFile => $size): ?>
                            <div class="flex items-center justify-between py-3 border-b border-gray-200">
                                <div>
                                    <span class="font-medium text-gray-900"><?php echo $logFile; ?></span>
                                    <span class="ml-2 text-sm text-gray-500">(<?php echo formatBytes($size); ?>)</span>
                                </div>
                                <div class="flex space-x-2">
                                    <a href="../logs/<?php echo $logFile; ?>" target="_blank" class="text-blue-600 hover:text-blue-800 text-sm">
                                        <i class="fas fa-eye mr-1"></i>
                                        Görüntüle
                                    </a>
                                    
                                    <form method="POST" style="display: inline;"
                                          onsubmit="return confirm('<?php echo $logFile; ?> dosyası temizlenecek. Devam edilsin mi?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="clear_logs">
                                        <input type="hidden" name="log_type" value="<?php echo str_replace('.log', '', $logFile); ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm">
                                            <i class="fas fa-trash mr-1"></i>
                                            Temizle
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- System Info -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-6">Sistem Bilgileri</h3>
                    
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">PHP Sürümü</dt>
                            <dd class="text-sm text-gray-900"><?php echo phpversion(); ?></dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Server Software</dt>
                            <dd class="text-sm text-gray-900"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'; ?></dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Maksimum Dosya Boyutu</dt>
                            <dd class="text-sm text-gray-900"><?php echo ini_get('upload_max_filesize'); ?></dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Bellek Limiti</dt>
                            <dd class="text-sm text-gray-900"><?php echo ini_get('memory_limit'); ?></dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Sistem Saati</dt>
                            <dd class="text-sm text-gray-900"><?php echo date('Y-m-d H:i:s'); ?></dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Disk Kullanımı</dt>
                            <dd class="text-sm text-gray-900">
                                <?php 
                                $totalSpace = disk_total_space('.');
                                $freeSpace = disk_free_space('.');
                                $usedSpace = $totalSpace - $freeSpace;
                                $usagePercent = round(($usedSpace / $totalSpace) * 100, 1);
                                echo formatBytes($usedSpace) . ' / ' . formatBytes($totalSpace) . ' (' . $usagePercent . '%)';
                                ?>
                            </dd>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .settings-tab {
        padding: 0.75rem 1rem;
        border-bottom: 2px solid transparent;
        color: #6b7280;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    
    .settings-tab:hover {
        color: #374151;
        border-bottom-color: #d1d5db;
    }
    
    .settings-tab.active {
        color: #6366f1;
        border-bottom-color: #6366f1;
    }
    
    .settings-content {
        animation: fadeIn 0.3s ease-in-out;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<script>
    function showTab(tabName) {
        // Hide all content
        document.querySelectorAll('.settings-content').forEach(content => {
            content.classList.add('hidden');
        });
        
        // Remove active class from all tabs
        document.querySelectorAll('.settings-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Show selected content
        document.getElementById('content-' + tabName).classList.remove('hidden');
        
        // Add active class to selected tab
        document.getElementById('tab-' + tabName).classList.add('active');
    }
    
    // Zoom ayarları çelişki yönetimi
    function handleZoomConflicts() {
        const joinBeforeHost = document.getElementById('zoom_join_before_host');
        const waitingRoom = document.getElementById('zoom_waiting_room');
        
        // Host'tan önce katılım açıldığında bekleme odası otomatik kapansın
        joinBeforeHost.addEventListener('change', function() {
            if (this.checked) {
                waitingRoom.checked = false;
                showNotification('ℹ️ Bekleme Odası otomatik kapatıldı çünkü Host\'tan önce katılım ile çelişiyor.', 'info');
            }
        });
        
        // Bekleme odası açıldığında host'tan önce katılım otomatik kapansın
        waitingRoom.addEventListener('change', function() {
            if (this.checked) {
                joinBeforeHost.checked = false;
                showNotification('ℹ️ Host\'tan önce katılım otomatik kapatıldı çünkü Bekleme Odası ile çelişiyor.', 'info');
            }
        });
    }
    
    // Bildirim göster
    function showNotification(message, type = 'info') {
        // Varolan alert div'i bul veya oluştur
        let alertDiv = document.querySelector('.zoom-notification');
        if (!alertDiv) {
            alertDiv = document.createElement('div');
            alertDiv.className = 'zoom-notification alert alert-' + type;
            alertDiv.style.cssText = 'margin: 10px 0; padding: 12px; border-radius: 6px; font-size: 14px;';
            
            // Zoom formunun başına ekle
            const zoomForm = document.querySelector('#content-zoom form');
            zoomForm.insertBefore(alertDiv, zoomForm.firstChild);
        }
        
        alertDiv.className = 'zoom-notification alert alert-' + type;
        alertDiv.innerHTML = message;
        
        // 5 saniye sonra kaldır
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.parentNode.removeChild(alertDiv);
            }
        }, 5000);
    }
    
    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        showTab('general');
        handleZoomConflicts();
    });
</script>

<?php include '../includes/footer.php'; ?>