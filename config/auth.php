<?php
/**
 * Authentication ve JWT Sistemi
 */

// Session başlat
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Functions.php'yi include et (aktivite kayıtları için)
require_once __DIR__ . '/../includes/functions.php';

// Migration Manager'ı include et (otomatik veritabanı güncellemeleri için)
require_once __DIR__ . '/../includes/MigrationManager.php';

/**
 * Custom JWT Implementation
 */
class JWT {
    private static $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    
    public static function encode($payload, $secret) {
        $header = self::base64UrlEncode(json_encode(self::$header));
        $payload = self::base64UrlEncode(json_encode($payload));
        $signature = self::sign($header . '.' . $payload, $secret);
        
        return $header . '.' . $payload . '.' . $signature;
    }
    
    public static function decode($token, $secret) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            throw new Exception('Invalid token format');
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Verify signature
        $expectedSignature = self::sign($header . '.' . $payload, $secret);
        if (!hash_equals($signature, $expectedSignature)) {
            throw new Exception('Invalid signature');
        }
        
        $payload = json_decode(self::base64UrlDecode($payload), true);
        
        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new Exception('Token expired');
        }
        
        return $payload;
    }
    
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64UrlDecode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
    
    private static function sign($data, $secret) {
        return self::base64UrlEncode(hash_hmac('sha256', $data, $secret, true));
    }
}

/**
 * Authentication Functions
 */

// Kullanıcı giriş kontrolü
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_email']);
}

// Admin kontrolü
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Kullanıcı bilgilerini al
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? '',
        'surname' => $_SESSION['user_surname'] ?? '',
        'email' => $_SESSION['user_email'],
        'role' => $_SESSION['user_role'] ?? 'user',
        'department_id' => $_SESSION['user_department_id'] ?? null,
        'theme' => $_SESSION['user_theme'] ?? 'light'
    ];
}

// Kullanıcı giriş yapma
function loginUser($email, $password) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, d.name as department_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            writeLog("Login attempt failed: User not found - $email", 'warning', 'auth.log');
            return ['success' => false, 'message' => 'E-posta adresi veya şifre hatalı.'];
        }
        
        // Kullanıcı durumu kontrolü
        if (isset($user['status']) && $user['status'] !== 'active') {
            writeLog("Login attempt failed: User account inactive - $email", 'warning', 'auth.log');
            return ['success' => false, 'message' => 'Hesabınız pasif durumda. Lütfen yöneticinize başvurun.'];
        }
        
        if (!password_verify($password, $user['password'])) {
            writeLog("Login attempt failed: Wrong password - $email", 'warning', 'auth.log');
            return ['success' => false, 'message' => 'E-posta adresi veya şifre hatalı.'];
        }
        
        // Session bilgilerini ayarla
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_surname'] = $user['surname'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_department_id'] = $user['department_id'];
        $_SESSION['user_department_name'] = $user['department_name'];
        $_SESSION['user_theme'] = $user['theme'];
        $_SESSION['login_time'] = time();
        
        // JWT token oluştur (API kullanımı için)
        $payload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'iat' => time(),
            'exp' => time() + (24 * 60 * 60) // 24 saat
        ];
        
        $jwtSecret = JWT_SECRET ?? 'default-secret';
        $token = JWT::encode($payload, $jwtSecret);
        $_SESSION['jwt_token'] = $token;
        
        writeLog("User logged in successfully - $email", 'info', 'auth.log');
        
        // Aktivite kaydet
        logActivity('login', 'user', $user['id'], 'Sisteme giriş yaptı', $user['id']);
        
        // Otomatik migration kontrolü - Admin için veritabanı güncellemelerini çalıştır
        if ($user['role'] === 'admin') {
            try {
                $migrationResult = runAutoMigrations();
                if (!empty($migrationResult['executed'])) {
                    writeLog(" Auto migrations executed on login: " . implode(', ', $migrationResult['executed']), 'info');
                }
            } catch (Exception $e) {
                // Migration hatası login'i engellemez
                writeLog("Migration check error: " . $e->getMessage(), 'warning');
            }
        }
        
        return [
            'success' => true,
            'message' => 'Giriş başarılı.',
            'user' => getCurrentUser(),
            'token' => $token
        ];
        
    } catch (Exception $e) {
        writeLog("Login error: " . $e->getMessage(), 'error', 'auth.log');
        return ['success' => false, 'message' => 'Giriş sırasında bir hata oluştu.'];
    }
}

// Kullanıcı çıkış yapma
function logoutUser() {
    $email = $_SESSION['user_email'] ?? 'Unknown';
    $userId = $_SESSION['user_id'] ?? null;
    
    // Aktivite kaydet (session temizlenmeden önce)
    if ($userId) {
        logActivity('logout', 'user', $userId, 'Sistemden çıkış yaptı', $userId);
    }
    
    // Session temizle
    session_unset();
    session_destroy();
    
    // Yeni session başlat
    session_start();
    session_regenerate_id(true);
    
    writeLog("User logged out - $email", 'info', 'auth.log');
    
    // Admin sayfasındaysa prefix düzeltmesi
    $loginPath = 'login.php';
    if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
        $loginPath = '../login.php';
    }
    
    header('Location: ' . $loginPath);
    exit;
}

// Kullanıcı kayıt
function registerUser($userData) {
    global $pdo;
    
    try {
        // E-posta kontrolü
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$userData['email']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Bu e-posta adresi zaten kayıtlı.'];
        }
        
        // Şifre hash
        $hashedPassword = password_hash($userData['password'], PASSWORD_DEFAULT);
        
        // Kullanıcı ekle
        $stmt = $pdo->prepare("
            INSERT INTO users (name, surname, email, password, department_id, role) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $userData['name'],
            $userData['surname'],
            $userData['email'],
            $hashedPassword,
            $userData['department_id'] ?? null,
            $userData['role'] ?? 'user'
        ]);
        
        if ($result) {
            $newUserId = $pdo->lastInsertId();
            
            writeLog("New user registered - " . $userData['email'], 'info', 'auth.log');
            
            // Aktivite kaydet
            logActivity('register', 'user', $newUserId,
                'Yeni kullanıcı kaydı oluşturuldu: ' . $userData['name'] . ' ' . $userData['surname'],
                $newUserId);
            
            return ['success' => true, 'message' => 'Kullanıcı başarıyla kaydedildi.'];
        } else {
            return ['success' => false, 'message' => 'Kullanıcı kaydı sırasında hata oluştu.'];
        }
        
    } catch (Exception $e) {
        writeLog("Registration error: " . $e->getMessage(), 'error', 'auth.log');
        return ['success' => false, 'message' => 'Kayıt sırasında bir hata oluştu.'];
    }
}

// Şifre değiştirme
function changePassword($userId, $currentPassword, $newPassword) {
    global $pdo;
    
    try {
        // Mevcut şifreyi kontrol et
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => 'Mevcut şifre hatalı.'];
        }
        
        // Yeni şifre hash
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Şifreyi güncelle
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $result = $stmt->execute([$hashedPassword, $userId]);
        
        if ($result) {
            writeLog("Password changed for user ID: $userId", 'info', 'auth.log');
            
            // Aktivite kaydet
            logActivity('password_change', 'user', $userId, 'Şifresini değiştirdi', $userId);
            
            return ['success' => true, 'message' => 'Şifre başarıyla değiştirildi.'];
        } else {
            return ['success' => false, 'message' => 'Şifre değiştirme sırasında hata oluştu.'];
        }
        
    } catch (Exception $e) {
        writeLog("Password change error: " . $e->getMessage(), 'error', 'auth.log');
        return ['success' => false, 'message' => 'Şifre değiştirme sırasında bir hata oluştu.'];
    }
}

// Profil güncelleme
function updateProfile($userId, $profileData) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET name = ?, surname = ?, theme = ? 
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $profileData['name'],
            $profileData['surname'],
            $profileData['theme'] ?? 'light',
            $userId
        ]);
        
        if ($result) {
            // Session güncelle
            $_SESSION['user_name'] = $profileData['name'];
            $_SESSION['user_surname'] = $profileData['surname'];
            $_SESSION['user_theme'] = $profileData['theme'] ?? 'light';
            
            writeLog("Profile updated for user ID: $userId", 'info', 'auth.log');
            
            // Aktivite kaydet
            logActivity('profile_update', 'user', $userId, 'Profil bilgilerini güncelledi', $userId);
            
            return ['success' => true, 'message' => 'Profil başarıyla güncellendi.'];
        } else {
            return ['success' => false, 'message' => 'Profil güncelleme sırasında hata oluştu.'];
        }
        
    } catch (Exception $e) {
        writeLog("Profile update error: " . $e->getMessage(), 'error', 'auth.log');
        return ['success' => false, 'message' => 'Profil güncelleme sırasında bir hata oluştu.'];
    }
}

// JWT token doğrulama (API için)
function verifyJWTToken($token) {
    try {
        $jwtSecret = JWT_SECRET ?? 'default-secret';
        $payload = JWT::decode($token, $jwtSecret);
        
        return [
            'success' => true,
            'user_id' => $payload['user_id'],
            'email' => $payload['email'],
            'role' => $payload['role']
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// API Authentication middleware
function requireAuth() {
    if (!isLoggedIn()) {
        http_response_code(401);
        sendJsonResponse(false, 'Oturum açmanız gerekiyor.');
    }
}

// Admin kontrolü middleware
function requireAdmin() {
    requireAuth();
    
    if (!isAdmin()) {
        http_response_code(403);
        sendJsonResponse(false, 'Bu işlem için yönetici yetkisi gerekiyor.');
    }
}

// Login sayfası yönlendirmesi
function requireLogin() {
    if (!isLoggedIn()) {
        $returnUrl = $_SERVER['REQUEST_URI'];
        
        // Admin sayfasındaysa prefix düzeltmesi
        $loginPath = 'login.php';
        if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
            $loginPath = '../login.php';
        }
        
        redirect($loginPath . '?return=' . urlencode($returnUrl));
    }
}

// Rate limiting (basit)
function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 300) {
    $rateLimitFile = __DIR__ . '/../data/rate_limit.json';
    
    // Dosya yoksa oluştur
    if (!file_exists($rateLimitFile)) {
        file_put_contents($rateLimitFile, json_encode([]));
    }
    
    $rateLimits = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    $now = time();
    
    // Eski kayıtları temizle
    foreach ($rateLimits as $key => $data) {
        if ($data['time'] < ($now - $timeWindow)) {
            unset($rateLimits[$key]);
        }
    }
    
    // Mevcut attempt sayısını kontrol et
    $attempts = 0;
    foreach ($rateLimits as $key => $data) {
        if (strpos($key, $identifier) === 0) {
            $attempts++;
        }
    }
    
    if ($attempts >= $maxAttempts) {
        return false;
    }
    
    // Yeni attempt kaydı
    $rateLimits[$identifier . '_' . $now] = ['time' => $now];
    file_put_contents($rateLimitFile, json_encode($rateLimits));
    
    return true;
}

// Session güvenlik kontrolü
function validateSession() {
    global $pdo;
    
    if (!isLoggedIn()) {
        return false;
    }
    
    // Kullanıcı durumu kontrolü (mevcut oturum için)
    try {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userStatus = $stmt->fetchColumn();
        
        if ($userStatus !== 'active') {
            writeLog("Session terminated: User account inactive - " . $_SESSION['user_email'], 'warning', 'auth.log');
            logoutUser();
            return false;
        }
    } catch (Exception $e) {
        writeLog("Session validation error: " . $e->getMessage(), 'error', 'auth.log');
        logoutUser();
        return false;
    }
    
    // Session timeout kontrolü (2 saat)
    $sessionTimeout = 2 * 60 * 60;
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $sessionTimeout) {
        logoutUser();
        return false;
    }
    
    // Session hijacking kontrolü (basit)
    $expectedSignature = hash('sha256', $_SESSION['user_id'] . $_SESSION['user_email'] . $_SERVER['HTTP_USER_AGENT']);
    if (!isset($_SESSION['signature'])) {
        $_SESSION['signature'] = $expectedSignature;
    } elseif (!hash_equals($_SESSION['signature'], $expectedSignature)) {
        logoutUser();
        return false;
    }
    
    return true;
}

// Session yenileme
function refreshSession() {
    if (isLoggedIn()) {
        $_SESSION['login_time'] = time();
        session_regenerate_id(true);
    }
}

// Forgot password (basit implementasyon)
function requestPasswordReset($email) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Güvenlik için her zaman başarılı mesajı gönder
            return ['success' => true, 'message' => 'Şifre sıfırlama bağlantısı e-posta adresinize gönderildi.'];
        }
        
        // Reset token oluştur
        $resetToken = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + (60 * 60)); // 1 saat
        
        // Token'ı veritabanına kaydet (password_resets tablosu gerekebilir)
        // Bu örnek implementasyonda sadece log yazıyoruz
        writeLog("Password reset requested for: $email, Token: $resetToken", 'info', 'auth.log');
        
        // E-posta gönder (gerçek implementasyonda)
        // sendEmail($email, 'Şifre Sıfırlama', "Şifre sıfırlama bağlantınız: /reset-password.php?token=$resetToken");
        
        return ['success' => true, 'message' => 'Şifre sıfırlama bağlantısı e-posta adresinize gönderildi.'];
        
    } catch (Exception $e) {
        writeLog("Password reset request error: " . $e->getMessage(), 'error', 'auth.log');
        return ['success' => false, 'message' => 'Şifre sıfırlama isteği sırasında bir hata oluştu.'];
    }
}