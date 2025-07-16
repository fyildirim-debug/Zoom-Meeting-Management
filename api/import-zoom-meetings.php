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
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Bu işlem için admin yetkisi gerekiyor.']);
    exit;
}

$currentUser = getCurrentUser();

// JSON verilerini al
$input = json_decode(file_get_contents('php://input'), true);

// CSRF kontrolü
$csrfToken = $input['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Güvenlik hatası. CSRF token geçersiz.']);
    exit;
}

$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'fetch_meetings':
            echo json_encode(fetchZoomMeetings($input));
            break;
            
        case 'import_meeting':
            echo json_encode(importSingleMeeting($input));
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
            break;
    }
} catch (Exception $e) {
    writeLog("Import Zoom meetings API error: " . $e->getMessage(), 'error');
    writeLog("Stack trace: " . $e->getTraceAsString(), 'error');
    echo json_encode(['success' => false, 'message' => 'Sunucu hatası oluştu: ' . $e->getMessage()]);
} catch (Error $e) {
    writeLog("Import Zoom meetings FATAL error: " . $e->getMessage(), 'error');
    writeLog("Stack trace: " . $e->getTraceAsString(), 'error');
    echo json_encode(['success' => false, 'message' => 'Fatal hata: ' . $e->getMessage()]);
}

/**
 * Zoom hesabından toplantıları çek
 */
function fetchZoomMeetings($input) {
    global $pdo;
    
    $zoomAccountId = (int)($input['zoom_account_id'] ?? 0);
    $targetUserId = (int)($input['target_user_id'] ?? 0);
    
    if (!$zoomAccountId) {
        return ['success' => false, 'message' => 'Zoom hesabı ID gerekli.'];
    }
    
    if (!$targetUserId) {
        return ['success' => false, 'message' => 'Hedef kullanıcı ID gerekli.'];
    }
    
    try {
        // Zoom Account Manager oluştur
        $zoomManager = new ZoomAccountManager($pdo);
        $zoomAPI = $zoomManager->getZoomAPI($zoomAccountId);
        
        // Zoom hesabının email'ini al
        $stmt = $pdo->prepare("SELECT email FROM zoom_accounts WHERE id = ?");
        $stmt->execute([$zoomAccountId]);
        $zoomAccount = $stmt->fetch();
        
        if (!$zoomAccount) {
            return ['success' => false, 'message' => 'Zoom hesabı bulunamadı.'];
        }
        
        // Hedef kullanıcının department bilgilerini al
        $stmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $targetUser = $stmt->fetch();
        
        if (!$targetUser) {
            return ['success' => false, 'message' => 'Hedef kullanıcı bulunamadı.'];
        }
        
        writeLog("Fetching Zoom meetings for account: " . $zoomAccount['email'], 'info');
        
        // Zoom'dan toplantıları çek - UI için sadece ana recurring meeting'leri göster
        $result = $zoomAPI->getAllMeetings($zoomAccount['email'], 300, 1, false);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message']];
        }
        
        $zoomMeetings = $result['data']['meetings'] ?? [];
        
        // Her toplantı için sistemde var mı kontrol et
        $processedMeetings = [];
        foreach ($zoomMeetings as $meeting) {
            $meetingId = $meeting['id'];
            
            // Sistemde var mı kontrol et
            $stmt = $pdo->prepare("SELECT id FROM meetings WHERE zoom_meeting_id = ?");
            $stmt->execute([$meetingId]);
            $existsInSystem = $stmt->fetch() !== false;
            
            $meeting['exists_in_system'] = $existsInSystem;
            $meeting['target_user_id'] = $targetUserId;
            $meeting['target_department_id'] = $targetUser['department_id'];
            
            $processedMeetings[] = $meeting;
        }
        
        writeLog("Fetched " . count($processedMeetings) . " meetings from Zoom", 'info');
        
        return [
            'success' => true,
            'message' => count($processedMeetings) . ' toplantı çekildi.',
            'meetings' => $processedMeetings,
            'total_count' => count($processedMeetings),
            'new_count' => count(array_filter($processedMeetings, function($m) { 
                return !$m['exists_in_system']; 
            }))
        ];
        
    } catch (Exception $e) {
        writeLog("Fetch Zoom meetings error: " . $e->getMessage(), 'error');
        return [
            'success' => false,
            'message' => 'Zoom toplantıları çekilirken hata oluştu: ' . $e->getMessage()
        ];
    }
}

/**
 * Tek toplantıyı sisteme içe aktar
 */
function importSingleMeeting($input) {
    global $pdo;
    
    $meetingData = $input['meeting_data'] ?? [];
    $targetUserId = (int)($input['target_user_id'] ?? 0);
    $zoomAccountId = (int)($input['zoom_account_id'] ?? 0);
    $debugMode = $input['debug_mode'] ?? false;
    
    if (empty($meetingData) || !$targetUserId) {
        return ['success' => false, 'message' => 'Toplantı verisi ve hedef kullanıcı gerekli.'];
    }
    
    // Debug mode aktifse detaylı logging
    if ($debugMode) {
        writeLog("🔍 DEBUG MODE: Import single meeting started", 'info');
        writeLog("🔍 DEBUG MODE: Meeting data: " . json_encode($meetingData), 'info');
        writeLog("🔍 DEBUG MODE: Target user: $targetUserId, Zoom account: $zoomAccountId", 'info');
    }
    
    try {
        // Hedef kullanıcının department bilgilerini al
        $stmt = $pdo->prepare("SELECT department_id, name, surname FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $targetUser = $stmt->fetch();
        
        if (!$targetUser) {
            return ['success' => false, 'message' => 'Hedef kullanıcı bulunamadı.'];
        }
        
        // Recurring meeting kontrolü ve debug
        $isRecurringParent = ($meetingData['is_recurring_parent'] ?? false);
        $meetingType = $meetingData['type'] ?? 2;
        
        writeLog("Meeting import debug - ID: " . $meetingData['id'] .
                 ", is_recurring_parent: " . ($isRecurringParent ? 'true' : 'false') .
                 ", type: " . $meetingType .
                 ", topic: " . ($meetingData['topic'] ?? 'no topic'), 'info');
        
        // Recurring meeting check - type 8 means recurring meeting
        if ($isRecurringParent || $meetingType == 8) {
            writeLog("Routing to recurring meeting import for meeting: " . $meetingData['id'], 'info');
            // Recurring meeting ise tüm occurrence'ları import et
            $result = importRecurringMeeting($meetingData, $targetUserId, $targetUser['department_id'], $zoomAccountId);
        } else {
            writeLog("Routing to single meeting import for meeting: " . $meetingData['id'], 'info');
            // ZoomAPI sınıfından import metodunu kullan
            $zoomManager = new ZoomAccountManager($pdo);
            
            // İlk aktif zoom hesabını al (import için)
            $stmt = $pdo->query("SELECT id FROM zoom_accounts WHERE status = 'active' LIMIT 1");
            $firstZoomAccount = $stmt->fetch();
            
            // Zoom account ID'yi belirle
            $useZoomAccountId = $zoomAccountId > 0 ? $zoomAccountId : ($firstZoomAccount ? $firstZoomAccount['id'] : null);
            
            if ($useZoomAccountId) {
                $zoomAPI = $zoomManager->getZoomAPI($useZoomAccountId);
                $result = $zoomAPI->importMeetingToSystem(
                    $meetingData,
                    $targetUserId,
                    $targetUser['department_id'],
                    $useZoomAccountId
                );
            } else {
                // Zoom API kullanmadan direkt import
                $result = importMeetingDirectly($meetingData, $targetUserId, $targetUser['department_id'], $targetUser, $useZoomAccountId);
            }
        }
        
        if ($result['success']) {
            writeLog("Meeting imported successfully: " . $meetingData['id'] . " -> User: $targetUserId", 'info');
        }
        
        return $result;
        
    } catch (Exception $e) {
        writeLog("Import single meeting error: " . $e->getMessage(), 'error');
        return [
            'success' => false,
            'message' => 'Toplantı içe aktarılırken hata oluştu: ' . $e->getMessage()
        ];
    }
}

/**
 * Recurring meeting'i tüm occurrence'larıyla birlikte import et
 */
function importRecurringMeeting($recurringMeeting, $targetUserId, $targetDepartmentId, $zoomAccountId) {
    global $pdo;
    
    try {
        $meetingId = $recurringMeeting['id'];
        
        writeLog("🔄 RECURRING IMPORT START: Meeting ID $meetingId", 'info');
        writeLog("🔄 RECURRING DATA: " . json_encode($recurringMeeting), 'info');
        writeLog("🔄 RECURRING TARGET: User $targetUserId, Dept $targetDepartmentId, Zoom Account $zoomAccountId", 'info');
        
        // ZoomAPI ile occurrence'ları direkt çek
        $zoomManager = new ZoomAccountManager($pdo);
        $zoomAPI = $zoomManager->getZoomAPI($zoomAccountId);
        
        writeLog("🔄 RECURRING API: ZoomAPI instance created for account $zoomAccountId", 'info');
        
        // Direkt occurrence'ları çek
        writeLog("🔄 RECURRING API CALL: getRecurringMeetingOccurrences($meetingId)", 'info');
        $occurrencesResult = $zoomAPI->getRecurringMeetingOccurrences($meetingId);
        
        writeLog("🔄 RECURRING API RESULT: " . json_encode($occurrencesResult), 'info');
        
        if (!$occurrencesResult['success']) {
            writeLog("Failed to get occurrences for meeting $meetingId: " . $occurrencesResult['message'], 'warning');
            
            // Occurrence'lar alınamazsa ana meeting'i import et
            $targetUser = getUserInfo($targetUserId);
            $fallbackResult = importMeetingDirectly($recurringMeeting, $targetUserId, $targetDepartmentId, $targetUser, $zoomAccountId);
            
            return [
                'success' => $fallbackResult['success'],
                'message' => 'Oturumlar alınamadı, ana toplantı import edildi: ' . $fallbackResult['message'],
                'imported_count' => $fallbackResult['success'] ? 1 : 0,
                'total_occurrences' => 1,
                'fallback' => true
            ];
        }
        
        $occurrences = $occurrencesResult['data'] ?? [];
        
        if (empty($occurrences)) {
            writeLog("No occurrences found for recurring meeting $meetingId", 'warning');
            
            // Occurrence bulunamazsa ana meeting'i import et
            $targetUser = getUserInfo($targetUserId);
            $fallbackResult = importMeetingDirectly($recurringMeeting, $targetUserId, $targetDepartmentId, $targetUser, $zoomAccountId);
            
            return [
                'success' => $fallbackResult['success'],
                'message' => 'Oturum bulunamadı, ana toplantı import edildi: ' . $fallbackResult['message'],
                'imported_count' => $fallbackResult['success'] ? 1 : 0,
                'total_occurrences' => 1,
                'fallback' => true
            ];
        }
        
        writeLog("Found " . count($occurrences) . " occurrences for recurring meeting $meetingId", 'info');
        
        $importedCount = 0;
        $errors = [];
        $targetUser = getUserInfo($targetUserId);
        
        // Her occurrence'ı ayrı toplantı olarak import et
        foreach ($occurrences as $occurrence) {
            // Occurrence verilerini düzenle
            $occurrence['is_recurring_occurrence'] = true;
            $occurrence['parent_meeting_id'] = $meetingId;
            $occurrence['occurrence_id'] = $occurrence['occurrence_id'] ?? uniqid();
            $occurrence['recurrence_type'] = $recurringMeeting['recurrence']['type'] ?? 'weekly';
            
            // Ana meeting'den eksik bilgileri kopyala
            if (empty($occurrence['topic'])) {
                $occurrence['topic'] = $recurringMeeting['topic'] ?? 'Tekrarlı Toplantı';
            }
            if (empty($occurrence['agenda'])) {
                $occurrence['agenda'] = $recurringMeeting['agenda'] ?? '';
            }
            
            $result = importMeetingDirectly($occurrence, $targetUserId, $targetDepartmentId, $targetUser, $zoomAccountId);
            
            if ($result['success']) {
                $importedCount++;
                writeLog("Imported occurrence " . $occurrence['occurrence_id'] . " for meeting $meetingId", 'info');
            } else {
                $errors[] = "Oturum " . ($occurrence['occurrence_id'] ?? 'unknown') . ": " . $result['message'];
                writeLog("Failed to import occurrence for meeting $meetingId: " . $result['message'], 'error');
            }
        }
        
        writeLog("Recurring meeting import completed: $importedCount/" . count($occurrences) . " occurrences imported", 'info');
        
        return [
            'success' => $importedCount > 0,
            'message' => "$importedCount oturum başarıyla import edildi" . (count($errors) > 0 ? ', ' . count($errors) . ' hata oluştu' : ''),
            'imported_count' => $importedCount,
            'total_occurrences' => count($occurrences),
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        writeLog("Recurring meeting import error: " . $e->getMessage(), 'error');
        return [
            'success' => false,
            'message' => 'Recurring meeting import hatası: ' . $e->getMessage()
        ];
    }
}

/**
 * Kullanıcı bilgilerini al (helper fonksiyon)
 */
function getUserInfo($userId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT name, surname FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

/**
 * Zoom API kullanmadan direkt import
 */
function importMeetingDirectly($zoomMeeting, $targetUserId, $targetDepartmentId, $targetUser, $zoomAccountId = null) {
    global $pdo;
    
    try {
        $meetingId = $zoomMeeting['id'];
        $title = $zoomMeeting['topic'] ?? 'Zoom\'dan İçe Aktarılan Toplantı';
        $startTime = new DateTime($zoomMeeting['start_time']);
        $duration = $zoomMeeting['duration'] ?? 60;
        $endTime = clone $startTime;
        $endTime->add(new DateInterval("PT{$duration}M"));
        
        // Bu toplantı sistemde var mı kontrol et
        $stmt = $pdo->prepare("SELECT id FROM meetings WHERE zoom_meeting_id = ?");
        $stmt->execute([$meetingId]);
        if ($stmt->fetch()) {
            return [
                'success' => false,
                'message' => 'Bu toplantı zaten sistemde mevcut',
                'exists' => true
            ];
        }
        
        // Tekrarlı toplantı bilgilerini al
        $isRecurring = $zoomMeeting['is_recurring_occurrence'] ?? false;
        $parentMeetingId = $zoomMeeting['parent_meeting_id'] ?? null;
        $occurrenceId = $zoomMeeting['occurrence_id'] ?? null;
        $recurrenceType = $zoomMeeting['recurrence_type'] ?? null;
        
        // 🔧 Meeting detaylarını Zoom API'den çek (join_url, start_url, password için)
        $enrichedMeetingData = $zoomMeeting;
        $zoomAPI = null;
        
        // ZoomAPI instance'ını hazırla (start URL enhancement için gerekli)
        if ($zoomAccountId) {
            try {
                $zoomManager = new ZoomAccountManager($pdo);
                $zoomAPI = $zoomManager->getZoomAPI($zoomAccountId);
            } catch (Exception $e) {
                writeLog("⚠️ API: Could not create ZoomAPI instance: " . $e->getMessage(), 'warning');
            }
        }
        
        // Zoom dokümantasyonuna göre: Recurring meeting'ler için parent meeting'in URL'lerini kullan
        if ($isRecurring && $parentMeetingId && $zoomAPI) {
            writeLog("🔄 API: Fetching parent recurring meeting details: Parent=$parentMeetingId", 'info');
            
            try {
                // Zoom dokümantasyonuna göre: Tüm occurrence'lar aynı join_url, start_url ve password kullanır
                // Parent meeting'den bu bilgileri al
                $parentDetailsResult = $zoomAPI->getMeeting($parentMeetingId);
                
                if ($parentDetailsResult['success'] && isset($parentDetailsResult['data'])) {
                    $parentData = $parentDetailsResult['data'];
                    
                    // Parent meeting'in URL'lerini kullan - tüm occurrence'lar için geçerli
                    $enrichedMeetingData['join_url'] = $parentData['join_url'] ?? null;
                    $enrichedMeetingData['start_url'] = $parentData['start_url'] ?? null;
                    $enrichedMeetingData['password'] = $parentData['password'] ?? null;
                    $enrichedMeetingData['uuid'] = $parentData['uuid'] ?? $zoomMeeting['uuid'] ?? null;
                    $enrichedMeetingData['host_id'] = $parentData['host_id'] ?? null;
                    
                    // 🚀 START URL AUTHENTICATION BYPASS ENHANCEMENT - RECURRING MEETING
                    if (!empty($enrichedMeetingData['start_url'])) {
                        $originalStartUrl = $enrichedMeetingData['start_url'];
                        $enhancedStartUrl = $zoomAPI->enhanceStartUrlForAutoAuth($originalStartUrl, $parentData);
                        
                        if ($enhancedStartUrl !== $originalStartUrl) {
                            $enrichedMeetingData['start_url'] = $enhancedStartUrl;
                            writeLog("🔐 AUTH BYPASS: Recurring meeting start URL enhanced for auto-auth: Parent=$parentMeetingId", 'info');
                        }
                    }
                    
                    writeLog("✅ API: Recurring parent meeting details used for occurrence - join_url=" .
                           ($enrichedMeetingData['join_url'] ? 'SET' : 'NULL') .
                           ", start_url=" . ($enrichedMeetingData['start_url'] ? 'ENHANCED' : 'NULL') .
                           ", password=" . ($enrichedMeetingData['password'] ? 'SET' : 'NULL'), 'info');
                } else {
                    writeLog("⚠️ API: Could not fetch parent meeting details: " . ($parentDetailsResult['message'] ?? 'Unknown error'), 'warning');
                }
            } catch (Exception $e) {
                writeLog("⚠️ API: Error fetching parent meeting details: " . $e->getMessage(), 'warning');
            }
        } else if ($zoomAPI && (!isset($zoomMeeting['join_url']) || !isset($zoomMeeting['start_url']) || !isset($zoomMeeting['password']))) {
            // Normal meeting için detayları al
            try {
                $detailsResult = $zoomAPI->getMeeting($meetingId);
                
                if ($detailsResult['success'] && isset($detailsResult['data'])) {
                    $detailData = $detailsResult['data'];
                    
                    // Eksik bilgileri detay API'den al
                    $enrichedMeetingData['join_url'] = $detailData['join_url'] ?? $zoomMeeting['join_url'] ?? null;
                    $enrichedMeetingData['start_url'] = $detailData['start_url'] ?? $zoomMeeting['start_url'] ?? null;
                    $enrichedMeetingData['password'] = $detailData['password'] ?? $zoomMeeting['password'] ?? null;
                    $enrichedMeetingData['uuid'] = $detailData['uuid'] ?? $zoomMeeting['uuid'] ?? null;
                    $enrichedMeetingData['host_id'] = $detailData['host_id'] ?? $zoomMeeting['host_id'] ?? null;
                    
                    // 🚀 START URL AUTHENTICATION BYPASS ENHANCEMENT - NORMAL MEETING
                    if (!empty($enrichedMeetingData['start_url'])) {
                        $originalStartUrl = $enrichedMeetingData['start_url'];
                        $enhancedStartUrl = $zoomAPI->enhanceStartUrlForAutoAuth($originalStartUrl, $detailData);
                        
                        if ($enhancedStartUrl !== $originalStartUrl) {
                            $enrichedMeetingData['start_url'] = $enhancedStartUrl;
                            writeLog("🔐 AUTH BYPASS: Normal meeting start URL enhanced for auto-auth: ID=$meetingId", 'info');
                        }
                    }
                    
                    writeLog("📋 API: Meeting details enriched from API: ID=$meetingId, join_url=" . ($enrichedMeetingData['join_url'] ? 'YES' : 'NO') . ", start_url=" . ($enrichedMeetingData['start_url'] ? 'ENHANCED' : 'NO') . ", password=" . ($enrichedMeetingData['password'] ? 'YES' : 'NO'), 'info');
                } else {
                    writeLog("⚠️ API: Could not fetch meeting details from Zoom API: " . ($detailsResult['message'] ?? 'Unknown error'), 'warning');
                }
            } catch (Exception $e) {
                writeLog("⚠️ API: Error fetching meeting details: " . $e->getMessage(), 'warning');
            }
        } else if ($zoomAPI && !empty($enrichedMeetingData['start_url'])) {
            // Zaten mevcut start_url varsa sadece enhance et
            $originalStartUrl = $enrichedMeetingData['start_url'];
            $enhancedStartUrl = $zoomAPI->enhanceStartUrlForAutoAuth($originalStartUrl, $enrichedMeetingData);
            
            if ($enhancedStartUrl !== $originalStartUrl) {
                $enrichedMeetingData['start_url'] = $enhancedStartUrl;
                writeLog("🔐 AUTH BYPASS: Existing start URL enhanced for auto-auth: ID=$meetingId", 'info');
            }
        }
        
        // Toplantı başlığını tekrarlı ise özelleştir
        if ($isRecurring && $parentMeetingId) {
            $title = $title . ' (Oturum: ' . date('d.m.Y H:i', strtotime($zoomMeeting['start_time'])) . ')';
        }
        
        // Toplantıyı sisteme ekle
        $stmt = $pdo->prepare("
            INSERT INTO meetings (
                title, date, start_time, end_time, moderator, description,
                user_id, department_id, status, zoom_account_id, zoom_meeting_id, zoom_uuid,
                zoom_join_url, zoom_start_url, zoom_password, zoom_host_id,
                parent_meeting_id, is_recurring_occurrence, occurrence_id, recurrence_type,
                created_at, approved_at, approved_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?
            )
        ");
        
        $result = $stmt->execute([
            $title,
            $startTime->format('Y-m-d'),
            $startTime->format('H:i:s'),
            $endTime->format('H:i:s'),
            $targetUser['name'] . ' ' . $targetUser['surname'],
            $enrichedMeetingData['agenda'] ?? $zoomMeeting['agenda'] ?? 'Zoom\'dan içe aktarılan toplantı',
            $targetUserId,
            $targetDepartmentId,
            $zoomAccountId, // zoom_account_id
            $meetingId,
            $enrichedMeetingData['uuid'], // 🔧 Enriched data kullan
            $enrichedMeetingData['join_url'], // 🔧 Enriched data kullan
            $enrichedMeetingData['start_url'], // 🔧 Enriched data kullan
            $enrichedMeetingData['password'], // 🔧 Enriched data kullan
            $enrichedMeetingData['host_id'], // 🔧 Enriched data kullan
            $parentMeetingId, // parent_meeting_id
            $isRecurring ? 1 : 0, // is_recurring_occurrence
            $occurrenceId, // occurrence_id
            $recurrenceType, // recurrence_type
            1 // System admin tarafından onaylandı
        ]);
        
        if ($result) {
            $newMeetingId = $pdo->lastInsertId();
            
            writeLog("📅 Meeting imported with enriched data: $meetingId -> DB ID: $newMeetingId" .
                    " | join_url=" . ($enrichedMeetingData['join_url'] ? 'SET' : 'NULL') .
                    " | start_url=" . ($enrichedMeetingData['start_url'] ? 'SET' : 'NULL') .
                    " | password=" . ($enrichedMeetingData['password'] ? 'SET' : 'NULL'), 'info');
            
            // Aktivite kaydet
            logActivity('imported', 'meeting', $newMeetingId,
                "Zoom'dan içe aktarıldı: $title", $targetUserId);
            
            return [
                'success' => true,
                'message' => 'Toplantı başarıyla içe aktarıldı (detay bilgilerle)',
                'meeting_id' => $newMeetingId,
                'enriched' => true,
                'details' => [
                    'join_url' => !empty($enrichedMeetingData['join_url']),
                    'start_url' => !empty($enrichedMeetingData['start_url']),
                    'password' => !empty($enrichedMeetingData['password'])
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Toplantı sisteme eklenirken hata oluştu'
            ];
        }
        
    } catch (Exception $e) {
        writeLog("Direct import error: " . $e->getMessage(), 'error');
        return [
            'success' => false,
            'message' => 'İçe aktarma hatası: ' . $e->getMessage()
        ];
    }
}
?>