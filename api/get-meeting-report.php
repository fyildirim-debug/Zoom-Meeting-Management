<?php
/**
 * Toplantı Sonrası Rapor API'si
 * 
 * Geçmiş toplantıların detaylı raporunu döndürür:
 * - Katılımcı listesi
 * - Toplantı süresi
 * - Anket sonuçları
 * - Q&A raporu
 * 
 * Kullanım: GET /api/get-meeting-report.php?meeting_id=123
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
    $meetingId = $_GET['meeting_id'] ?? null;
    
    if (!$meetingId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'meeting_id parametresi gerekli'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Veritabanından toplantı bilgilerini al
    $stmt = $pdo->prepare("
        SELECT m.*, za.id as account_id
        FROM meetings m
        LEFT JOIN zoom_accounts za ON m.zoom_account_id = za.id
        WHERE m.id = ?
    ");
    $stmt->execute([$meetingId]);
    $meeting = $stmt->fetch();
    
    if (!$meeting) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Toplantı bulunamadı'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Yetki kontrolü
    if ($meeting['department_id'] != $currentUser['department_id'] && !isAdmin()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Bu toplantının raporuna erişim yetkiniz yok'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Zoom hesabı kontrolü
    if (!$meeting['account_id']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Bu toplantı için Zoom hesabı atanmamış'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Zoom meeting ID kontrolü
    $zoomMeetingId = $meeting['zoom_meeting_id'] ?? $meeting['meeting_id'] ?? null;
    $zoomUuid = $meeting['zoom_uuid'] ?? null;
    
    if (!$zoomMeetingId && !$zoomUuid) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Bu toplantı için Zoom toplantı ID\'si bulunamadı'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Zoom API bağlantısı
    $zoomManager = new ZoomAccountManager($pdo);
    $zoomAPI = $zoomManager->getZoomAPI($meeting['account_id']);
    
    // UUID varsa onu kullan, yoksa meeting ID kullan
    // UUID geçmiş toplantılar için daha güvenilir
    $reportId = $zoomUuid ?? $zoomMeetingId;
    
    // Raporu al
    $result = $zoomAPI->getMeetingReport($reportId);
    
    // Sistem bilgilerini ekle
    $result['data']['system_info'] = [
        'db_meeting_id' => $meeting['id'],
        'title' => $meeting['title'],
        'date' => $meeting['date'],
        'start_time' => $meeting['start_time'],
        'end_time' => $meeting['end_time'],
        'moderator' => $meeting['moderator'],
        'status' => $meeting['status']
    ];
    
    if ($result['success']) {
        writeLog("Meeting report retrieved for meeting $meetingId by user " . $currentUser['id'], 'info');
        
        echo json_encode([
            'success' => true,
            'message' => $result['message'],
            'data' => $result['data']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Toplantı raporu alınamadı'
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    writeLog("Get meeting report error: " . $e->getMessage(), 'error');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sunucu hatası: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
