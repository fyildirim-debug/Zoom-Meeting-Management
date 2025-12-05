<?php
/**
 * Kullanıcı - Toplantı Kayıtlarım
 * 
 * Kullanıcı kendi geçmiş toplantılarının Zoom Cloud kayıtlarını görebilir
 */

require_once 'config/config.php';
require_once 'config/auth.php';
require_once 'includes/ZoomAPI.php';

// Oturum kontrolü
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$currentUser = getCurrentUser();
$pageTitle = 'Toplantı Kayıtlarım';

// Filtreler
$dateFrom = $_GET['from'] ?? date('Y-m-01'); // Ayın başı
$dateTo = $_GET['to'] ?? date('Y-m-d');
$searchQuery = $_GET['search'] ?? '';

// Kullanıcının geçmiş toplantılarını çek
try {
    $sql = "SELECT m.*, d.name as department_name
            FROM meetings m
            LEFT JOIN departments d ON m.department_id = d.id
            WHERE m.status = 'approved' 
            AND m.date <= CURDATE()
            AND m.date >= ?
            AND m.date <= ?
            AND m.user_id = ?";
    
    $params = [$dateFrom, $dateTo, $currentUser['id']];
    
    if ($searchQuery) {
        $sql .= " AND (m.title LIKE ? OR m.moderator LIKE ? OR m.zoom_meeting_id LIKE ?)";
        $searchParam = "%{$searchQuery}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY m.date DESC, m.start_time DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $meetings = $stmt->fetchAll();
} catch (Exception $e) {
    $meetings = [];
    $error = $e->getMessage();
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content flex-1 p-6">
    <div class="max-w-4xl mx-auto">
        <!-- Başlık -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 mb-6">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-film text-purple-600 mr-3"></i>
                        Toplantı Kayıtlarım
                    </h1>
                    <p class="text-gray-600 mt-1">Geçmiş toplantılarınızın Zoom Cloud kayıtlarını görüntüleyin</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-3 py-1 bg-purple-100 text-purple-700 rounded-full text-sm font-medium">
                        <i class="fas fa-calendar mr-1"></i>
                        <?php echo count($meetings); ?> toplantı
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Filtreler -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Başlangıç Tarihi</label>
                    <input type="date" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bitiş Tarihi</label>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($dateTo); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Arama</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>"
                           placeholder="Toplantı adı..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                        <i class="fas fa-filter mr-1"></i> Filtrele
                    </button>
                    <a href="my-recordings.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Toplantı Listesi -->
        <?php if (empty($meetings)): ?>
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-12 text-center">
                <i class="fas fa-video-slash text-gray-300 text-6xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-700 mb-2">Kayıt Bulunamadı</h3>
                <p class="text-gray-500">Seçilen tarih aralığında geçmiş toplantınız bulunmuyor.</p>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($meetings as $meeting): ?>
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 hover:shadow-xl transition-shadow">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-2">
                                    <h3 class="text-lg font-semibold text-gray-900">
                                        <?php echo htmlspecialchars($meeting['title']); ?>
                                    </h3>
                                    <?php if ($meeting['zoom_meeting_id']): ?>
                                        <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded-full">
                                            Zoom ID: <?php echo htmlspecialchars($meeting['zoom_meeting_id']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600">
                                    <span class="flex items-center">
                                        <i class="fas fa-calendar text-gray-400 mr-1"></i>
                                        <?php echo date('d.m.Y', strtotime($meeting['date'])); ?>
                                    </span>
                                    <span class="flex items-center">
                                        <i class="fas fa-clock text-gray-400 mr-1"></i>
                                        <?php echo substr($meeting['start_time'], 0, 5); ?> - <?php echo substr($meeting['end_time'], 0, 5); ?>
                                    </span>
                                    <span class="flex items-center">
                                        <i class="fas fa-user text-gray-400 mr-1"></i>
                                        <?php echo htmlspecialchars($meeting['moderator']); ?>
                                    </span>
                                    <?php if ($meeting['department_name']): ?>
                                    <span class="flex items-center">
                                        <i class="fas fa-building text-gray-400 mr-1"></i>
                                        <?php echo htmlspecialchars($meeting['department_name']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-2">
                                <?php if ($meeting['zoom_meeting_id']): ?>
                                    <button onclick="loadRecordings(<?php echo $meeting['id']; ?>, '<?php echo htmlspecialchars($meeting['zoom_meeting_id']); ?>')"
                                            class="inline-flex items-center px-4 py-2 bg-purple-600 text-white text-sm font-medium rounded-lg hover:bg-purple-700 transition-colors"
                                            id="btn-<?php echo $meeting['id']; ?>">
                                        <i class="fas fa-video mr-2"></i>Kayıtları Gör
                                    </button>
                                <?php else: ?>
                                    <span class="px-4 py-2 bg-gray-100 text-gray-500 text-sm rounded-lg">
                                        <i class="fas fa-exclamation-circle mr-1"></i>Zoom ID yok
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Kayıtlar Container -->
                        <div id="recordings-<?php echo $meeting['id']; ?>" class="hidden mt-4 pt-4 border-t border-gray-200">
                            <div class="flex items-center justify-center py-8">
                                <i class="fas fa-spinner fa-spin text-purple-600 mr-2"></i>
                                <span class="text-gray-600">Kayıtlar yükleniyor...</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Kayıtları yükle
async function loadRecordings(meetingId, zoomMeetingId) {
    const container = document.getElementById('recordings-' + meetingId);
    const button = document.getElementById('btn-' + meetingId);
    
    if (!container) return;
    
    // Toggle
    if (!container.classList.contains('hidden') && container.dataset.loaded === 'true') {
        container.classList.add('hidden');
        button.innerHTML = '<i class="fas fa-video mr-2"></i>Kayıtları Gör';
        return;
    }
    
    // Show container and loading
    container.classList.remove('hidden');
    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Yükleniyor...';
    button.disabled = true;
    
    try {
        const response = await fetch('api/get-recordings.php?meeting_id=' + encodeURIComponent(zoomMeetingId));
        const data = await response.json();
        
        console.log('Recordings API response:', data);
        
        if (data.success && data.recordings && data.recordings.length > 0) {
            let html = '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
            
            // Şifre bilgisi (varsa)
            if (data.password) {
                html += '<div class="col-span-full bg-yellow-50 border border-yellow-300 rounded-lg p-3 mb-2">' +
                            '<div class="flex items-center justify-between">' +
                                '<div class="flex items-center">' +
                                    '<i class="fas fa-key text-yellow-600 mr-2"></i>' +
                                    '<span class="text-sm font-medium text-yellow-800">Kayıt Şifresi:</span>' +
                                '</div>' +
                                '<div class="flex items-center gap-2">' +
                                    '<code class="px-3 py-1 bg-yellow-100 text-yellow-900 rounded font-mono text-sm font-bold">' + data.password + '</code>' +
                                    '<button onclick="copyPassword(\'' + data.password + '\')" class="px-2 py-1 bg-yellow-600 text-white text-xs rounded hover:bg-yellow-700" title="Şifreyi Kopyala">' +
                                        '<i class="fas fa-copy"></i>' +
                                    '</button>' +
                                '</div>' +
                            '</div>' +
                        '</div>';
            }
            
            // Kayıtlar
            html += '<div class="bg-purple-50 border border-purple-200 rounded-lg p-4">' +
                    '<h5 class="text-sm font-semibold text-purple-800 mb-3 flex items-center">' +
                        '<i class="fas fa-video mr-2"></i>Kayıt Dosyaları (' + data.recordings.length + ')' +
                    '</h5>' +
                    '<div class="space-y-2">';
            
            data.recordings.forEach(function(recording) {
                const fileSize = recording.file_size ? formatFileSize(recording.file_size) : '';
                
                html += '<div class="bg-white p-3 rounded border border-purple-100">' +
                            '<div class="flex items-center justify-between">' +
                                '<div class="flex items-center">' +
                                    '<i class="fas fa-file-video text-purple-500 mr-2"></i>' +
                                    '<div>' +
                                        '<p class="text-sm font-medium text-gray-800">' + (recording.recording_type || 'Video') + '</p>' +
                                        '<p class="text-xs text-gray-500">' + fileSize + '</p>' +
                                    '</div>' +
                                '</div>' +
                                '<div class="flex gap-1">';
                
                if (recording.play_url) {
                    html += '<a href="' + recording.play_url + '" target="_blank" ' +
                               'class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700" title="İzle">' +
                                '<i class="fas fa-play"></i>' +
                            '</a>';
                }
                
                if (recording.download_url) {
                    html += '<a href="' + recording.download_url + '" target="_blank" ' +
                               'class="px-2 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700" title="İndir">' +
                                '<i class="fas fa-download"></i>' +
                            '</a>';
                }
                
                html += '</div></div></div>';
            });
            
            html += '</div></div>';
            
            // Rapor (varsa)
            if (data.report) {
                html += '<div class="bg-blue-50 border border-blue-200 rounded-lg p-4">' +
                            '<h5 class="text-sm font-semibold text-blue-800 mb-3 flex items-center">' +
                                '<i class="fas fa-chart-bar mr-2"></i>Toplantı Raporu' +
                            '</h5>' +
                            '<div class="grid grid-cols-2 gap-3">' +
                                '<div class="bg-white p-3 rounded text-center">' +
                                    '<p class="text-2xl font-bold text-blue-600">' + (data.report.participants_count || 0) + '</p>' +
                                    '<p class="text-xs text-gray-500">Katılımcı</p>' +
                                '</div>' +
                                '<div class="bg-white p-3 rounded text-center">' +
                                    '<p class="text-2xl font-bold text-blue-600">' + (data.report.duration || 0) + '</p>' +
                                    '<p class="text-xs text-gray-500">Dakika</p>' +
                                '</div>' +
                            '</div>' +
                        '</div>';
            }
            
            // Toplantı bilgisi
            if (data.meeting_info && data.meeting_info.topic) {
                html += '<div class="col-span-full bg-gray-50 border border-gray-200 rounded-lg p-3">' +
                            '<p class="text-xs text-gray-500">' +
                                '<strong>Toplam Boyut:</strong> ' + formatFileSize(data.meeting_info.total_size || 0) +
                            '</p>' +
                        '</div>';
            }
            
            html += '</div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div class="bg-gray-50 border border-gray-200 rounded-lg p-6 text-center">' +
                                    '<i class="fas fa-info-circle text-gray-400 text-3xl mb-2"></i>' +
                                    '<p class="text-gray-600">Bu toplantı için Zoom Cloud kaydı bulunamadı.</p>' +
                                    '<p class="text-xs text-gray-400 mt-1">' + (data.message || 'Kayıt özelliği aktif olmayabilir.') + '</p>' +
                                  '</div>';
        }
        
        container.dataset.loaded = 'true';
        button.innerHTML = '<i class="fas fa-eye-slash mr-2"></i>Gizle';
        button.disabled = false;
        
    } catch (error) {
        console.error('Error loading recordings:', error);
        container.innerHTML = '<div class="bg-red-50 border border-red-200 rounded-lg p-6 text-center">' +
                                '<i class="fas fa-exclamation-circle text-red-400 text-3xl mb-2"></i>' +
                                '<p class="text-red-600">Kayıtlar yüklenirken hata oluştu.</p>' +
                                '<p class="text-xs text-red-400 mt-1">' + error.message + '</p>' +
                              '</div>';
        button.innerHTML = '<i class="fas fa-video mr-2"></i>Tekrar Dene';
        button.disabled = false;
    }
}

// Dosya boyutu formatla
function formatFileSize(bytes) {
    if (!bytes) return '';
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
}

// Şifreyi panoya kopyala
function copyPassword(password) {
    navigator.clipboard.writeText(password).then(function() {
        // Toast bildirimi göster
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-4 right-4 bg-green-600 text-white px-4 py-2 rounded-lg shadow-lg z-50 animate-pulse';
        toast.innerHTML = '<i class="fas fa-check mr-2"></i>Şifre kopyalandı!';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    }).catch(function(err) {
        alert('Kopyalama başarısız: ' + err);
    });
}
</script>

<?php include 'includes/footer.php'; ?>
