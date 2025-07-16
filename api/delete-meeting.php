<?php
require_once '../config/config.php';
require_once '../config/auth.php';
require_once '../includes/ZoomAPI.php';

header('Content-Type: application/json');

// POST kontrolü
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Sadece POST istekleri kabul edilir.']);
    exit;
}

// Oturum kontrolü
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Oturumunuz sonlanmış.']);
    exit;
}

$currentUser = getCurrentUser();

// JSON verilerini al
$input = json_decode(file_get_contents('php://input'), true);
$meetingId = (int)($input['meeting_id'] ?? 0);

// CSRF kontrolü - fetch ile X-CSRF-Token header'ı HTTP_X_CSRF_TOKEN olur
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Güvenlik hatası. CSRF token geçersiz.']);
    exit;
}

if (!$meetingId) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz toplantı ID.']);
    exit;
}

try {
    // Toplantının kullanıcıya ait olduğunu ve silinebilir durumda olduğunu kontrol et
    $stmt = $pdo->prepare("
        SELECT id, status, title, date 
        FROM meetings 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$meetingId, $currentUser['id']]);
    $meeting = $stmt->fetch();
    
    if (!$meeting) {
        echo json_encode(['success' => false, 'message' => 'Bu toplantıya sadece toplantıyı oluşturan kullanıcı erişebilir.']);
        exit;
    }
    
    // Sadece reddedilen veya iptal edilmiş toplantılar silinebilir
    if (!in_array($meeting['status'], ['rejected', 'cancelled'])) {
        echo json_encode(['success' => false, 'message' => 'Sadece reddedilen veya iptal edilmiş toplantılar silinebilir.']);
        exit;
    }
    
    // Toplantıyı sil
    $stmt = $pdo->prepare("DELETE FROM meetings WHERE id = ? AND user_id = ?");
    
    if ($stmt->execute([$meetingId, $currentUser['id']])) {
        writeLog("Meeting deleted: ID $meetingId by user " . $currentUser['id'], 'info');
        
        echo json_encode([
            'success' => true,
            'message' => 'Toplantı başarıyla silindi.'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Silme işlemi başarısız.']);
    }
    
} catch (Exception $e) {
    writeLog("Delete meeting error: " . $e->getMessage(), 'error');
    echo json_encode(['success' => false, 'message' => 'Silme işlemi sırasında hata oluştu.']);
}
?>