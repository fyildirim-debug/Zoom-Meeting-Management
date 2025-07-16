<?php
/**
 * Kullanıcı Kayıt Sayfası - Davet Linki ile Kayıt
 */

// Session başlatma kontrolü (CSRF token için gerekli)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Kullanıcı Kaydı';
require_once 'config/config.php';

$error = '';
$success = '';
$invitationData = null;
$token = '';

// Token kontrolü
if (isset($_GET['token'])) {
    $token = sanitizeInput($_GET['token']);
    
    try {
        // Token'ı veritabanından kontrol et (email gerekli değil artık)
        $stmt = $pdo->prepare("
            SELECT il.*, d.name as department_name 
            FROM invitation_links il
            LEFT JOIN departments d ON il.department_id = d.id
            WHERE il.token = ? AND il.status = 'active' AND il.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $invitation = $stmt->fetch();
        
        if ($invitation) {
            $invitationData = json_decode($invitation['invitation_data'], true);
            $invitationData['department_id'] = $invitation['department_id'];
            $invitationData['department_name'] = $invitation['department_name'];
            $invitationData['expires_at'] = $invitation['expires_at'];
        } else {
            $error = 'Davetiye linki geçersiz veya süresi dolmuş.';
        }
        
    } catch (Exception $e) {
        writeLog("Token validation error: " . $e->getMessage(), 'error');
        $error = 'Davetiye doğrulanırken hata oluştu.';
    }
} else {
    $error = 'Geçersiz davetiye linki.';
}

// Form gönderildi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invitationData) {
    // CSRF token kontrolü
    $tokenValid = false;
    if (isset($_POST['csrf_token'])) {
        // Session'da token var mı kontrol et
        if (isset($_SESSION['csrf_token'])) {
            $tokenValid = hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
        }
    }
    
    if (!$tokenValid) {
        // Debug bilgisi (sadece geliştirme için)
        writeLog("CSRF token validation failed. Session token: " . (isset($_SESSION['csrf_token']) ? 'exists' : 'missing') . ", POST token: " . (isset($_POST['csrf_token']) ? 'exists' : 'missing'), 'warning');
        $error = 'Güvenlik token hatası. Lütfen sayfayı yenileyin ve tekrar deneyin.';
    } else {
        $name = sanitizeInput($_POST['name']);
        $surname = sanitizeInput($_POST['surname']);
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $confirmPassword = $_POST['confirm_password'];
        
        // Validasyon
        if (empty($name) || empty($surname) || empty($email) || empty($password)) {
            $error = 'Lütfen tüm alanları doldurun.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Geçerli bir e-posta adresi girin.';
        } elseif (strlen($password) < 6) {
            $error = 'Şifre en az 6 karakter olmalıdır.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Şifreler eşleşmiyor.';
        } else {
            try {
                // Email kontrolü (başka biri bu email'i kullanıyor mu?)
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'Bu e-posta adresi zaten kullanılıyor.';
                } else {
                    // Token hala geçerli mi kontrol et
                    $stmt = $pdo->prepare("
                        SELECT id FROM invitation_links 
                        WHERE token = ? AND status = 'active' AND expires_at > NOW()
                    ");
                    $stmt->execute([$token]);
                    if (!$stmt->fetch()) {
                        $error = 'Davetiye linki artık geçerli değil.';
                    } else {
                        // Kullanıcıyı oluştur
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO users (name, surname, email, password, department_id, role, status) 
                            VALUES (?, ?, ?, ?, ?, ?, 'active')
                        ");
                        
                        $result = $stmt->execute([
                            $name,
                            $surname,
                            $email,
                            $hashedPassword,
                            $invitationData['department_id'],
                            $invitationData['role']
                        ]);
                        
                        if ($result) {
                            // Davetiye durumunu güncelle
                            $stmt = $pdo->prepare("
                                UPDATE invitation_links 
                                SET status = 'used', used_at = NOW() 
                                WHERE token = ?
                            ");
                            $stmt->execute([$token]);
                            
                            writeLog("User registered via invitation: " . $email, 'info');
                            
                            $success = 'Hesabınız başarıyla oluşturuldu! Giriş yapabilirsiniz.';
                            $invitationData = null; // Formu gizle
                        } else {
                            $error = 'Hesap oluşturulurken hata oluştu. Lütfen tekrar deneyin.';
                        }
                    }
                }
                
            } catch (Exception $e) {
                writeLog("User registration error: " . $e->getMessage(), 'error');
                $error = 'Hesap oluşturulurken hata oluştu.';
            }
        }
    }
}

// Minimum header
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .gradient-bg {
            background: var(--gradient-primary);
            min-height: 100vh;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        .input-field {
            transition: all 0.3s ease;
        }
        
        .input-field:focus {
            transform: translateY(-2px);
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <!-- Logo & Title -->
            <div class="text-center mb-8 fade-in">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-white bg-opacity-20 rounded-full mb-4">
                    <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                    </svg>
                </div>
                <h1 class="text-3xl font-bold text-white mb-2"><?php echo APP_NAME; ?></h1>
                <p class="text-white text-opacity-80">Hesap Oluştur</p>
            </div>

            <!-- Registration Form -->
            <div class="glass-card rounded-2xl p-8 fade-in">
                <?php if ($error): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo $success; ?>
                    </div>
                    
                    <div class="text-center">
                        <a href="login.php" class="btn-primary">
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            Giriş Yap
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($invitationData && !$success): ?>
                    <!-- Invitation Info -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <h3 class="text-lg font-semibold text-blue-900 mb-2">
                            <i class="fas fa-envelope mr-2"></i>
                            Davetiye Bilgileri
                        </h3>
                        <div class="text-sm text-blue-800 space-y-1">
                            <p><span class="font-medium">Birim:</span> <?php echo htmlspecialchars($invitationData['department_name']); ?></p>
                            <p><span class="font-medium">Yetki:</span> <?php echo $invitationData['role'] === 'admin' ? 'Yönetici' : 'Kullanıcı'; ?></p>
                            <p><span class="font-medium">Davet Eden:</span> <?php echo htmlspecialchars($invitationData['invited_by']); ?></p>
                            <p class="text-orange-600">
                                <i class="fas fa-clock mr-1"></i>
                                <span class="font-medium">Geçerlilik:</span> <?php echo date('d.m.Y H:i', strtotime($invitationData['expires_at'])); ?> tarihine kadar
                            </p>
                        </div>
                    </div>

                    <form method="POST" action="" class="space-y-6">
                        <?php 
                        // CSRF token oluştur/al
                        if (!isset($_SESSION['csrf_token'])) {
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        }
                        ?>
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <!-- Name -->
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                                Ad *
                            </label>
                            <input 
                                type="text" 
                                id="name" 
                                name="name" 
                                value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                class="input-field w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="Adınızı girin"
                                required
                            >
                        </div>

                        <!-- Surname -->
                        <div>
                            <label for="surname" class="block text-sm font-medium text-gray-700 mb-2">
                                Soyad *
                            </label>
                            <input 
                                type="text" 
                                id="surname" 
                                name="surname" 
                                value="<?php echo htmlspecialchars($_POST['surname'] ?? ''); ?>"
                                class="input-field w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="Soyadınızı girin"
                                required
                            >
                        </div>

                        <!-- Email -->
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                E-posta Adresi *
                            </label>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                class="input-field w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="kullanici@email.com"
                                required
                            >
                        </div>

                        <!-- Password -->
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                                Şifre *
                            </label>
                            <div class="relative">
                                <input 
                                    type="password" 
                                    id="password" 
                                    name="password" 
                                    class="input-field w-full px-4 py-3 pr-12 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="En az 6 karakter"
                                    required
                                >
                                <button 
                                    type="button" 
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center"
                                    onclick="togglePassword('password')"
                                >
                                    <i id="password-eye" class="fas fa-eye text-gray-400"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
                                Şifre Tekrarı *
                            </label>
                            <div class="relative">
                                <input 
                                    type="password" 
                                    id="confirm_password" 
                                    name="confirm_password" 
                                    class="input-field w-full px-4 py-3 pr-12 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="Şifrenizi tekrar girin"
                                    required
                                >
                                <button 
                                    type="button" 
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center"
                                    onclick="togglePassword('confirm_password')"
                                >
                                    <i id="confirm_password-eye" class="fas fa-eye text-gray-400"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button 
                            type="submit" 
                            class="btn-primary w-full text-white font-semibold py-3 px-4 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                        >
                            <i class="fas fa-user-plus mr-2"></i>
                            Hesap Oluştur
                        </button>
                    </form>

                    <div class="mt-6 text-center">
                        <p class="text-sm text-gray-600">
                            Zaten hesabınız var mı? 
                            <a href="login.php" class="text-blue-600 hover:text-blue-500 font-medium">
                                Giriş yapın
                            </a>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if (!$invitationData && !$success): ?>
                    <div class="text-center">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Geçersiz Davetiye</h3>
                        <p class="text-gray-600 mb-6">Bu davetiye linki geçersiz veya süresi dolmuş.</p>
                        <a href="login.php" class="btn-primary">
                            <i class="fas fa-arrow-left mr-2"></i>
                            Giriş Sayfasına Dön
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="text-center mt-8">
                <p class="text-white text-opacity-60 text-sm">
                    © <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. Tüm hakları saklıdır.
                </p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const eyeIcon = document.getElementById(fieldId + '-eye');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const password = document.getElementById('password').value;
                    const confirmPassword = document.getElementById('confirm_password').value;
                    
                    if (password !== confirmPassword) {
                        e.preventDefault();
                        alert('Şifreler eşleşmiyor!');
                        return false;
                    }
                    
                    if (password.length < 6) {
                        e.preventDefault();
                        alert('Şifre en az 6 karakter olmalıdır!');
                        return false;
                    }
                });
            }
        });
    </script>
</body>
</html> 