<?php
/**
 * Yardımcı Fonksiyonlar
 */

// XSS koruması
function cleanInput($data) {
    if (is_array($data)) {
        return array_map('cleanInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Alternatif sanitize fonksiyonu (register.php ile uyumluluk için)
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
    }
}

// CSRF token oluşturma
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Sadece CSRF token değerini al
function getCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// CSRF token doğrulama
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// JSON response gönderme
function sendJsonResponse($success, $message = '', $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

// Tarih formatları
function formatDate($date, $format = 'd.m.Y') {
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = 'd.m.Y H:i') {
    return date($format, strtotime($datetime));
}

function formatTime($time, $format = 'H:i') {
    return date($format, strtotime($time));
}

// Türkçe tarih formatı
function formatDateTurkish($date) {
    $months = [
        1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
        5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
        9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
    ];
    
    $days = [
        'Monday' => 'Pazartesi', 'Tuesday' => 'Salı', 'Wednesday' => 'Çarşamba',
        'Thursday' => 'Perşembe', 'Friday' => 'Cuma', 'Saturday' => 'Cumartesi', 'Sunday' => 'Pazar'
    ];
    
    $timestamp = strtotime($date);
    $day = date('j', $timestamp);
    $month = $months[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp);
    $dayName = $days[date('l', $timestamp)];
    
    return "$day $month $year, $dayName";
}

// Türkçe tarih-saat formatı
function formatDateTimeTurkish($datetime) {
    $months = [
        1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
        5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
        9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
    ];
    
    $timestamp = strtotime($datetime);
    $day = date('j', $timestamp);
    $month = $months[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp);
    $time = date('H:i', $timestamp);
    
    return "$day $month $year, $time";
}

// E-posta doğrulama
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Tarih doğrulama
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Saat doğrulama
function validateTime($time, $format = 'H:i') {
    // Hem H:i hem de H:i:s formatlarını kabul et
    $formats = ['H:i', 'H:i:s'];
    
    foreach ($formats as $fmt) {
        $t = DateTime::createFromFormat($fmt, $time);
        if ($t && $t->format($fmt) === $time) {
            return true;
        }
    }
    
    return false;
}

// Şifre güvenlik kontrolü
function isValidPassword($password) {
    return strlen($password) >= 6;
}

// Dosya boyutu formatı
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $factor = floor((strlen($size) - 1) / 3);
    return sprintf("%.2f", $size / pow(1024, $factor)) . ' ' . $units[$factor];
}

// URL güvenlik kontrolü
function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

// IP adresi alma
function getUserIP() {
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Log yazma
function writeLog($message, $type = 'info', $file = 'app.log') {
    $logDir = __DIR__ . '/../logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/' . $file;
    $timestamp = date('Y-m-d H:i:s');
    $ip = getUserIP();
    $user = $_SESSION['user_id'] ?? 'Guest';
    
    $logEntry = "[$timestamp] [$type] [User:$user] [IP:$ip] $message" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Rastgele string oluşturma
function generateRandomString($length = 10) {
    return substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)))), 1, $length);
}

// Slug oluşturma
function createSlug($text) {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

// Sayfalama hesaplama
function calculatePagination($totalItems, $itemsPerPage, $currentPage = 1) {
    $totalPages = ceil($totalItems / $itemsPerPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $itemsPerPage;
    
    return [
        'total_items' => $totalItems,
        'items_per_page' => $itemsPerPage,
        'total_pages' => $totalPages,
        'current_page' => $currentPage,
        'offset' => $offset,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages,
        'prev_page' => $currentPage > 1 ? $currentPage - 1 : null,
        'next_page' => $currentPage < $totalPages ? $currentPage + 1 : null
    ];
}

// Çalışma saatleri kontrolü
function isWorkingHours($time = null) {
    if ($time === null) {
        $time = date('H:i');
    }
    
    $workStart = (defined('WORK_START') && WORK_START) ? WORK_START : '09:00';
    $workEnd = (defined('WORK_END') && WORK_END) ? WORK_END : '18:00';
    
    // Saatleri timestamp'e çevir
    $timeTimestamp = strtotime("2000-01-01 $time");
    $startTimestamp = strtotime("2000-01-01 $workStart");
    $endTimestamp = strtotime("2000-01-01 $workEnd");
    
    return $timeTimestamp >= $startTimestamp && $timeTimestamp <= $endTimestamp;
}

// Hafta sonu kontrolü
function isWeekend($date = null) {
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    $dayOfWeek = date('N', strtotime($date));
    return $dayOfWeek >= 6; // Cumartesi (6) ve Pazar (7)
}

// İş günü kontrolü
function isWorkingDay($date = null) {
    return !isWeekend($date);
}

// Toplantı süresi hesaplama
function calculateMeetingDuration($startTime, $endTime) {
    $start = strtotime($startTime);
    $end = strtotime($endTime);
    $duration = $end - $start;
    
    $hours = floor($duration / 3600);
    $minutes = floor(($duration % 3600) / 60);
    
    if ($hours > 0) {
        return $hours . ' saat ' . $minutes . ' dakika';
    } else {
        return $minutes . ' dakika';
    }
}

// Çakışma kontrolü
function checkTimeConflict($date, $startTime, $endTime, $excludeMeetingId = null) {
    global $pdo;
    
    $sql = "SELECT COUNT(*) FROM meetings 
            WHERE date = ? 
            AND status IN ('pending', 'approved')
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )";
    
    $params = [$date, $startTime, $startTime, $endTime, $endTime, $startTime, $endTime];
    
    if ($excludeMeetingId) {
        $sql .= " AND id != ?";
        $params[] = $excludeMeetingId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchColumn() > 0;
}

// Kullanıcı çakışma kontrolü
function checkUserConflict($userId, $date, $startTime, $endTime, $excludeMeetingId = null) {
    global $pdo;
    
    $sql = "SELECT COUNT(*) FROM meetings 
            WHERE user_id = ? 
            AND date = ? 
            AND status IN ('pending', 'approved')
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )";
    
    $params = [$userId, $date, $startTime, $startTime, $endTime, $endTime, $startTime, $endTime];
    
    if ($excludeMeetingId) {
        $sql .= " AND id != ?";
        $params[] = $excludeMeetingId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchColumn() > 0;
}

// Birim haftalık limit kontrolü
function checkDepartmentWeeklyLimit($departmentId, $date, $excludeMeetingId = null) {
    global $pdo;
    
    // Haftanın başlangıcı ve bitişi
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    $weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
    
    // Birim limitini al
    $stmt = $pdo->prepare("SELECT weekly_limit FROM departments WHERE id = ?");
    $stmt->execute([$departmentId]);
    $department = $stmt->fetch();
    
    if (!$department) {
        return false;
    }
    
    // Bu hafta onaylanmış toplantı sayısı
    $sql = "SELECT COUNT(*) FROM meetings
            WHERE department_id = ?
            AND date BETWEEN ? AND ?
            AND status = 'approved'";
    
    $params = [$departmentId, $weekStart, $weekEnd];
    
    if ($excludeMeetingId) {
        $sql .= " AND id != ?";
        $params[] = $excludeMeetingId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $currentCount = $stmt->fetchColumn();
    
    return $currentCount < $department['weekly_limit'];
}

// Zoom hesabı çakışma kontrolü
function checkZoomAccountConflict($zoomAccountId, $date, $startTime, $endTime, $excludeMeetingId = null) {
    global $pdo;
    
    $sql = "SELECT COUNT(*) FROM meetings 
            WHERE zoom_account_id = ? 
            AND date = ? 
            AND status = 'approved'
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )";
    
    $params = [$zoomAccountId, $date, $startTime, $startTime, $endTime, $endTime, $startTime, $endTime];
    
    if ($excludeMeetingId) {
        $sql .= " AND id != ?";
        $params[] = $excludeMeetingId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchColumn() > 0;
}

// Ayar değeri alma
function getSetting($key, $default = null) {
    global $pdo;
    
    static $settings = [];
    
    if (empty($settings)) {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    return $settings[$key] ?? $default;
}

// Ayar değeri güncelleme
function updateSetting($key, $value) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    
    // SQLite için farklı syntax
    if (DB_TYPE === 'sqlite') {
        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO settings (setting_key, setting_value) 
            VALUES (?, ?)
        ");
    }
    
    return $stmt->execute([$key, $value]);
}

// Bildirim gönderme
function sendNotification($userId, $title, $message, $type = 'info') {
    global $pdo;
    
    // Basit bildirim sistemi - veritabanına kaydet
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    
    // SQLite için
    if (DB_TYPE === 'sqlite') {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, created_at) 
            VALUES (?, ?, ?, ?, datetime('now'))
        ");
    }
    
    return $stmt->execute([$userId, $title, $message, $type]);
}

// E-posta gönderme (basit)
function sendEmail($to, $subject, $message, $from = null) {
    if (!$from) {
        $from = getSetting('site_email', 'noreply@' . $_SERVER['HTTP_HOST']);
    }
    
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion()
    ];
    
    return mail($to, $subject, $message, implode("\r\n", $headers));
}

// Redirect helper
function redirect($url, $statusCode = 302) {
    header("Location: $url", true, $statusCode);
    exit();
}

// Template include helper
function includeTemplate($template, $vars = []) {
    extract($vars);
    include __DIR__ . "/../templates/$template.php";
}

// Asset URL helper
function asset($path) {
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    
    if ($basePath === '/') {
        $basePath = '';
    }
    
    return $baseUrl . $basePath . '/' . ltrim($path, '/');
}

// Debug helper
function dd($var) {
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
    exit();
}

// Memory usage helper
function getMemoryUsage() {
    return formatFileSize(memory_get_usage(true));
}

// Execution time helper
function getExecutionTime($start = null) {
    if ($start === null) {
        return microtime(true);
    }
    
    return round((microtime(true) - $start) * 1000, 2) . ' ms';
}

/**
 * Aktivite kayıt sistemi
 */
function logActivity($action, $entityType, $entityId = null, $details = null, $userId = null) {
    global $pdo;
    
    try {
        // Eğer userId belirtilmemişse mevcut kullanıcıyı al
        if ($userId === null) {
            $currentUser = getCurrentUser();
            $userId = $currentUser ? $currentUser['id'] : null;
        }
        
        // Kullanıcı ID'si yoksa kayıt yapma
        if (!$userId) {
            return false;
        }
        
        // IP adresi ve user agent bilgilerini al
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Aktiviteyi veritabanına kaydet
        if (DB_TYPE === 'mysql') {
            $stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
        }
        
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $details,
            $ipAddress,
            $userAgent
        ]);
        
        return true;
        
    } catch (Exception $e) {
        writeLog("Activity log error: " . $e->getMessage(), 'error');
        return false;
    }
}

/**
 * Son aktiviteleri getir
 */
function getRecentActivities($limit = 20, $userId = null) {
    global $pdo;
    
    try {
        $sql = "
            SELECT
                al.*,
                u.name,
                u.surname,
                CASE
                    WHEN al.entity_type = 'meeting' THEN m.title
                    WHEN al.entity_type = 'user' THEN CONCAT(u2.name, ' ', u2.surname)
                    WHEN al.entity_type = 'department' THEN d.name
                    ELSE NULL
                END as entity_name
            FROM activity_logs al
            JOIN users u ON al.user_id = u.id
            LEFT JOIN meetings m ON al.entity_type = 'meeting' AND al.entity_id = m.id
            LEFT JOIN users u2 ON al.entity_type = 'user' AND al.entity_id = u2.id
            LEFT JOIN departments d ON al.entity_type = 'department' AND al.entity_id = d.id
        ";
        
        if ($userId) {
            $sql .= " WHERE al.user_id = ?";
        }
        
        $sql .= " ORDER BY al.created_at DESC LIMIT ?";
        
        $stmt = $pdo->prepare($sql);
        
        if ($userId) {
            $stmt->execute([$userId, $limit]);
        } else {
            $stmt->execute([$limit]);
        }
        
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        writeLog("Get activities error: " . $e->getMessage(), 'error');
        return [];
    }
}

/**
 * Aktivite açıklamasını Türkçe'ye çevir
 */
function getActivityDescription($action, $entityType, $entityName = null, $details = null) {
    $descriptions = [
        'meeting' => [
            'created' => 'toplantı talebi oluşturdu',
            'updated' => 'toplantı bilgilerini güncelledi',
            'approved' => 'toplantıyı onayladı',
            'rejected' => 'toplantıyı reddetti',
            'cancelled' => 'toplantıyı iptal etti',
            'deleted' => 'toplantıyı sildi'
        ],
        'user' => [
            'created' => 'yeni kullanıcı ekledi',
            'updated' => 'kullanıcı bilgilerini güncelledi',
            'deleted' => 'kullanıcıyı sildi',
            'login' => 'sisteme giriş yaptı',
            'logout' => 'sistemden çıkış yaptı'
        ],
        'department' => [
            'created' => 'yeni birim ekledi',
            'updated' => 'birim bilgilerini güncelledi',
            'deleted' => 'birimi sildi'
        ],
        'zoom_account' => [
            'created' => 'yeni Zoom hesabı ekledi',
            'updated' => 'Zoom hesabı bilgilerini güncelledi',
            'deleted' => 'Zoom hesabını sildi'
        ]
    ];
    
    $description = $descriptions[$entityType][$action] ?? "$action işlemini gerçekleştirdi";
    
    if ($entityName) {
        $description = "\"$entityName\" $description";
    }
    
    return $description;
}

/**
 * Aktivite ikonunu al
 */
function getActivityIcon($action, $entityType) {
    $icons = [
        'meeting' => [
            'created' => 'fa-plus text-blue-600',
            'updated' => 'fa-edit text-yellow-600',
            'approved' => 'fa-check text-green-600',
            'rejected' => 'fa-times text-red-600',
            'cancelled' => 'fa-ban text-gray-600',
            'deleted' => 'fa-trash text-red-600'
        ],
        'user' => [
            'created' => 'fa-user-plus text-green-600',
            'updated' => 'fa-user-edit text-blue-600',
            'deleted' => 'fa-user-minus text-red-600',
            'login' => 'fa-sign-in-alt text-green-600',
            'logout' => 'fa-sign-out-alt text-gray-600'
        ],
        'department' => [
            'created' => 'fa-building text-green-600',
            'updated' => 'fa-edit text-blue-600',
            'deleted' => 'fa-trash text-red-600'
        ],
        'zoom_account' => [
            'created' => 'fa-video text-green-600',
            'updated' => 'fa-edit text-blue-600',
            'deleted' => 'fa-trash text-red-600'
        ]
    ];
    
    return $icons[$entityType][$action] ?? 'fa-info text-gray-600';
}

/**
 * Sistem Kapatma Kontrol Fonksiyonları
 */

/**
 * Belirli bir tarihin kapalı olup olmadığını kontrol et
 * @param string $date Kontrol edilecek tarih (Y-m-d formatında)
 * @return array|false Kapalıysa kapatma bilgisi, değilse false
 */
function isDateClosed($date) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM system_closures 
            WHERE is_active = 1 
            AND ? BETWEEN start_date AND end_date
            ORDER BY start_date ASC
            LIMIT 1
        ");
        $stmt->execute([$date]);
        $closure = $stmt->fetch();
        
        return $closure ?: false;
    } catch (Exception $e) {
        // Tablo yoksa veya hata varsa false döndür
        return false;
    }
}

/**
 * Tarih aralığının kapalı günlerle çakışıp çakışmadığını kontrol et
 * @param string $startDate Başlangıç tarihi
 * @param string $endDate Bitiş tarihi (opsiyonel, tek gün için boş bırakılabilir)
 * @return array|false Çakışma varsa kapatma bilgisi, yoksa false
 */
function getClosureForDateRange($startDate, $endDate = null) {
    global $pdo;
    
    if ($endDate === null) {
        $endDate = $startDate;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM system_closures 
            WHERE is_active = 1 
            AND (
                (start_date <= ? AND end_date >= ?) OR
                (start_date <= ? AND end_date >= ?) OR
                (start_date >= ? AND end_date <= ?)
            )
            ORDER BY start_date ASC
            LIMIT 1
        ");
        $stmt->execute([$startDate, $startDate, $endDate, $endDate, $startDate, $endDate]);
        $closure = $stmt->fetch();
        
        return $closure ?: false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Aktif kapatma dönemlerini al
 * @return array Aktif kapatma listesi
 */
function getActiveClosures() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT * FROM system_closures 
            WHERE is_active = 1 
            AND end_date >= CURDATE()
            ORDER BY start_date ASC
        ");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Toplantı tarihi için kapatma kontrolü yap
 * Hata mesajı ile birlikte döndürür
 * @param string $date Toplantı tarihi
 * @return array ['allowed' => bool, 'message' => string, 'closure' => array|null]
 */
function checkMeetingDateAllowed($date) {
    $closure = isDateClosed($date);
    
    if ($closure) {
        return [
            'allowed' => false,
            'message' => sprintf(
                'Bu tarihte toplantı oluşturulamaz. %s (%s - %s)',
                $closure['title'],
                formatDateTurkish($closure['start_date']),
                formatDateTurkish($closure['end_date'])
            ),
            'closure' => $closure
        ];
    }
    
    return [
        'allowed' => true,
        'message' => '',
        'closure' => null
    ];
}