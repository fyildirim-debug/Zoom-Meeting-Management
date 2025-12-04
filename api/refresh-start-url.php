<?php
/**
 * Toplantı Başlatma URL'si Yenileme API'si
 * 
 * Bu endpoint toplantı başlatılırken anlık güncel token alır.
 * Eski/geçersiz token sorununu çözer.
 * 
 * Kullanım: POST /api/refresh-start-url.php
 * Body: { "meeting_id": 123 }
 */

// Fatal error'ları JSON olarak döndür
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Önceki çıktıları temizle
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Sunucu hatası: ' . $error['message'],
            'debug' => [
                'file' => basename($error['file']),
                'line' => $error['line']
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
});

// Hata yakalama - JSON çıktısını bozmayı engelle
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Output buffering başlat
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONS request için early return
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit();
}

try {
    require_once '../config/config.php';
    require_once '../config/auth.php';
    require_once '../includes/ZoomAPI.php';
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Dosya yükleme hatası: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// writeLog fonksiyonu yoksa tanımla
if (!function_exists('writeLog')) {
    function writeLog($message, $level = 'info') {
        // Sessizce geç
    }
}

// logActivity fonksiyonu yoksa tanımla
if (!function_exists('logActivity')) {
    function logActivity($action, $entity, $entityId, $message, $userId) {
        // Sessizce geç
    }
}

// Oturum kontrolü
if (!isLoggedIn()) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Oturum açmanız gerekiyor'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Sadece POST metodu kabul et
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Sadece POST metodu kabul edilir'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // JSON body'yi parse et
    $input = json_decode(file_get_contents('php://input'), true);
    
    $meetingId = $input['meeting_id'] ?? $_POST['meeting_id'] ?? null;
    
    if (!$meetingId) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'meeting_id parametresi gerekli'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $currentUser = getCurrentUser();
    
    // Veritabanından toplantı bilgilerini al
    $stmt = $pdo->prepare("
        SELECT m.*, za.id as zoom_account_id
        FROM meetings m
        LEFT JOIN zoom_accounts za ON m.zoom_account_id = za.id
        WHERE m.id = ?
    ");
    $stmt->execute([$meetingId]);
    $meeting = $stmt->fetch();
    
    if (!$meeting) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Toplantı bulunamadı'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Yetki kontrolü - sadece kendi toplantısını veya aynı birimdeki toplantıları başlatabilir
    if ($meeting['user_id'] != $currentUser['id'] && $meeting['department_id'] != $currentUser['department_id']) {
        // Admin kontrolü
        if (!isAdmin()) {
            ob_end_clean();
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Bu toplantıyı başlatma yetkiniz yok'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }
    
    // Zoom hesabı kontrolü
    if (!$meeting['zoom_account_id']) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Bu toplantı için Zoom hesabı atanmamış'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Zoom meeting ID kontrolü
    $zoomMeetingId = $meeting['zoom_meeting_id'] ?? $meeting['meeting_id'] ?? null;
    if (!$zoomMeetingId) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Bu toplantı için Zoom toplantı ID\'si bulunamadı'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Zoom API bağlantısı kur
    $zoomManager = new ZoomAccountManager($pdo);
    $zoomAPI = $zoomManager->getZoomAPI($meeting['zoom_account_id']);
    
    // Güncel start URL al
    $result = $zoomAPI->getFreshStartUrl($zoomMeetingId);
    
    if ($result['success']) {
        // Log kaydı
        writeLog("Fresh start URL obtained for meeting $meetingId by user " . $currentUser['id'], 'info');
        
        // Aktivite kaydı
        logActivity('start_meeting', 'meeting', $meetingId, 
            'Toplantı başlatıldı: ' . $meeting['title'], $currentUser['id']);
        
        // Buffer'ı temizle ve JSON döndür
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Güncel başlatma URL\'si alındı',
            'data' => $result['data']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        ob_end_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Başlatma URL\'si alınamadı'
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    writeLog("Refresh start URL error: " . $e->getMessage(), 'error');
    
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sunucu hatası: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
