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
$query = trim($_GET['q'] ?? '');

if (empty($query) || strlen($query) < 2) {
    echo json_encode(['success' => true, 'meetings' => []]);
    exit;
}

try {
    // Admin tüm toplantıları, normal kullanıcı sadece kendi toplantılarını görebilir
    if (isAdmin()) {
        // Admin - tüm toplantıları ara
        $stmt = $pdo->prepare("
            SELECT m.id, m.title, m.date, m.start_time, m.end_time, m.status,
                   u.name as user_name, u.surname as user_surname,
                   d.name as department_name
            FROM meetings m
            LEFT JOIN users u ON m.user_id = u.id
            LEFT JOIN departments d ON m.department_id = d.id
            WHERE m.title LIKE ?
            ORDER BY m.date DESC, m.start_time DESC
            LIMIT 3
        ");
        $stmt->execute(['%' . $query . '%']);
    } else {
        // Normal kullanıcı - sadece kendi toplantıları
        $stmt = $pdo->prepare("
            SELECT m.id, m.title, m.date, m.start_time, m.end_time, m.status,
                   u.name as user_name, u.surname as user_surname,
                   d.name as department_name
            FROM meetings m
            LEFT JOIN users u ON m.user_id = u.id
            LEFT JOIN departments d ON m.department_id = d.id
            WHERE m.title LIKE ? AND m.user_id = ?
            ORDER BY m.date DESC, m.start_time DESC
            LIMIT 3
        ");
        $stmt->execute(['%' . $query . '%', $currentUser['id']]);
    }
    
    $meetings = $stmt->fetchAll();
    
    // Tarih ve saat formatlarını düzenle
    foreach ($meetings as &$meeting) {
        $meeting['formatted_date'] = formatDateTurkish($meeting['date']);
        $meeting['formatted_start_time'] = formatTime($meeting['start_time']);
        $meeting['formatted_end_time'] = formatTime($meeting['end_time']);
        
        // Status rengini belirle
        switch ($meeting['status']) {
            case 'approved':
                $meeting['status_color'] = 'green';
                $meeting['status_text'] = 'Onaylı';
                break;
            case 'pending':
                $meeting['status_color'] = 'orange';
                $meeting['status_text'] = 'Bekliyor';
                break;
            case 'rejected':
                $meeting['status_color'] = 'red';
                $meeting['status_text'] = 'Reddedildi';
                break;
            case 'cancelled':
                $meeting['status_color'] = 'gray';
                $meeting['status_text'] = 'İptal Edildi';
                break;
            default:
                $meeting['status_color'] = 'gray';
                $meeting['status_text'] = $meeting['status'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'meetings' => $meetings,
        'is_admin' => isAdmin(),
        'query' => $query
    ]);
    
} catch (Exception $e) {
    writeLog("Search meetings error: " . $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Arama sırasında hata oluştu.']);
}
?> 