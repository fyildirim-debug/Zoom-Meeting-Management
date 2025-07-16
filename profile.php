<?php
$pageTitle = 'Profil Ayarları';
require_once 'config/config.php';
require_once 'config/auth.php';

requireLogin();

$currentUser = getCurrentUser();
$error = '';
$success = '';

// Form işleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF kontrolü
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Güvenlik hatası. Lütfen sayfayı yenileyin.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_profile') {
            // Profil güncelleme
            $name = cleanInput($_POST['name'] ?? '');
            $surname = cleanInput($_POST['surname'] ?? '');
            $theme = cleanInput($_POST['theme'] ?? 'light');
            
            if (empty($name) || empty($surname)) {
                $error = 'Ad ve soyad alanları zorunludur.';
            } else {
                $result = updateProfile($currentUser['id'], [
                    'name' => $name,
                    'surname' => $surname,
                    'theme' => $theme
                ]);
                
                if ($result['success']) {
                    $success = $result['message'];
                    // Güncel kullanıcı bilgilerini al
                    $currentUser = getCurrentUser();
                } else {
                    $error = $result['message'];
                }
            }
            
        } elseif ($action === 'change_password') {
            // Şifre değiştirme
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $error = 'Tüm şifre alanları zorunludur.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Yeni şifreler eşleşmiyor.';
            } elseif (strlen($newPassword) < 6) {
                $error = 'Yeni şifre en az 6 karakter olmalıdır.';
            } else {
                $result = changePassword($currentUser['id'], $currentPassword, $newPassword);
                
                if ($result['success']) {
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
            }
        }
    }
}

// Kullanıcı istatistikleri
try {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status != 'cancelled' THEN 1 ELSE 0 END) as total_meetings,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_meetings,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_meetings,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_meetings
        FROM meetings
        WHERE user_id = ?
    ");
    $stmt->execute([$currentUser['id']]);
    $userStats = $stmt->fetch();
    
    // Birim bilgisi
    if ($currentUser['department_id']) {
        $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
        $stmt->execute([$currentUser['department_id']]);
        $department = $stmt->fetch();
        $currentUser['department_name'] = $department['name'] ?? 'Bilinmiyor';
    }
    
} catch (Exception $e) {
    writeLog("Profile stats error: " . $e->getMessage(), 'error');
    $userStats = ['total_meetings' => 0, 'pending_meetings' => 0, 'approved_meetings' => 0, 'rejected_meetings' => 0];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content flex-1 p-6">
    <div class="max-w-4xl mx-auto">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center space-x-4">
                <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-pink-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-user-cog text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Profil Ayarları</h1>
                    <p class="text-gray-600">Kişisel bilgilerinizi ve hesap ayarlarınızı yönetin</p>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-3"></i>
                    <?php echo $error; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-3"></i>
                    <?php echo $success; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Sol Taraf - Profil Bilgileri -->
            <div class="lg:col-span-1">
                <!-- Profil Kartı -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 mb-6">
                    <div class="text-center">
                        <div class="w-24 h-24 bg-gradient-to-r from-purple-500 to-pink-600 rounded-full flex items-center justify-center mx-auto mb-4">
                            <span class="text-3xl font-bold text-white">
                                <?php echo strtoupper(substr($currentUser['name'], 0, 1) . substr($currentUser['surname'], 0, 1)); ?>
                            </span>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($currentUser['name'] . ' ' . $currentUser['surname']); ?></h3>
                        <p class="text-gray-600"><?php echo htmlspecialchars($currentUser['email']); ?></p>
                        <p class="text-sm text-gray-500 mt-1">
                            <?php echo $currentUser['role'] === 'admin' ? 'Sistem Yöneticisi' : 'Kullanıcı'; ?>
                        </p>
                        <?php if (isset($currentUser['department_name'])): ?>
                            <p class="text-sm text-gray-500"><?php echo htmlspecialchars($currentUser['department_name']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- İstatistikler -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                    <h4 class="text-lg font-semibold text-gray-900 mb-4">Toplantı İstatistikleri</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Toplam Toplantı</span>
                            <span class="font-semibold text-gray-900"><?php echo $userStats['total_meetings']; ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Bekleyen</span>
                            <span class="font-semibold text-orange-600"><?php echo $userStats['pending_meetings']; ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Onaylanan</span>
                            <span class="font-semibold text-green-600"><?php echo $userStats['approved_meetings']; ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Reddedilen</span>
                            <span class="font-semibold text-red-600"><?php echo $userStats['rejected_meetings']; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sağ Taraf - Ayarlar -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Profil Bilgileri Düzenleme -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Profil Bilgileri</h3>
                        <p class="text-sm text-gray-600 mt-1">Kişisel bilgilerinizi güncelleyin</p>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Ad *</label>
                                <input type="text" 
                                       id="name" 
                                       name="name" 
                                       value="<?php echo htmlspecialchars($currentUser['name']); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                       required>
                            </div>
                            
                            <div>
                                <label for="surname" class="block text-sm font-medium text-gray-700 mb-2">Soyad *</label>
                                <input type="text" 
                                       id="surname" 
                                       name="surname" 
                                       value="<?php echo htmlspecialchars($currentUser['surname']); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                       required>
                            </div>
                        </div>
                        
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">E-posta</label>
                            <input type="email" 
                                   id="email" 
                                   value="<?php echo htmlspecialchars($currentUser['email']); ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50"
                                   disabled>
                            <p class="text-xs text-gray-500 mt-1">E-posta adresi değiştirilemez</p>
                        </div>
                        
                        <div>
                            <label for="theme" class="block text-sm font-medium text-gray-700 mb-2">Tema Tercihi</label>
                            <select id="theme" 
                                    name="theme"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                                <option value="light" <?php echo $currentUser['theme'] === 'light' ? 'selected' : ''; ?>>Açık Tema</option>
                                <option value="dark" <?php echo $currentUser['theme'] === 'dark' ? 'selected' : ''; ?>>Koyu Tema</option>
                            </select>
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" class="btn-primary px-6 py-3">
                                <i class="fas fa-save mr-2"></i>
                                Profili Güncelle
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Şifre Değiştirme -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Şifre Değiştir</h3>
                        <p class="text-sm text-gray-600 mt-1">Hesap güvenliğiniz için güçlü bir şifre seçin</p>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div>
                            <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">Mevcut Şifre *</label>
                            <input type="password" 
                                   id="current_password" 
                                   name="current_password"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                   required>
                        </div>
                        
                        <div>
                            <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">Yeni Şifre *</label>
                            <input type="password" 
                                   id="new_password" 
                                   name="new_password"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                   required
                                   minlength="6">
                            <p class="text-xs text-gray-500 mt-1">En az 6 karakter olmalıdır</p>
                        </div>
                        
                        <div>
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Yeni Şifre Tekrar *</label>
                            <input type="password" 
                                   id="confirm_password" 
                                   name="confirm_password"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                   required
                                   minlength="6">
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                                <i class="fas fa-key mr-2"></i>
                                Şifreyi Değiştir
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Hesap Bilgileri -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Hesap Bilgileri</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <span class="text-sm font-medium text-gray-700">Hesap Türü</span>
                                <p class="text-gray-900"><?php echo $currentUser['role'] === 'admin' ? 'Sistem Yöneticisi' : 'Standart Kullanıcı'; ?></p>
                            </div>
                            <div>
                                <span class="text-sm font-medium text-gray-700">Mevcut Tema</span>
                                <p class="text-gray-900"><?php echo $currentUser['theme'] === 'light' ? 'Açık Tema' : 'Koyu Tema'; ?></p>
                            </div>
                        </div>
                        
                        <?php if (isset($currentUser['department_name'])): ?>
                            <div>
                                <span class="text-sm font-medium text-gray-700">Birim</span>
                                <p class="text-gray-900"><?php echo htmlspecialchars($currentUser['department_name']); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="pt-4 border-t border-gray-200">
                            <p class="text-sm text-gray-500">
                                Son giriş: <?php echo date('d.m.Y H:i', $_SESSION['login_time'] ?? time()); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Şifre doğrulama
document.addEventListener('DOMContentLoaded', function() {
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    
    function validatePasswords() {
        if (newPassword.value && confirmPassword.value) {
            if (newPassword.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Şifreler eşleşmiyor');
            } else {
                confirmPassword.setCustomValidity('');
            }
        }
    }
    
    newPassword.addEventListener('input', validatePasswords);
    confirmPassword.addEventListener('input', validatePasswords);
});
</script>

<?php include 'includes/footer.php'; ?>