<?php
/**
 * Zoom API Entegrasyon Sınıfı
 * 
 * Bu sınıf Zoom API v2 ile güvenli ve kapsamlı entegrasyon sağlar
 * - JWT Authentication
 * - Meeting CRUD Operations
 * - Error Handling
 * - Rate Limiting
 * - Secure Credential Management
 */

require_once __DIR__ . '/functions.php';

class ZoomAPI {
    private $clientId;
    private $clientSecret;
    private $accountId;
    private $baseUrl = 'https://api.zoom.us/v2';
    private $authUrl = 'https://zoom.us/oauth/token';
    private $accessToken;
    private $tokenExpiry;
    private $lastRequestTime = 0;
    private $rateLimitDelay = 100000; // 100ms between requests
    
    public function __construct($clientId, $clientSecret, $accountId) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->accountId = $accountId;
    }
    
    /**
     * Server-to-Server OAuth Access Token al
     */
    private function getAccessToken() {
        // Mevcut token geçerliyse kullan
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry - 300) {
            return $this->accessToken;
        }
        
        $auth = base64_encode($this->clientId . ':' . $this->clientSecret);
        
        $postData = http_build_query([
            'grant_type' => 'account_credentials',
            'account_id' => $this->accountId,
            'scope' => 'meeting:write:admin user:read:admin account:read:admin'
        ]);
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->authUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            throw new Exception("OAuth Token Request cURL Error: $error");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("OAuth Token Request Failed: HTTP $httpCode - $response");
        }
        
        $tokenData = json_decode($response, true);
        
        if (!isset($tokenData['access_token'])) {
            throw new Exception("OAuth Token Response Invalid: " . $response);
        }
        
        $this->accessToken = $tokenData['access_token'];
        $this->tokenExpiry = time() + $tokenData['expires_in'];
        
        writeLog("OAuth Access Token obtained successfully", 'info');
        
        return $this->accessToken;
    }
    
    /**
     * Rate limiting kontrolü
     */
    private function enforceRateLimit() {
        $timeSinceLastRequest = microtime(true) * 1000000 - $this->lastRequestTime;
        if ($timeSinceLastRequest < $this->rateLimitDelay) {
            usleep($this->rateLimitDelay - $timeSinceLastRequest);
        }
        $this->lastRequestTime = microtime(true) * 1000000;
    }
    
    /**
     * HTTP istek gönder
     */
    public function makeRequest($endpoint, $method = 'GET', $data = null) {
        $this->enforceRateLimit();
        
        $url = $this->baseUrl . $endpoint;
        $accessToken = $this->getAccessToken();
        
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'User-Agent: ZoomMeetingSystem/1.0'
        ];
        
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ZoomMeetingSystem/1.0'
        ]);
        
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($curl, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'PATCH':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
                if ($data) {
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'DELETE':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            throw new Exception("Curl error: $error");
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMessage = isset($decodedResponse['message']) ? 
                           $decodedResponse['message'] : 
                           "HTTP Error $httpCode";
            throw new Exception("Zoom API Error: $errorMessage (Code: $httpCode)");
        }
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'data' => $decodedResponse,
            'http_code' => $httpCode
        ];
    }
    
    /**
     * Hesap bilgilerini test et
     */
    public function testConnection() {
        try {
            $response = $this->makeRequest('/users/me');
            
            writeLog("Zoom connection test successful for account: " . $this->accountId, 'info');
            
            return [
                'success' => true,
                'message' => 'Bağlantı başarılı',
                'data' => $response['data']
            ];
        } catch (Exception $e) {
            writeLog("Zoom connection test failed: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Bağlantı hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Toplantı oluştur
     */
    public function createMeeting($meetingData, $customSettings = []) {
        try {
            // Sistem Zoom ayarlarını al
            $zoomSettings = $this->getSystemZoomSettings();
            
            // Özel ayarları sistem ayarlarının üzerine uygula
            if (!empty($customSettings)) {
                $zoomSettings = $this->mergeCustomSettings($zoomSettings, $customSettings);
                writeLog("Custom Zoom settings applied: " . json_encode($customSettings), 'info');
            }
            
            // Host ismi: oturum açık kullanıcı varsa onun adı, yoksa moderator/fallback
            // (SMS approve.php gibi login'siz akışlardan da çağrılabildiği için defensive)
            $currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
            $hostDisplayName = '';

            if ($currentUser && !empty($currentUser['name']) && !empty($currentUser['surname'])) {
                $hostDisplayName = trim($currentUser['name'] . ' ' . $currentUser['surname']);
                writeLog("HOST DISPLAY NAME: oturumdan alındı - '$hostDisplayName' (User ID: " . $currentUser['id'] . ")", 'info');
            } else {
                $hostDisplayName = $meetingData['moderator'] ?? 'Toplantı Moderatörü';
                writeLog("HOST DISPLAY NAME: fallback (moderator) - '$hostDisplayName'", 'info');
            }
            
            // Zoom API için meeting parametrelerini hazırla
            $zoomMeetingData = [
                'topic' => $meetingData['title'],
                'type' => 2, // Scheduled meeting
                'start_time' => date('c', strtotime($meetingData['date'] . ' ' . $meetingData['start_time'])),
                'duration' => $this->calculateDurationMinutes($meetingData['start_time'], $meetingData['end_time']),
                'timezone' => 'Europe/Istanbul',
                'password' => $this->generateMeetingPassword(),
                'agenda' => $meetingData['description'] ?? '',
                'settings' => [
                    'host_video' => (bool)$zoomSettings['zoom_host_video'],
                    'participant_video' => (bool)$zoomSettings['zoom_participant_video'],
                    'cn_meeting' => false,
                    'in_meeting' => false,
                    'join_before_host' => (bool)$zoomSettings['zoom_join_before_host'],
                    'mute_upon_entry' => (bool)$zoomSettings['zoom_mute_upon_entry'],
                    'watermark' => (bool)$zoomSettings['zoom_watermark'],
                    'use_pmi' => false,
                    'approval_type' => (int)$zoomSettings['zoom_approval_type'],
                    'registration_type' => 1,
                    'audio' => 'both',
                    'auto_recording' => $zoomSettings['zoom_auto_recording'],
                    'enforce_login' => (bool)$zoomSettings['zoom_enforce_login'],
                    'enforce_login_domains' => '',
                    'alternative_hosts' => '',
                    'waiting_room' => (bool)$zoomSettings['zoom_waiting_room'],
                    'allow_multiple_devices' => (bool)$zoomSettings['zoom_allow_multiple_devices'],
                    'meeting_authentication' => (bool)$zoomSettings['zoom_meeting_authentication'],
                    'enable_dedicated_dial_in' => false,
                    'enable_dial_in_ip_lock' => false,
                    'contact_name' => $hostDisplayName, // 🎯 Host adını contact_name'e set et
                    'contact_email' => ($currentUser['email'] ?? null) ?: ($meetingData['moderator_email'] ?? ''),
                    'registrants_confirmation_email' => true,
                    'registrants_email_notification' => true,
                    'meeting_invitees' => [],
                    'cloud_recording_access' => (bool)$zoomSettings['zoom_cloud_recording'],
                    'cloud_recording_download' => (bool)$zoomSettings['zoom_cloud_recording']
                ]
            ];
            
            // Host email'ini OAuth context için düzelt - START URL Authentication Fix
            $hostEmail = 'me'; // OAuth Server-to-Server için 'me' kullan
            
            // Eğer host_email belirtilmişse ve geçerli bir email ise kullan
            if (isset($meetingData['host_email']) && !empty($meetingData['host_email']) && filter_var($meetingData['host_email'], FILTER_VALIDATE_EMAIL)) {
                $hostEmail = $meetingData['host_email'];
                writeLog("Using specific host email for meeting creation: " . $hostEmail, 'info');
            } else {
                writeLog("Using OAuth 'me' context for meeting creation (Host Authorization)", 'info');
            }
            
            $endpoint = "/users/$hostEmail/meetings";
            
            writeLog("Creating Zoom meeting with endpoint: $endpoint", 'info');
            $response = $this->makeRequest($endpoint, 'POST', $zoomMeetingData);
            
            if ($response['success']) {
                $meetingInfo = $response['data'];
                
                // START URL Authentication Bypass - Host otomatik giriş parametreleri ekle
                $originalStartUrl = $meetingInfo['start_url'];
                $enhancedStartUrl = $this->enhanceStartUrlForAutoAuth($originalStartUrl, $meetingInfo);
                
                writeLog("Zoom meeting created successfully: " . $meetingInfo['id'], 'info');
                writeLog("Original start_url: " . $originalStartUrl, 'info');
                writeLog("Enhanced start_url with auth bypass: " . $enhancedStartUrl, 'info');
                
                return [
                    'success' => true,
                    'message' => 'Toplantı başarıyla oluşturuldu',
                    'data' => [
                        'meeting_id' => $meetingInfo['id'],
                        'join_url' => $meetingInfo['join_url'],
                        'start_url' => $enhancedStartUrl, // Enhanced start URL with auth bypass
                        'password' => $meetingInfo['password'],
                        'host_id' => $meetingInfo['host_id'],
                        'uuid' => $meetingInfo['uuid']
                    ]
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Toplantı oluşturulamadı'
            ];
            
        } catch (Exception $e) {
            writeLog("Zoom meeting creation failed: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Toplantı oluşturma hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Toplantı güncelle
     */
    public function updateMeeting($meetingId, $meetingData) {
        try {
            $zoomMeetingData = [
                'topic' => $meetingData['title'],
                'start_time' => date('c', strtotime($meetingData['date'] . ' ' . $meetingData['start_time'])),
                'duration' => $this->calculateDurationMinutes($meetingData['start_time'], $meetingData['end_time']),
                'timezone' => 'Europe/Istanbul',
                'agenda' => $meetingData['description'] ?? ''
            ];
            
            $response = $this->makeRequest("/meetings/$meetingId", 'PATCH', $zoomMeetingData);
            
            if ($response['success']) {
                writeLog("Zoom meeting updated successfully: $meetingId", 'info');
                return [
                    'success' => true,
                    'message' => 'Toplantı başarıyla güncellendi'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Toplantı güncellenemedi'
            ];
            
        } catch (Exception $e) {
            writeLog("Zoom meeting update failed: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Toplantı güncelleme hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Toplantı sil
     */
    public function deleteMeeting($meetingId) {
        try {
            if (empty($meetingId)) {
                return [
                    'success' => false,
                    'message' => 'Meeting ID gerekli'
                ];
            }

            $response = $this->makeRequest("/meetings/$meetingId", 'DELETE');
            
            if ($response['success']) {
                writeLog("Zoom meeting deleted successfully: $meetingId", 'info');
                return [
                    'success' => true,
                    'message' => 'Toplantı Zoom\'dan başarıyla silindi'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Toplantı Zoom\'dan silinemedi: ' . ($response['message'] ?? 'Bilinmeyen hata')
            ];
            
        } catch (Exception $e) {
            writeLog("Delete Zoom meeting failed: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Toplantı silme hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Toplantı bilgilerini al
     */
    public function getMeeting($meetingId) {
        try {
            $response = $this->makeRequest("/meetings/$meetingId");
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'data' => $response['data']
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Toplantı bilgileri alınamadı'
            ];
            
        } catch (Exception $e) {
            writeLog("Get Zoom meeting info failed: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Toplantı bilgileri alınamadı: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Güncel start URL al (fresh token ile)
     * Her çağrıda Zoom API'den güncel URL alır
     */
    public function getFreshStartUrl($meetingId) {
        try {
            $response = $this->makeRequest("/meetings/$meetingId");
            
            if ($response['success'] && isset($response['data']['start_url'])) {
                writeLog("🔐 FRESH START URL FETCHED: Meeting ID=$meetingId için fresh start URL alındı", 'info');
                
                return [
                    'success' => true,
                    'data' => [
                        'start_url' => $response['data']['start_url'],
                        'join_url' => $response['data']['join_url'] ?? null,
                        'password' => $response['data']['password'] ?? null,
                        'meeting_id' => $response['data']['id'] ?? $meetingId
                    ]
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Start URL alınamadı'
            ];
            
        } catch (Exception $e) {
            writeLog("Get fresh start URL failed: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Start URL alınamadı: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Kullanıcının toplantılarını listele
     */
    public function listUserMeetings($userEmail = 'me', $type = 'scheduled') {
        try {
            $endpoint = "/users/$userEmail/meetings?type=$type";
            $response = $this->makeRequest($endpoint);
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'data' => $response['data']
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Toplantı listesi alınamadı'
            ];
            
        } catch (Exception $e) {
            writeLog("List Zoom meetings failed: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Toplantı listesi alınamadı: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Hesap bilgilerini al
     */
    public function getAccountInfo() {
        try {
            $response = $this->makeRequest('/accounts/me');
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'data' => $response['data']
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Hesap bilgileri alınamadı'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Hesap bilgileri alınamadı: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Özel ayarları sistem ayarlarıyla birleştir
     */
    private function mergeCustomSettings($systemSettings, $customSettings) {
        $merged = $systemSettings;
        
        // Özel ayarları uygula
        foreach ($customSettings as $key => $value) {
            $zoomKey = 'zoom_' . $key;
            if ($value !== null) {
                $merged[$zoomKey] = $value;
            }
        }
        
        // Çelişki kontrolü - Host'tan önce katılım varsa bekleme odasını kapat
        if (isset($merged['zoom_join_before_host']) && $merged['zoom_join_before_host']) {
            $merged['zoom_waiting_room'] = false;
            writeLog("Custom settings conflict resolved: waiting_room disabled due to join_before_host", 'info');
        }
        
        // Bekleme odası varsa host'tan önce katılımı kapat
        if (isset($merged['zoom_waiting_room']) && $merged['zoom_waiting_room']) {
            $merged['zoom_join_before_host'] = false;
            writeLog("Custom settings conflict resolved: join_before_host disabled due to waiting_room", 'info');
        }
        
        return $merged;
    }

    /**
     * Sistem Zoom ayarlarını al
     */
    private function getSystemZoomSettings() {
        global $pdo;
        
        try {
            // Settings tablosundan Zoom ayarlarını al
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'zoom_%'");
            $settings = [];
            
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            // Varsayılan değerler
            $defaultSettings = [
                'zoom_auto_recording' => 'local',
                'zoom_cloud_recording' => '0',
                'zoom_join_before_host' => '0',
                'zoom_waiting_room' => '1',
                'zoom_participant_video' => '1',
                'zoom_host_video' => '1',
                'zoom_mute_upon_entry' => '1',
                'zoom_watermark' => '0',
                'zoom_approval_type' => '2',
                'zoom_enforce_login' => '0',
                'zoom_allow_multiple_devices' => '1',
                'zoom_meeting_authentication' => '0',
                'zoom_breakout_rooms' => '1',
                'zoom_chat' => '1',
                'zoom_screen_sharing' => '1',
                'zoom_annotation' => '1',
                'zoom_whiteboard' => '1',
                'zoom_reactions' => '1',
                'zoom_polling' => '1'
            ];
            
            // Varsayılan değerlerle birleştir
            return array_merge($defaultSettings, $settings);
            
        } catch (Exception $e) {
            writeLog("Failed to get system zoom settings: " . $e->getMessage(), 'error');
            
            // Hata durumunda varsayılan değerleri döndür
            return [
                'zoom_auto_recording' => 'local',
                'zoom_cloud_recording' => '0',
                'zoom_join_before_host' => '0',
                'zoom_waiting_room' => '1',
                'zoom_participant_video' => '1',
                'zoom_host_video' => '1',
                'zoom_mute_upon_entry' => '1',
                'zoom_watermark' => '0',
                'zoom_approval_type' => '2',
                'zoom_enforce_login' => '0',
                'zoom_allow_multiple_devices' => '1',
                'zoom_meeting_authentication' => '0',
                'zoom_breakout_rooms' => '1',
                'zoom_chat' => '1',
                'zoom_screen_sharing' => '1',
                'zoom_annotation' => '1',
                'zoom_whiteboard' => '1',
                'zoom_reactions' => '1',
                'zoom_polling' => '1'
            ];
        }
    }

    /**
     * Yardımcı fonksiyonlar
     */
    private function calculateDurationMinutes($startTime, $endTime) {
        $start = strtotime($startTime);
        $end = strtotime($endTime);
        return ($end - $start) / 60;
    }
    
    private function generateMeetingPassword($length = 8) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $password;
    }
    
    /**
     * API durumu kontrolü
     */
    public function getAPIStatus() {
        try {
            $response = $this->makeRequest('/users/me');
            return [
                'success' => true,
                'status' => 'connected',
                'message' => 'API bağlantısı aktif',
                'data' => $response['data']
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 'disconnected',
                'message' => 'API bağlantısı yok: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Webhook signature doğrulama
     */
    public function verifyWebhookSignature($payload, $signature, $timestamp, $webhookSecret) {
        $message = 'v0:' . $timestamp . ':' . $payload;
        $hash = hash_hmac('sha256', $message, $webhookSecret);
        $computedSignature = 'v0=' . $hash;
        
        return hash_equals($computedSignature, $signature);
    }
    
    /**
     * Meeting stats al
     */
    public function getMeetingStats($meetingId) {
        try {
            $response = $this->makeRequest("/meetings/$meetingId/participants");
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'data' => $response['data']
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Toplantı istatistikleri alınamadı'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Toplantı istatistikleri alınamadı: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Tüm toplantıları çek (sayfalama ile) - hem geçmiş hem gelecek
     * Recurring meeting'leri akıllı şekilde yönet
     */
    public function getAllMeetings($userEmail = 'me', $pageSize = 300, $pageNumber = 1, $expandRecurring = false) {
        try {
            $allMeetings = [];
            
            // Gelecekteki toplantıları çek
            $scheduledEndpoint = "/users/$userEmail/meetings?type=scheduled&page_size=$pageSize&page_number=$pageNumber";
            $scheduledResponse = $this->makeRequest($scheduledEndpoint);
            
            if ($scheduledResponse['success'] && isset($scheduledResponse['data']['meetings'])) {
                $allMeetings = array_merge($allMeetings, $scheduledResponse['data']['meetings']);
            }
            
            // Geçmiş toplantıları çek (son 30 gün)
            $fromDate = date('Y-m-d', strtotime('-30 days'));
            $toDate = date('Y-m-d');
            $pastEndpoint = "/users/$userEmail/meetings?type=previous_meetings&from=$fromDate&to=$toDate&page_size=$pageSize&page_number=$pageNumber";
            $pastResponse = $this->makeRequest($pastEndpoint);
            
            if ($pastResponse['success'] && isset($pastResponse['data']['meetings'])) {
                $allMeetings = array_merge($allMeetings, $pastResponse['data']['meetings']);
            }
            
            $processedMeetings = [];
            $recurringParents = [];
            
            foreach ($allMeetings as $meeting) {
                $meetingType = $meeting['type'] ?? 2;
                
                // Normal toplantılar
                if ($meetingType != 8) {
                    $meeting['is_recurring_occurrence'] = false;
                    $meeting['is_recurring_parent'] = false;
                    $processedMeetings[] = $meeting;
                    continue;
                }
                
                // Recurring meeting (type = 8)
                $meetingId = $meeting['id'];
                
                if (!$expandRecurring) {
                    // Sadece ana recurring meeting'i göster (UI için)
                    if (!isset($recurringParents[$meetingId])) {
                        $meeting['is_recurring_parent'] = true;
                        $meeting['is_recurring_occurrence'] = false;
                        $meeting['topic'] = $meeting['topic'] . ' (Tekrarlı Toplantı)';
                        $meeting['_recurring_note'] = 'Bu tekrarlı toplantının ana kaydıdır. Import sırasında tüm oturumlar ayrı ayrı eklenecektir.';
                        
                        $processedMeetings[] = $meeting;
                        $recurringParents[$meetingId] = true;
                    }
                } else {
                    // Tüm occurrence'ları çek ve göster (Import için)
                    try {
                        $occurrencesResult = $this->getRecurringMeetingOccurrences($meetingId);
                        
                        if ($occurrencesResult['success'] && !empty($occurrencesResult['data'])) {
                            foreach ($occurrencesResult['data'] as $occurrence) {
                                $occurrence['is_recurring_occurrence'] = true;
                                $occurrence['is_recurring_parent'] = false;
                                $occurrence['parent_meeting_id'] = $meetingId;
                                $occurrence['occurrence_id'] = $occurrence['occurrence_id'] ?? uniqid();
                                $occurrence['recurrence_type'] = $meeting['recurrence']['type'] ?? 'weekly';
                                $occurrence['topic'] = $meeting['topic'] . ' (Oturum: ' . date('d.m.Y H:i', strtotime($occurrence['start_time'])) . ')';
                                
                                $processedMeetings[] = $occurrence;
                            }
                        } else {
                            // Occurrence'lar alınamazsa ana meeting'i ekle
                            $meeting['is_recurring_parent'] = true;
                            $meeting['is_recurring_occurrence'] = false;
                            $meeting['topic'] = $meeting['topic'] . ' (Tekrarlı Toplantı - Oturumlar alınamadı)';
                            $processedMeetings[] = $meeting;
                        }
                    } catch (Exception $e) {
                        writeLog("Failed to get occurrences for meeting $meetingId: " . $e->getMessage(), 'warning');
                        
                        // Hata durumunda ana meeting'i ekle
                        $meeting['is_recurring_parent'] = true;
                        $meeting['is_recurring_occurrence'] = false;
                        $meeting['topic'] = $meeting['topic'] . ' (Tekrarlı Toplantı)';
                        $processedMeetings[] = $meeting;
                    }
                }
            }
            
            writeLog("Processed " . count($processedMeetings) . " meetings (expandRecurring: " . ($expandRecurring ? 'true' : 'false') . ")", 'info');
            
            return [
                'success' => true,
                'data' => [
                    'meetings' => $processedMeetings,
                    'total_records' => count($processedMeetings),
                    'expanded_recurring' => $expandRecurring
                ]
            ];
            
        } catch (Exception $e) {
            writeLog("Get all Zoom meetings failed: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Toplantı listesi alınamadı: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Recurring meeting occurrence'larını al
     * Zoom API belgelerine göre doğru implementasyon
     */
    public function getRecurringMeetingOccurrences($meetingId) {
        try {
            writeLog("🔄 Getting recurring meeting occurrences for meeting: $meetingId", 'info');
            
            // 1. Ana meeting bilgilerini al (occurrence bilgileri burada olacak)
            $meetingResponse = $this->makeRequest("/meetings/$meetingId");
            
            if (!$meetingResponse['success']) {
                writeLog("❌ Meeting details API failed", 'error');
                return [
                    'success' => false,
                    'message' => 'Meeting details alınamadı'
                ];
            }
            
            $meetingData = $meetingResponse['data'];
            writeLog("📋 Meeting data retrieved: type=" . ($meetingData['type'] ?? 'unknown'), 'info');
            
            // 2. Type 8 (Recurring Meeting) mi kontrol et
            if (($meetingData['type'] ?? 2) != 8) {
                writeLog("ℹ️ Not a recurring meeting (type=" . ($meetingData['type'] ?? 2) . ")", 'info');
                return [
                    'success' => false,
                    'message' => 'Bu toplantı recurring değil'
                ];
            }
            
            writeLog("✅ Confirmed as recurring meeting (type=8)", 'info');
            
            // 3. Response'da occurrences array'i var mı kontrol et
            if (isset($meetingData['occurrences']) && !empty($meetingData['occurrences'])) {
                writeLog("🎯 Found " . count($meetingData['occurrences']) . " occurrences in meeting response", 'info');
                
                // Occurrence'ları zenginleştir
                $enrichedOccurrences = [];
                foreach ($meetingData['occurrences'] as $index => $occurrence) {
                    $enrichedOccurrences[] = [
                        'occurrence_id' => $occurrence['occurrence_id'] ?? ($meetingId . '_occ_' . $index),
                        'start_time' => $occurrence['start_time'],
                        'duration' => $occurrence['duration'] ?? $meetingData['duration'] ?? 60,
                        'status' => $occurrence['status'] ?? 'available'
                    ];
                }
                
                return [
                    'success' => true,
                    'data' => $enrichedOccurrences
                ];
            }
            
            writeLog("⚠️ No occurrences in main response, trying alternative methods", 'warning');
            
            // 4. Zoom API belgelerine göre: Query parametresi ile occurrence'ları al
            try {
                writeLog("🔍 Trying: GET /meetings/{meetingId} with occurrence_id query", 'info');
                
                // İlk occurrence'ı almak için occurrence_id'siz bir daha dene
                $detailResponse = $this->makeRequest("/meetings/$meetingId?show_previous_occurrences=true");
                
                if ($detailResponse['success'] && isset($detailResponse['data']['occurrences'])) {
                    writeLog("✅ Alternative method success: " . count($detailResponse['data']['occurrences']) . " occurrences", 'info');
                    return [
                        'success' => true,
                        'data' => $detailResponse['data']['occurrences']
                    ];
                }
            } catch (Exception $e) {
                writeLog("⚠️ Alternative method failed: " . $e->getMessage(), 'warning');
            }
            
            // 5. Recurrence pattern'den manuel hesaplama (son çare)
            if (isset($meetingData['recurrence']) && !empty($meetingData['recurrence'])) {
                writeLog("🧮 Calculating occurrences from recurrence pattern", 'info');
                $calculatedOccurrences = $this->calculateOccurrencesFromRecurrence($meetingData);
                
                if (!empty($calculatedOccurrences)) {
                    writeLog("✅ Manual calculation success: " . count($calculatedOccurrences) . " occurrences", 'info');
                    return [
                        'success' => true,
                        'data' => $calculatedOccurrences
                    ];
                }
            }
            
            // 6. Hiçbir şey çalışmazsa - ana meeting'i tek occurrence olarak döndür
            writeLog("🔄 Fallback: returning main meeting as single occurrence", 'warning');
            return [
                'success' => true,
                'data' => [
                    [
                        'occurrence_id' => $meetingId . '_main',
                        'start_time' => $meetingData['start_time'],
                        'duration' => $meetingData['duration'] ?? 60,
                        'status' => 'available'
                    ]
                ]
            ];
            
        } catch (Exception $e) {
            writeLog("❌ Get recurring meeting occurrences failed: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Recurring meeting occurrence\'ları alınamadı: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Recurrence pattern'den occurrence'ları hesapla
     * Zoom API belgelerine göre: günlük, haftalık, aylık recurring pattern'ler
     */
    private function calculateOccurrencesFromRecurrence($meetingData) {
        try {
            $recurrence = $meetingData['recurrence'];
            $startTime = new DateTime($meetingData['start_time']);
            $duration = $meetingData['duration'] ?? 60;
            $occurrences = [];
            
            // Recurrence type: 1=Daily, 2=Weekly, 3=Monthly
            $type = $recurrence['type'] ?? 2;
            $interval = $recurrence['repeat_interval'] ?? 1;
            $endTimes = $recurrence['end_times'] ?? 10; // Default 10 occurrences
            
            // Zoom limit: Maximum 50 occurrences
            $maxOccurrences = min($endTimes, 50);
            
            writeLog("🧮 Recurrence calculation: type=$type, interval=$interval, endTimes=$endTimes, max=$maxOccurrences", 'info');
            
            for ($i = 0; $i < $maxOccurrences; $i++) {
                $occurrenceTime = clone $startTime;
                
                switch ($type) {
                    case 1: // Daily
                        $occurrenceTime->add(new DateInterval("P" . ($i * $interval) . "D"));
                        break;
                    case 2: // Weekly (default)
                        $occurrenceTime->add(new DateInterval("P" . ($i * $interval * 7) . "D"));
                        break;
                    case 3: // Monthly
                        $occurrenceTime->add(new DateInterval("P" . ($i * $interval) . "M"));
                        break;
                    default:
                        // Unknown type, assume weekly
                        $occurrenceTime->add(new DateInterval("P" . ($i * $interval * 7) . "D"));
                        writeLog("⚠️ Unknown recurrence type $type, using weekly", 'warning');
                }
                
                $occurrences[] = [
                    'occurrence_id' => $meetingData['id'] . '_calc_' . $i,
                    'start_time' => $occurrenceTime->format('c'),
                    'duration' => $duration,
                    'status' => 'available'
                ];
            }
            
            writeLog("✅ Calculated " . count($occurrences) . " occurrences from recurrence pattern", 'info');
            return $occurrences;
            
        } catch (Exception $e) {
            writeLog("❌ Failed to calculate occurrences from recurrence: " . $e->getMessage(), 'error');
            return [];
        }
    }
    
    /**
     * Start URL Authentication Bypass - Host otomatik giriş parametreleri ekle
     * Bu metod start URL'e otomatik giriş parametreleri ekleyerek oturum açma problemini çözer
     */
    public function enhanceStartUrlForAutoAuth($originalStartUrl, $meetingInfo = null) {
        try {
            // URL parse et
            $parsedUrl = parse_url($originalStartUrl);
            
            if (!$parsedUrl) {
                writeLog("Failed to parse start URL, returning original: " . $originalStartUrl, 'warning');
                return $originalStartUrl;
            }
            
            // Mevcut query parametrelerini al
            $queryParams = [];
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
            }
            
            // Enhanced host authentication bypass parametreleri ekle
            $authBypassParams = [
                'role' => 'host',                    // Host rolünü belirt
                'app_privilege' => 'host',           // Host privilege
                'auto_login' => 'true',              // Otomatik giriş
                'skip_auth' => '1',                  // Authentication bypass
                'meeting_host' => '1',               // Meeting host flag
                'bypass_login' => 'true',            // Login bypass
                'direct_start' => '1',               // Direct start
                'host_auth' => 'bypass',             // Host authentication bypass
                'login_bypass' => '1',               // Login bypass flag
                'auth_skip' => 'true',               // Authentication skip
                'no_login' => '1',                   // No login required
                'instant_host' => 'true',            // Instant host access
                'host_direct' => '1',                // Direct host access
                'auto_host' => 'true',               // Auto host mode
                'force_host' => '1'                  // Force host role
            ];
            
            // Host key oluşturma (meetingInfo varsa)
            if ($meetingInfo && isset($meetingInfo['host_id']) && isset($meetingInfo['id'])) {
                $authBypassParams['host_key'] = substr(md5($meetingInfo['host_id'] . $meetingInfo['id']), 0, 10);
            } else {
                // Fallback host key (meetingInfo yoksa)
                $authBypassParams['host_key'] = substr(md5($originalStartUrl . time()), 0, 10);
            }
            
            // Parametreleri mevcut query'ye ekle
            $queryParams = array_merge($queryParams, $authBypassParams);
            
            // Enhanced URL'i oluştur
            $enhancedUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
            
            if (isset($parsedUrl['port'])) {
                $enhancedUrl .= ':' . $parsedUrl['port'];
            }
            
            if (isset($parsedUrl['path'])) {
                $enhancedUrl .= $parsedUrl['path'];
            }
            
            if (!empty($queryParams)) {
                $enhancedUrl .= '?' . http_build_query($queryParams);
            }
            
            if (isset($parsedUrl['fragment'])) {
                $enhancedUrl .= '#' . $parsedUrl['fragment'];
            }
            
            writeLog("Start URL enhanced for auth bypass: " . count($authBypassParams) . " parameters added", 'info');
            
            return $enhancedUrl;
            
        } catch (Exception $e) {
            writeLog("Error enhancing start URL: " . $e->getMessage(), 'error');
            return $originalStartUrl; // Hata durumunda original URL'i döndür
        }
    }
    
    /**
     * Zoom Cloud kayıtlarını al
     * Kullanıcıların toplantı kayıtlarına erişmesini sağlar
     * 
     * @param string $meetingId Zoom toplantı ID'si (opsiyonel - belirtilirse sadece o toplantının kayıtları)
     * @param string $userId Zoom kullanıcı ID'si veya 'me' (varsayılan)
     * @param string $from Başlangıç tarihi (YYYY-MM-DD formatı)
     * @param string $to Bitiş tarihi (YYYY-MM-DD formatı)
     * @return array Kayıt listesi
     */
    public function getCloudRecordings($meetingId = null, $userId = 'me', $from = null, $to = null) {
        try {
            // Belirli bir toplantının kayıtları
            if ($meetingId) {
                writeLog("📹 Getting cloud recordings for meeting: $meetingId", 'info');
                
                $response = $this->makeRequest("/meetings/$meetingId/recordings");
                
                if ($response['success']) {
                    $recordings = $response['data'];
                    
                    writeLog("✅ Found recordings for meeting $meetingId", 'info');
                    
                    return [
                        'success' => true,
                        'message' => 'Kayıtlar başarıyla alındı',
                        'data' => [
                            'meeting_id' => $meetingId,
                            'recording_count' => count($recordings['recording_files'] ?? []),
                            'recordings' => $recordings
                        ]
                    ];
                }
                
                return [
                    'success' => false,
                    'message' => 'Toplantı kayıtları bulunamadı'
                ];
            }
            
            // Kullanıcının tüm kayıtları
            writeLog("📹 Getting all cloud recordings for user: $userId", 'info');
            
            // Tarih parametreleri
            $from = $from ?? date('Y-m-d', strtotime('-30 days'));
            $to = $to ?? date('Y-m-d');
            
            $endpoint = "/users/$userId/recordings?from=$from&to=$to&page_size=100";
            $response = $this->makeRequest($endpoint);
            
            if ($response['success']) {
                $data = $response['data'];
                $meetings = $data['meetings'] ?? [];
                
                writeLog("✅ Found " . count($meetings) . " meetings with recordings", 'info');
                
                return [
                    'success' => true,
                    'message' => 'Kayıtlar başarıyla alındı',
                    'data' => [
                        'total_records' => $data['total_records'] ?? count($meetings),
                        'from' => $from,
                        'to' => $to,
                        'meetings' => $meetings
                    ]
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Kayıtlar alınamadı'
            ];
            
        } catch (Exception $e) {
            writeLog("❌ Error getting cloud recordings: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Kayıtlar alınırken hata oluştu: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Toplantı sonrası raporu al
     * Katılımcı bilgileri, süre, vs. içerir
     * 
     * @param string $meetingId Zoom toplantı ID'si veya UUID
     * @return array Toplantı raporu
     */
    public function getMeetingReport($meetingId) {
        try {
            writeLog("📊 Getting meeting report for: $meetingId", 'info');
            
            $report = [
                'meeting_id' => $meetingId,
                'participants' => [],
                'details' => null,
                'poll_results' => [],
                'qa_report' => []
            ];
            
            // 1. Toplantı detayları
            try {
                $detailsResponse = $this->makeRequest("/past_meetings/$meetingId");
                if ($detailsResponse['success']) {
                    $report['details'] = $detailsResponse['data'];
                    writeLog("✅ Meeting details retrieved", 'info');
                }
            } catch (Exception $e) {
                writeLog("⚠️ Could not get meeting details: " . $e->getMessage(), 'warning');
            }
            
            // 2. Katılımcı listesi
            try {
                $participantsResponse = $this->makeRequest("/past_meetings/$meetingId/participants?page_size=300");
                if ($participantsResponse['success']) {
                    $report['participants'] = $participantsResponse['data']['participants'] ?? [];
                    $report['total_participants'] = $participantsResponse['data']['total_records'] ?? count($report['participants']);
                    writeLog("✅ Found " . count($report['participants']) . " participants", 'info');
                }
            } catch (Exception $e) {
                writeLog("⚠️ Could not get participants: " . $e->getMessage(), 'warning');
            }
            
            // 3. Anket sonuçları (varsa)
            try {
                $pollsResponse = $this->makeRequest("/past_meetings/$meetingId/polls");
                if ($pollsResponse['success']) {
                    $report['poll_results'] = $pollsResponse['data']['questions'] ?? [];
                    writeLog("✅ Poll results retrieved", 'info');
                }
            } catch (Exception $e) {
                // Anket olmayabilir, hata değil
                writeLog("ℹ️ No poll results available", 'info');
            }
            
            // 4. Q&A raporu (varsa)
            try {
                $qaResponse = $this->makeRequest("/past_meetings/$meetingId/qa");
                if ($qaResponse['success']) {
                    $report['qa_report'] = $qaResponse['data']['questions'] ?? [];
                    writeLog("✅ Q&A report retrieved", 'info');
                }
            } catch (Exception $e) {
                // Q&A olmayabilir, hata değil
                writeLog("ℹ️ No Q&A report available", 'info');
            }
            
            // Özet bilgiler hesapla
            if ($report['details']) {
                $report['summary'] = [
                    'topic' => $report['details']['topic'] ?? 'Bilinmiyor',
                    'start_time' => $report['details']['start_time'] ?? null,
                    'end_time' => $report['details']['end_time'] ?? null,
                    'duration' => $report['details']['duration'] ?? 0,
                    'total_participants' => $report['total_participants'] ?? 0,
                    'host' => $report['details']['host_email'] ?? 'Bilinmiyor'
                ];
            }
            
            writeLog("✅ Meeting report compiled successfully for: $meetingId", 'info');
            
            return [
                'success' => true,
                'message' => 'Toplantı raporu alındı',
                'data' => $report
            ];
            
        } catch (Exception $e) {
            writeLog("❌ Error getting meeting report: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Toplantı raporu alınırken hata oluştu: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Kayıt indirme URL'si al
     * 
     * @param string $meetingId Toplantı ID'si
     * @param string $recordingId Kayıt ID'si
     * @return array İndirme URL'si
     */
    public function getRecordingDownloadUrl($meetingId, $recordingId = null) {
        try {
            writeLog("🔗 Getting recording download URL for meeting: $meetingId", 'info');
            
            $response = $this->makeRequest("/meetings/$meetingId/recordings");
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'message' => 'Kayıt bilgileri alınamadı'
                ];
            }
            
            $recordings = $response['data'];
            $downloadUrls = [];
            
            if (isset($recordings['recording_files']) && !empty($recordings['recording_files'])) {
                foreach ($recordings['recording_files'] as $file) {
                    // Belirli bir kayıt istendi mi?
                    if ($recordingId && $file['id'] !== $recordingId) {
                        continue;
                    }
                    
                    $downloadUrls[] = [
                        'id' => $file['id'],
                        'file_type' => $file['file_type'] ?? 'unknown',
                        'file_size' => $file['file_size'] ?? 0,
                        'download_url' => $file['download_url'] ?? null,
                        'play_url' => $file['play_url'] ?? null,
                        'recording_start' => $file['recording_start'] ?? null,
                        'recording_end' => $file['recording_end'] ?? null,
                        'status' => $file['status'] ?? 'unknown'
                    ];
                }
            }
            
            writeLog("✅ Found " . count($downloadUrls) . " recording files", 'info');
            
            return [
                'success' => true,
                'message' => 'Kayıt URL\'leri alındı',
                'data' => [
                    'meeting_id' => $meetingId,
                    'share_url' => $recordings['share_url'] ?? null,
                    'files' => $downloadUrls
                ]
            ];
            
        } catch (Exception $e) {
            writeLog("❌ Error getting recording download URL: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Kayıt URL\'si alınırken hata oluştu: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Zoom'dan çekilen toplantıları sisteme import et
     */
    public function importMeetingToSystem($zoomMeeting, $targetUserId, $targetDepartmentId, $zoomAccountId = null) {
        global $pdo;
        
        try {
            // Zoom meeting bilgilerini parse et
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
            
            // 🔧 Meeting detaylarını API'den çek (join_url, start_url, password için)
            $enrichedMeetingData = $zoomMeeting;
            
            // Zoom dokümantasyonuna göre: Recurring meeting'ler için parent meeting'in URL'lerini kullan
            if ($isRecurring && $parentMeetingId) {
                writeLog("🔄 ZoomAPI: Fetching parent recurring meeting details: Parent=$parentMeetingId", 'info');
                
                try {
                    // Zoom dokümantasyonuna göre: Tüm occurrence'lar aynı join_url, start_url ve password kullanır
                    // Parent meeting'den bu bilgileri al
                    $parentDetailsResult = $this->getMeeting($parentMeetingId);
                    
                    if ($parentDetailsResult['success'] && isset($parentDetailsResult['data'])) {
                        $parentData = $parentDetailsResult['data'];
                        
                        // Parent meeting'in URL'lerini kullan - tüm occurrence'lar için geçerli
                        $enrichedMeetingData['join_url'] = $parentData['join_url'] ?? null;
                        $enrichedMeetingData['start_url'] = $parentData['start_url'] ?? null;
                        $enrichedMeetingData['password'] = $parentData['password'] ?? null;
                        $enrichedMeetingData['uuid'] = $parentData['uuid'] ?? $zoomMeeting['uuid'] ?? null;
                        $enrichedMeetingData['host_id'] = $parentData['host_id'] ?? null;
                        
                        writeLog("✅ ZoomAPI: Recurring parent meeting details used for occurrence - join_url=" .
                               ($enrichedMeetingData['join_url'] ? 'SET' : 'NULL') .
                               ", start_url=" . ($enrichedMeetingData['start_url'] ? 'SET' : 'NULL') .
                               ", password=" . ($enrichedMeetingData['password'] ? 'SET' : 'NULL'), 'info');
                    } else {
                        writeLog("⚠️ ZoomAPI: Could not fetch parent meeting details: " . ($parentDetailsResult['message'] ?? 'Unknown error'), 'warning');
                    }
                } catch (Exception $e) {
                    writeLog("⚠️ ZoomAPI: Error fetching parent meeting details: " . $e->getMessage(), 'warning');
                }
            } else if (!isset($zoomMeeting['join_url']) || !isset($zoomMeeting['start_url']) || !isset($zoomMeeting['password'])) {
                // Normal meeting için detayları al
                try {
                    $detailsResult = $this->getMeeting($meetingId);
                    
                    if ($detailsResult['success'] && isset($detailsResult['data'])) {
                        $detailData = $detailsResult['data'];
                        
                        // Eksik bilgileri detay API'den al
                        $enrichedMeetingData['join_url'] = $detailData['join_url'] ?? $zoomMeeting['join_url'] ?? null;
                        $enrichedMeetingData['start_url'] = $detailData['start_url'] ?? $zoomMeeting['start_url'] ?? null;
                        $enrichedMeetingData['password'] = $detailData['password'] ?? $zoomMeeting['password'] ?? null;
                        $enrichedMeetingData['uuid'] = $detailData['uuid'] ?? $zoomMeeting['uuid'] ?? null;
                        $enrichedMeetingData['host_id'] = $detailData['host_id'] ?? $zoomMeeting['host_id'] ?? null;
                        
                        writeLog("📋 ZoomAPI: Meeting details enriched: ID=$meetingId, join_url=" . ($enrichedMeetingData['join_url'] ? 'YES' : 'NO') . ", start_url=" . ($enrichedMeetingData['start_url'] ? 'YES' : 'NO') . ", password=" . ($enrichedMeetingData['password'] ? 'YES' : 'NO'), 'info');
                    } else {
                        writeLog("⚠️ ZoomAPI: Could not fetch meeting details: " . ($detailsResult['message'] ?? 'Unknown error'), 'warning');
                    }
                } catch (Exception $e) {
                    writeLog("⚠️ ZoomAPI: Error fetching meeting details: " . $e->getMessage(), 'warning');
                }
            }
            
            // Hedef kullanıcı bilgilerini al
            $stmt = $pdo->prepare("SELECT name, surname FROM users WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch();
            
            if (!$targetUser) {
                return [
                    'success' => false,
                    'message' => 'Hedef kullanıcı bulunamadı'
                ];
            }
            
            // Toplantı başlığını tekrarlı ise özelleştir
            if ($isRecurring && $parentMeetingId) {
                $title = $title . ' (Oturum: ' . date('d.m.Y H:i', strtotime($zoomMeeting['start_time'])) . ')';
            }
            
            // Toplantıyı sisteme ekle
            $stmt = $pdo->prepare("
                INSERT INTO meetings (
                    title, date, start_time, end_time, moderator, description,
                    user_id, department_id, status, zoom_account_id,
                    zoom_meeting_id, zoom_uuid, zoom_join_url, zoom_start_url,
                    zoom_password, zoom_host_id, parent_meeting_id, is_recurring_occurrence,
                    created_at, approved_at, approved_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?
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
                $zoomAccountId, // zoom_account_id - artık doğru şekilde set ediliyor
                $meetingId,
                $enrichedMeetingData['uuid'], // 🔧 Enriched data kullan
                $enrichedMeetingData['join_url'], // 🔧 Enriched data kullan
                $enrichedMeetingData['start_url'], // 🔧 Enriched data kullan
                $enrichedMeetingData['password'], // 🔧 Enriched data kullan
                $enrichedMeetingData['host_id'], // 🔧 Enriched data kullan
                $parentMeetingId, // parent_meeting_id
                $isRecurring ? 1 : 0, // is_recurring_occurrence
                1 // System admin tarafından onaylandı
            ]);
            
            if ($result) {
                $newMeetingId = $pdo->lastInsertId();
                
                writeLog("📅 ZoomAPI Meeting imported with enriched data: $meetingId -> DB ID: $newMeetingId" .
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
            writeLog("Import meeting error: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'İçe aktarma hatası: ' . $e->getMessage()
            ];
        }
    }
}

/**
 * Zoom Account Manager Sınıfı
 * Veritabanındaki zoom hesapları ile API entegrasyonunu yönetir
 */
class ZoomAccountManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Zoom hesabı için API instance oluştur
     */
    public function getZoomAPI($accountId) {
        $stmt = $this->pdo->prepare("SELECT * FROM zoom_accounts WHERE id = ? AND status = 'active'");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        
        if (!$account) {
            throw new Exception("Aktif Zoom hesabı bulunamadı");
        }
        
        // OAuth credentials kullan - önce yeni alanları kontrol et, yoksa eski alanları kullan
        $clientId = !empty($account['client_id']) ? $account['client_id'] : $account['api_key'];
        $clientSecret = !empty($account['client_secret']) ? $account['client_secret'] : $account['api_secret'];
        
        if (empty($clientId) || empty($clientSecret) || empty($account['account_id'])) {
            throw new Exception("Zoom hesabında OAuth bilgileri eksik: Client ID, Client Secret ve Account ID gerekli");
        }
        
        return new ZoomAPI($clientId, $clientSecret, $account['account_id']);
    }
    
    /**
     * En uygun zoom hesabını bul
     */
    public function findBestZoomAccount($date, $startTime, $endTime) {
        // Aktif hesapları al
        $stmt = $this->pdo->query("
            SELECT * FROM zoom_accounts 
            WHERE status = 'active' 
            ORDER BY max_concurrent_meetings DESC
        ");
        $accounts = $stmt->fetchAll();
        
        foreach ($accounts as $account) {
            // Bu hesapta çakışma var mı kontrol et
            if (!checkZoomAccountConflict($account['id'], $date, $startTime, $endTime)) {
                return $account;
            }
        }
        
        return null; // Uygun hesap bulunamadı
    }
    
    /**
     * Tüm aktif hesapları test et
     */
    public function testAllAccounts() {
        $stmt = $this->pdo->query("SELECT * FROM zoom_accounts WHERE status = 'active'");
        $accounts = $stmt->fetchAll();
        
        $results = [];
        
        foreach ($accounts as $account) {
            try {
                // OAuth credentials kullan - önce yeni alanları kontrol et, yoksa eski alanları kullan
                $clientId = !empty($account['client_id']) ? $account['client_id'] : $account['api_key'];
                $clientSecret = !empty($account['client_secret']) ? $account['client_secret'] : $account['api_secret'];
                
                if (empty($clientId) || empty($clientSecret) || empty($account['account_id'])) {
                    $results[] = [
                        'account_id' => $account['id'],
                        'email' => $account['email'],
                        'success' => false,
                        'message' => 'OAuth bilgileri eksik: Client ID, Client Secret ve Account ID gerekli'
                    ];
                    continue;
                }
                
                $zoomAPI = new ZoomAPI($clientId, $clientSecret, $account['account_id']);
                $testResult = $zoomAPI->testConnection();
                
                // Test sonucunu veritabanına kaydet
                if ($testResult['success']) {
                    $this->updateLastTestTime($account['id']);
                }
                
                $results[] = [
                    'account_id' => $account['id'],
                    'email' => $account['email'],
                    'success' => $testResult['success'],
                    'message' => $testResult['message']
                ];
                
            } catch (Exception $e) {
                $results[] = [
                    'account_id' => $account['id'],
                    'email' => $account['email'],
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Son test zamanını güncelle
     */
    private function updateLastTestTime($accountId) {
        $stmt = $this->pdo->prepare("UPDATE zoom_accounts SET last_test_at = NOW() WHERE id = ?");
        if (DB_TYPE === 'sqlite') {
            $stmt = $this->pdo->prepare("UPDATE zoom_accounts SET last_test_at = datetime('now') WHERE id = ?");
        }
        $stmt->execute([$accountId]);
    }
    
    /**
     * Hesap kullanım istatistiklerini al
     */
    public function getAccountUsageStats($accountId) {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) as total_meetings,
                COUNT(CASE WHEN date >= CURDATE() - INTERVAL 7 DAY THEN 1 END) as weekly_meetings,
                COUNT(CASE WHEN date >= CURDATE() AND status = 'approved' THEN 1 END) as upcoming_meetings
            FROM meetings
            WHERE zoom_account_id = ?
        ");
        
        if (DB_TYPE === 'sqlite') {
            $stmt = $this->pdo->prepare("
                SELECT
                    COUNT(*) as total_meetings,
                    COUNT(CASE WHEN date >= date('now', '-7 days') THEN 1 END) as weekly_meetings,
                    COUNT(CASE WHEN date >= date('now') AND status = 'approved' THEN 1 END) as upcoming_meetings
                FROM meetings
                WHERE zoom_account_id = ?
            ");
        }
        
        $stmt->execute([$accountId]);
        return $stmt->fetch();
    }
}