<?php
$pageTitle = 'Zoom Toplantıları İçe Aktar';
require_once '../config/config.php';
require_once '../config/auth.php';
require_once '../includes/ZoomAPI.php';

requireLogin();
if (!isAdmin()) {
    redirect('../dashboard.php');
}

$currentUser = getCurrentUser();
$message = '';
$messageType = '';

// Zoom hesaplarını al
try {
    $stmt = $pdo->query("
        SELECT za.*, 
               (SELECT COUNT(*) FROM meetings WHERE zoom_account_id = za.id) as meeting_count
        FROM zoom_accounts za 
        WHERE za.status = 'active' 
        ORDER BY za.name
    ");
    $zoomAccounts = $stmt->fetchAll();
} catch (Exception $e) {
    writeLog("Get zoom accounts error: " . $e->getMessage(), 'error');
    $zoomAccounts = [];
}

// Kullanıcıları al (devretmek için)
try {
    $stmt = $pdo->query("
        SELECT u.id, u.name, u.surname, u.email, d.name as department_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.role = 'user' AND u.status = 'active'
        ORDER BY u.name, u.surname
    ");
    $users = $stmt->fetchAll();
} catch (Exception $e) {
    writeLog("Get users error: " . $e->getMessage(), 'error');
    $users = [];
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content flex-1 p-6">
    <div class="max-w-7xl mx-auto">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center space-x-4">
                <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-indigo-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-download text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Zoom Toplantıları İçe Aktar</h1>
                    <p class="text-gray-600">
                        Zoom hesaplarından mevcut toplantıları çekerek sisteme ekleyin
                    </p>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Import Control Panel -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 mb-8">
            <h2 class="text-xl font-semibold text-gray-900 mb-6">
                <i class="fas fa-cogs mr-2 text-purple-600"></i>
                İçe Aktarma Kontrol Paneli
            </h2>
            
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Zoom Account Selection -->
                <div>
                    <label for="zoom-account" class="block text-sm font-medium text-gray-700 mb-2">
                        Zoom Hesabı Seçin
                    </label>
                    <select id="zoom-account" class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-white text-gray-900">
                        <option value="">Hesap seçin...</option>
                        <?php foreach ($zoomAccounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>" 
                                    data-email="<?php echo htmlspecialchars($account['email']); ?>">
                                <?php echo htmlspecialchars($account['name']); ?> 
                                (<?php echo htmlspecialchars($account['email']); ?>)
                                - <?php echo $account['meeting_count']; ?> toplantı
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Default User Assignment -->
                <div>
                    <label for="target-user" class="block text-sm font-medium text-gray-700 mb-2">
                        Varsayılan Devredilecek Kullanıcı
                    </label>
                    <select id="target-user" class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-white text-gray-900">
                        <option value="">Kullanıcı seçin...</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" 
                                    data-department="<?php echo htmlspecialchars($user['department_name']); ?>">
                                <?php echo htmlspecialchars($user['name'] . ' ' . $user['surname']); ?>
                                (<?php echo htmlspecialchars($user['department_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row sm:space-x-4 space-y-4 sm:space-y-0 mt-6">
                <button id="fetch-meetings" 
                        class="btn-primary px-6 py-3 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-cloud-download-alt mr-2"></i>
                    Zoom Toplantılarını Çek
                </button>
                
                <button id="import-selected" 
                        class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        disabled>
                    <i class="fas fa-plus-circle mr-2"></i>
                    Seçilenleri İçe Aktar
                </button>
                
                <button id="refresh-accounts" 
                        class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg transition-colors">
                    <i class="fas fa-sync-alt mr-2"></i>
                    Hesapları Yenile
                </button>
            </div>
        </div>

        <!-- Import Status -->
        <div id="import-status" class="hidden bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <div class="flex items-center">
                <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600 mr-3"></div>
                <span class="text-blue-800" id="status-text">İşlem yapılıyor...</span>
            </div>
            <div class="mt-2">
                <div class="bg-blue-200 rounded-full h-2">
                    <div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
            </div>
        </div>

        <!-- Zoom Meetings List -->
        <div id="zoom-meetings-container" class="hidden">
            <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 rounded-t-xl">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">
                            <i class="fas fa-list mr-2 text-purple-600"></i>
                            Zoom Toplantıları
                        </h3>
                        <div class="flex items-center space-x-4">
                            <label class="flex items-center text-sm text-gray-600">
                                <input type="checkbox" id="hide-old-meetings" class="mr-2 rounded border-gray-300">
                                <i class="fas fa-history mr-1"></i>Eski toplantıları gizle
                            </label>
                            <label class="flex items-center text-sm text-gray-600">
                                <input type="checkbox" id="hide-existing-meetings" class="mr-2 rounded border-gray-300" checked>
                                <i class="fas fa-eye-slash mr-1"></i>Sistemde var olanları gizle
                            </label>
                            <div class="border-l border-gray-300 pl-4 flex items-center space-x-3">
                                <button id="select-all" class="text-sm text-blue-600 hover:text-blue-500">
                                    <i class="fas fa-check-square mr-1"></i>Tümünü Seç
                                </button>
                                <button id="deselect-all" class="text-sm text-gray-600 hover:text-gray-500">
                                    <i class="fas fa-square mr-1"></i>Seçimi Kaldır
                                </button>
                                <span id="selected-count" class="text-sm text-gray-500">0 toplantı seçili</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="meetings-list" class="divide-y divide-gray-100 max-h-96 overflow-y-auto">
                    <!-- Meetings will be loaded here -->
                </div>
                
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-xl">
                    <div class="flex items-center justify-between text-sm text-gray-600">
                        <span id="total-meetings">Toplam: 0 toplantı</span>
                        <span id="new-meetings">Yeni: 0 toplantı</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Results -->
        <div id="import-results" class="hidden mt-6">
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">
                    <i class="fas fa-check-circle mr-2 text-green-600"></i>
                    İçe Aktarma Sonuçları
                </h3>
                <div id="results-content"></div>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let zoomMeetings = [];
let selectedMeetings = [];
let isProcessing = false;
let filteredMeetings = [];

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Fetch meetings button
    document.getElementById('fetch-meetings').addEventListener('click', fetchZoomMeetings);
    
    // Import selected button
    document.getElementById('import-selected').addEventListener('click', importSelectedMeetings);
    
    // Refresh accounts button
    document.getElementById('refresh-accounts').addEventListener('click', refreshAccounts);
    
    // Select/Deselect all buttons
    document.getElementById('select-all').addEventListener('click', selectAllMeetings);
    document.getElementById('deselect-all').addEventListener('click', deselectAllMeetings);
    
    // Filter checkboxes
    document.getElementById('hide-old-meetings').addEventListener('change', applyFilters);
    document.getElementById('hide-existing-meetings').addEventListener('change', applyFilters);
});

// Fetch Zoom meetings
async function fetchZoomMeetings() {
    const zoomAccountId = document.getElementById('zoom-account').value;
    const targetUserId = document.getElementById('target-user').value;
    
    if (!zoomAccountId) {
        showNotification('Lütfen bir Zoom hesabı seçin', 'error');
        return;
    }
    
    if (!targetUserId) {
        showNotification('Lütfen bir hedef kullanıcı seçin', 'error');
        return;
    }
    
    if (isProcessing) return;
    
    try {
        isProcessing = true;
        showStatus('Zoom toplantıları çekiliyor...', 0);
        
        const response = await fetch('../api/import-zoom-meetings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'fetch_meetings',
                zoom_account_id: zoomAccountId,
                target_user_id: targetUserId,
                csrf_token: '<?php echo generateCSRFToken(); ?>'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            zoomMeetings = data.meetings || [];
            // Tarihe göre sırala (en yeniden en eskiye)
            zoomMeetings.sort((a, b) => new Date(b.start_time) - new Date(a.start_time));
            applyFilters();
            hideStatus();
            
            showNotification(`${zoomMeetings.length} toplantı çekildi`, 'success');
        } else {
            hideStatus();
            showNotification(data.message || 'Toplantılar çekilirken hata oluştu', 'error');
        }
        
    } catch (error) {
        console.error('Fetch error:', error);
        hideStatus();
        showNotification('Bağlantı hatası oluştu', 'error');
    } finally {
        isProcessing = false;
    }
}

// Apply filters function
function applyFilters() {
    const hideOld = document.getElementById('hide-old-meetings').checked;
    const hideExisting = document.getElementById('hide-existing-meetings').checked;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    filteredMeetings = zoomMeetings.filter(meeting => {
        // Eski toplantıları gizle filtresi
        if (hideOld) {
            const meetingDate = new Date(meeting.start_time);
            meetingDate.setHours(0, 0, 0, 0);
            if (meetingDate < today) {
                return false;
            }
        }
        
        // Sistemde var olanları gizle filtresi
        if (hideExisting && meeting.exists_in_system) {
            return false;
        }
        
        return true;
    });
    
    displayMeetings();
}

// Display meetings (gelişmiş filtreleme ile)
function displayMeetings() {
    // Filtreleme aktifse filteredMeetings, değilse zoomMeetings kullan
    const hideOld = document.getElementById('hide-old-meetings').checked;
    const hideExisting = document.getElementById('hide-existing-meetings').checked;
    
    let meetings;
    if (hideOld || hideExisting) {
        meetings = filteredMeetings;
    } else {
        meetings = zoomMeetings;
    }
    
    const container = document.getElementById('zoom-meetings-container');
    const listElement = document.getElementById('meetings-list');
    
    if (meetings.length === 0) {
        listElement.innerHTML = `
            <div class="p-8 text-center text-gray-500">
                <i class="fas fa-calendar-times text-4xl mb-4"></i>
                <p>Filtrelenen kriterlere uygun toplantı bulunamadı</p>
                <p class="text-sm mt-2">Filtreleri kaldırarak daha fazla sonuç görebilirsiniz</p>
            </div>
        `;
    } else {
        listElement.innerHTML = meetings.map(meeting => `
            <div class="p-4 hover:bg-gray-50 transition-colors meeting-item" data-meeting-id="${meeting.id}">
                <div class="flex items-start space-x-4">
                    <input type="checkbox" class="meeting-checkbox mt-1" value="${meeting.id}">
                    <div class="flex-1">
                        <div class="flex items-start justify-between">
                            <div>
                                <h4 class="font-medium text-gray-900">
                                    ${escapeHtml(meeting.topic || 'Başlıksız Toplantı')}
                                    ${meeting.is_recurring_occurrence ?
                                        '<span class="ml-2 inline-flex items-center px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full"><i class="fas fa-calendar-day mr-1"></i>Oturum</span>' :
                                        (meeting.is_recurring_parent ? '<span class="ml-2 inline-flex items-center px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full"><i class="fas fa-repeat mr-1"></i>Ana Toplantı</span>' : '')
                                    }
                                </h4>
                                <div class="flex items-center space-x-4 mt-1 text-sm text-gray-500">
                                    <span><i class="fas fa-calendar mr-1"></i>${formatDate(meeting.start_time)}</span>
                                    <span><i class="fas fa-clock mr-1"></i>${formatTime(meeting.start_time)} (${meeting.duration} dk)</span>
                                    <span><i class="fas fa-key mr-1"></i>ID: ${meeting.id}</span>
                                    ${meeting.is_recurring_occurrence ? `<span><i class="fas fa-link mr-1"></i>Ana: ${meeting.parent_meeting_id}</span>` : ''}
                                    ${meeting.occurrence_id ? `<span><i class="fas fa-hashtag mr-1"></i>Oturum: ${meeting.occurrence_id}</span>` : ''}
                                    ${meeting.recurrence_type ? `<span><i class="fas fa-sync mr-1"></i>Tip: ${meeting.recurrence_type}</span>` : ''}
                                </div>
                                ${meeting.agenda ? `<p class="text-sm text-gray-600 mt-2">${escapeHtml(meeting.agenda)}</p>` : ''}
                                ${meeting.is_recurring_occurrence ?
                                    `<div class="text-xs text-blue-600 mt-1 bg-blue-50 p-2 rounded border-l-4 border-blue-300">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Bu toplantı tekrarlı seride bir oturumdur. Ana toplantı ID: ${meeting.parent_meeting_id}
                                        ${meeting.recurrence_type ? ` | Tekrar tipi: ${meeting.recurrence_type}` : ''}
                                    </div>` :
                                    (meeting.is_recurring_parent ?
                                        `<div class="text-xs text-purple-600 mt-1 bg-purple-50 p-2 rounded border-l-4 border-purple-300">
                                            <i class="fas fa-calendar-plus mr-1"></i>
                                            Bu ana tekrarlı toplantıdır. Oturumlar ayrı ayrı listelenmektedir.
                                        </div>` : ''
                                    )
                                }
                            </div>
                            <div class="flex items-center space-x-2">
                                ${meeting.exists_in_system ?
                                    '<span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded-full"><i class="fas fa-exclamation-triangle mr-1"></i>Mevcut</span>' :
                                    '<span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full"><i class="fas fa-plus mr-1"></i>Yeni</span>'
                                }
                                ${meeting.is_recurring_occurrence ?
                                    '<span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full"><i class="fas fa-calendar-day mr-1"></i>Oturum</span>' : ''
                                }
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
        
        // Add event listeners to checkboxes
        document.querySelectorAll('.meeting-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });
    }
    
    // Update counters
    const newMeetings = meetings.filter(m => !m.exists_in_system).length;
    document.getElementById('total-meetings').textContent = `Toplam: ${meetings.length} toplantı`;
    document.getElementById('new-meetings').textContent = `Yeni: ${newMeetings} toplantı`;
    
    container.classList.remove('hidden');
    updateSelectedCount();
}

// Update selected count
function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.meeting-checkbox:checked');
    const count = checkboxes.length;
    
    selectedMeetings = Array.from(checkboxes).map(cb => cb.value);
    
    document.getElementById('selected-count').textContent = `${count} toplantı seçili`;
    document.getElementById('import-selected').disabled = count === 0;
}

// Select all meetings (sadece görünür olanları)
function selectAllMeetings() {
    document.querySelectorAll('.meeting-checkbox').forEach(checkbox => {
        if (!checkbox.disabled && checkbox.closest('.meeting-item').style.display !== 'none') {
            checkbox.checked = true;
        }
    });
    updateSelectedCount();
}

// Deselect all meetings
function deselectAllMeetings() {
    document.querySelectorAll('.meeting-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    updateSelectedCount();
}

// Import selected meetings with custom modal
async function importSelectedMeetings() {
    if (selectedMeetings.length === 0) {
        showNotification('Lütfen içe aktarılacak toplantıları seçin', 'warning');
        return;
    }
    
    const targetUserId = document.getElementById('target-user').value;
    if (!targetUserId) {
        showNotification('Lütfen bir hedef kullanıcı seçin', 'error');
        return;
    }
    
    if (isProcessing) return;
    
    // Seçilen toplantıların detaylarını hazırla
    const selectedMeetingDetails = selectedMeetings.map(id => {
        // String ve number karşılaştırmasını düzelt
        const meeting = filteredMeetings.find(m => String(m.id) === String(id)) ||
                       zoomMeetings.find(m => String(m.id) === String(id));
        
        return meeting ? {
            id: meeting.id,
            topic: meeting.topic || 'Başlıksız Toplantı',
            start_time: meeting.start_time,
            exists_in_system: meeting.exists_in_system
        } : null;
    }).filter(m => m !== null);
    
    // Modal onay ekranını göster
    const confirmed = await showImportConfirmationModal(selectedMeetingDetails);
    if (!confirmed) {
        return;
    }
    
    try {
        isProcessing = true;
        showStatus('Toplantılar içe aktarılıyor...', 0);
        
        const results = [];
        const total = selectedMeetings.length;
        
        for (let i = 0; i < selectedMeetings.length; i++) {
            const meetingId = selectedMeetings[i];
            
            // String ve number karşılaştırmasını düzelt
            let meeting = filteredMeetings.find(m => String(m.id) === String(meetingId)) ||
                         zoomMeetings.find(m => String(m.id) === String(meetingId));
            
            if (!meeting) {
                results.push({
                    meeting: { id: meetingId, topic: 'Bilinmeyen Toplantı' },
                    result: { success: false, message: 'Toplantı bulunamadı' }
                });
                continue;
            }
            
            updateStatus(`${meeting.topic || 'Toplantı'} aktarılıyor... (${i + 1}/${total})`, ((i + 1) / total) * 100);
            
            const response = await fetch('../api/import-zoom-meetings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'import_meeting',
                    meeting_data: meeting,
                    target_user_id: targetUserId,
                    zoom_account_id: document.getElementById('zoom-account').value,
                    csrf_token: '<?php echo generateCSRFToken(); ?>',
                    debug_mode: true // Debug modu aktif
                })
            });
            
            const result = await response.json();
            results.push({
                meeting: meeting,
                result: result
            });
            
            // Small delay to prevent overwhelming the server
            await new Promise(resolve => setTimeout(resolve, 300));
        }
        
        hideStatus();
        displayImportResults(results);
        
        // Refresh the meetings list after import
        setTimeout(() => {
            fetchZoomMeetings();
        }, 1500);
        
    } catch (error) {
        console.error('Import error:', error);
        hideStatus();
        showNotification('İçe aktarma sırasında hata oluştu', 'error');
    } finally {
        isProcessing = false;
    }
}

// Custom modal for import confirmation
function showImportConfirmationModal(meetings) {
    return new Promise((resolve) => {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
        modal.innerHTML = `
            <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-80vh overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-download text-blue-600 mr-2"></i>
                        Toplantıları İçe Aktar
                    </h3>
                </div>
                <div class="px-6 py-4 max-h-60 overflow-y-auto">
                    <p class="text-gray-700 mb-4">
                        ${meetings.length} toplantıyı sisteme aktarmak istediğinizden emin misiniz?
                    </p>
                    <div class="space-y-2 text-sm">
                        ${meetings.slice(0, 5).map(meeting => `
                            <div class="flex items-center justify-between p-2 bg-gray-50 rounded">
                                <div class="flex-1 truncate">
                                    <span class="font-medium">${escapeHtml(meeting.topic)}</span>
                                    <span class="text-gray-500 ml-2">${formatDate(meeting.start_time)}</span>
                                </div>
                                ${meeting.exists_in_system ?
                                    '<span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded">Mevcut</span>' :
                                    '<span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded">Yeni</span>'
                                }
                            </div>
                        `).join('')}
                        ${meetings.length > 5 ? `
                            <div class="text-center text-gray-500 text-sm py-2">
                                ... ve ${meetings.length - 5} toplantı daha
                            </div>
                        ` : ''}
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex space-x-3 justify-end">
                    <button id="modal-cancel" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        <i class="fas fa-times mr-1"></i>İptal
                    </button>
                    <button id="modal-confirm" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-check mr-1"></i>Evet, İçe Aktar
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Prevent modal from closing on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                // Don't close - user must click button
                return;
            }
        });
        
        // Handle buttons
        document.getElementById('modal-cancel').addEventListener('click', () => {
            document.body.removeChild(modal);
            resolve(false);
        });
        
        document.getElementById('modal-confirm').addEventListener('click', () => {
            document.body.removeChild(modal);
            resolve(true);
        });
        
        // Focus on confirm button
        setTimeout(() => {
            document.getElementById('modal-confirm').focus();
        }, 100);
    });
}

// Display import results
function displayImportResults(results) {
    const successful = results.filter(r => r.result.success).length;
    const failed = results.filter(r => !r.result.success).length;
    
    const resultsHtml = `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div class="bg-green-100 border border-green-200 rounded-lg p-4">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-600 text-xl mr-3"></i>
                    <div>
                        <h4 class="font-semibold text-green-800">Başarılı</h4>
                        <p class="text-green-700">${successful} toplantı içe aktarıldı</p>
                    </div>
                </div>
            </div>
            <div class="bg-red-100 border border-red-200 rounded-lg p-4">
                <div class="flex items-center">
                    <i class="fas fa-times-circle text-red-600 text-xl mr-3"></i>
                    <div>
                        <h4 class="font-semibold text-red-800">Başarısız</h4>
                        <p class="text-red-700">${failed} toplantı aktarılamadı</p>
                    </div>
                </div>
            </div>
        </div>
        
        ${failed > 0 ? `
            <div class="space-y-2">
                <h5 class="font-medium text-gray-900">Hata Detayları:</h5>
                ${results.filter(r => !r.result.success).map(r => `
                    <div class="bg-red-50 border border-red-200 rounded p-3">
                        <div class="font-medium text-red-800">${escapeHtml(r.meeting.topic || 'Başlıksız Toplantı')}</div>
                        <div class="text-sm text-red-600">${escapeHtml(r.result.message || 'Bilinmeyen hata')}</div>
                    </div>
                `).join('')}
            </div>
        ` : ''}
    `;
    
    document.getElementById('results-content').innerHTML = resultsHtml;
    document.getElementById('import-results').classList.remove('hidden');
    
    showNotification(`İçe aktarma tamamlandı: ${successful} başarılı, ${failed} başarısız`, successful > 0 ? 'success' : 'error');
}

// Refresh accounts
async function refreshAccounts() {
    location.reload();
}

// Show status
function showStatus(text, progress) {
    document.getElementById('import-status').classList.remove('hidden');
    document.getElementById('status-text').textContent = text;
    document.getElementById('progress-bar').style.width = progress + '%';
}

// Update status
function updateStatus(text, progress) {
    document.getElementById('status-text').textContent = text;
    document.getElementById('progress-bar').style.width = progress + '%';
}

// Hide status
function hideStatus() {
    document.getElementById('import-status').classList.add('hidden');
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('tr-TR');
}

function formatTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
}

function showNotification(message, type) {
    // Simple notification (can be enhanced)
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full`;
    
    const colors = {
        'success': 'bg-green-600 text-white',
        'error': 'bg-red-600 text-white',
        'warning': 'bg-yellow-600 text-white',
        'info': 'bg-blue-600 text-white'
    };
    
    notification.className += ` ${colors[type] || colors.info}`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);
    
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 300);
    }, 3000);
}
</script>

<?php include '../includes/footer.php'; ?>