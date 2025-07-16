<?php
$pageTitle = 'Toplantı Detayları';
require_once 'config/config.php';
require_once 'config/auth.php';

requireLogin();
$currentUser = getCurrentUser();

$meetingId = (int)($_GET['id'] ?? 0);

if (!$meetingId) {
    redirect('my-meetings.php');
}

try {
    // Toplantı detaylarını al - admin tüm toplantıları görebilir, kullanıcı kendi + aynı birim toplantılarını görebilir
    if (isAdmin()) {
        $whereClause = 'WHERE m.id = ?';
        $params = [$meetingId];
    } else {
        $whereClause = 'WHERE m.id = ? AND (m.user_id = ? OR m.department_id = ?)';
        $params = [$meetingId, $currentUser['id'], $currentUser['department_id']];
    }
    
    $stmt = $pdo->prepare("
        SELECT m.*,
               u.name as user_name, u.surname as user_surname, u.email as user_email,
               d.name as department_name,
               za.email as zoom_email, za.name as zoom_account_name,
               au.name as approved_by_name, au.surname as approved_by_surname,
               ru.name as rejected_by_name, ru.surname as rejected_by_surname,
               cu.name as cancelled_by_name, cu.surname as cancelled_by_surname
        FROM meetings m
        JOIN users u ON m.user_id = u.id
        LEFT JOIN departments d ON m.department_id = d.id
        LEFT JOIN zoom_accounts za ON m.zoom_account_id = za.id
        LEFT JOIN users au ON m.approved_by = au.id
        LEFT JOIN users ru ON m.rejected_by = ru.id
        LEFT JOIN users cu ON m.cancelled_by = cu.id
        $whereClause
    ");
    $stmt->execute($params);
    $meeting = $stmt->fetch();
    
    if (!$meeting) {
        redirect('my-meetings.php');
    }
    
    // Toplantı sahibi kontrolü
    $isOwner = ($meeting['user_id'] == $currentUser['id']);
    $isSameDepartment = ($meeting['department_id'] == $currentUser['department_id']);
    
} catch (Exception $e) {
    writeLog("Meeting details error: " . $e->getMessage(), 'error');
    redirect('my-meetings.php');
}

// 🔐 FRESH START URL FETCH - Zoom API'den fresh start URL al
if ($meeting['status'] === 'approved' && $meeting['zoom_meeting_id'] && $meeting['zoom_account_id']) {
    try {
        require_once 'includes/ZoomAPI.php';
        $zoomAccountManager = new ZoomAccountManager($pdo);
        $zoomAPI = $zoomAccountManager->getZoomAPI($meeting['zoom_account_id']);
        
        // Zoom API'den fresh meeting details al
        $freshMeetingResult = $zoomAPI->getMeeting($meeting['zoom_meeting_id']);
        
        if ($freshMeetingResult['success'] && isset($freshMeetingResult['data']['start_url'])) {
            $freshStartUrl = $freshMeetingResult['data']['start_url'];
            
            // Fresh start URL'i meeting array'ine kaydet
            $meeting['zoom_start_url'] = $freshStartUrl;
            
            // Aynı zamanda database'i de güncelle (cache için)
            $stmt = $pdo->prepare("UPDATE meetings SET zoom_start_url = ? WHERE id = ?");
            $stmt->execute([$freshStartUrl, $meeting['id']]);
            
            writeLog("🔐 FRESH START URL FETCHED: Meeting ID=" . $meeting['id'] . " için fresh start URL alındı", 'info');
            
        } else {
            writeLog("🔐 FRESH START URL ERROR: API response failed - " . ($freshMeetingResult['message'] ?? 'Unknown error'), 'error');
        }
        
    } catch (Exception $e) {
        writeLog("🔐 FRESH START URL ERROR: " . $e->getMessage(), 'error');
        // Hata durumunda original URL'i koru
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content flex-1 p-6">
    <div class="max-w-4xl mx-auto">
        <!-- Page Header -->
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center space-x-4">
                <a href="<?php echo isAdmin() ? 'admin/meeting-approvals.php' : 'my-meetings.php'; ?>" 
                   class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center hover:bg-gray-200 transition-colors">
                    <i class="fas fa-arrow-left text-gray-600"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Toplantı Detayları</h1>
                    <p class="text-gray-600">Toplantı bilgilerini görüntüleyebilirsiniz</p>
                </div>
            </div>
            
            <div class="flex items-center space-x-3">
                <?php if ($meeting['status'] === 'approved' && ($meeting['zoom_join_url'] || $meeting['meeting_link'])): ?>
                    <a href="<?php echo htmlspecialchars($meeting['zoom_join_url'] ?: $meeting['meeting_link']); ?>"
                       target="_blank"
                       class="btn-primary">
                        <i class="fas fa-video mr-2"></i>
                        Toplantıya Katıl
                    </a>
                <?php endif; ?>
                
                <?php if (!isAdmin() && $meeting['status'] === 'pending'): ?>
                    <a href="edit-meeting.php?id=<?php echo $meeting['id']; ?>" class="btn-secondary">
                        <i class="fas fa-edit mr-2"></i>
                        Düzenle
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Meeting Info Card -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($meeting['title']); ?></h2>
                    <span class="badge badge-<?php 
                        echo $meeting['status'] === 'approved' ? 'success' : 
                            ($meeting['status'] === 'pending' ? 'warning' : 
                            ($meeting['status'] === 'rejected' ? 'error' : 'info')); 
                    ?>">
                        <?php 
                        $statusLabels = [
                            'pending' => 'Bekliyor',
                            'approved' => 'Onaylı',
                            'rejected' => 'Reddedildi',
                            'cancelled' => 'İptal Edildi'
                        ];
                        echo $statusLabels[$meeting['status']];
                        ?>
                    </span>
                </div>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Basic Info -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-500 mb-1">Tarih & Saat</label>
                            <div class="text-lg font-semibold text-gray-900">
                                <?php echo formatDateTurkish($meeting['date']); ?>
                            </div>
                            <div class="text-sm text-gray-600">
                                <?php echo formatTime($meeting['start_time']) . ' - ' . formatTime($meeting['end_time']); ?>
                                (<?php echo calculateMeetingDuration($meeting['start_time'], $meeting['end_time']); ?>)
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-500 mb-1">Moderatör</label>
                            <div class="text-lg text-gray-900"><?php echo htmlspecialchars($meeting['moderator']); ?></div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-500 mb-1">Katılımcı Sayısı</label>
                            <div class="text-lg text-gray-900">
                                <i class="fas fa-users mr-2 text-gray-400"></i>
                                <?php echo $meeting['participants_count']; ?> kişi
                            </div>
                        </div>
                    </div>
                    
                    <!-- Request Info -->
                    <div class="space-y-4">
                        <?php if ($isOwner || isAdmin()): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-500 mb-1">Talep Eden</label>
                            <div class="text-lg text-gray-900">
                                <?php echo htmlspecialchars($meeting['user_name'] . ' ' . $meeting['user_surname']); ?>
                            </div>
                            <div class="text-sm text-gray-600"><?php echo htmlspecialchars($meeting['user_email']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-500 mb-1">Birim</label>
                            <div class="text-lg text-gray-900"><?php echo htmlspecialchars($meeting['department_name']); ?></div>
                        </div>
                        
                        <?php if ($isOwner || isAdmin()): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-500 mb-1">Talep Tarihi</label>
                            <div class="text-lg text-gray-900">
                                <?php echo formatDateTimeTurkish($meeting['created_at']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($meeting['description']): ?>
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <label class="block text-sm font-medium text-gray-500 mb-2">Açıklama</label>
                    <div class="text-gray-900 whitespace-pre-wrap"><?php echo htmlspecialchars($meeting['description']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status Specific Information -->
        <?php if ($meeting['status'] === 'approved'): ?>
            <div class="bg-green-50 border border-green-200 rounded-xl p-6 mb-6">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-check text-green-600"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-green-900">Toplantı Onaylandı</h3>
                        <p class="text-green-700">
                            <?php 
                            if ($meeting['approved_by_name']) {
                                echo 'Onaylayan: ' . htmlspecialchars($meeting['approved_by_name'] . ' ' . $meeting['approved_by_surname']);
                            }
                            if ($meeting['approved_at']) {
                                echo ' - ' . formatDateTimeTurkish($meeting['approved_at']);
                            }
                            ?>
                        </p>
                    </div>
                </div>
                
                <?php if ($isOwner || isAdmin()): ?>
                    <!-- Kendi toplantısı veya admin - tüm detayları göster -->
                    
                    <?php if ($meeting['zoom_email'] && isAdmin()): ?>
                    <!-- Zoom hesap bilgileri sadece admin için -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-green-700 mb-1">Zoom Hesabı (Admin)</label>
                            <div class="text-green-900"><?php echo htmlspecialchars($meeting['zoom_email']); ?></div>
                            <?php if ($meeting['zoom_account_name']): ?>
                                <div class="text-sm text-green-600"><?php echo htmlspecialchars($meeting['zoom_account_name']); ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($meeting['zoom_meeting_id'] && isAdmin()): ?>
                        <div>
                            <label class="block text-sm font-medium text-green-700 mb-1">Meeting ID</label>
                            <div class="text-green-900 font-mono"><?php echo htmlspecialchars($meeting['zoom_meeting_id']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Meeting ID ve şifre - herkese göster -->
                    <?php if ($meeting['zoom_meeting_id']): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-green-700 mb-1">Meeting ID</label>
                            <div class="flex items-center space-x-2">
                                <div class="text-green-900 font-mono flex-1"><?php echo htmlspecialchars($meeting['zoom_meeting_id']); ?></div>
                                <button onclick="copyToClipboard('<?php echo htmlspecialchars($meeting['zoom_meeting_id']); ?>')"
                                        class="btn-secondary px-3 py-2">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        
                        <?php if ($meeting['zoom_password']): ?>
                        <div>
                            <label class="block text-sm font-medium text-green-700 mb-1">Toplantı Şifresi</label>
                            <div class="flex items-center space-x-2">
                                <div class="text-green-900 font-mono flex-1"><?php echo htmlspecialchars($meeting['zoom_password']); ?></div>
                                <button onclick="copyToClipboard('<?php echo htmlspecialchars($meeting['zoom_password']); ?>')"
                                        class="btn-secondary px-3 py-2">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($meeting['zoom_join_url']): ?>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-green-700 mb-1">Katılım Linki</label>
                        <div class="flex items-center space-x-2">
                            <input type="text" value="<?php echo htmlspecialchars($meeting['zoom_join_url']); ?>"
                                   readonly class="form-input text-sm bg-white flex-1">
                            <button onclick="copyToClipboard('<?php echo htmlspecialchars($meeting['zoom_join_url']); ?>')"
                                    class="btn-secondary px-3 py-2">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($meeting['zoom_start_url'] && ($isOwner || isAdmin())): ?>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-green-700 mb-1">Host Linki (Sadece Moderatör)</label>
                        <div class="flex items-center space-x-2">
                            <input type="text" value="<?php echo htmlspecialchars($meeting['zoom_start_url']); ?>"
                                   readonly class="form-input text-sm bg-white flex-1">
                            <button onclick="copyToClipboard('<?php echo htmlspecialchars($meeting['zoom_start_url']); ?>')"
                                    class="btn-secondary px-3 py-2">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($meeting['meeting_link'] && !$meeting['zoom_join_url']): ?>
                    <!-- Fallback link for old meetings -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-green-700 mb-1">Toplantı Linki</label>
                        <div class="flex items-center space-x-2">
                            <input type="text" value="<?php echo htmlspecialchars($meeting['meeting_link']); ?>"
                                   readonly class="form-input text-sm bg-white flex-1">
                            <button onclick="copyToClipboard('<?php echo htmlspecialchars($meeting['meeting_link']); ?>')"
                                    class="btn-secondary px-3 py-2">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <!-- Başkasının toplantısı - sadece temel bilgiler -->
                    <div class="bg-blue-50 p-4 rounded-lg mb-4">
                        <p class="text-sm text-blue-800 mb-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Bu birim toplantısına katılabilirsiniz. Sadece katılım bilgileri gösterilmektedir.
                        </p>
                    </div>
                    
                    <?php if ($meeting['zoom_meeting_id']): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-green-700 mb-1">Meeting ID</label>
                            <div class="flex items-center space-x-2">
                                <div class="text-green-900 font-mono flex-1"><?php echo htmlspecialchars($meeting['zoom_meeting_id']); ?></div>
                                <button onclick="copyToClipboard('<?php echo htmlspecialchars($meeting['zoom_meeting_id']); ?>')"
                                        class="btn-secondary px-3 py-2">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        
                        <?php if ($meeting['zoom_password']): ?>
                        <div>
                            <label class="block text-sm font-medium text-green-700 mb-1">Toplantı Şifresi</label>
                            <div class="flex items-center space-x-2">
                                <div class="text-green-900 font-mono flex-1"><?php echo htmlspecialchars($meeting['zoom_password']); ?></div>
                                <button onclick="copyToClipboard('<?php echo htmlspecialchars($meeting['zoom_password']); ?>')"
                                        class="btn-secondary px-3 py-2">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($meeting['zoom_join_url']): ?>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-green-700 mb-1">Katılım Linki</label>
                        <div class="flex items-center space-x-2">
                            <input type="text" value="<?php echo htmlspecialchars($meeting['zoom_join_url']); ?>"
                                   readonly class="form-input text-sm bg-white flex-1">
                            <button onclick="copyToClipboard('<?php echo htmlspecialchars($meeting['zoom_join_url']); ?>')"
                                    class="btn-secondary px-3 py-2">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <?php elseif ($meeting['meeting_link']): ?>
                    <!-- Fallback link for old meetings -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-green-700 mb-1">Katılım Linki</label>
                        <div class="flex items-center space-x-2">
                            <input type="text" value="<?php echo htmlspecialchars($meeting['meeting_link']); ?>"
                                   readonly class="form-input text-sm bg-white flex-1">
                            <button onclick="copyToClipboard('<?php echo htmlspecialchars($meeting['meeting_link']); ?>')"
                                    class="btn-secondary px-3 py-2">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
        <?php elseif ($meeting['status'] === 'rejected'): ?>
            <div class="bg-red-50 border border-red-200 rounded-xl p-6 mb-6">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-times text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-red-900">Toplantı Reddedildi</h3>
                        <p class="text-red-700">
                            <?php 
                            if ($meeting['rejected_by_name']) {
                                echo 'Reddeden: ' . htmlspecialchars($meeting['rejected_by_name'] . ' ' . $meeting['rejected_by_surname']);
                            }
                            if ($meeting['rejected_at']) {
                                echo ' - ' . formatDateTimeTurkish($meeting['rejected_at']);
                            }
                            ?>
                        </p>
                    </div>
                </div>
                
                <?php if ($meeting['rejection_reason']): ?>
                <div>
                    <label class="block text-sm font-medium text-red-700 mb-2">Red Nedeni</label>
                    <div class="text-red-900 bg-white p-3 rounded-lg border border-red-200">
                        <?php echo htmlspecialchars($meeting['rejection_reason']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($meeting['status'] === 'cancelled'): ?>
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-6 mb-6">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-ban text-gray-600"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Toplantı İptal Edildi</h3>
                        <p class="text-gray-700">
                            <?php 
                            if ($meeting['cancelled_by_name']) {
                                echo 'İptal Eden: ' . htmlspecialchars($meeting['cancelled_by_name'] . ' ' . $meeting['cancelled_by_surname']);
                            }
                            if ($meeting['cancelled_at']) {
                                echo ' - ' . formatDateTimeTurkish($meeting['cancelled_at']);
                            }
                            ?>
                        </p>
                    </div>
                </div>
                
                <?php if ($meeting['cancel_reason']): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">İptal Nedeni</label>
                    <div class="text-gray-900 bg-white p-3 rounded-lg border border-gray-200">
                        <?php echo htmlspecialchars($meeting['cancel_reason']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($meeting['status'] === 'pending'): ?>
            <div class="bg-orange-50 border border-orange-200 rounded-xl p-6 mb-6">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-clock text-orange-600"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-orange-900">Onay Bekleniyor</h3>
                        <p class="text-orange-700">Toplantı talebiniz yönetici onayı bekliyor.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Admin Actions for Pending Meetings -->
        <?php if (isAdmin() && $meeting['status'] === 'pending'): ?>
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Yönetici İşlemleri</h3>
                <div class="flex space-x-3">
                    <button onclick="approveMeetingModal(<?php echo $meeting['id']; ?>)" 
                            class="btn-primary bg-green-600 hover:bg-green-700">
                        <i class="fas fa-check mr-2"></i>
                        Onayla
                    </button>
                    <button onclick="rejectMeetingModal(<?php echo $meeting['id']; ?>)" 
                            class="btn-secondary bg-red-600 hover:bg-red-700 text-white">
                        <i class="fas fa-times mr-2"></i>
                        Reddet
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        showToast('Link panoya kopyalandı!', 'success');
    }).catch(function(err) {
        console.error('Clipboard error:', err);
        showToast('Link kopyalanamadı', 'error');
    });
}

// Admin modal functions (if admin)
<?php if (isAdmin() && $meeting['status'] === 'pending'): ?>
function approveMeetingModal(meetingId) {
    // Bu fonksiyon meeting-approvals.php'deki modal'ı açar
    // Basit bir form gönderimi veya AJAX çağrısı yapılabilir
    if (confirm('Bu toplantıyı onaylamak istediğinizden emin misiniz?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'admin/meeting-approvals.php';
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo generateCSRFToken(); ?>';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'approve_meeting';
        
        const meetingInput = document.createElement('input');
        meetingInput.type = 'hidden';
        meetingInput.name = 'meeting_id';
        meetingInput.value = meetingId;
        
        form.appendChild(csrfInput);
        form.appendChild(actionInput);
        form.appendChild(meetingInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function rejectMeetingModal(meetingId) {
    const reason = prompt('Red nedeni (opsiyonel):');
    if (reason !== null) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'admin/meeting-approvals.php';
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo generateCSRFToken(); ?>';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'reject_meeting';
        
        const meetingInput = document.createElement('input');
        meetingInput.type = 'hidden';
        meetingInput.name = 'meeting_id';
        meetingInput.value = meetingId;
        
        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'rejection_reason';
        reasonInput.value = reason;
        
        form.appendChild(csrfInput);
        form.appendChild(actionInput);
        form.appendChild(meetingInput);
        form.appendChild(reasonInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>