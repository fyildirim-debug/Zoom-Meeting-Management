<?php
/**
 * Repair Script: Create Zoom Meetings for NULL zoom_meeting_id
 *
 * Bu script approved toplantılar için eksik zoom_meeting_id'leri oluşturur
 */

$pageTitle = 'Zoom Meeting Repair';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/auth.php';
require_once dirname(__DIR__) . '/includes/ZoomAPI.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// CLI mode check - bypass authentication for CLI execution
$isCLI = (php_sapi_name() === 'cli');

// Sadece admin erişimi
if (!$isCLI) {
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
set_time_limit(300);
ini_set('max_execution_time', 300);

$currentUser = $isCLI ? ['id' => 1, 'email' => 'cli@system.local'] : getCurrentUser();
$repairResults = [];
$totalProcessed = 0;
$successCount = 0;
$errorCount = 0;
$debugInfo = [];

// POST request veya CLI - repair işlemini başlat
if (($isCLI) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'repair')) {
    $debugInfo['request_method'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $debugInfo['post_action'] = $_POST['action'] ?? 'NONE';
    $debugInfo['is_cli'] = $isCLI ? 'YES' : 'NO';
    
    if (!$isCLI) {
        if (!verifyCSRFToken($_POST['csrf_token'])) {
            $error = "CSRF token validation failed";
            $debugInfo['csrf_error'] = $error;
        }
    }
    
    $debugInfo['csrf_check'] = isset($error) ? 'FAILED' : 'PASSED';
    
    try {
        // NULL zoom_meeting_id olan approved toplantıları çek
        $stmt = $pdo->prepare("
            SELECT id, title, date, start_time, end_time, duration, zoom_account_id, user_id
            FROM meetings
            WHERE status = 'approved'
            AND zoom_meeting_id IS NULL
            AND zoom_account_id IS NOT NULL
            ORDER BY date ASC
        ");
        
        $stmt->execute();
        $meetings = $stmt->fetchAll();
        
        $debugInfo['query_executed'] = 'SUCCESS';
        $debugInfo['meetings_found'] = count($meetings);
        $debugInfo['meetings_sample'] = array_slice($meetings, 0, 3); // İlk 3 toplantı
        
        // Her toplantı için Zoom meeting oluştur
        foreach ($meetings as $meeting) {
            $totalProcessed++;
            $repairResult = [
                'id' => $meeting['id'],
                'title' => $meeting['title'],
                'date' => $meeting['date'],
                'status' => 'pending',
                'zoom_meeting_id' => null,
                'error' => null
            ];
            
            try {
                // Zoom API instance al
                $zoomAccountManager = new ZoomAccountManager($pdo);
                $zoomAPI = $zoomAccountManager->getZoomAPI($meeting['zoom_account_id']);
                
                // Meeting date/time format
                $meetingDateTime = $meeting['date'] . ' ' . $meeting['start_time'];
                $startTime = new DateTime($meetingDateTime);
                $startTimeISO = $startTime->format('Y-m-d\TH:i:s\Z');
                
                // Zoom meeting data
                $meetingData = [
                    'topic' => $meeting['title'],
                    'type' => 2, // Scheduled meeting
                    'start_time' => $startTimeISO,
                    'duration' => $meeting['duration'],
                    'timezone' => 'Europe/Istanbul',
                    'password' => '',
                    'settings' => [
                        'host_video' => true,
                        'participant_video' => true,
                        'join_before_host' => false,
                        'mute_upon_entry' => true,
                        'watermark' => false,
                        'use_pmi' => false,
                        'approval_type' => 0,
                        'audio' => 'both',
                        'auto_recording' => 'none'
                    ]
                ];
                
                // Zoom meeting oluştur
                $createResult = $zoomAPI->createMeeting($meetingData);
                
                if ($createResult['success'] && isset($createResult['data']['id'])) {
                    $zoomMeetingId = $createResult['data']['id'];
                    $zoomStartUrl = $createResult['data']['start_url'];
                    $zoomJoinUrl = $createResult['data']['join_url'];
                    
                    // Database'i güncelle
                    $updateStmt = $pdo->prepare("
                        UPDATE meetings 
                        SET zoom_meeting_id = ?, zoom_start_url = ?, zoom_join_url = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$zoomMeetingId, $zoomStartUrl, $zoomJoinUrl, $meeting['id']]);
                    
                    $repairResult['zoom_meeting_id'] = $zoomMeetingId;
                    $repairResult['status'] = 'success';
                    $successCount++;
                    
                } else {
                    $repairResult['status'] = 'error';
                    $repairResult['error'] = 'Zoom meeting creation failed: ' . ($createResult['message'] ?? 'Unknown error');
                    $errorCount++;
                }
                
            } catch (Exception $e) {
                $repairResult['status'] = 'error';
                $repairResult['error'] = $e->getMessage();
                $errorCount++;
            }
            
            $repairResults[] = $repairResult;
            
            // Rate limiting - 200ms delay
            usleep(200000);
        }
        
        // Repair tamamlandı
        $message = "Repair completed: $totalProcessed meetings processed, $successCount successful, $errorCount errors";
        
        // Activity log
        logActivity('repair_zoom_meetings', 'system', null, $message, $currentUser['id']);
        
    } catch (Exception $e) {
        $error = "Repair hatası: " . $e->getMessage();
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
    if (!empty($repairResults)) {
        echo json_encode([
            'success' => true,
            'message' => "Repair completed: $totalProcessed meetings processed, $successCount successful, $errorCount errors",
            'data' => [
                'total_processed' => $totalProcessed,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'results' => $repairResults
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
                    <h1 class="text-3xl font-bold text-gray-900">Zoom Meeting Repair</h1>
                    <p class="text-gray-600">NULL zoom_meeting_id'leri düzelt</p>
                </div>
            </div>
        </div>

        <!-- Repair Info -->
        <div class="bg-red-50 border border-red-200 rounded-xl p-6 mb-6">
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-wrench text-red-600"></i>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-red-900">Repair Hakkında</h3>
                    <p class="text-red-700">Bu işlem approved toplantılar için eksik Zoom meeting'leri oluşturur.</p>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <strong class="text-red-800">İşlenecek Toplantılar:</strong>
                    <ul class="mt-2 space-y-1 text-red-700">
                        <li>• Approved toplantılar (status = 'approved')</li>
                        <li>• NULL zoom_meeting_id olan toplantılar</li>
                        <li>• Zoom account ID'si olan toplantılar</li>
                    </ul>
                </div>
                <div>
                    <strong class="text-red-800">Yapılacak İşlemler:</strong>
                    <ul class="mt-2 space-y-1 text-red-700">
                        <li>• Zoom API'dan yeni meeting oluştur</li>
                        <li>• zoom_meeting_id güncelle</li>
                        <li>• start_url ve join_url güncelle</li>
                    </ul>
                </div>
            </div>
        </div>

        <?php if (empty($repairResults)): ?>
            <!-- Repair Form -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Repair Başlat</h3>
                
                <?php if (!empty($debugInfo)): ?>
                    <!-- Debug Information -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <h4 class="text-md font-semibold text-blue-900 mb-3">
                            <i class="fas fa-bug mr-2"></i>Debug Bilgileri
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <strong class="text-blue-800">Request Info:</strong>
                                <ul class="mt-1 space-y-1 text-blue-700">
                                    <li>• Method: <?php echo $debugInfo['request_method']; ?></li>
                                    <li>• Action: <?php echo $debugInfo['post_action']; ?></li>
                                    <li>• CLI: <?php echo $debugInfo['is_cli']; ?></li>
                                    <li>• CSRF: <?php echo $debugInfo['csrf_check']; ?></li>
                                </ul>
                            </div>
                            <div>
                                <strong class="text-blue-800">Database Info:</strong>
                                <ul class="mt-1 space-y-1 text-blue-700">
                                    <li>• Query: <?php echo $debugInfo['query_executed'] ?? 'NOT EXECUTED'; ?></li>
                                    <li>• Meetings Found: <?php echo $debugInfo['meetings_found'] ?? 'NONE'; ?></li>
                                </ul>
                            </div>
                        </div>
                        
                        <?php if (!empty($debugInfo['meetings_sample'])): ?>
                            <div class="mt-4">
                                <strong class="text-blue-800">Sample Meetings:</strong>
                                <div class="mt-2 max-h-32 overflow-y-auto">
                                    <table class="min-w-full text-xs">
                                        <thead class="bg-blue-100">
                                            <tr>
                                                <th class="px-2 py-1 text-left">ID</th>
                                                <th class="px-2 py-1 text-left">Title</th>
                                                <th class="px-2 py-1 text-left">Date</th>
                                                <th class="px-2 py-1 text-left">Account ID</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($debugInfo['meetings_sample'] as $meeting): ?>
                                                <tr class="border-t border-blue-200">
                                                    <td class="px-2 py-1"><?php echo $meeting['id']; ?></td>
                                                    <td class="px-2 py-1"><?php echo htmlspecialchars(substr($meeting['title'], 0, 15)); ?>...</td>
                                                    <td class="px-2 py-1"><?php echo $meeting['date']; ?></td>
                                                    <td class="px-2 py-1"><?php echo $meeting['zoom_account_id']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($debugInfo['csrf_error'])): ?>
                            <div class="mt-4 p-3 bg-red-100 border border-red-200 rounded">
                                <strong class="text-red-800">CSRF Error:</strong>
                                <span class="text-red-700"><?php echo $debugInfo['csrf_error']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-yellow-600 mr-2"></i>
                        <span class="text-yellow-800">
                            <strong>Dikkat:</strong> Bu işlem NULL zoom_meeting_id olan tüm toplantıları düzeltecek.
                        </span>
                    </div>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="repair">
                    
                    <div class="flex items-center space-x-4">
                        <button type="submit" class="btn-primary bg-red-600 hover:bg-red-700">
                            <i class="fas fa-wrench mr-2"></i>
                            Repair Başlat
                        </button>
                        <span class="text-sm text-gray-600">
                            İşlem uzun sürebilir. Lütfen bekleyiniz.
                        </span>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <!-- Repair Results -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">Repair Sonuçları</h3>
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
                
                <!-- Results Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Toplantı
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Tarih
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Durum
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Zoom Meeting ID
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Detay
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($repairResults as $result): ?>
                                <tr>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($result['title']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">ID: <?php echo $result['id']; ?></div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo date('d.m.Y', strtotime($result['date'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <?php if ($result['status'] === 'success'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check mr-1"></i>
                                                Başarılı
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-times mr-1"></i>
                                                Hata
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <?php if ($result['zoom_meeting_id']): ?>
                                            <div class="text-sm text-gray-900 font-mono">
                                                <?php echo htmlspecialchars($result['zoom_meeting_id']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-500">NULL</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <?php if ($result['status'] === 'error'): ?>
                                            <div class="text-sm text-red-600">
                                                <?php echo htmlspecialchars($result['error']); ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-sm text-green-600">
                                                Zoom meeting başarıyla oluşturuldu
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
                            Repair tamamlandı. Toplam <?php echo $totalProcessed; ?> toplantı işlendi.
                        </div>
                        <div class="flex items-center space-x-4">
                            <a href="migrate-start-urls.php" class="btn-primary">
                                <i class="fas fa-rocket mr-2"></i>
                                Migration Çalıştır
                            </a>
                            <a href="repair-zoom-meetings.php" class="btn-secondary">
                                <i class="fas fa-redo mr-2"></i>
                                Yeniden Repair
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