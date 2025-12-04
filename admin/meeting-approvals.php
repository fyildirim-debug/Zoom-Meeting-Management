<?php
$pageTitle = 'Toplantı Onayları';
require_once '../config/config.php';
require_once '../config/auth.php';
require_once '../includes/ZoomAPI.php';

requireLogin();
if (!isAdmin()) {
    redirect('../dashboard.php');
}

$currentUser = getCurrentUser();

// Toplantı işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Güvenlik token hatası. Sayfayı yenileyin ve tekrar deneyin.';
        $messageType = 'error';
    } elseif (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'approve_meeting':
                $customSettings = extractCustomZoomSettings($_POST);
                $result = approveMeeting($_POST['meeting_id'], $_POST['zoom_account_id'] ?? null, $customSettings);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'reject_meeting':
                $result = rejectMeeting($_POST['meeting_id'], $_POST['rejection_reason'] ?? '');
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'bulk_approve':
                $bulkCustomSettings = extractCustomZoomSettings($_POST);
                $result = bulkApproveMeetings(
                    $_POST['meeting_ids'] ?? [], 
                    $_POST['bulk_zoom_account_id'] ?? null,
                    $bulkCustomSettings
                );
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'cancel_meeting':
                $result = cancelMeeting($_POST['meeting_id'], $_POST['cancel_reason'] ?? '');
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            default:
                $message = 'Geçersiz işlem.';
                $messageType = 'error';
                break;
        }
    }
}

// Toplantıları listele
$filter = $_GET['filter'] ?? 'pending';
$sort = $_GET['sort'] ?? 'smart'; // smart, date_asc, date_desc, created_desc
$page = max(1, $_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Tarih değişkenleri
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

try {
    // Where clause for filter
    $whereClause = '';
    $isSQLite = defined('DB_TYPE') && DB_TYPE === 'sqlite';
    $nowFunc = $isSQLite ? "DATE('now')" : "CURDATE()";
    
    switch ($filter) {
        case 'pending':
            $whereClause = "WHERE m.status = 'pending'";
            break;
        case 'approved':
            $whereClause = "WHERE m.status = 'approved'";
            break;
        case 'approved_upcoming':
            $whereClause = "WHERE m.status = 'approved' AND m.date >= $nowFunc";
            break;
        case 'approved_past':
            $whereClause = "WHERE m.status = 'approved' AND m.date < $nowFunc";
            break;
        case 'rejected':
            $whereClause = "WHERE m.status = 'rejected'";
            break;
        case 'cancelled':
            $whereClause = "WHERE m.status = 'cancelled'";
            break;
        case 'today':
            $whereClause = "WHERE DATE(m.date) = $nowFunc AND m.status = 'approved'";
            break;
        case 'this_week':
            $whereClause = "WHERE m.date BETWEEN '$weekStart' AND '$weekEnd' AND m.status IN ('approved', 'pending')";
            break;
        case 'this_month':
            $whereClause = "WHERE m.date BETWEEN '$monthStart' AND '$monthEnd' AND m.status IN ('approved', 'pending')";
            break;
        case 'upcoming':
            $whereClause = "WHERE m.date > $nowFunc AND m.status = 'approved'";
            break;
        case 'all':
            $whereClause = '';
            break;
        default:
            $whereClause = '';
    }
    
    // Sıralama mantığı
    $orderClause = match($sort) {
        'date_asc' => "m.date ASC, m.start_time ASC",
        'date_desc' => "m.date DESC, m.start_time DESC",
        'created_desc' => "m.created_at DESC",
        'created_asc' => "m.created_at ASC",
        default => // smart sorting
            "CASE 
                WHEN m.status = 'pending' THEN 0 
                WHEN m.status = 'approved' AND m.date >= $nowFunc THEN 1
                WHEN m.status = 'approved' AND m.date < $nowFunc THEN 3
                ELSE 2 
            END,
            CASE 
                WHEN m.status = 'pending' THEN m.date 
                WHEN m.status = 'approved' AND m.date >= $nowFunc THEN m.date
                ELSE NULL
            END ASC,
            CASE 
                WHEN m.status IN ('rejected', 'cancelled') OR (m.status = 'approved' AND m.date < $nowFunc) 
                THEN m.date 
                ELSE NULL 
            END DESC,
            m.start_time ASC"
    };
    
    // Build the main query
    $mainQuery = "
        SELECT m.*,
               u.name as user_name, u.surname as user_surname, u.email as user_email,
               d.name as department_name,
               za.email as zoom_email, za.name as zoom_account_name
        FROM meetings m
        JOIN users u ON m.user_id = u.id
        LEFT JOIN departments d ON m.department_id = d.id
        LEFT JOIN zoom_accounts za ON m.zoom_account_id = za.id
        $whereClause
        ORDER BY $orderClause
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($mainQuery);
    $stmt->execute();
    $meetings = $stmt->fetchAll();
    
    // Total count for pagination
    $countQuery = "
        SELECT COUNT(*) FROM meetings m
        JOIN users u ON m.user_id = u.id
        LEFT JOIN departments d ON m.department_id = d.id
        $whereClause
    ";
    
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute();
    $totalCount = $countStmt->fetchColumn();
    $totalPages = ceil($totalCount / $limit);
    
    // Zoom hesapları
    $stmt = $pdo->query("SELECT * FROM zoom_accounts WHERE status = 'active' ORDER BY email");
    $zoomAccounts = $stmt->fetchAll();
    
    // Ana ayarları çek (default değerler için)
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'zoom_%'");
    $defaultZoomSettings = [];
    while ($row = $stmt->fetch()) {
        $defaultZoomSettings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Varsayılan değerler
    $zoomDefaults = [
        'zoom_join_before_host' => $defaultZoomSettings['zoom_join_before_host'] ?? '0',
        'zoom_waiting_room' => $defaultZoomSettings['zoom_waiting_room'] ?? '1',
        'zoom_meeting_authentication' => $defaultZoomSettings['zoom_meeting_authentication'] ?? '0',
        'zoom_host_video' => $defaultZoomSettings['zoom_host_video'] ?? '1',
        'zoom_participant_video' => $defaultZoomSettings['zoom_participant_video'] ?? '1',
        'zoom_mute_upon_entry' => $defaultZoomSettings['zoom_mute_upon_entry'] ?? '1',
        'zoom_auto_recording' => $defaultZoomSettings['zoom_auto_recording'] ?? 'none',
        'zoom_cloud_recording' => $defaultZoomSettings['zoom_cloud_recording'] ?? '0',
        'zoom_chat' => $defaultZoomSettings['zoom_chat'] ?? '1',
        'zoom_screen_sharing' => $defaultZoomSettings['zoom_screen_sharing'] ?? '1',
        'zoom_breakout_rooms' => $defaultZoomSettings['zoom_breakout_rooms'] ?? '1'
    ];
    
    // Stats - Database compatible version
    if (defined('DB_TYPE') && DB_TYPE === 'sqlite') {
        $statsQuery = "
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
                COUNT(CASE WHEN DATE(date) = DATE('now') AND status = 'approved' THEN 1 END) as today
            FROM meetings
        ";
    } else {
        $statsQuery = "
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
                COUNT(CASE WHEN DATE(date) = CURDATE() AND status = 'approved' THEN 1 END) as today
            FROM meetings
        ";
    }
    
    $statsStmt = $pdo->query($statsQuery);
    $stats = $statsStmt->fetch();
    
} catch (Exception $e) {
    writeLog("Meeting approvals page error: " . $e->getMessage(), 'error');
    writeLog("Meeting approvals page error trace: " . $e->getTraceAsString(), 'error');
    $meetings = [];
    $zoomAccounts = [];
    $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'today' => 0];
}

// Helper functions
function extractCustomZoomSettings($postData) {
    $customSettings = [];
    
    // Özel ayarları çıkar
    $settingsMap = [
        'custom_join_before_host' => 'join_before_host',
        'custom_waiting_room' => 'waiting_room',
        'custom_meeting_authentication' => 'meeting_authentication',
        'custom_host_video' => 'host_video',
        'custom_participant_video' => 'participant_video',
        'custom_mute_upon_entry' => 'mute_upon_entry',
        'custom_cloud_recording' => 'cloud_recording',
        'custom_chat' => 'chat',
        'custom_screen_sharing' => 'screen_sharing',
        'custom_breakout_rooms' => 'breakout_rooms'
    ];
    
    foreach ($settingsMap as $postKey => $zoomKey) {
        if (isset($postData[$postKey])) {
            $customSettings[$zoomKey] = true;
        }
    }
    
    // Otomatik kayıt özel ayarı
    if (isset($postData['custom_auto_recording']) && !empty($postData['custom_auto_recording'])) {
        $customSettings['auto_recording'] = $postData['custom_auto_recording'];
    }
    
    // Çelişki kontrolü - Host'tan önce katılım varsa bekleme odasını kapat
    if (isset($customSettings['join_before_host']) && $customSettings['join_before_host']) {
        $customSettings['waiting_room'] = false;
    }
    
    // Bekleme odası varsa host'tan önce katılımı kapat
    if (isset($customSettings['waiting_room']) && $customSettings['waiting_room']) {
        $customSettings['join_before_host'] = false;
    }
    
    return $customSettings;
}

function approveMeeting($meetingId, $zoomAccountId = null, $customSettings = []) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Toplantı bilgilerini al
        $stmt = $pdo->prepare("SELECT * FROM meetings WHERE id = ?");
        $stmt->execute([$meetingId]);
        $meeting = $stmt->fetch();
        
        if (!$meeting) {
            throw new Exception('Toplantı bulunamadı.');
        }
        
        if ($meeting['status'] !== 'pending') {
            throw new Exception('Bu toplantı zaten işlenmiş.');
        }
        
        // Zoom hesabı kontrolü ve API entegrasyonu
        $finalZoomAccountId = null;
        $meetingLink = null;
        $zoomMeetingData = null;
        
        // Boş string'i null'a çevir
        if ($zoomAccountId === '' || $zoomAccountId === '0') {
            $zoomAccountId = null;
        }
        
        if ($zoomAccountId) {
            // Manuel seçim - çakışma kontrolü yap
            if (isZoomAccountBusy($zoomAccountId, $meeting['date'], $meeting['start_time'], $meeting['end_time'])) {
                throw new Exception('Seçilen Zoom hesabı bu saatte başka bir toplantıda kullanılıyor.');
            }
            
            $stmt = $pdo->prepare("SELECT * FROM zoom_accounts WHERE id = ? AND status = 'active'");
            $stmt->execute([$zoomAccountId]);
            $zoomAccount = $stmt->fetch();
            
            if (!$zoomAccount) {
                throw new Exception('Seçilen Zoom hesabı bulunamadı veya aktif değil.');
            }
            
            // Zoom API entegrasyonu
            try {
                $zoomAccountManager = new ZoomAccountManager($pdo);
                $zoomAPI = $zoomAccountManager->getZoomAPI($zoomAccountId);
                
                // Meeting verilerini hazırla
                $meetingData = [
                    'title' => $meeting['title'],
                    'description' => $meeting['description'],
                    'date' => $meeting['date'],
                    'start_time' => $meeting['start_time'],
                    'end_time' => $meeting['end_time'],
                    'host_email' => $zoomAccount['email']
                ];
                
                // Zoom'da toplantı oluştur - özel ayarlarla birlikte
                $apiResult = $zoomAPI->createMeeting($meetingData, $customSettings);
                
                if ($apiResult['success']) {
                    $zoomMeetingData = $apiResult['data'];
                    $finalZoomAccountId = $zoomAccountId;
                    $meetingLink = $zoomMeetingData['join_url'];
                    
                    // API log kaydet
                    logZoomAPIActivity($pdo, $zoomAccountId, $meetingId, 'create_meeting', '/meetings',
                                     $meetingData, $zoomMeetingData, 200, true);
                    
                    writeLog("Zoom meeting created successfully via API: Meeting ID {$zoomMeetingData['meeting_id']}", 'info');
                } else {
                    // API hatası - log kaydet
                    logZoomAPIActivity($pdo, $zoomAccountId, $meetingId, 'create_meeting', '/meetings',
                                     $meetingData, null, null, false, $apiResult['message']);
                    
                    throw new Exception('Zoom API hatası: ' . $apiResult['message']);
                }
                
            } catch (Exception $apiException) {
                writeLog("Zoom API integration error: " . $apiException->getMessage(), 'error');
                
                // API hatası durumunda fallback - basit link oluştur
                $finalZoomAccountId = $zoomAccountId;
                $meetingLink = generateFallbackMeetingLink($zoomAccount, $meeting);
                $zoomMeetingData = null;
                
                writeLog("Falling back to simple meeting link generation", 'warning');
            }
        } else {
            throw new Exception('Zoom hesabı seçimi zorunludur.');
        }
        
        // Toplantıyı onayla ve Zoom verilerini kaydet
        if ($zoomMeetingData) {
            // Gerçek API verilerini kaydet
            $stmt = $pdo->prepare("
                UPDATE meetings
                SET status = 'approved',
                    zoom_account_id = ?,
                    meeting_link = ?,
                    zoom_meeting_id = ?,
                    zoom_uuid = ?,
                    zoom_join_url = ?,
                    zoom_start_url = ?,
                    zoom_password = ?,
                    zoom_host_id = ?,
                    api_created_at = NOW(),
                    approved_at = NOW(),
                    approved_by = ?
                WHERE id = ?
            ");
            $result = $stmt->execute([
                $finalZoomAccountId,
                $meetingLink,
                $zoomMeetingData['meeting_id'],
                $zoomMeetingData['uuid'],
                $zoomMeetingData['join_url'],
                $zoomMeetingData['start_url'],
                $zoomMeetingData['password'],
                $zoomMeetingData['host_id'],
                $_SESSION['user_id'],
                $meetingId
            ]);
        } else {
            // Fallback verilerini kaydet
            $stmt = $pdo->prepare("
                UPDATE meetings
                SET status = 'approved',
                    zoom_account_id = ?,
                    meeting_link = ?,
                    approved_at = NOW(),
                    approved_by = ?
                WHERE id = ?
            ");
            $result = $stmt->execute([$finalZoomAccountId, $meetingLink, $_SESSION['user_id'], $meetingId]);
        }
        
        if ($result) {
            $pdo->commit();
            writeLog("Meeting approved: ID $meetingId, Zoom Account: $finalZoomAccountId", 'info');
            
            // Aktivite kaydet
            logActivity('approved', 'meeting', $meetingId,
                'Toplantı onaylandı: ' . $meeting['title'] . ' (' . $meeting['date'] . ')',
                $_SESSION['user_id']);
            
            return ['success' => true, 'message' => 'Toplantı başarıyla onaylandı ve Zoom toplantısı oluşturuldu.'];
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        writeLog("Approve meeting error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function rejectMeeting($meetingId, $reason = '') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE meetings 
            SET status = 'rejected', 
                rejection_reason = ?,
                rejected_at = NOW(),
                rejected_by = ?
            WHERE id = ? AND status = 'pending'
        ");
        $result = $stmt->execute([$reason, $_SESSION['user_id'], $meetingId]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Toplantı bilgilerini al (aktivite için)
            $stmt = $pdo->prepare("SELECT title, date FROM meetings WHERE id = ?");
            $stmt->execute([$meetingId]);
            $meeting = $stmt->fetch();
            
            writeLog("Meeting rejected: ID $meetingId", 'info');
            
            // Aktivite kaydet
            if ($meeting) {
                logActivity('reject_meeting', 'meeting', $meetingId,
                    'Toplantı reddedildi: ' . $meeting['title'] . ' (' . $meeting['date'] . ')' .
                    ($reason ? ' - Sebep: ' . $reason : ''),
                    $_SESSION['user_id']);
            }
            
            return ['success' => true, 'message' => 'Toplantı reddedildi.'];
        } else {
            return ['success' => false, 'message' => 'Toplantı bulunamadı veya zaten işlenmiş.'];
        }
        
    } catch (Exception $e) {
        writeLog("Reject meeting error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Toplantı reddedilirken hata oluştu.'];
    }
}

function bulkApproveMeetings($meetingIds, $zoomAccountId = null, $customSettings = []) {
    global $pdo;
    
    if (empty($meetingIds)) {
        return ['success' => false, 'message' => 'Hiç toplantı seçilmedi.'];
    }
    
    if (empty($zoomAccountId)) {
        return ['success' => false, 'message' => 'Zoom hesabı seçimi zorunludur.'];
    }
    
    $successCount = 0;
    $failCount = 0;
    $errors = [];
    
    foreach ($meetingIds as $meetingId) {
        $result = approveMeeting($meetingId, $zoomAccountId, $customSettings);
        if ($result['success']) {
            $successCount++;
        } else {
            $failCount++;
            $errors[] = "Toplantı #$meetingId: " . $result['message'];
        }
    }
    
    if ($successCount > 0 && $failCount == 0) {
        return ['success' => true, 'message' => "$successCount toplantı başarıyla onaylandı."];
    } elseif ($successCount > 0 && $failCount > 0) {
        return ['success' => true, 'message' => "$successCount toplantı onaylandı, $failCount başarısız."];
    } else {
        return ['success' => false, 'message' => 'Hiçbir toplantı onaylanamadı. ' . implode('; ', array_slice($errors, 0, 3))];
    }
}

function cancelMeeting($meetingId, $reason = '') {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Toplantı bilgilerini al
        $stmt = $pdo->prepare("
            SELECT m.*, za.email as zoom_email, za.id as zoom_account_id
            FROM meetings m
            LEFT JOIN zoom_accounts za ON m.zoom_account_id = za.id
            WHERE m.id = ? AND m.status = 'approved'
        ");
        $stmt->execute([$meetingId]);
        $meeting = $stmt->fetch();
        
        if (!$meeting) {
            throw new Exception('Toplantı bulunamadı veya iptal edilemez.');
        }
        
        // Zoom API'den toplantıyı sil (eğer Zoom meeting ID varsa)
        if ($meeting['zoom_meeting_id'] && $meeting['zoom_account_id']) {
            try {
                $zoomAccountManager = new ZoomAccountManager($pdo);
                $zoomAPI = $zoomAccountManager->getZoomAPI($meeting['zoom_account_id']);
                
                $deleteResult = $zoomAPI->deleteMeeting($meeting['zoom_meeting_id']);
                
                if ($deleteResult['success']) {
                    writeLog("Zoom meeting deleted successfully: " . $meeting['zoom_meeting_id'], 'info');
                    
                    // API log kaydet
                    logZoomAPIActivity($pdo, $meeting['zoom_account_id'], $meetingId, 'delete_meeting', '/meetings/' . $meeting['zoom_meeting_id'],
                                     null, null, 204, true);
                } else {
                    writeLog("Failed to delete Zoom meeting: " . $deleteResult['message'], 'warning');
                    
                    // API log kaydet
                    logZoomAPIActivity($pdo, $meeting['zoom_account_id'], $meetingId, 'delete_meeting', '/meetings/' . $meeting['zoom_meeting_id'],
                                     null, null, null, false, $deleteResult['message']);
                }
                
            } catch (Exception $apiException) {
                writeLog("Zoom API error during meeting cancellation: " . $apiException->getMessage(), 'warning');
                // API hatası toplantı iptalini engellemez
            }
        }
        
        // Veritabanından toplantıyı iptal et
        $stmt = $pdo->prepare("
            UPDATE meetings
            SET status = 'cancelled',
                cancel_reason = ?,
                cancelled_at = NOW(),
                cancelled_by = ?
            WHERE id = ? AND status = 'approved'
        ");
        $result = $stmt->execute([$reason, $_SESSION['user_id'], $meetingId]);
        
        if ($result && $stmt->rowCount() > 0) {
            $pdo->commit();
            
            writeLog("Meeting cancelled: ID $meetingId", 'info');
            
            // Aktivite kaydet
            logActivity('cancel_meeting', 'meeting', $meetingId,
                'Toplantı iptal edildi: ' . $meeting['title'] . ' (' . $meeting['date'] . ')' .
                ($reason ? ' - Sebep: ' . $reason : ''),
                $_SESSION['user_id']);
            
            return ['success' => true, 'message' => 'Toplantı başarıyla iptal edildi ve Zoom\'dan silindi.'];
        } else {
            throw new Exception('Toplantı iptal edilemedi.');
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        writeLog("Cancel meeting error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function isZoomAccountBusy($zoomAccountId, $date, $startTime, $endTime, $excludeMeetingId = null) {
    global $pdo;
    
    try {
        // Debug log ekle
        writeLog("Checking zoom account busy: Account ID=$zoomAccountId, Date=$date, Time=$startTime-$endTime", 'info');
        
        // Basit çakışma kontrolü - aynı tarih ve saatte başka toplantı var mı?
        $sql = "
            SELECT id, title, start_time, end_time FROM meetings
            WHERE zoom_account_id = ?
            AND date = ?
            AND status = 'approved'
            AND (
                (start_time < ? AND end_time > ?) OR
                (start_time = ? AND end_time = ?) OR
                (start_time <= ? AND end_time >= ?) OR
                (start_time >= ? AND start_time < ?)
            )
        ";
        
        $params = [
            $zoomAccountId, $date,
            $endTime, $startTime,  // Bitiş saati başlangıçtan sonra ve başlangıç saati bitişten önce
            $startTime, $endTime,  // Tam aynı saat
            $startTime, $endTime,  // İçinde kalan
            $startTime, $endTime   // Örtüşen
        ];
        
        // Eğer belirli bir toplantıyı hariç tutmak istiyorsak
        if ($excludeMeetingId) {
            $sql .= " AND id != ?";
            $params[] = $excludeMeetingId;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $conflictingMeetings = $stmt->fetchAll();
        
        // Debug: çakışan toplantıları logla
        if (count($conflictingMeetings) > 0) {
            writeLog("Found conflicting meetings: " . json_encode($conflictingMeetings), 'warning');
            return true;
        } else {
            writeLog("No conflicting meetings found for zoom account $zoomAccountId", 'info');
            return false;
        }
        
    } catch (Exception $e) {
        writeLog("Zoom account busy check error: " . $e->getMessage(), 'error');
        return false; // Hata durumunda güvenli tarafta kal - çakışma var gibi davran
    }
}

function generateFallbackMeetingLink($zoomAccount, $meeting) {
    // API hatası durumunda basit meeting link oluştur
    $meetingId = rand(100000000, 999999999);
    return "https://zoom.us/j/{$meetingId}?pwd=" . base64_encode($meeting['title']);
}

function logZoomAPIActivity($pdo, $zoomAccountId, $meetingId, $action, $endpoint, $requestData, $responseData, $httpCode, $success, $errorMessage = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO zoom_api_logs (
                zoom_account_id, meeting_id, action, endpoint,
                request_data, response_data, http_code, success,
                error_message, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $zoomAccountId,
            $meetingId,
            $action,
            $endpoint,
            $requestData ? json_encode($requestData) : null,
            $responseData ? json_encode($responseData) : null,
            $httpCode,
            $success ? 1 : 0,
            $errorMessage
        ]);
        
        writeLog("Zoom API activity logged: $action for meeting $meetingId", 'info');
        
    } catch (Exception $e) {
        writeLog("Failed to log Zoom API activity: " . $e->getMessage(), 'error');
    }
}

function generateZoomMeetingLink($zoomAccount, $meeting) {
    // Eski basit meeting link oluştur (geriye uyumluluk için)
    $meetingId = rand(100000000, 999999999);
    return "https://zoom.us/j/{$meetingId}?pwd=" . base64_encode($meeting['title']);
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content flex-1 p-6">
    <div class="max-w-7xl mx-auto">
        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Toplantı Onayları</h1>
                <p class="mt-2 text-gray-600">Toplantı taleplerini inceleyin ve onaylayın</p>
            </div>
            <div class="mt-4 sm:mt-0 flex space-x-3">
                <button onclick="openBulkApprovalModal()" class="btn-secondary">
                    <i class="fas fa-tasks mr-2"></i>
                    Toplu Onay
                </button>
                <a href="../calendar.php" class="btn-primary">
                    <i class="fas fa-calendar mr-2"></i>
                    Takvim Görünümü
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> mb-6">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Toplam</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['total']; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar text-gray-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Bekleyen</p>
                        <p class="text-2xl font-bold text-orange-600"><?php echo $stats['pending']; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-clock text-orange-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Onaylı</p>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['approved']; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check text-green-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Reddedilen</p>
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats['rejected']; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-times text-red-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Bugün</p>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['today']; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar-day text-blue-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Tabs - Modern Design -->
        <div class="bg-gradient-to-r from-slate-50 to-gray-50 rounded-2xl shadow-sm border border-gray-100 mb-6 overflow-hidden">
            <!-- Durum Filtreleri -->
            <div class="px-6 py-5">
                <div class="flex items-center gap-3 mb-5">
                    <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 shadow-sm">
                        <i class="fas fa-filter text-white text-xs"></i>
                    </div>
                    <h4 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Durum</h4>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="?filter=pending&sort=<?php echo $sort; ?>" 
                       class="group relative flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?php echo $filter === 'pending' ? 'bg-gradient-to-r from-amber-500 to-orange-500 text-white shadow-lg shadow-orange-200' : 'bg-white text-gray-600 hover:bg-orange-50 hover:text-orange-600 border border-gray-200 hover:border-orange-200 hover:shadow-md'; ?>">
                        <i class="fas fa-hourglass-half <?php echo $filter === 'pending' ? '' : 'text-orange-400'; ?>"></i>
                        <span>Bekleyen</span>
                        <span class="<?php echo $filter === 'pending' ? 'bg-white/20' : 'bg-orange-100 text-orange-600'; ?> px-2 py-0.5 rounded-full text-xs font-bold"><?php echo $stats['pending']; ?></span>
                    </a>
                    <a href="?filter=approved_upcoming&sort=<?php echo $sort; ?>" 
                       class="group relative flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?php echo $filter === 'approved_upcoming' ? 'bg-gradient-to-r from-emerald-500 to-green-500 text-white shadow-lg shadow-green-200' : 'bg-white text-gray-600 hover:bg-green-50 hover:text-green-600 border border-gray-200 hover:border-green-200 hover:shadow-md'; ?>">
                        <i class="fas fa-check-circle <?php echo $filter === 'approved_upcoming' ? '' : 'text-green-400'; ?>"></i>
                        <span>Onaylı (Gelecek)</span>
                    </a>
                    <a href="?filter=approved_past&sort=<?php echo $sort; ?>" 
                       class="group relative flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?php echo $filter === 'approved_past' ? 'bg-gradient-to-r from-slate-500 to-gray-500 text-white shadow-lg shadow-gray-300' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200 hover:shadow-md'; ?>">
                        <i class="fas fa-history <?php echo $filter === 'approved_past' ? '' : 'text-gray-400'; ?>"></i>
                        <span>Onaylı (Geçmiş)</span>
                    </a>
                    <a href="?filter=rejected&sort=<?php echo $sort; ?>" 
                       class="group relative flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?php echo $filter === 'rejected' ? 'bg-gradient-to-r from-red-500 to-rose-500 text-white shadow-lg shadow-red-200' : 'bg-white text-gray-600 hover:bg-red-50 hover:text-red-600 border border-gray-200 hover:border-red-200 hover:shadow-md'; ?>">
                        <i class="fas fa-times-circle <?php echo $filter === 'rejected' ? '' : 'text-red-400'; ?>"></i>
                        <span>Reddedilen</span>
                        <span class="<?php echo $filter === 'rejected' ? 'bg-white/20' : 'bg-red-100 text-red-600'; ?> px-2 py-0.5 rounded-full text-xs font-bold"><?php echo $stats['rejected']; ?></span>
                    </a>
                    <a href="?filter=cancelled&sort=<?php echo $sort; ?>" 
                       class="group relative flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?php echo $filter === 'cancelled' ? 'bg-gradient-to-r from-gray-600 to-gray-700 text-white shadow-lg shadow-gray-300' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200 hover:shadow-md'; ?>">
                        <i class="fas fa-ban <?php echo $filter === 'cancelled' ? '' : 'text-gray-400'; ?>"></i>
                        <span>İptal</span>
                    </a>
                </div>
            </div>
            
            <!-- Tarih & Sıralama -->
            <div class="px-6 py-5 bg-white/50 border-t border-gray-100">
                <div class="flex flex-wrap items-center gap-6">
                    <!-- Tarih Filtreleri -->
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-400 to-purple-500">
                            <i class="fas fa-calendar text-white text-xs"></i>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="?filter=today&sort=<?php echo $sort; ?>" 
                               class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $filter === 'today' ? 'bg-blue-600 text-white shadow-md' : 'text-gray-500 hover:bg-blue-50 hover:text-blue-600'; ?>">
                                <i class="fas fa-sun mr-1.5 text-xs"></i>Bugün
                                <?php if($stats['today'] > 0): ?><span class="ml-1 text-xs opacity-75">(<?php echo $stats['today']; ?>)</span><?php endif; ?>
                            </a>
                            <a href="?filter=this_week&sort=<?php echo $sort; ?>" 
                               class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $filter === 'this_week' ? 'bg-indigo-600 text-white shadow-md' : 'text-gray-500 hover:bg-indigo-50 hover:text-indigo-600'; ?>">
                                <i class="fas fa-calendar-week mr-1.5 text-xs"></i>Bu Hafta
                            </a>
                            <a href="?filter=this_month&sort=<?php echo $sort; ?>" 
                               class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $filter === 'this_month' ? 'bg-purple-600 text-white shadow-md' : 'text-gray-500 hover:bg-purple-50 hover:text-purple-600'; ?>">
                                <i class="fas fa-calendar-alt mr-1.5 text-xs"></i>Bu Ay
                            </a>
                            <a href="?filter=all&sort=<?php echo $sort; ?>" 
                               class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $filter === 'all' ? 'bg-gray-700 text-white shadow-md' : 'text-gray-500 hover:bg-gray-100'; ?>">
                                <i class="fas fa-layer-group mr-1.5 text-xs"></i>Tümü
                                <span class="ml-1 text-xs opacity-75">(<?php echo $stats['total']; ?>)</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="hidden md:block w-px h-8 bg-gray-200"></div>
                    
                    <!-- Sıralama -->
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-7 h-7 rounded-lg bg-gradient-to-br from-cyan-400 to-blue-500">
                            <i class="fas fa-sort text-white text-xs"></i>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="?filter=<?php echo $filter; ?>&sort=smart" 
                               class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $sort === 'smart' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'text-gray-500 hover:bg-cyan-50 hover:text-cyan-600'; ?>">
                                <i class="fas fa-wand-magic-sparkles mr-1.5 text-xs"></i>Akıllı
                            </a>
                            <a href="?filter=<?php echo $filter; ?>&sort=date_desc" 
                               class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $sort === 'date_desc' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'text-gray-500 hover:bg-cyan-50 hover:text-cyan-600'; ?>">
                                <i class="fas fa-arrow-down-wide-short mr-1.5 text-xs"></i>Yeni→Eski
                            </a>
                            <a href="?filter=<?php echo $filter; ?>&sort=date_asc" 
                               class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $sort === 'date_asc' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'text-gray-500 hover:bg-cyan-50 hover:text-cyan-600'; ?>">
                                <i class="fas fa-arrow-up-wide-short mr-1.5 text-xs"></i>Eski→Yeni
                            </a>
                            <a href="?filter=<?php echo $filter; ?>&sort=created_desc" 
                               class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $sort === 'created_desc' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'text-gray-500 hover:bg-cyan-50 hover:text-cyan-600'; ?>">
                                <i class="fas fa-clock-rotate-left mr-1.5 text-xs"></i>Son Eklenen
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Meetings Table -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">
                    <?php 
                    $filterTitles = [
                        'pending' => 'Bekleyen Toplantılar',
                        'approved' => 'Tüm Onaylı Toplantılar',
                        'approved_upcoming' => 'Onaylı Gelecek Toplantılar',
                        'approved_past' => 'Onaylı Geçmiş Toplantılar',
                        'rejected' => 'Reddedilen Toplantılar',
                        'cancelled' => 'İptal Edilen Toplantılar',
                        'today' => 'Bugünkü Toplantılar',
                        'this_week' => 'Bu Haftaki Toplantılar',
                        'this_month' => 'Bu Ayki Toplantılar',
                        'upcoming' => 'Yaklaşan Toplantılar',
                        'all' => 'Tüm Toplantılar'
                    ];
                    echo $filterTitles[$filter] ?? 'Toplantılar';
                    ?>
                </h3>
                
                <?php if ($filter === 'pending' && count($meetings) > 0): ?>
                    <button onclick="selectAllMeetings()" class="text-sm text-blue-600 hover:text-blue-800">
                        Tümünü Seç
                    </button>
                <?php endif; ?>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <?php if ($filter === 'pending'): ?>
                                <th class="px-6 py-3 text-left">
                                    <input type="checkbox" id="selectAll" onchange="toggleAllMeetings()">
                                </th>
                            <?php endif; ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Toplantı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Talep Eden</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tarih & Saat</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Süre</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durum</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($meetings as $meeting): ?>
                            <tr class="hover:bg-gray-50">
                                <?php if ($filter === 'pending'): ?>
                                    <td class="px-6 py-4">
                                        <input type="checkbox" class="meeting-checkbox" value="<?php echo $meeting['id']; ?>">
                                    </td>
                                <?php endif; ?>
                                <td class="px-6 py-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($meeting['title']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($meeting['description']); ?>
                                        </div>
                                        <?php if ($meeting['department_name']): ?>
                                            <div class="text-xs text-gray-400">
                                                <?php echo htmlspecialchars($meeting['department_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($meeting['user_name'] . ' ' . $meeting['user_surname']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($meeting['user_email']); ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php echo formatDateTurkish($meeting['date']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo formatTime($meeting['start_time']) . ' - ' . formatTime($meeting['end_time']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php 
                                    $start = new DateTime($meeting['start_time']);
                                    $end = new DateTime($meeting['end_time']);
                                    $duration = $end->diff($start);
                                    echo $duration->h . ' saat ' . $duration->i . ' dk';
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $statusClasses = [
                                        'pending' => 'bg-orange-100 text-orange-800',
                                        'approved' => 'bg-green-100 text-green-800',
                                        'rejected' => 'bg-red-100 text-red-800',
                                        'cancelled' => 'bg-gray-100 text-gray-800'
                                    ];
                                    $statusTexts = [
                                        'pending' => 'Bekliyor',
                                        'approved' => 'Onaylı',
                                        'rejected' => 'Reddedildi',
                                        'cancelled' => 'İptal'
                                    ];
                                    ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $statusClasses[$meeting['status']]; ?>">
                                        <?php echo $statusTexts[$meeting['status']]; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <?php if ($meeting['status'] === 'pending'): ?>
                                            <button onclick="approveMeetingModal(<?php echo $meeting['id']; ?>)" 
                                                    class="text-green-600 hover:text-green-900" title="Onayla">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button onclick="rejectMeetingModal(<?php echo $meeting['id']; ?>)" 
                                                    class="text-red-600 hover:text-red-900" title="Reddet">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php elseif ($meeting['status'] === 'approved'): ?>
                                            <?php if ($meeting['meeting_link']): ?>
                                                <a href="<?php echo $meeting['meeting_link']; ?>" target="_blank" 
                                                   class="text-blue-600 hover:text-blue-900" title="Toplantıya Katıl">
                                                    <i class="fas fa-video"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button onclick="cancelMeetingModal(<?php echo $meeting['id']; ?>)" 
                                                    class="text-red-600 hover:text-red-900" title="İptal Et">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <a href="../meeting-details.php?id=<?php echo $meeting['id']; ?>" 
                                           class="text-gray-600 hover:text-gray-900" title="Detaylar">
                                            <i class="fas fa-info-circle"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if (empty($meetings)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-calendar-times text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-500">Bu kategoride toplantı bulunmuyor.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="px-6 py-4 border-t border-gray-200">
                    <div class="flex justify-between items-center">
                        <div class="text-sm text-gray-500">
                            Toplam <?php echo $totalCount; ?> toplantı, Sayfa <?php echo $page; ?> / <?php echo $totalPages; ?>
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?filter=<?php echo $filter; ?>&sort=<?php echo $sort; ?>&page=<?php echo $page - 1; ?>" 
                                   class="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
                                    Önceki
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?filter=<?php echo $filter; ?>&sort=<?php echo $sort; ?>&page=<?php echo $page + 1; ?>" 
                                   class="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
                                    Sonraki
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Approve Meeting Modal -->
<div id="approveMeetingModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-[100000]">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Toplantıyı Onayla</h3>
            </div>
            
            <form method="POST" class="p-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="approve_meeting">
                <input type="hidden" name="meeting_id" id="approve_meeting_id">
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Zoom Hesabı <span class="text-red-500">*</span></label>
                    <select name="zoom_account_id" class="form-select" required>
                        <option value="">Zoom hesabı seçin...</option>
                        <?php foreach ($zoomAccounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>">
                                <?php echo htmlspecialchars($account['email']); ?>
                                <?php if ($account['name']): ?>
                                    - <?php echo htmlspecialchars($account['name']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-sm text-gray-500 mt-1">Zoom hesabı seçimi zorunludur. Sistem çakışma kontrolü yapacaktır.</p>
                </div>

                <!-- Toplantıya Özel Zoom Ayarları -->
                <div class="mb-6 border-t border-gray-200 pt-6">
                    <h4 class="text-md font-semibold text-gray-900 mb-4">
                        <i class="fab fa-zoom mr-2 text-blue-600"></i>
                        Toplantıya Özel Zoom Ayarları
                    </h4>
                    <p class="text-sm text-gray-500 mb-4">
                        <strong>Ana ayarlardan otomatik yüklendi.</strong> Bu toplantı için değiştirmek istediğiniz ayarları aşağıdan seçin.
                        <a href="settings.php" class="text-blue-600 hover:text-blue-800 ml-2" target="_blank">
                            <i class="fas fa-cog mr-1"></i>Ana Ayarları Düzenle
                        </a>
                    </p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Katılım Kontrolü -->
                        <div class="space-y-3">
                            <h5 class="text-sm font-medium text-gray-700">🚪 Katılım Kontrolü</h5>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_join_before_host" id="custom_join_before_host" class="form-checkbox" 
                                       <?php echo $zoomDefaults['zoom_join_before_host'] == '1' ? 'checked' : ''; ?>>
                                <label for="custom_join_before_host" class="ml-2 text-sm text-gray-700">
                                    Host'tan önce katılım
                                    <span class="text-xs text-gray-500"><?php echo $zoomDefaults['zoom_join_before_host'] == '1' ? '(varsayılan: aktif)' : '(varsayılan: pasif)'; ?></span>
                                </label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_waiting_room" id="custom_waiting_room" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_waiting_room'] == '1' ? 'checked' : ''; ?>>
                                <label for="custom_waiting_room" class="ml-2 text-sm text-gray-700">
                                    Bekleme Odası
                                    <span class="text-xs text-gray-500"><?php echo $zoomDefaults['zoom_waiting_room'] == '1' ? '(varsayılan: aktif)' : '(varsayılan: pasif)'; ?></span>
                                </label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_meeting_authentication" id="custom_meeting_authentication" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_meeting_authentication'] == '1' ? 'checked' : ''; ?>>
                                <label for="custom_meeting_authentication" class="ml-2 text-sm text-gray-700">
                                    Kimlik Doğrulama
                                    <span class="text-xs text-gray-500"><?php echo $zoomDefaults['zoom_meeting_authentication'] == '1' ? '(varsayılan: aktif)' : '(varsayılan: pasif)'; ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Video & Ses -->
                        <div class="space-y-3">
                            <h5 class="text-sm font-medium text-gray-700">📺 Video & Ses</h5>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_host_video" id="custom_host_video" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_host_video'] == '1' ? 'checked' : ''; ?>>
                                <label for="custom_host_video" class="ml-2 text-sm text-gray-700">
                                    Host Video Açık
                                    <span class="text-xs text-gray-500"><?php echo $zoomDefaults['zoom_host_video'] == '1' ? '(varsayılan: aktif)' : '(varsayılan: pasif)'; ?></span>
                                </label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_participant_video" id="custom_participant_video" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_participant_video'] == '1' ? 'checked' : ''; ?>>
                                <label for="custom_participant_video" class="ml-2 text-sm text-gray-700">
                                    Katılımcı Video Açık
                                    <span class="text-xs text-gray-500"><?php echo $zoomDefaults['zoom_participant_video'] == '1' ? '(varsayılan: aktif)' : '(varsayılan: pasif)'; ?></span>
                                </label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_mute_upon_entry" id="custom_mute_upon_entry" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_mute_upon_entry'] == '1' ? 'checked' : ''; ?>>
                                <label for="custom_mute_upon_entry" class="ml-2 text-sm text-gray-700">
                                    Katılımda Sessiz
                                    <span class="text-xs text-gray-500"><?php echo $zoomDefaults['zoom_mute_upon_entry'] == '1' ? '(varsayılan: aktif)' : '(varsayılan: pasif)'; ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Kayıt Ayarları -->
                        <div class="space-y-3">
                            <h5 class="text-sm font-medium text-gray-700">📹 Kayıt</h5>
                            
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">
                                    Otomatik Kayıt 
                                    <span class="text-xs text-gray-500">(varsayılan: <?php 
                                        $recordingLabels = ['none' => 'Kayıt Yok', 'local' => 'Yerel Kayıt', 'cloud' => 'Cloud Kayıt'];
                                        echo $recordingLabels[$zoomDefaults['zoom_auto_recording']] ?? 'Kayıt Yok';
                                    ?>)</span>
                                </label>
                                <select name="custom_auto_recording" class="form-select text-sm">
                                    <option value="">Sistem ayarını kullan</option>
                                    <option value="none" <?php echo $zoomDefaults['zoom_auto_recording'] == 'none' ? 'selected' : ''; ?>>Kayıt Yok</option>
                                    <option value="local" <?php echo $zoomDefaults['zoom_auto_recording'] == 'local' ? 'selected' : ''; ?>>Yerel Kayıt</option>
                                    <option value="cloud" <?php echo $zoomDefaults['zoom_auto_recording'] == 'cloud' ? 'selected' : ''; ?>>Cloud Kayıt</option>
                                </select>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_cloud_recording" id="custom_cloud_recording" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_cloud_recording'] == '1' ? 'checked' : ''; ?>>
                                <label for="custom_cloud_recording" class="ml-2 text-sm text-gray-700">
                                    Cloud Kayıt İzni
                                    <span class="text-xs text-gray-500"><?php echo $zoomDefaults['zoom_cloud_recording'] == '1' ? '(varsayılan: aktif)' : '(varsayılan: pasif)'; ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Etkileşim -->
                        <div class="space-y-3">
                            <h5 class="text-sm font-medium text-gray-700">⚡ Etkileşim</h5>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_chat" id="custom_chat" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_chat'] == '1' ? 'checked' : ''; ?>>
                                <label for="custom_chat" class="ml-2 text-sm text-gray-700">
                                    Sohbet
                                    <span class="text-xs text-gray-500"><?php echo $zoomDefaults['zoom_chat'] == '1' ? '(varsayılan: aktif)' : '(varsayılan: pasif)'; ?></span>
                                </label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_screen_sharing" id="custom_screen_sharing" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_screen_sharing'] == '1' ? 'checked' : ''; ?>>
                                <label for="custom_screen_sharing" class="ml-2 text-sm text-gray-700">
                                    Ekran Paylaşımı
                                    <span class="text-xs text-gray-500"><?php echo $zoomDefaults['zoom_screen_sharing'] == '1' ? '(varsayılan: aktif)' : '(varsayılan: pasif)'; ?></span>
                                </label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_breakout_rooms" id="custom_breakout_rooms" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_breakout_rooms'] == '1' ? 'checked' : ''; ?>>
                                <label for="custom_breakout_rooms" class="ml-2 text-sm text-gray-700">
                                    Grup Odaları
                                    <span class="text-xs text-gray-500"><?php echo $zoomDefaults['zoom_breakout_rooms'] == '1' ? '(varsayılan: aktif)' : '(varsayılan: pasif)'; ?></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4 space-y-3">
                        <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-md">
                            <div class="flex">
                                <i class="fas fa-info-circle text-yellow-600 mt-0.5 mr-2"></i>
                                <div class="text-sm text-yellow-800">
                                    <strong>Önemli:</strong> Host'tan önce katılım ve Bekleme Odası birlikte çalışmaz. Biri seçildiğinde diğeri otomatik kapanır.
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <button type="button" onclick="resetToDefaults()" class="text-sm text-blue-600 hover:text-blue-800 flex items-center">
                                <i class="fas fa-undo mr-1"></i>
                                Varsayılan Ayarlara Sıfırla
                            </button>
                            <span class="text-xs text-gray-500">
                                <i class="fas fa-lightbulb mr-1"></i>
                                Değişiklikler sadece bu toplantı için geçerlidir
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeApproveMeetingModal()" class="btn-secondary">İptal</button>
                    <button type="submit" class="btn-primary bg-green-600 hover:bg-green-700">Onayla</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Meeting Modal -->
<div id="rejectMeetingModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-[100000]">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Toplantıyı Reddet</h3>
            </div>
            
            <form method="POST" class="p-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="reject_meeting">
                <input type="hidden" name="meeting_id" id="reject_meeting_id">
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Red Sebebi</label>
                    <textarea name="rejection_reason" rows="4" placeholder="Toplantının neden reddedildiğini belirtin..." class="form-input"></textarea>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeRejectMeetingModal()" class="btn-secondary">İptal</button>
                    <button type="submit" class="btn-primary bg-red-600 hover:bg-red-700">Reddet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Approval Modal -->
<div id="bulkApprovalModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-[100000] overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full my-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-tasks mr-2 text-green-600"></i>Toplu Onay Ayarları
                </h3>
                <p class="text-sm text-gray-500 mt-1">Seçili tüm toplantılar aşağıdaki ayarlarla onaylanacak</p>
            </div>
            
            <form method="POST" class="p-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="bulk_approve">
                <div id="selectedMeetingsContainer"></div>
                
                <!-- Seçili Toplantı Sayısı -->
                <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-blue-600 mr-3"></i>
                        <span class="text-blue-800">
                            <strong id="bulkMeetingCount">0</strong> toplantı seçildi
                        </span>
                    </div>
                </div>
                
                <!-- Zoom Hesabı Seçimi -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Zoom Hesabı <span class="text-red-500">*</span>
                    </label>
                    <select name="bulk_zoom_account_id" class="form-select" required>
                        <option value="">Zoom hesabı seçin...</option>
                        <?php foreach ($zoomAccounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>">
                                <?php echo htmlspecialchars($account['email']); ?>
                                <?php if ($account['name']): ?> - <?php echo htmlspecialchars($account['name']); ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-sm text-gray-500 mt-1">Tüm toplantılar bu hesap üzerinden oluşturulacak</p>
                </div>

                <!-- Zoom Ayarları -->
                <div class="mb-6 border-t border-gray-200 pt-6">
                    <h4 class="text-md font-semibold text-gray-900 mb-4">
                        <i class="fab fa-zoom mr-2 text-blue-600"></i>
                        Zoom Ayarları (Tüm Toplantılar İçin)
                    </h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Katılım Kontrolü -->
                        <div class="space-y-3">
                            <h5 class="text-sm font-medium text-gray-700">🚪 Katılım Kontrolü</h5>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_join_before_host" id="bulk_join_before_host" class="form-checkbox" 
                                       <?php echo $zoomDefaults['zoom_join_before_host'] == '1' ? 'checked' : ''; ?>>
                                <label for="bulk_join_before_host" class="ml-2 text-sm text-gray-700">Host'tan önce katılım</label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_waiting_room" id="bulk_waiting_room" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_waiting_room'] == '1' ? 'checked' : ''; ?>>
                                <label for="bulk_waiting_room" class="ml-2 text-sm text-gray-700">Bekleme Odası</label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_mute_upon_entry" id="bulk_mute_upon_entry" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_mute_upon_entry'] == '1' ? 'checked' : ''; ?>>
                                <label for="bulk_mute_upon_entry" class="ml-2 text-sm text-gray-700">Katılımda Sessiz</label>
                            </div>
                        </div>

                        <!-- Video & Kayıt -->
                        <div class="space-y-3">
                            <h5 class="text-sm font-medium text-gray-700">📺 Video & Kayıt</h5>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_host_video" id="bulk_host_video" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_host_video'] == '1' ? 'checked' : ''; ?>>
                                <label for="bulk_host_video" class="ml-2 text-sm text-gray-700">Host Video Açık</label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" name="custom_participant_video" id="bulk_participant_video" class="form-checkbox"
                                       <?php echo $zoomDefaults['zoom_participant_video'] == '1' ? 'checked' : ''; ?>>
                                <label for="bulk_participant_video" class="ml-2 text-sm text-gray-700">Katılımcı Video Açık</label>
                            </div>
                            
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Otomatik Kayıt</label>
                                <select name="custom_auto_recording" class="form-select text-sm">
                                    <option value="none" <?php echo $zoomDefaults['zoom_auto_recording'] == 'none' ? 'selected' : ''; ?>>Kayıt Yok</option>
                                    <option value="local" <?php echo $zoomDefaults['zoom_auto_recording'] == 'local' ? 'selected' : ''; ?>>Yerel Kayıt</option>
                                    <option value="cloud" <?php echo $zoomDefaults['zoom_auto_recording'] == 'cloud' ? 'selected' : ''; ?>>Cloud Kayıt</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                    <button type="button" onclick="closeBulkApprovalModal()" class="btn-secondary">İptal</button>
                    <button type="submit" class="btn-primary bg-green-600 hover:bg-green-700">
                        <i class="fas fa-check-double mr-2"></i>Toplu Onayla
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Meeting Modal -->
<div id="cancelMeetingModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-[100000]">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Toplantıyı İptal Et</h3>
            </div>
            
            <form method="POST" class="p-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="cancel_meeting">
                <input type="hidden" name="meeting_id" id="cancel_meeting_id">
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">İptal Sebebi</label>
                    <textarea name="cancel_reason" rows="4" placeholder="Toplantının neden iptal edildiğini belirtin..." class="form-input"></textarea>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeCancelMeetingModal()" class="btn-secondary">İptal</button>
                    <button type="submit" class="btn-primary bg-red-600 hover:bg-red-700">İptal Et</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Özel Zoom ayarları için çelişki yönetimi
    function handleCustomZoomConflicts() {
        const joinBeforeHost = document.getElementById('custom_join_before_host');
        const waitingRoom = document.getElementById('custom_waiting_room');
        
        if (joinBeforeHost && waitingRoom) {
            // Host'tan önce katılım açıldığında bekleme odası otomatik kapansın
            joinBeforeHost.addEventListener('change', function() {
                if (this.checked) {
                    waitingRoom.checked = false;
                    showCustomSettingNotification('ℹ️ Bekleme Odası otomatik kapatıldı çünkü Host\'tan önce katılım ile çelişiyor.', 'info');
                }
            });
            
            // Bekleme odası açıldığında host'tan önce katılım otomatik kapansın
            waitingRoom.addEventListener('change', function() {
                if (this.checked) {
                    joinBeforeHost.checked = false;
                    showCustomSettingNotification('ℹ️ Host\'tan önce katılım otomatik kapatıldı çünkü Bekleme Odası ile çelişiyor.', 'info');
                }
            });
        }
    }
    
    // Özel ayar bildirimi göster
    function showCustomSettingNotification(message, type = 'info') {
        // Varolan notification div'i bul veya oluştur
        let notificationDiv = document.querySelector('.custom-zoom-notification');
        if (!notificationDiv) {
            notificationDiv = document.createElement('div');
            notificationDiv.className = 'custom-zoom-notification';
            
            // Özel ayarlar bölümüne ekle
            const customSettingsSection = document.querySelector('.mb-6.border-t');
            if (customSettingsSection) {
                customSettingsSection.appendChild(notificationDiv);
            }
        }
        
        // Tip'e göre stil ayarla
        const styles = {
            'info': 'background-color: #dbeafe; color: #1e40af; border: 1px solid #93c5fd;',
            'success': 'background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0;',
            'warning': 'background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d;'
        };
        
        notificationDiv.style.cssText = `margin-top: 10px; padding: 8px 12px; border-radius: 4px; font-size: 13px; ${styles[type] || styles.info}`;
        notificationDiv.innerHTML = message;
        notificationDiv.style.display = 'block';
        
        // 4 saniye sonra kaldır
        setTimeout(() => {
            if (notificationDiv) {
                notificationDiv.style.display = 'none';
            }
        }, 4000);
    }

    // Varsayılan değerleri JavaScript'e aktar
    const defaultZoomSettings = <?php echo json_encode($zoomDefaults); ?>;
    
    function resetToDefaults() {
        // Checkbox'ları varsayılan değerlere ayarla
        document.getElementById('custom_join_before_host').checked = defaultZoomSettings.zoom_join_before_host == '1';
        document.getElementById('custom_waiting_room').checked = defaultZoomSettings.zoom_waiting_room == '1';
        document.getElementById('custom_meeting_authentication').checked = defaultZoomSettings.zoom_meeting_authentication == '1';
        document.getElementById('custom_host_video').checked = defaultZoomSettings.zoom_host_video == '1';
        document.getElementById('custom_participant_video').checked = defaultZoomSettings.zoom_participant_video == '1';
        document.getElementById('custom_mute_upon_entry').checked = defaultZoomSettings.zoom_mute_upon_entry == '1';
        document.getElementById('custom_cloud_recording').checked = defaultZoomSettings.zoom_cloud_recording == '1';
        document.getElementById('custom_chat').checked = defaultZoomSettings.zoom_chat == '1';
        document.getElementById('custom_screen_sharing').checked = defaultZoomSettings.zoom_screen_sharing == '1';
        document.getElementById('custom_breakout_rooms').checked = defaultZoomSettings.zoom_breakout_rooms == '1';
        
        // Select'i varsayılan değere ayarla
        const recordingSelect = document.querySelector('select[name="custom_auto_recording"]');
        if (recordingSelect) {
            recordingSelect.value = defaultZoomSettings.zoom_auto_recording || 'none';
        }
        
        showCustomSettingNotification('✅ Ayarlar ana sistem ayarlarına sıfırlandı!', 'success');
        
        // Çelişki kontrolü yap
        setTimeout(() => {
            handleCustomZoomConflicts();
        }, 100);
    }

    function approveMeetingModal(meetingId) {
        document.getElementById('approve_meeting_id').value = meetingId;
        document.getElementById('approveMeetingModal').classList.remove('hidden');
        
        // Çelişki yönetimini başlat
        setTimeout(() => {
            handleCustomZoomConflicts();
        }, 100);
    }
    
    function closeApproveMeetingModal() {
        document.getElementById('approveMeetingModal').classList.add('hidden');
    }
    
    function rejectMeetingModal(meetingId) {
        document.getElementById('reject_meeting_id').value = meetingId;
        document.getElementById('rejectMeetingModal').classList.remove('hidden');
    }
    
    function closeRejectMeetingModal() {
        document.getElementById('rejectMeetingModal').classList.add('hidden');
    }
    
    function cancelMeetingModal(meetingId) {
        document.getElementById('cancel_meeting_id').value = meetingId;
        document.getElementById('cancelMeetingModal').classList.remove('hidden');
    }
    
    function closeCancelMeetingModal() {
        document.getElementById('cancelMeetingModal').classList.add('hidden');
    }
    
    function openBulkApprovalModal() {
        const checkedBoxes = document.querySelectorAll('.meeting-checkbox:checked');
        if (checkedBoxes.length === 0) {
            alert('Lütfen en az bir toplantı seçin.');
            return;
        }
        
        const container = document.getElementById('selectedMeetingsContainer');
        container.innerHTML = '';
        
        checkedBoxes.forEach(checkbox => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'meeting_ids[]';
            input.value = checkbox.value;
            container.appendChild(input);
        });
        
        // Seçili toplantı sayısını güncelle
        document.getElementById('bulkMeetingCount').textContent = checkedBoxes.length;
        
        document.getElementById('bulkApprovalModal').classList.remove('hidden');
        
        // Bulk modal için çelişki yönetimi
        const bulkJoin = document.getElementById('bulk_join_before_host');
        const bulkWait = document.getElementById('bulk_waiting_room');
        if (bulkJoin && bulkWait) {
            bulkJoin.addEventListener('change', function() {
                if (this.checked) bulkWait.checked = false;
            });
            bulkWait.addEventListener('change', function() {
                if (this.checked) bulkJoin.checked = false;
            });
        }
    }
    
    function closeBulkApprovalModal() {
        document.getElementById('bulkApprovalModal').classList.add('hidden');
    }
    
    function toggleAllMeetings() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.meeting-checkbox');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
    }
    
    function selectAllMeetings() {
        const checkboxes = document.querySelectorAll('.meeting-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
        document.getElementById('selectAll').checked = true;
    }
    
    // Close modals when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target.id === 'approveMeetingModal') {
            closeApproveMeetingModal();
        }
        if (e.target.id === 'rejectMeetingModal') {
            closeRejectMeetingModal();
        }
        if (e.target.id === 'bulkApprovalModal') {
            closeBulkApprovalModal();
        }
        if (e.target.id === 'cancelMeetingModal') {
            closeCancelMeetingModal();
        }
    });
</script>

<?php include '../includes/footer.php'; ?>