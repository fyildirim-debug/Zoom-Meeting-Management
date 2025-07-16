<?php
require_once '../config/config.php';
require_once '../config/auth.php';

header('Content-Type: application/json');

// Oturum kontrolü
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Oturumunuz sonlanmış.']);
    exit;
}

$currentUser = getCurrentUser();
$meetingId = (int)($_GET['id'] ?? 0);

if (!$meetingId) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz toplantı ID.']);
    exit;
}

try {
    // Toplantı detaylarını al - Birim bazlı erişim kontrolü
    $stmt = $pdo->prepare("
        SELECT m.*,
               d.name as department_name,
               u.name as user_name, u.surname as user_surname,
               za.email as zoom_email, za.name as zoom_account_name
        FROM meetings m
        LEFT JOIN departments d ON m.department_id = d.id
        LEFT JOIN users u ON m.user_id = u.id
        LEFT JOIN zoom_accounts za ON m.zoom_account_id = za.id
        WHERE m.id = ? AND m.department_id = ?
    ");
    $stmt->execute([$meetingId, $currentUser['department_id']]);
    $meeting = $stmt->fetch();
    
    if (!$meeting) {
        echo json_encode(['success' => false, 'message' => 'Bu toplantıya erişim yetkiniz yok.']);
        exit;
    }
    
    // Tarih formatını düzenle
    $meeting['formatted_date'] = formatDateTurkish($meeting['date']);
    $meeting['formatted_start_time'] = formatTime($meeting['start_time']);
    $meeting['formatted_end_time'] = formatTime($meeting['end_time']);
    $meeting['duration'] = calculateMeetingDuration($meeting['start_time'], $meeting['end_time']);
    
    // Zoom hesap bilgilerini sadece admin kullanıcılara göster
    if (!isAdmin()) {
        unset($meeting['zoom_email']);
        unset($meeting['zoom_account_name']);
    }
    
    echo json_encode([
        'success' => true,
        'meeting' => $meeting
    ]);
    
} catch (Exception $e) {
    writeLog("Meeting details error: " . $e->getMessage(), 'error');
    echo json_encode(['success' => false, 'message' => 'Toplantı detayları alınırken hata oluştu.']);
}
?>