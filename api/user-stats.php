<?php
require_once '../config/config.php';
require_once '../config/auth.php';

header('Content-Type: application/json');

// Authentication kontrolü
if (!isLoggedIn()) {
    http_response_code(401);
    sendJsonResponse(false, 'Oturum açmanız gerekiyor.');
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

try {
    $stats = [];
    
    // Kullanıcının toplam toplantı sayısı (iptal edilenler ve reddedilenler hariç)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE user_id = ? AND status NOT IN ('cancelled', 'rejected')");
    $stmt->execute([$userId]);
    $stats['meetings_count'] = $stmt->fetchColumn();
    
    // Bekleyen toplantı sayısı
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$userId]);
    $stats['pending_count'] = $stmt->fetchColumn();
    
    // Onaylanmış toplantı sayısı
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$userId]);
    $stats['approved_count'] = $stmt->fetchColumn();
    
    // Bu hafta toplantı sayısı
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM meetings 
        WHERE user_id = ? AND date BETWEEN ? AND ? AND status = 'approved'
    ");
    $stmt->execute([$userId, $weekStart, $weekEnd]);
    $stats['this_week_count'] = $stmt->fetchColumn();
    
    // Yaklaşan toplantılar (7 gün)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM meetings 
        WHERE user_id = ? AND status = 'approved' 
        AND date >= CURDATE() AND date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->execute([$userId]);
    $stats['upcoming_count'] = $stmt->fetchColumn();
    
    sendJsonResponse(true, 'İstatistikler başarıyla alındı.', $stats);
    
} catch (Exception $e) {
    writeLog("User stats API error: " . $e->getMessage(), 'error');
    http_response_code(500);
    sendJsonResponse(false, 'İstatistikler alınırken hata oluştu.');
}