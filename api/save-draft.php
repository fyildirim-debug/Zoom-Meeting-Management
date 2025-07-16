<?php
require_once '../config/config.php';
require_once '../config/auth.php';

header('Content-Type: application/json');

// Authentication kontrolü
if (!isLoggedIn()) {
    http_response_code(401);
    sendJsonResponse(false, 'Oturum açmanız gerekiyor.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendJsonResponse(false, 'Sadece POST istekleri kabul edilir.');
}

$currentUser = getCurrentUser();

// Form verilerini al
$title = cleanInput($_POST['title'] ?? '');
$date = cleanInput($_POST['date'] ?? '');
$startTime = cleanInput($_POST['start_time'] ?? '');
$endTime = cleanInput($_POST['end_time'] ?? '');
$moderator = cleanInput($_POST['moderator'] ?? '');
$description = cleanInput($_POST['description'] ?? '');
$participantsCount = (int)($_POST['participants_count'] ?? 0);

try {
    // Draft'ı veritabanına kaydet (opsiyonel özellik)
    // Bu örnekte sadece success döndürüyoruz
    writeLog("Draft saved for user " . $currentUser['id'], 'info');
    
    sendJsonResponse(true, 'Taslak kaydedildi.');
    
} catch (Exception $e) {
    writeLog("Error saving draft: " . $e->getMessage(), 'error');
    http_response_code(500);
    sendJsonResponse(false, 'Taslak kaydedilirken hata oluştu.');
}