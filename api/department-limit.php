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

if (!$currentUser['department_id']) {
    sendJsonResponse(false, 'Birim bilgisi bulunamadı.');
}

try {
    // Birim limitini al
    $stmt = $pdo->prepare("SELECT weekly_limit, name FROM departments WHERE id = ?");
    $stmt->execute([$currentUser['department_id']]);
    $department = $stmt->fetch();
    
    if (!$department) {
        sendJsonResponse(false, 'Birim bulunamadı.');
    }
    
    // Bu haftanın başlangıcı ve bitişi
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    
    // Bu hafta onaylanmış toplantı sayısı (birim bazında)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM meetings 
        WHERE department_id = ? 
        AND date BETWEEN ? AND ? 
        AND status = 'approved'
    ");
    $stmt->execute([$currentUser['department_id'], $weekStart, $weekEnd]);
    $weeklyUsed = $stmt->fetchColumn();
    
    // Bu hafta bekleyen toplantı sayısı
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM meetings 
        WHERE department_id = ? 
        AND date BETWEEN ? AND ? 
        AND status = 'pending'
    ");
    $stmt->execute([$currentUser['department_id'], $weekStart, $weekEnd]);
    $weeklyPending = $stmt->fetchColumn();
    
    // Kullanıcının bu hafta toplantı sayısı
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM meetings 
        WHERE user_id = ? 
        AND date BETWEEN ? AND ? 
        AND status IN ('approved', 'pending')
    ");
    $stmt->execute([$currentUser['id'], $weekStart, $weekEnd]);
    $userWeeklyCount = $stmt->fetchColumn();
    
    $weeklyLimit = (int)$department['weekly_limit'];
    $weeklyRemaining = max(0, $weeklyLimit - $weeklyUsed - $weeklyPending);
    $canCreate = $weeklyRemaining > 0;
    
    // Birim kullanım yüzdesi
    $usagePercentage = $weeklyLimit > 0 ? round((($weeklyUsed + $weeklyPending) / $weeklyLimit) * 100, 1) : 0;
    
    $data = [
        'department_name' => $department['name'],
        'limit' => $weeklyLimit,
        'used' => $weeklyUsed,
        'pending' => $weeklyPending,
        'remaining' => $weeklyRemaining,
        'total_allocated' => $weeklyUsed + $weeklyPending,
        'can_create' => $canCreate,
        'usage_percentage' => $usagePercentage,
        'user_weekly_count' => $userWeeklyCount,
        'week_start' => $weekStart,
        'week_end' => $weekEnd
    ];
    
    sendJsonResponse(true, 'Birim limit bilgileri başarıyla alındı.', $data);
    
} catch (Exception $e) {
    writeLog("Department limit API error: " . $e->getMessage(), 'error');
    http_response_code(500);
    sendJsonResponse(false, 'Birim limit bilgileri alınırken hata oluştu.');
}