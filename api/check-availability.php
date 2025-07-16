<?php
require_once '../config/config.php';
require_once '../config/auth.php';

header('Content-Type: application/json');

// Authentication kontrolü
if (!isLoggedIn()) {
    http_response_code(401);
    sendJsonResponse(false, 'Oturum açmanız gerekiyor.');
}

// CSRF kontrolü
$headers = getallheaders();
$csrfToken = $headers['X-CSRF-Token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    sendJsonResponse(false, 'Güvenlik hatası.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendJsonResponse(false, 'Sadece POST istekleri kabul edilir.');
}

$input = json_decode(file_get_contents('php://input'), true);

$date = cleanInput($input['date'] ?? '');
$startTime = cleanInput($input['start_time'] ?? '');
$endTime = cleanInput($input['end_time'] ?? '');
$excludeMeetingId = (int)($input['exclude_meeting_id'] ?? 0);

// Validation
if (empty($date) || empty($startTime) || empty($endTime)) {
    sendJsonResponse(false, 'Tarih ve saat bilgileri eksik.');
}

if (!validateDate($date) || !validateTime($startTime) || !validateTime($endTime)) {
    sendJsonResponse(false, 'Geçersiz tarih veya saat formatı.');
}

if (strtotime($date . ' ' . $startTime) <= time()) {
    sendJsonResponse(false, 'Geçmiş tarih için kontrol yapılamaz.');
}

if ($startTime >= $endTime) {
    sendJsonResponse(false, 'Bitiş saati başlangıç saatinden sonra olmalıdır.');
}

// Working hours and weekend restrictions removed - meetings can be scheduled 7/24

$currentUser = getCurrentUser();
$conflicts = [];
$available = true;

// Debug için
writeLog("Check availability request - Date: $date, Start: $startTime, End: $endTime, User: " . $currentUser['id'], 'info');
writeLog("Work hours - WORK_START: " . (defined('WORK_START') ? WORK_START : 'NOT_DEFINED') . ", WORK_END: " . (defined('WORK_END') ? WORK_END : 'NOT_DEFINED'), 'info');

try {
    // Kullanıcının kendi çakışması
    if (checkUserConflict($currentUser['id'], $date, $startTime, $endTime, $excludeMeetingId)) {
        $conflicts[] = 'Bu tarih ve saatte zaten bir toplantınız bulunuyor.';
        $available = false;
    }
    
    // Birim haftalık limit kontrolü
    if (!checkDepartmentWeeklyLimit($currentUser['department_id'], $date)) {
        $conflicts[] = 'Biriminizin haftalık toplantı limiti dolmuş.';
        $available = false;
    }
    
    // Çakışan toplantıları al (detaylı bilgi için)
    $stmt = $pdo->prepare("
        SELECT m.title, m.start_time, m.end_time, u.name, u.surname, d.name as department_name
        FROM meetings m
        JOIN users u ON m.user_id = u.id
        JOIN departments d ON m.department_id = d.id
        WHERE m.date = ? 
        AND m.status IN ('pending', 'approved')
        AND (
            (m.start_time <= ? AND m.end_time > ?) OR
            (m.start_time < ? AND m.end_time >= ?) OR
            (m.start_time >= ? AND m.end_time <= ?)
        )
    ");
    
    $params = [$date, $startTime, $startTime, $endTime, $endTime, $startTime, $endTime];
    
    if ($excludeMeetingId) {
        $stmt = $pdo->prepare("
            SELECT m.title, m.start_time, m.end_time, u.name, u.surname, d.name as department_name
            FROM meetings m
            JOIN users u ON m.user_id = u.id
            JOIN departments d ON m.department_id = d.id
            WHERE m.date = ? 
            AND m.status IN ('pending', 'approved')
            AND m.id != ?
            AND (
                (m.start_time <= ? AND m.end_time > ?) OR
                (m.start_time < ? AND m.end_time >= ?) OR
                (m.start_time >= ? AND m.end_time <= ?)
            )
        ");
        $params = [$date, $excludeMeetingId, $startTime, $startTime, $endTime, $endTime, $startTime, $endTime];
    }
    
    $stmt->execute($params);
    $conflictingMeetings = $stmt->fetchAll();
    
    // Alternatif zaman önerileri oluştur
    $suggestions = [];
    if (!$available) {
        $suggestions = generateTimeSuggestions($date, $startTime, $endTime, $currentUser['id']);
    }
    
    $data = [
        'available' => $available,
        'conflicts' => $conflicts,
        'conflicting_meetings' => $conflictingMeetings,
        'suggestions' => $suggestions
    ];
    
    $message = $available ? 'Seçilen tarih ve saat uygun.' : implode(' ', $conflicts);
    
    sendJsonResponse(true, $message, $data);
    
} catch (Exception $e) {
    writeLog("Availability check error: " . $e->getMessage(), 'error');
    http_response_code(500);
    sendJsonResponse(false, 'Uygunluk kontrolü sırasında hata oluştu.');
}

// Alternatif zaman önerileri oluştur
function generateTimeSuggestions($date, $preferredStart, $preferredEnd, $userId) {
    global $pdo;
    
    $suggestions = [];
    $duration = strtotime($preferredEnd) - strtotime($preferredStart);
    $durationMinutes = $duration / 60;
    
    // 24/7 zaman önerileri - 30 dakika aralıklarla kontrol et
    $dayStart = strtotime('00:00');
    $dayEnd = strtotime('23:59');
    
    for ($time = $dayStart; $time <= $dayEnd - $duration; $time += 1800) { // 30 dakika
        $suggestedStart = date('H:i', $time);
        $suggestedEnd = date('H:i', $time + $duration);
        
        // Bu zaman diliminde çakışma var mı?
        if (!checkUserConflict($userId, $date, $suggestedStart, $suggestedEnd)) {
            $suggestions[] = [
                'start_time' => $suggestedStart,
                'end_time' => $suggestedEnd,
                'duration_minutes' => $durationMinutes
            ];
            
            // En fazla 5 öneri
            if (count($suggestions) >= 5) {
                break;
            }
        }
    }
    
    return $suggestions;
}