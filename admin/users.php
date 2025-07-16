<?php
$pageTitle = 'Kullanıcı Yönetimi';
require_once '../config/config.php';
require_once '../config/auth.php';

requireLogin();
if (!isAdmin()) {
    redirect('../dashboard.php');
}

$currentUser = getCurrentUser();

// Kullanıcı işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Güvenlik token hatası. Sayfayı yenileyin ve tekrar deneyin.';
        $messageType = 'error';
    } elseif (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'add_user':
                $result = addUser($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'edit_user':
                $result = editUser($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'delete_user':
                $result = deleteUser($_POST['user_id']);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'toggle_status':
                $result = toggleUserStatus($_POST['user_id']);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'invite_user':
                $result = inviteUser($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                
                // Davet başarılı ise mail içeriğini session'a kaydet
                if ($result['success'] && isset($result['mail_content'])) {
                    $_SESSION['invitation_mail_content'] = $result['mail_content'];
                    $_SESSION['invitation_link'] = $result['invite_link'];
                }
                break;
                
            default:
                $message = 'Geçersiz işlem.';
                $messageType = 'error';
                break;
        }
    }
}

// Kullanıcıları listele
try {
    $stmt = $pdo->query("
        SELECT u.*, d.name as department_name 
        FROM users u 
        LEFT JOIN departments d ON u.department_id = d.id 
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll();
    
    // Birimleri al
    $stmt = $pdo->query("SELECT * FROM departments ORDER BY name");
    $departments = $stmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("Users page error: " . $e->getMessage(), 'error');
    $users = [];
    $departments = [];
}

// Helper functions
function addUser($data) {
    global $pdo;
    
    try {
        // Email kontrolü
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Bu e-posta adresi zaten kullanılıyor.'];
        }
        
        $password = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (name, surname, email, password, department_id, role, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        
        $result = $stmt->execute([
            $data['name'],
            $data['surname'],
            $data['email'],
            $password,
            $data['department_id'] ?: null,
            $data['role']
        ]);
        
        if ($result) {
            writeLog("New user added: " . $data['email'], 'info');
            return ['success' => true, 'message' => 'Kullanıcı başarıyla eklendi.'];
        }
        
    } catch (Exception $e) {
        writeLog("Add user error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Kullanıcı eklenirken hata oluştu.'];
    }
}

function editUser($data) {
    global $pdo;
    
    try {
        // Email kontrolü (mevcut kullanıcı dışında)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$data['email'], $data['user_id']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Bu e-posta adresi zaten kullanılıyor.'];
        }
        
        if (!empty($data['password'])) {
            $password = password_hash($data['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users 
                SET name = ?, surname = ?, email = ?, password = ?, department_id = ?, role = ?
                WHERE id = ?
            ");
            $result = $stmt->execute([
                $data['name'],
                $data['surname'],
                $data['email'],
                $password,
                $data['department_id'] ?: null,
                $data['role'],
                $data['user_id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET name = ?, surname = ?, email = ?, department_id = ?, role = ?
                WHERE id = ?
            ");
            $result = $stmt->execute([
                $data['name'],
                $data['surname'],
                $data['email'],
                $data['department_id'] ?: null,
                $data['role'],
                $data['user_id']
            ]);
        }
        
        if ($result) {
            writeLog("User updated: " . $data['email'], 'info');
            return ['success' => true, 'message' => 'Kullanıcı başarıyla güncellendi.'];
        }
        
    } catch (Exception $e) {
        writeLog("Edit user error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Kullanıcı güncellenirken hata oluştu.'];
    }
}

function deleteUser($userId) {
    global $pdo;
    
    try {
        // Admin kullanıcısını silme kontrolü
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user && $user['role'] === 'admin') {
            return ['success' => false, 'message' => 'Admin kullanıcıları silinemez.'];
        }
        
        // Kullanıcının toplantıları var mı kontrol et
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $meetingCount = $stmt->fetchColumn();
        
        if ($meetingCount > 0) {
            // Kullanıcının toplantıları var, silme yerine deaktif et
            $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
            $result = $stmt->execute([$userId]);
            
            if ($result) {
                writeLog("User deactivated instead of deleted due to existing meetings: ID " . $userId, 'info');
                return ['success' => true, 'message' => 'Kullanıcının mevcut toplantıları bulunduğu için hesap deaktif edildi. Toplantıları kaldırıldıktan sonra tamamen silinebilir.'];
            }
        } else {
            // Kullanıcının toplantısı yok, güvenli bir şekilde silebilir
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $result = $stmt->execute([$userId]);
            
            if ($result) {
                writeLog("User deleted: ID " . $userId, 'info');
                return ['success' => true, 'message' => 'Kullanıcı başarıyla silindi.'];
            }
        }
        
    } catch (Exception $e) {
        writeLog("Delete user error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Kullanıcı silinirken hata oluştu: ' . $e->getMessage()];
    }
    
    return ['success' => false, 'message' => 'Kullanıcı silinirken bilinmeyen bir hata oluştu.'];
}

function toggleUserStatus($userId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END 
            WHERE id = ?
        ");
        $result = $stmt->execute([$userId]);
        
        if ($result) {
            writeLog("User status toggled: ID " . $userId, 'info');
            return ['success' => true, 'message' => 'Kullanıcı durumu güncellendi.'];
        }
        
    } catch (Exception $e) {
        writeLog("Toggle user status error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Kullanıcı durumu güncellenirken hata oluştu.'];
    }
}

function inviteUser($data) {
    global $pdo, $currentUser;
    
    try {
        // Benzersiz token oluştur
        $token = bin2hex(random_bytes(32));
        
        // 1 saat sonra süresi dolacak
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Davetiye verilerini JSON olarak kaydet
        $invitationData = json_encode([
            'department_name' => getDepartmentName($data['department_id']),
            'role' => $data['role'],
            'invited_by' => $currentUser['name'] . ' ' . $currentUser['surname']
        ]);
        
        // Veritabanına kaydet (email olmadan)
        $stmt = $pdo->prepare("
            INSERT INTO invitation_links (token, department_id, created_by, expires_at, invitation_data) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $token,
            $data['department_id'],
            $currentUser['id'],
            $expiresAt,
            $invitationData
        ]);
        
        if ($result) {
            // Davet linki oluştur
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $basePath = dirname(dirname($_SERVER['PHP_SELF']));
            $inviteLink = $protocol . '://' . $host . $basePath . '/register.php?token=' . $token;
            
            writeLog("User invitation created for department: " . getDepartmentName($data['department_id']), 'info');
            
            // Mail içeriği oluştur (kopyalanabilir format)
            $departmentName = getDepartmentName($data['department_id']);
            $roleName = $data['role'] === 'admin' ? 'Yönetici' : 'Kullanıcı';
            
            $mailContent = generateMailContent([
                'department' => $departmentName,
                'role' => $roleName,
                'invited_by' => $currentUser['name'] . ' ' . $currentUser['surname'],
                'link' => $inviteLink,
                'expires_at' => $expiresAt
            ]);
            
            return [
                'success' => true, 
                'message' => 'Davetiye başarıyla oluşturuldu! Mail içeriği hazır.',
                'mail_content' => $mailContent,
                'invite_link' => $inviteLink
            ];
        }
        
    } catch (Exception $e) {
        writeLog("Invite user error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Davetiye oluşturulurken hata oluştu.'];
    }
    
    return ['success' => false, 'message' => 'Davetiye oluşturulamadı.'];
}

function getDepartmentName($departmentId) {
    global $pdo;
    
    if (!$departmentId) return 'Birim Atanmamış';
    
    try {
        $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
        $stmt->execute([$departmentId]);
        $result = $stmt->fetch();
        return $result ? $result['name'] : 'Bilinmeyen Birim';
    } catch (Exception $e) {
        return 'Bilinmeyen Birim';
    }
}

function generateMailContent($data) {
    return "Konu: Zoom Toplantı Yönetim Sistemi - Hesap Davetiyesi

Merhaba,

" . $data['invited_by'] . " tarafından Zoom Toplantı Yönetim Sistemi'ne davet edildiniz.

🏢 Birim: " . $data['department'] . "
👤 Yetki: " . $data['role'] . "

Hesabınızı oluşturmak için aşağıdaki linke tıklayın:
🔗 " . $data['link'] . "

⚠️ Bu davetiye " . date('d.m.Y H:i', strtotime($data['expires_at'])) . " tarihine kadar geçerlidir (1 saat).

Kayıt olurken e-posta adresinizi ve şifrenizi kendiniz belirleyeceksiniz.
Davetiyeyi kabul ettikten sonra sisteme giriş yapabilir ve toplantı taleplerinde bulunabilirsiniz.

İyi çalışmalar dileriz!

---
Zoom Toplantı Yönetim Sistemi
" . $_SERVER['HTTP_HOST'];
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
                <h1 class="text-3xl font-bold text-gray-900">Kullanıcı Yönetimi</h1>
                <p class="mt-2 text-gray-600">Sistem kullanıcılarını yönetin</p>
            </div>
            <div class="mt-4 sm:mt-0">
                <button onclick="openAddUserModal()" class="btn-primary mr-3">
                    <i class="fas fa-plus mr-2"></i>
                    Yeni Kullanıcı
                </button>
                <button onclick="openInviteUserModal()" class="btn-secondary">
                    <i class="fas fa-envelope mr-2"></i>
                    Kullanıcı Davet Et
                </button>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> mb-6">
                <?php
                $icons = [
                    'success' => 'fas fa-check-circle',
                    'error' => 'fas fa-exclamation-circle',
                    'warning' => 'fas fa-exclamation-triangle',
                    'info' => 'fas fa-info-circle'
                ];
                ?>
                <i class="<?php echo $icons[$messageType] ?? $icons['info']; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Mail Content Display -->
        <?php if (isset($_SESSION['invitation_mail_content'])): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 mb-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-envelope text-blue-600"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-blue-900">Mail İçeriği Hazır</h3>
                            <p class="text-sm text-blue-700">Aşağıdaki içeriği kopyalayıp e-posta olarak gönderebilirsiniz.</p>
                        </div>
                    </div>
                    <button onclick="copyMailContent()" class="btn-secondary text-sm">
                        <i class="fas fa-copy mr-2"></i>
                        Kopyala
                    </button>
                </div>
                
                <div class="bg-white border border-blue-200 rounded-lg p-4">
                    <textarea 
                        id="mail-content" 
                        readonly 
                        class="w-full h-64 text-sm text-gray-800 bg-white border-0 resize-none focus:outline-none font-mono"
                        onclick="this.select()"
                    ><?php echo htmlspecialchars($_SESSION['invitation_mail_content']); ?></textarea>
                </div>
                
                <div class="mt-4 flex items-center justify-between">
                    <div class="flex items-center text-sm text-blue-700">
                        <i class="fas fa-info-circle mr-2"></i>
                        <span>Davet linki 1 saat geçerlidir</span>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="testInviteLink()" class="text-blue-600 hover:text-blue-800 text-sm">
                            <i class="fas fa-external-link-alt mr-1"></i>
                            Linki Test Et
                        </button>
                        <button onclick="closeMailContent()" class="text-gray-500 hover:text-gray-700 text-sm">
                            <i class="fas fa-times mr-1"></i>
                            Kapat
                        </button>
                    </div>
                </div>
            </div>
            
            <script>
                const inviteLink = '<?php echo addslashes($_SESSION['invitation_link']); ?>';
                
                function copyMailContent() {
                    const textarea = document.getElementById('mail-content');
                    textarea.select();
                    document.execCommand('copy');
                    
                    // Visual feedback
                    const button = event.target.closest('button');
                    const originalText = button.innerHTML;
                    button.innerHTML = '<i class="fas fa-check mr-2"></i>Kopyalandı!';
                    button.classList.add('bg-green-500', 'text-white');
                    
                    setTimeout(() => {
                        button.innerHTML = originalText;
                        button.classList.remove('bg-green-500', 'text-white');
                    }, 2000);
                }
                
                function testInviteLink() {
                    window.open(inviteLink, '_blank');
                }
                
                function closeMailContent() {
                    // Hide the mail content div
                    event.target.closest('.bg-blue-50').style.display = 'none';
                    
                    // Clear session data via AJAX
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=clear_mail_content&csrf_token=' + window.APP_CONFIG.csrf_token
                    });
                }
            </script>
            
            <?php 
                // Clear session data after display
                unset($_SESSION['invitation_mail_content']);
                unset($_SESSION['invitation_link']);
            ?>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Toplam Kullanıcı</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo count($users); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-users text-blue-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Aktif Kullanıcı</p>
                        <p class="text-3xl font-bold text-green-600">
                            <?php echo count(array_filter($users, function($u) { return $u['status'] === 'active'; })); ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-user-check text-green-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Admin Kullanıcı</p>
                        <p class="text-3xl font-bold text-purple-600">
                            <?php echo count(array_filter($users, function($u) { return $u['role'] === 'admin'; })); ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-user-shield text-purple-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Pasif Kullanıcı</p>
                        <p class="text-3xl font-bold text-red-600">
                            <?php echo count(array_filter($users, function($u) { return $u['status'] === 'inactive'; })); ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-user-times text-red-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Users Table -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Kullanıcı Listesi</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kullanıcı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">E-posta</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Birim</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rol</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durum</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kayıt Tarihi</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($users as $user): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                                            <span class="text-blue-600 font-semibold">
                                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                            </span>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($user['name'] . ' ' . $user['surname']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($user['email']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $user['department_name'] ? htmlspecialchars($user['department_name']) : '-'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $user['role'] === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo $user['role'] === 'admin' ? 'Admin' : 'Kullanıcı'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $user['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $user['status'] === 'active' ? 'Aktif' : 'Pasif'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo formatDate($user['created_at']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <button onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)" 
                                                class="text-blue-600 hover:text-blue-900">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <button onclick="confirmToggleStatus(<?php echo $user['id']; ?>)"
                                                class="text-yellow-600 hover:text-yellow-900">
                                            <i class="fas fa-toggle-<?php echo $user['status'] === 'active' ? 'on' : 'off'; ?>"></i>
                                        </button>
                                        
                                        <?php if ($user['role'] !== 'admin'): ?>
                                            <button onclick="confirmDeleteUser(<?php echo $user['id']; ?>)"
                                                    class="text-red-600 hover:text-red-900">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    // Debug: Check if functions exist
    console.log('createFormModal exists:', typeof createFormModal !== 'undefined');
    console.log('confirmAction exists:', typeof confirmAction !== 'undefined');
    console.log('APP_CONFIG exists:', typeof window.APP_CONFIG !== 'undefined');
    
    function openAddUserModal() {
        if (typeof createFormModal === 'undefined') {
            alert('Modal sistemi yüklenemedi. Sayfayı yenileyin.');
            return;
        }
        
        createFormModal({
            id: 'add-user-modal',
            title: 'Yeni Kullanıcı Ekle',
            fields: [
                {
                    name: 'name',
                    label: 'Ad',
                    type: 'text',
                    required: true,
                    placeholder: 'Kullanıcı adını girin'
                },
                {
                    name: 'surname',
                    label: 'Soyad',
                    type: 'text',
                    required: true,
                    placeholder: 'Kullanıcı soyadını girin'
                },
                {
                    name: 'email',
                    label: 'E-posta',
                    type: 'email',
                    required: true,
                    placeholder: 'kullanici@email.com'
                },
                {
                    name: 'password',
                    label: 'Şifre',
                    type: 'password',
                    required: true,
                    placeholder: 'Güvenli bir şifre girin'
                },
                {
                    name: 'department_id',
                    label: 'Birim',
                    type: 'select',
                    placeholder: 'Birim seçin',
                    options: [
                        { value: '', text: 'Birim Seçin' },
                        <?php foreach ($departments as $dept): ?>
                        { value: '<?php echo $dept['id']; ?>', text: '<?php echo htmlspecialchars($dept['name']); ?>' },
                        <?php endforeach; ?>
                    ]
                },
                {
                    name: 'role',
                    label: 'Rol',
                    type: 'select',
                    required: true,
                    options: [
                        { value: 'user', text: 'Kullanıcı' },
                        { value: 'admin', text: 'Admin' }
                    ]
                }
            ],
            submitText: 'Kullanıcı Ekle',
            onSubmit: function(data, form) {
                // Create form element and submit
                const formElement = document.createElement('form');
                formElement.method = 'POST';
                
                // Add CSRF token
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = window.APP_CONFIG.csrf_token;
                formElement.appendChild(csrfInput);
                
                // Add form data
                const fields = ['action', 'name', 'surname', 'email', 'password', 'department_id', 'role'];
                const values = {action: 'add_user', ...data};
                
                fields.forEach(field => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = field;
                    input.value = values[field] || '';
                    formElement.appendChild(input);
                });
                
                document.body.appendChild(formElement);
                formElement.submit();
            }
        });
    }
    
    function editUser(user) {
        createFormModal({
            id: 'edit-user-modal',
            title: 'Kullanıcı Düzenle',
            data: user,
            fields: [
                {
                    name: 'name',
                    label: 'Ad',
                    type: 'text',
                    required: true,
                    placeholder: 'Kullanıcı adını girin'
                },
                {
                    name: 'surname',
                    label: 'Soyad',
                    type: 'text',
                    required: true,
                    placeholder: 'Kullanıcı soyadını girin'
                },
                {
                    name: 'email',
                    label: 'E-posta',
                    type: 'email',
                    required: true,
                    placeholder: 'kullanici@email.com'
                },
                {
                    name: 'password',
                    label: 'Yeni Şifre (boş bırakılırsa değişmez)',
                    type: 'password',
                    placeholder: 'Yeni şifre girin'
                },
                {
                    name: 'department_id',
                    label: 'Birim',
                    type: 'select',
                    placeholder: 'Birim seçin',
                    options: [
                        { value: '', text: 'Birim Seçin' },
                        <?php foreach ($departments as $dept): ?>
                        { value: '<?php echo $dept['id']; ?>', text: '<?php echo htmlspecialchars($dept['name']); ?>' },
                        <?php endforeach; ?>
                    ]
                },
                {
                    name: 'role',
                    label: 'Rol',
                    type: 'select',
                    required: true,
                    options: [
                        { value: 'user', text: 'Kullanıcı' },
                        { value: 'admin', text: 'Admin' }
                    ]
                }
            ],
            submitText: 'Güncelle',
            onSubmit: function(data, form) {
                // Create form element and submit
                const formElement = document.createElement('form');
                formElement.method = 'POST';
                
                // Add CSRF token
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = window.APP_CONFIG.csrf_token;
                formElement.appendChild(csrfInput);
                
                // Add form data
                const fields = ['action', 'user_id', 'name', 'surname', 'email', 'password', 'department_id', 'role'];
                const values = {action: 'edit_user', user_id: user.id, ...data};
                
                fields.forEach(field => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = field;
                    input.value = values[field] || '';
                    formElement.appendChild(input);
                });
                
                document.body.appendChild(formElement);
                formElement.submit();
            }
        });
    }
    
    function confirmToggleStatus(userId) {
        if (typeof confirmAction === 'undefined') {
            if (confirm('Bu kullanıcının durumunu değiştirmek istediğinizden emin misiniz?')) {
                // Fallback to direct form submission
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="${window.APP_CONFIG?.csrf_token || ''}">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="user_id" value="${userId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
            return;
        }
        
        confirmAction({
            title: 'Kullanıcı Durumunu Değiştir',
            message: 'Bu kullanıcının durumunu değiştirmek istediğinizden emin misiniz?',
            type: 'warning',
            confirmText: 'Evet, Değiştir',
            cancelText: 'İptal',
            onConfirm: function() {
                const form = document.createElement('form');
                form.method = 'POST';
                
                // Add CSRF token
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = window.APP_CONFIG.csrf_token;
                form.appendChild(csrfInput);
                
                // Add action
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'toggle_status';
                form.appendChild(actionInput);
                
                // Add user_id
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                form.appendChild(userIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    
    function openInviteUserModal() {
        if (typeof createFormModal === 'undefined') {
            alert('Modal sistemi yüklenemedi. Sayfayı yenileyin.');
            return;
        }
        
        createFormModal({
            id: 'invite-user-modal',
            title: 'Kullanıcı Davet Et',
            description: 'Davet linki oluşturun. Kullanıcı kayıt olurken e-posta adresini kendisi belirleyecek.',
            fields: [
                {
                    name: 'department_id',
                    label: 'Birim',
                    type: 'select',
                    required: true,
                    placeholder: 'Birim seçin',
                    options: [
                        { value: '', text: 'Birim Seçin' },
                        <?php foreach ($departments as $dept): ?>
                        { value: '<?php echo $dept['id']; ?>', text: '<?php echo htmlspecialchars($dept['name']); ?>' },
                        <?php endforeach; ?>
                    ]
                },
                {
                    name: 'role',
                    label: 'Yetki Seviyesi',
                    type: 'select',
                    required: true,
                    options: [
                        { value: 'user', text: 'Kullanıcı' },
                        { value: 'admin', text: 'Yönetici' }
                    ]
                }
            ],
            submitText: 'Davetiye Oluştur',
            onSubmit: function(data, form) {
                // Create form element and submit
                const formElement = document.createElement('form');
                formElement.method = 'POST';
                
                // Add CSRF token
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = window.APP_CONFIG.csrf_token;
                formElement.appendChild(csrfInput);
                
                // Add form data
                const fields = ['action', 'department_id', 'role'];
                const values = {action: 'invite_user', ...data};
                
                fields.forEach(field => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = field;
                    input.value = values[field] || '';
                    formElement.appendChild(input);
                });
                
                document.body.appendChild(formElement);
                formElement.submit();
            }
        });
    }

    function confirmDeleteUser(userId) {
        if (typeof confirmAction === 'undefined') {
            if (confirm('Bu kullanıcıyı kalıcı olarak silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.')) {
                // Fallback to direct form submission
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="${window.APP_CONFIG?.csrf_token || ''}">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="${userId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
            return;
        }
        
        confirmAction({
            title: 'Kullanıcıyı Sil',
            message: 'Bu kullanıcıyı kalıcı olarak silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.',
            type: 'danger',
            confirmText: 'Evet, Sil',
            cancelText: 'İptal',
            onConfirm: function() {
                const form = document.createElement('form');
                form.method = 'POST';
                
                // Add CSRF token
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = window.APP_CONFIG.csrf_token;
                form.appendChild(csrfInput);
                
                // Add action
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_user';
                form.appendChild(actionInput);
                
                // Add user_id
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                form.appendChild(userIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
</script>

<?php include '../includes/footer.php'; ?>