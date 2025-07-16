<?php
/**
 * Migration Script: Fresh Start URLs for Existing Meetings
 *
 * Bu script mevcut onaylanmış toplantıların start URL'lerini
 * Zoom API'den fresh URL'lerle günceller
 */

$pageTitle = 'Start URL Migration';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/auth.php';
require_once dirname(__DIR__) . '/includes/ZoomAPI.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// CLI mode check - bypass authentication for CLI execution
$isCLI = (php_sapi_name() === 'cli');

// Sadece admin erişimi (CLI'dan çalıştırılıyorsa bypass)
if (!$isCLI) {
    // Web interface için admin kontrolü
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit;
    }
    
    if (!isAdmin()) {
        header('Location: ../index.php?error=admin_required');
        exit;
    }
}

// Set time limit for long-running process
set_time_limit(300); // 5 dakika
ini_set('max_execution_time', 300);

$currentUser = $isCLI ? ['id' => 1, 'email' => 'cli@system.local'] : getCurrentUser();
$migrationResults = [];
$totalProcessed = 0;
$successCount = 0;
$errorCount = 0;

// POST request veya CLI - migration işlemini başlat
if (($isCLI) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'migrate')) {
    if (!$isCLI) {
        if (!verifyCSRFToken($_POST['csrf_token'])) {
            $error = "CSRF token validation failed";
        }
    }
    
    try {
        writeLog("🔄 START URL MIGRATION: Migration başlatıldı - Admin: " . $currentUser['email'], 'info');
        
        // Database türünü güvenli şekilde belirle
        $dbType = defined('DB_TYPE') ? DB_TYPE : 'mysql';
        
        // Onaylanmış toplantıları çek (zoom_meeting_id olan) - TÜM TOPLANTILARI KAPSAYACAK ŞEKİLDE
        $stmt = $pdo->prepare("
            SELECT id, title, zoom_meeting_id, zoom_account_id, zoom_start_url, date, start_time
            FROM meetings
            WHERE status = 'approved'
            AND zoom_meeting_id IS NOT NULL
            AND zoom_account_id IS NOT NULL
            ORDER BY date ASC
        ");
        
        writeLog("🔍 DATABASE TYPE: " . $dbType . " - Query hazırlandı", 'info');
        
        $stmt->execute();
        $meetings = $stmt->fetchAll();
        
        writeLog("📋 MIGRATION: " . count($meetings) . " toplantı migration için hazırlandı", 'info');
        
        // DEBUG: Toplantı sayısı production'da düşükse debug bilgisi
    $debugInfo = [];
    if (count($meetings) < 26) {  // Log'da 26 approved meeting olduğu görülüyor
        $debugInfo['found_meetings'] = count($meetings);
        
        // Toplam approved meeting sayısı
        $totalStmt = $pdo->prepare("SELECT COUNT(*) as total FROM meetings WHERE status = 'approved'");
        $totalStmt->execute();
        $totalApproved = $totalStmt->fetch()['total'];
        $debugInfo['total_approved'] = $totalApproved;
        
        // Zoom meeting ID'si olan toplantılar
        $zoomStmt = $pdo->prepare("SELECT COUNT(*) as total FROM meetings WHERE status = 'approved' AND zoom_meeting_id IS NOT NULL");
        $zoomStmt->execute();
        $totalWithZoom = $zoomStmt->fetch()['total'];
        $debugInfo['with_zoom_meeting_id'] = $totalWithZoom;
        
        // Zoom account ID'si olan toplantılar
        $accountStmt = $pdo->prepare("SELECT COUNT(*) as total FROM meetings WHERE status = 'approved' AND zoom_meeting_id IS NOT NULL AND zoom_account_id IS NOT NULL");
        $accountStmt->execute();
        $totalWithAccount = $accountStmt->fetch()['total'];
        $debugInfo['with_zoom_account_id'] = $totalWithAccount;
        
        // Config debug
        $debugInfo['db_type_defined'] = defined('DB_TYPE') ? 'YES' : 'NO';
        $debugInfo['db_type_value'] = $dbType;
        
        // Zoom meeting ID'si NULL olan approved toplantılar
        $nullZoomStmt = $pdo->prepare("SELECT COUNT(*) as total FROM meetings WHERE status = 'approved' AND zoom_meeting_id IS NULL");
        $nullZoomStmt->execute();
        $totalNullZoom = $nullZoomStmt->fetch()['total'];
        $debugInfo['null_zoom_meeting_id'] = $totalNullZoom;
        
        // Zoom account ID'si NULL olan approved toplantılar
        $nullAccountStmt = $pdo->prepare("SELECT COUNT(*) as total FROM meetings WHERE status = 'approved' AND zoom_meeting_id IS NOT NULL AND zoom_account_id IS NULL");
        $nullAccountStmt->execute();
        $totalNullAccount = $nullAccountStmt->fetch()['total'];
        $debugInfo['null_zoom_account_id'] = $totalNullAccount;
        
        // Sample meeting data - approved but filtered out
        $sampleStmt = $pdo->prepare("SELECT id, title, status, zoom_meeting_id, zoom_account_id FROM meetings WHERE status = 'approved' LIMIT 10");
        $sampleStmt->execute();
        $sampleMeetings = $sampleStmt->fetchAll();
        $debugInfo['sample_meetings'] = $sampleMeetings;
    }
        
        // Her toplantı için fresh URL çek
        foreach ($meetings as $meeting) {
            $totalProcessed++;
            $meetingResult = [
                'id' => $meeting['id'],
                'title' => $meeting['title'],
                'zoom_meeting_id' => $meeting['zoom_meeting_id'],
                'original_url' => $meeting['zoom_start_url'],
                'fresh_url' => null,
                'status' => 'pending',
                'error' => null
            ];
            
            try {
                // Zoom API instance al
                $zoomAccountManager = new ZoomAccountManager($pdo);
                $zoomAPI = $zoomAccountManager->getZoomAPI($meeting['zoom_account_id']);
                
                // Fresh meeting details çek
                $freshMeetingResult = $zoomAPI->getMeeting($meeting['zoom_meeting_id']);
                
                if ($freshMeetingResult['success'] && isset($freshMeetingResult['data']['start_url'])) {
                    $freshStartUrl = $freshMeetingResult['data']['start_url'];
                    
                    // URL değişti mi kontrol et
                    if ($freshStartUrl !== $meeting['zoom_start_url']) {
                        // Database'i güncelle
                        $updateStmt = $pdo->prepare("UPDATE meetings SET zoom_start_url = ? WHERE id = ?");
                        $updateStmt->execute([$freshStartUrl, $meeting['id']]);
                        
                        $meetingResult['fresh_url'] = $freshStartUrl;
                        $meetingResult['status'] = 'updated';
                        $successCount++;
                        
                        writeLog("✅ MIGRATION: Meeting ID=" . $meeting['id'] . " start URL güncellendi", 'info');
                    } else {
                        $meetingResult['fresh_url'] = $freshStartUrl;
                        $meetingResult['status'] = 'unchanged';
                        $successCount++;
                        
                        writeLog("ℹ️ MIGRATION: Meeting ID=" . $meeting['id'] . " start URL değişmedi", 'info');
                    }
                } else {
                    $meetingResult['status'] = 'error';
                    $meetingResult['error'] = 'Zoom API response failed: ' . ($freshMeetingResult['message'] ?? 'Unknown error');
                    $errorCount++;
                    
                    writeLog("❌ MIGRATION: Meeting ID=" . $meeting['id'] . " API hatası: " . $meetingResult['error'], 'warning');
                }
                
            } catch (Exception $e) {
                $meetingResult['status'] = 'error';
                $meetingResult['error'] = $e->getMessage();
                $errorCount++;
                
                writeLog("❌ MIGRATION: Meeting ID=" . $meeting['id'] . " Exception: " . $e->getMessage(), 'error');
            }
            
            $migrationResults[] = $meetingResult;
            
            // Rate limiting - 100ms delay
            usleep(100000);
        }
        
        // Migration tamamlandı
        writeLog("🎉 MIGRATION COMPLETED: Total=$totalProcessed, Success=$successCount, Error=$errorCount", 'info');
        
        // Activity log
        logActivity('migrate_start_urls', 'system', null, 
            "Start URL migration completed: $totalProcessed meetings processed, $successCount successful, $errorCount errors", 
            $currentUser['id']);
        
    } catch (Exception $e) {
        writeLog("❌ MIGRATION FAILED: " . $e->getMessage(), 'error');
        $error = "Migration hatası: " . $e->getMessage();
    }
}

// CLI modunda HTML render etme
if (!$isCLI) {
    include dirname(__DIR__) . '/includes/header.php';
    include dirname(__DIR__) . '/includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content flex-1 p-6">
<?php } else {
    // CLI modunda JSON output
    if (!empty($migrationResults)) {
        echo json_encode([
            'success' => true,
            'message' => "Migration completed: $totalProcessed meetings processed, $successCount successful, $errorCount errors",
            'data' => [
                'total_processed' => $totalProcessed,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'results' => $migrationResults
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!$isCLI): ?>
    <div class="max-w-6xl mx-auto">
        <!-- Page Header -->
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center space-x-4">
                <a href="index.php" class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center hover:bg-gray-200 transition-colors">
                    <i class="fas fa-arrow-left text-gray-600"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Start URL Migration</h1>
                    <p class="text-gray-600">Mevcut toplantılar için fresh start URL'leri güncelle</p>
                </div>
            </div>
        </div>

        <!-- Migration Info -->
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 mb-6">
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-info-circle text-blue-600"></i>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-blue-900">Migration Hakkında</h3>
                    <p class="text-blue-700">Bu işlem mevcut onaylanmış toplantıların start URL'lerini Zoom API'den fresh URL'lerle günceller.</p>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <strong class="text-blue-800">Güncellenecek Toplantılar:</strong>
                    <ul class="mt-2 space-y-1 text-blue-700">
                        <li>• Onaylanmış toplantılar (status = 'approved')</li>
                        <li>• Zoom meeting ID'si olan toplantılar</li>
                        <li>• TÜM tarihlerdeki toplantılar</li>
                    </ul>
                </div>
                <div>
                    <strong class="text-blue-800">Güvenlik:</strong>
                    <ul class="mt-2 space-y-1 text-blue-700">
                        <li>• Sadece admin kullanıcıları çalıştırabilir</li>
                        <li>• Tüm işlemler loglanır</li>
                        <li>• Hata durumunda rollback yapılmaz</li>
                    </ul>
                </div>
            </div>
        </div>

        <?php if (empty($migrationResults)): ?>
            <!-- Migration Form -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Migration Başlat</h3>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-yellow-600 mr-2"></i>
                        <span class="text-yellow-800">
                            <strong>Dikkat:</strong> Bu işlem tüm onaylanmış toplantıları işleyecek ve uzun sürebilir.
                        </span>
                    </div>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="migrate">
                    
                    <div class="flex items-center space-x-4">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-rocket mr-2"></i>
                            Migration Başlat
                        </button>
                        <span class="text-sm text-gray-600">
                            İşlem 5 dakika sürebilir. Lütfen bekleyiniz.
                        </span>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <!-- Migration Results -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">Migration Sonuçları</h3>
                    <div class="flex items-center space-x-4 text-sm">
                        <span class="text-green-600">
                            <i class="fas fa-check-circle mr-1"></i>
                            Başarılı: <?php echo $successCount; ?>
                        </span>
                        <span class="text-red-600">
                            <i class="fas fa-times-circle mr-1"></i>
                            Hata: <?php echo $errorCount; ?>
                        </span>
                        <span class="text-gray-600">
                            <i class="fas fa-list mr-1"></i>
                            Toplam: <?php echo $totalProcessed; ?>
                        </span>
                    </div>
                </div>
                
                <?php if (!empty($debugInfo)): ?>
                    <!-- Debug Information -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <h4 class="text-md font-semibold text-blue-900 mb-3">
                            <i class="fas fa-bug mr-2"></i>Debug Bilgileri
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <strong class="text-blue-800">Database İstatistikleri:</strong>
                                <ul class="mt-1 space-y-1 text-blue-700">
                                    <li>• Toplam Approved: <?php echo $debugInfo['total_approved']; ?></li>
                                    <li>• Zoom Meeting ID olan: <?php echo $debugInfo['with_zoom_meeting_id']; ?></li>
                                    <li>• Zoom Account ID olan: <?php echo $debugInfo['with_zoom_account_id']; ?></li>
                                    <li>• Migration bulduğu: <?php echo $debugInfo['found_meetings']; ?></li>
                                </ul>
                            </div>
                            <div>
                                <strong class="text-blue-800">NULL Değerler:</strong>
                                <ul class="mt-1 space-y-1 text-blue-700">
                                    <li>• NULL Zoom Meeting ID: <?php echo $debugInfo['null_zoom_meeting_id']; ?></li>
                                    <li>• NULL Zoom Account ID: <?php echo $debugInfo['null_zoom_account_id']; ?></li>
                                    <li>• DB_TYPE defined: <?php echo $debugInfo['db_type_defined']; ?></li>
                                    <li>• DB_TYPE value: <?php echo $debugInfo['db_type_value']; ?></li>
                                </ul>
                            </div>
                        </div>
                        
                        <?php if (!empty($debugInfo['sample_meetings'])): ?>
                            <div class="mt-4">
                                <strong class="text-blue-800">Sample Meetings:</strong>
                                <div class="mt-2 max-h-32 overflow-y-auto">
                                    <table class="min-w-full text-xs">
                                        <thead class="bg-blue-100">
                                            <tr>
                                                <th class="px-2 py-1 text-left">ID</th>
                                                <th class="px-2 py-1 text-left">Title</th>
                                                <th class="px-2 py-1 text-left">Status</th>
                                                <th class="px-2 py-1 text-left">Zoom Meeting ID</th>
                                                <th class="px-2 py-1 text-left">Zoom Account ID</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($debugInfo['sample_meetings'] as $meeting): ?>
                                                <tr class="border-t border-blue-200">
                                                    <td class="px-2 py-1"><?php echo $meeting['id']; ?></td>
                                                    <td class="px-2 py-1"><?php echo htmlspecialchars(substr($meeting['title'], 0, 20)); ?>...</td>
                                                    <td class="px-2 py-1"><?php echo $meeting['status']; ?></td>
                                                    <td class="px-2 py-1"><?php echo $meeting['zoom_meeting_id'] ?? 'NULL'; ?></td>
                                                    <td class="px-2 py-1"><?php echo $meeting['zoom_account_id'] ?? 'NULL'; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Results Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Toplantı
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Meeting ID
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Durum
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Detay
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($migrationResults as $result): ?>
                                <tr>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($result['title']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">ID: <?php echo $result['id']; ?></div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 font-mono">
                                            <?php echo htmlspecialchars($result['zoom_meeting_id']); ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <?php if ($result['status'] === 'updated'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check mr-1"></i>
                                                Güncellendi
                                            </span>
                                        <?php elseif ($result['status'] === 'unchanged'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <i class="fas fa-equals mr-1"></i>
                                                Değişmedi
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-times mr-1"></i>
                                                Hata
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <?php if ($result['status'] === 'error'): ?>
                                            <div class="text-sm text-red-600">
                                                <?php echo htmlspecialchars($result['error']); ?>
                                            </div>
                                        <?php elseif ($result['status'] === 'updated'): ?>
                                            <div class="text-sm text-green-600">
                                                Start URL başarıyla güncellendi
                                            </div>
                                        <?php else: ?>
                                            <div class="text-sm text-gray-500">
                                                Start URL zaten güncel
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Summary -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            Migration tamamlandı. Toplam <?php echo $totalProcessed; ?> toplantı işlendi.
                        </div>
                        <div class="flex items-center space-x-4">
                            <a href="index.php" class="btn-secondary">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Admin Paneli
                            </a>
                            <a href="migrate-start-urls.php" class="btn-primary">
                                <i class="fas fa-redo mr-2"></i>
                                Yeniden Çalıştır
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php
if (!$isCLI) {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>