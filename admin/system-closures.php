<?php
$pageTitle = 'Sistem Kapatma Yönetimi';
require_once '../config/config.php';
require_once '../config/auth.php';

requireLogin();
if (!isAdmin()) {
    redirect('../dashboard.php');
}

$currentUser = getCurrentUser();
$message = '';
$messageType = '';

// Migration çalıştır
try {
    require_once '../includes/MigrationManager.php';
    $migrationManager = new MigrationManager($pdo);
    $migrationManager->runPendingMigrations();
} catch (Exception $e) {}

// Form işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Güvenlik token hatası.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                $title = cleanInput($_POST['title'] ?? '');
                $startDate = cleanInput($_POST['start_date'] ?? '');
                $endDate = cleanInput($_POST['end_date'] ?? '');
                $reason = cleanInput($_POST['reason'] ?? '');
                
                if (empty($title) || empty($startDate) || empty($endDate)) {
                    $message = 'Başlık, başlangıç ve bitiş tarihleri zorunludur.';
                    $messageType = 'error';
                } elseif ($endDate < $startDate) {
                    $message = 'Bitiş tarihi başlangıç tarihinden önce olamaz.';
                    $messageType = 'error';
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO system_closures (title, start_date, end_date, reason, created_by) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$title, $startDate, $endDate, $reason, $currentUser['id']]);
                        $message = 'Kapatma dönemi başarıyla eklendi.';
                        $messageType = 'success';
                        logActivity('create', 'system_closure', $pdo->lastInsertId(), "Sistem kapatma eklendi: $title", $currentUser['id']);
                    } catch (Exception $e) {
                        $message = 'Kapatma eklenirken hata: ' . $e->getMessage();
                        $messageType = 'error';
                    }
                }
                break;
                
            case 'toggle':
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE system_closures SET is_active = NOT is_active WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = 'Durum güncellendi.';
                    $messageType = 'success';
                }
                break;
                
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    $stmt = $pdo->prepare("SELECT title FROM system_closures WHERE id = ?");
                    $stmt->execute([$id]);
                    $closure = $stmt->fetch();
                    
                    $stmt = $pdo->prepare("DELETE FROM system_closures WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = 'Kapatma dönemi silindi.';
                    $messageType = 'success';
                    logActivity('delete', 'system_closure', $id, "Sistem kapatma silindi: " . ($closure['title'] ?? ''), $currentUser['id']);
                }
                break;
        }
    }
}

// Kapatma dönemlerini al
try {
    $stmt = $pdo->query("
        SELECT sc.*, u.name as creator_name, u.surname as creator_surname
        FROM system_closures sc
        LEFT JOIN users u ON sc.created_by = u.id
        ORDER BY sc.start_date DESC
    ");
    $closures = $stmt->fetchAll();
} catch (Exception $e) {
    $closures = [];
}

// Aktif kapatmaları al
$activeClosures = array_filter($closures, function($c) {
    return $c['is_active'] && $c['end_date'] >= date('Y-m-d');
});

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content flex-1 p-6">
    <div class="max-w-6xl mx-auto">
        <!-- Başlık -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 bg-gradient-to-r from-red-500 to-orange-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar-times text-white text-xl"></i>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Sistem Kapatma Yönetimi</h1>
                        <p class="text-gray-600">Belirli tarih aralıklarında toplantı oluşturmayı engelleyin</p>
                    </div>
                </div>
                <button onclick="openAddModal()" class="btn-primary inline-flex items-center px-4 py-2 rounded-lg">
                    <i class="fas fa-plus mr-2"></i>
                    Yeni Kapatma Ekle
                </button>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200'; ?>">
            <div class="flex items-center">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Aktif Uyarı -->
        <?php if (!empty($activeClosures)): ?>
        <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
            <div class="flex items-start">
                <i class="fas fa-exclamation-triangle text-yellow-600 mt-1 mr-3"></i>
                <div>
                    <h3 class="font-semibold text-yellow-800">Aktif Kapatma Dönemleri</h3>
                    <ul class="mt-2 text-sm text-yellow-700">
                        <?php foreach ($activeClosures as $ac): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($ac['title']); ?></strong>: 
                            <?php echo formatDateTurkish($ac['start_date']); ?> - <?php echo formatDateTurkish($ac['end_date']); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Kapatma Listesi -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-900">Kapatma Dönemleri</h2>
            </div>
            
            <?php if (empty($closures)): ?>
            <div class="p-12 text-center">
                <i class="fas fa-calendar-check text-6xl text-gray-300 mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">Henüz kapatma dönemi yok</h3>
                <p class="text-gray-500 mb-4">Tatil veya bakım dönemlerinde toplantı oluşturmayı engellemek için kapatma ekleyin.</p>
                <button onclick="openAddModal()" class="btn-primary px-6 py-2 rounded-lg">
                    <i class="fas fa-plus mr-2"></i>İlk Kapatmayı Ekle
                </button>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Başlık</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tarih Aralığı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Açıklama</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Durum</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Oluşturan</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($closures as $closure): 
                            $isPast = $closure['end_date'] < date('Y-m-d');
                            $isCurrent = $closure['start_date'] <= date('Y-m-d') && $closure['end_date'] >= date('Y-m-d');
                        ?>
                        <tr class="<?php echo $isPast ? 'bg-gray-50 opacity-60' : ''; ?>">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <?php if ($isCurrent && $closure['is_active']): ?>
                                    <span class="w-2 h-2 bg-red-500 rounded-full mr-2 animate-pulse"></span>
                                    <?php endif; ?>
                                    <span class="font-medium text-gray-900"><?php echo htmlspecialchars($closure['title']); ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm">
                                    <span class="text-gray-900"><?php echo formatDateTurkish($closure['start_date']); ?></span>
                                    <span class="text-gray-400 mx-1">→</span>
                                    <span class="text-gray-900"><?php echo formatDateTurkish($closure['end_date']); ?></span>
                                </div>
                                <?php 
                                $days = (strtotime($closure['end_date']) - strtotime($closure['start_date'])) / 86400 + 1;
                                ?>
                                <div class="text-xs text-gray-500"><?php echo $days; ?> gün</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm text-gray-600">
                                    <?php echo $closure['reason'] ? htmlspecialchars(mb_substr($closure['reason'], 0, 50)) . (mb_strlen($closure['reason']) > 50 ? '...' : '') : '-'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($isPast): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                        <i class="fas fa-history mr-1"></i>Geçmiş
                                    </span>
                                <?php elseif ($closure['is_active']): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        <i class="fas fa-lock mr-1"></i>Aktif
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                        <i class="fas fa-unlock mr-1"></i>Pasif
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                <?php echo htmlspecialchars(($closure['creator_name'] ?? '') . ' ' . ($closure['creator_surname'] ?? '')); ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end space-x-2">
                                    <?php if (!$isPast): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $closure['id']; ?>">
                                        <button type="submit" class="p-2 text-gray-400 hover:text-blue-600 transition-colors" title="<?php echo $closure['is_active'] ? 'Pasif Yap' : 'Aktif Yap'; ?>">
                                            <i class="fas <?php echo $closure['is_active'] ? 'fa-toggle-on text-green-500' : 'fa-toggle-off'; ?>"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($closure)); ?>)" class="p-2 text-gray-400 hover:text-blue-600 transition-colors" title="Düzenle">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirm('Bu kapatma dönemini silmek istediğinizden emin misiniz?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $closure['id']; ?>">
                                        <button type="submit" class="p-2 text-gray-400 hover:text-red-600 transition-colors" title="Sil">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Ekleme/Düzenleme Modal -->
<div id="closureModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 id="modalTitle" class="text-lg font-semibold text-gray-900">Kapatma Dönemi Ekle</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form method="POST" id="closureForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="closureId" value="">
            
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Başlık *</label>
                    <input type="text" name="title" id="closureTitle" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Örn: Yılbaşı Tatili, Sistem Bakımı">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Başlangıç Tarihi *</label>
                        <input type="date" name="start_date" id="closureStartDate" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bitiş Tarihi *</label>
                        <input type="date" name="end_date" id="closureEndDate" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Açıklama</label>
                    <textarea name="reason" id="closureReason" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="Kapatma nedenini açıklayın..."></textarea>
                </div>
                
                <div id="activeToggleContainer" class="hidden">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_active" id="closureActive" value="1" checked
                               class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <span class="ml-2 text-sm text-gray-700">Aktif (toplantı oluşturmayı engelle)</span>
                    </label>
                </div>
            </div>
            
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                    İptal
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <i class="fas fa-save mr-2"></i><span id="submitBtnText">Kaydet</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Yeni Kapatma Dönemi';
    document.getElementById('formAction').value = 'add';
    document.getElementById('closureId').value = '';
    document.getElementById('closureTitle').value = '';
    document.getElementById('closureStartDate').value = '';
    document.getElementById('closureEndDate').value = '';
    document.getElementById('closureReason').value = '';
    document.getElementById('closureActive').checked = true;
    document.getElementById('activeToggleContainer').classList.add('hidden');
    document.getElementById('submitBtnText').textContent = 'Ekle';
    document.getElementById('closureModal').classList.remove('hidden');
}

function openEditModal(closure) {
    document.getElementById('modalTitle').textContent = 'Kapatma Düzenle';
    document.getElementById('formAction').value = 'update';
    document.getElementById('closureId').value = closure.id;
    document.getElementById('closureTitle').value = closure.title;
    document.getElementById('closureStartDate').value = closure.start_date;
    document.getElementById('closureEndDate').value = closure.end_date;
    document.getElementById('closureReason').value = closure.reason || '';
    document.getElementById('closureActive').checked = closure.is_active == 1;
    document.getElementById('activeToggleContainer').classList.remove('hidden');
    document.getElementById('submitBtnText').textContent = 'Güncelle';
    document.getElementById('closureModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('closureModal').classList.add('hidden');
}

// Modal dışına tıklayınca kapat
document.getElementById('closureModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ESC tuşu ile kapat
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php
$additionalScripts = '';
include '../includes/footer.php';
?>
