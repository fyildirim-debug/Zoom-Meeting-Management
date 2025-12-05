<?php
/**
 * Zoom Cloud Kayıtları API'si
 * 
 * Toplantı kayıtlarını ve indirme linklerini döndürür.
 * 
 * Kullanım: 
 * GET /api/get-recordings.php?meeting_id=123456789  - Zoom Meeting ID ile kayıtları al
 * GET /api/get-recordings.php?local_id=5            - Lokal toplantı ID ile kayıtları al
 * GET /api/get-recordings.php?all=1                 - Tüm kayıtlar
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONS request için early return
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';
require_once '../config/auth.php';
require_once '../includes/ZoomAPI.php';

// Oturum kontrolü
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Oturum açmanız gerekiyor'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $currentUser = getCurrentUser();
    $zoomMeetingId = $_GET['meeting_id'] ?? null; // Zoom Meeting ID
    $localMeetingId = $_GET['local_id'] ?? null;  // Lokal veritabanı ID
    $getAll = isset($_GET['all']) && $_GET['all'] === '1';
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    $zoomAccountId = $_GET['zoom_account_id'] ?? null;
    
    // Zoom hesabını belirle
    if ($localMeetingId) {
        // Lokal ID ile toplantı ara
        $stmt = $pdo->prepare("
            SELECT m.*, za.id as account_id
            FROM meetings m
            LEFT JOIN zoom_accounts za ON m.zoom_account_id = za.id
            WHERE m.id = ?
        ");
        $stmt->execute([$localMeetingId]);
        $meeting = $stmt->fetch();
        
        if ($meeting) {
            $zoomAccountId = $meeting['account_id'];
            $zoomMeetingId = $meeting['zoom_meeting_id'];
        }
    } else if ($zoomMeetingId) {
        // Zoom Meeting ID ile toplantı ara
        $stmt = $pdo->prepare("
            SELECT m.*, za.id as account_id
            FROM meetings m
            LEFT JOIN zoom_accounts za ON m.zoom_account_id = za.id
            WHERE m.zoom_meeting_id = ?
        ");
        $stmt->execute([$zoomMeetingId]);
        $meeting = $stmt->fetch();
        
        if ($meeting) {
            $zoomAccountId = $meeting['account_id'];
        }
    }
    
    // Zoom hesabı bulunamadıysa aktif olanı al
    if (!$zoomAccountId) {
        $stmt = $pdo->query("SELECT id FROM zoom_accounts WHERE status = 'active' LIMIT 1");
        $account = $stmt->fetch();
        
        if ($account) {
            $zoomAccountId = $account['id'];
        }
    }
    
    if (!$zoomAccountId) {
        echo json_encode([
            'success' => false,
            'message' => 'Aktif Zoom hesabı bulunamadı',
            'recordings' => []
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Zoom API bağlantısı
    $zoomManager = new ZoomAccountManager($pdo);
    $zoomAPI = $zoomManager->getZoomAPI($zoomAccountId);
    
    // Kayıtları al
    if ($zoomMeetingId) {
        // Belirli toplantının kayıtları
        writeLog("Getting recordings for Zoom Meeting ID: $zoomMeetingId", 'info');
        $result = $zoomAPI->getCloudRecordings($zoomMeetingId);
        
        if ($result['success'] && isset($result['data']['recordings'])) {
            $recordingsData = $result['data']['recordings'];
            $recordingFiles = $recordingsData['recording_files'] ?? [];
            
            // Kayıtları işle
            $recordings = [];
            foreach ($recordingFiles as $file) {
                $recordings[] = [
                    'id' => $file['id'] ?? '',
                    'recording_type' => formatRecordingType($file['recording_type'] ?? ''),
                    'file_type' => $file['file_type'] ?? '',
                    'file_size' => $file['file_size'] ?? 0,
                    'play_url' => $file['play_url'] ?? null,
                    'download_url' => $file['download_url'] ?? null,
                    'recording_start' => $file['recording_start'] ?? null,
                    'recording_end' => $file['recording_end'] ?? null,
                    'status' => $file['status'] ?? ''
                ];
            }
            
            // Rapor bilgisi de al
            $report = null;
            try {
                $reportResult = $zoomAPI->getMeetingReport($zoomMeetingId);
                if ($reportResult['success'] && isset($reportResult['data'])) {
                    $reportData = $reportResult['data'];
                    $report = [
                        'participants_count' => $reportData['participants_count'] ?? count($reportData['participants'] ?? []),
                        'duration' => $reportData['details']['duration'] ?? 0,
                        'start_time' => $reportData['details']['start_time'] ?? null,
                        'end_time' => $reportData['details']['end_time'] ?? null
                    ];
                }
            } catch (Exception $e) {
                // Rapor alınamazsa devam et
                writeLog("Could not get meeting report: " . $e->getMessage(), 'warning');
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Kayıtlar başarıyla alındı',
                'recordings' => $recordings,
                'report' => $report,
                'password' => $recordingsData['password'] ?? null,
                'meeting_info' => [
                    'topic' => $recordingsData['topic'] ?? '',
                    'start_time' => $recordingsData['start_time'] ?? '',
                    'duration' => $recordingsData['duration'] ?? 0,
                    'total_size' => $recordingsData['total_size'] ?? 0
                ]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Kayıtlar bulunamadı',
                'recordings' => []
            ], JSON_UNESCAPED_UNICODE);
        }
    } else {
        // Tüm kayıtlar
        $result = $zoomAPI->getCloudRecordings(null, 'me', $from, $to);
        
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => $result['message'],
                'data' => $result['data']
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Kayıtlar bulunamadı',
                'recordings' => []
            ], JSON_UNESCAPED_UNICODE);
        }
    }
    
} catch (Exception $e) {
    writeLog("Get recordings error: " . $e->getMessage(), 'error');
    
    echo json_encode([
        'success' => false,
        'message' => 'Sunucu hatası: ' . $e->getMessage(),
        'recordings' => []
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Kayıt tipini Türkçeleştir
 */
function formatRecordingType($type) {
    $types = [
        'shared_screen_with_speaker_view' => 'Ekran + Konuşmacı',
        'shared_screen_with_gallery_view' => 'Ekran + Galeri',
        'shared_screen' => 'Ekran Paylaşımı',
        'speaker_view' => 'Konuşmacı Görünümü',
        'gallery_view' => 'Galeri Görünümü',
        'active_speaker' => 'Aktif Konuşmacı',
        'audio_only' => 'Sadece Ses',
        'audio_transcript' => 'Ses Transkripti',
        'chat_file' => 'Sohbet Dosyası',
        'timeline' => 'Zaman Çizelgesi'
    ];
    
    return $types[$type] ?? $type;
}
