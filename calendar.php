<?php
$pageTitle = 'Takvim/Ajanda';
require_once 'config/config.php';
require_once 'config/auth.php';

requireLogin();

$currentUser = getCurrentUser();
$isAdmin = isAdmin();

// Bu ayın toplantılarını getir
$month = cleanInput($_GET['month'] ?? date('Y-m'));
// Geçerli tarih formatını kontrol et
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$currentMonth = $month;
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));

try {
    if ($isAdmin) {
        // Admin tüm toplantıları görür
        $stmt = $pdo->prepare("
            SELECT m.*, u.name, u.surname, d.name as department_name
            FROM meetings m
            JOIN users u ON m.user_id = u.id
            LEFT JOIN departments d ON m.department_id = d.id
            WHERE m.date >= ? AND m.date < ?
            AND m.status IN ('pending', 'approved')
            ORDER BY m.date ASC, m.start_time ASC
        ");
        $stmt->execute([$currentMonth . '-01', $nextMonth . '-01']);
    } else {
        // Normal kullanıcı sadece kendi toplantılarını görür
        $stmt = $pdo->prepare("
            SELECT m.*, d.name as department_name
            FROM meetings m
            LEFT JOIN departments d ON m.department_id = d.id
            WHERE m.user_id = ? AND m.date >= ? AND m.date < ?
            AND m.status IN ('pending', 'approved')
            ORDER BY m.date ASC, m.start_time ASC
        ");
        $stmt->execute([$currentUser['id'], $currentMonth . '-01', $nextMonth . '-01']);
    }
    
    $meetings = $stmt->fetchAll();
    
    // Toplantıları tarihlere göre gruplandır
    $calendarData = [];
    foreach ($meetings as $meeting) {
        $date = $meeting['date'];
        if (!isset($calendarData[$date])) {
            $calendarData[$date] = [];
        }
        $calendarData[$date][] = $meeting;
    }
    
} catch (Exception $e) {
    writeLog("Calendar error: " . $e->getMessage(), 'error');
    $meetings = [];
    $calendarData = [];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content flex-1 p-6">
    <div class="max-w-7xl mx-auto">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center space-x-4">
                <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-teal-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-calendar-alt text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Takvim/Ajanda</h1>
                    <p class="text-gray-600">
                        <?php echo $isAdmin ? 'Tüm toplantıları' : 'Toplantılarınızı'; ?> takvim üzerinde görüntüleyebilirsiniz
                    </p>
                </div>
            </div>
        </div>

        <!-- Calendar Navigation -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-gray-900">
                        <?php echo formatDate($currentMonth . '-01', 'F Y'); ?>
                    </h2>
                    <div class="flex space-x-2">
                        <a href="?month=<?php echo $prevMonth; ?>"
                           class="btn-secondary px-3 py-2 text-sm">
                            <i class="fas fa-chevron-left mr-1"></i>
                            Önceki Ay
                        </a>
                        <a href="calendar.php"
                           class="btn-primary px-3 py-2 text-sm">
                            Bugün
                        </a>
                        <a href="?month=<?php echo date('Y-m', strtotime($currentMonth . '-01 +1 month')); ?>"
                           class="btn-secondary px-3 py-2 text-sm">
                            Sonraki Ay
                            <i class="fas fa-chevron-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Calendar Grid -->
            <div class="p-6">
                <div class="grid grid-cols-7 gap-1 mb-4">
                    <div class="text-center py-2 text-sm font-medium text-gray-500">Pazartesi</div>
                    <div class="text-center py-2 text-sm font-medium text-gray-500">Salı</div>
                    <div class="text-center py-2 text-sm font-medium text-gray-500">Çarşamba</div>
                    <div class="text-center py-2 text-sm font-medium text-gray-500">Perşembe</div>
                    <div class="text-center py-2 text-sm font-medium text-gray-500">Cuma</div>
                    <div class="text-center py-2 text-sm font-medium text-gray-500">Cumartesi</div>
                    <div class="text-center py-2 text-sm font-medium text-gray-500">Pazar</div>
                </div>

                <div class="grid grid-cols-7 gap-1" id="calendar-grid">
                    <?php
                    $monthYear = explode('-', $currentMonth);
                    $year = (int)$monthYear[0];
                    $monthNum = (int)$monthYear[1];
                    
                    $firstDay = mktime(0, 0, 0, $monthNum, 1, $year);
                    $daysInMonth = date('t', $firstDay);
                    $dayOfWeek = date('N', $firstDay) - 1; // 0 = Monday

                    // Empty cells for days before the first day of month
                    for ($i = 0; $i < $dayOfWeek; $i++) {
                        echo '<div class="h-24 border border-gray-200 bg-gray-50"></div>';
                    }

                    // Days of the month
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $date = date('Y-m-d', mktime(0, 0, 0, $monthNum, $day, $year));
                        $dayMeetings = $calendarData[$date] ?? [];
                        $isToday = $date === date('Y-m-d');
                        
                        echo '<div class="h-24 border border-gray-200 p-1 ' . ($isToday ? 'bg-blue-50 border-blue-300' : 'bg-white') . '">';
                        echo '<div class="text-sm font-medium ' . ($isToday ? 'text-blue-600' : 'text-gray-900') . '">' . $day . '</div>';
                        
                        foreach (array_slice($dayMeetings, 0, 2) as $meeting) {
                            $statusColor = $meeting['status'] === 'approved' ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800';
                            echo '<div class="text-xs px-1 py-0.5 mt-1 rounded ' . $statusColor . ' truncate" title="' . htmlspecialchars($meeting['title']) . '">';
                            echo formatTime($meeting['start_time']) . ' ' . htmlspecialchars(substr($meeting['title'], 0, 10)) . '...';
                            echo '</div>';
                        }
                        
                        if (count($dayMeetings) > 2) {
                            echo '<div class="text-xs text-gray-500 mt-1">+' . (count($dayMeetings) - 2) . ' daha</div>';
                        }
                        
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
        </div>

        <!-- Meeting List -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Bu Ayın Toplantıları</h3>
            </div>
            <div class="divide-y divide-gray-200">
                <?php if (!empty($meetings)): ?>
                    <?php foreach ($meetings as $meeting): ?>
                        <div class="p-6 flex items-center justify-between hover:bg-gray-50">
                            <div class="flex items-center space-x-4">
                                <div class="w-3 h-3 rounded-full <?php echo $meeting['status'] === 'approved' ? 'bg-green-500' : 'bg-orange-500'; ?>"></div>
                                <div>
                                    <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($meeting['title']); ?></h4>
                                    <p class="text-sm text-gray-500">
                                        <?php echo formatDateTurkish($meeting['date']); ?> - 
                                        <?php echo formatTime($meeting['start_time']); ?> - <?php echo formatTime($meeting['end_time']); ?>
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        Moderatör: <?php echo htmlspecialchars($meeting['moderator']); ?>
                                        <?php if ($isAdmin && isset($meeting['name'])): ?>
                                            | Talep Eden: <?php echo htmlspecialchars($meeting['name'] . ' ' . $meeting['surname']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="badge badge-<?php echo $meeting['status'] === 'approved' ? 'success' : 'warning'; ?>">
                                    <?php echo $meeting['status'] === 'approved' ? 'Onaylı' : 'Bekliyor'; ?>
                                </span>
                                
                                <!-- Toplantı Detayları Butonu -->
                                <button
                                    onclick="openMeetingModal(<?php echo $meeting['id']; ?>)"
                                    class="text-blue-600 hover:text-blue-500 px-2 py-1 rounded-lg hover:bg-blue-50 transition-colors text-sm"
                                    title="Detayları Gör"
                                >
                                    <i class="fas fa-info-circle mr-1"></i>Detay
                                </button>
                                
                                <?php if ($meeting['status'] === 'approved' && $meeting['user_id'] == $currentUser['id'] && $meeting['zoom_start_url']): ?>
                                    <!-- Başlat Butonu - Sadece Kendi Toplantısı İçin -->
                                    <a href="<?php echo htmlspecialchars($meeting['zoom_start_url']); ?>"
                                       target="_blank"
                                       class="btn-success px-3 py-1 text-sm">
                                        <i class="fas fa-crown mr-1"></i>Başlat
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="p-6 text-center text-gray-500">
                        <i class="fas fa-calendar-times text-4xl mb-4 text-gray-300"></i>
                        <p>Bu ay için toplantı bulunmuyor.</p>
                        <a href="new-meeting.php" class="btn-primary inline-flex items-center px-4 py-2 mt-4">
                            <i class="fas fa-plus mr-2"></i>
                            Yeni Toplantı Talep Et
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Meeting Details Modal -->
<div id="meeting-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9999] flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-xl font-semibold text-gray-900">Toplantı Detayları</h3>
            <button onclick="closeMeetingModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div id="meeting-modal-content" class="p-6">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<?php
$additionalScripts = '
<script>
// Set user ID for modal access control
window.APP_CONFIG = {
    user_id: ' . $currentUser['id'] . ',
    csrf_token: "' . ($_SESSION['csrf_token'] ?? '') . '"
};

// Modal işlemleri
function openMeetingModal(meetingId) {
    var modal = document.getElementById("meeting-modal");
    var content = document.getElementById("meeting-modal-content");
    
    modal.classList.remove("hidden");
    content.innerHTML = "<div class=\"text-center py-8\"><div class=\"loading-spinner mx-auto\"></div><p class=\"mt-4\">Yükleniyor...</p></div>";
    
    fetch("api/meeting-details.php?id=" + meetingId)
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                content.innerHTML = generateMeetingDetails(data.meeting);
            } else {
                content.innerHTML = "<div class=\"text-center py-8 text-red-600\">" + data.message + "</div>";
            }
        })
        .catch(function(error) {
            console.error("Meeting details error:", error);
            content.innerHTML = "<div class=\"text-center py-8 text-red-600\">Detaylar yüklenirken hata oluştu.</div>";
        });
}

function closeMeetingModal() {
    document.getElementById("meeting-modal").classList.add("hidden");
}

function generateMeetingDetails(meeting) {
    var statusColors = {
        pending: "text-orange-600 bg-orange-100",
        approved: "text-green-600 bg-green-100",
        rejected: "text-red-600 bg-red-100",
        cancelled: "text-gray-600 bg-gray-100"
    };
    
    var statusLabels = {
        pending: "Bekliyor",
        approved: "Onaylı",
        rejected: "Reddedildi",
        cancelled: "İptal Edildi"
    };
    
    var html = "<div class=\"space-y-6\">" +
            "<div class=\"grid grid-cols-1 md:grid-cols-2 gap-6\">" +
                "<div>" +
                    "<h4 class=\"text-sm font-medium text-gray-500 mb-2\">Toplantı Başlığı</h4>" +
                    "<p class=\"text-lg font-semibold text-gray-900\">" + meeting.title + "</p>" +
                "</div>" +
                "<div>" +
                    "<h4 class=\"text-sm font-medium text-gray-500 mb-2\">Durum</h4>" +
                    "<span class=\"inline-flex items-center px-3 py-1 rounded-full text-sm font-medium " + (statusColors[meeting.status] || statusColors.pending) + "\">" +
                        (statusLabels[meeting.status] || meeting.status) +
                    "</span>" +
                "</div>" +
            "</div>" +
            "<div class=\"grid grid-cols-1 md:grid-cols-3 gap-6\">" +
                "<div>" +
                    "<h4 class=\"text-sm font-medium text-gray-500 mb-2\">Tarih</h4>" +
                    "<p class=\"text-gray-900\">" + new Date(meeting.date).toLocaleDateString("tr-TR") + "</p>" +
                "</div>" +
                "<div>" +
                    "<h4 class=\"text-sm font-medium text-gray-500 mb-2\">Başlangıç</h4>" +
                    "<p class=\"text-gray-900\">" + meeting.start_time + "</p>" +
                "</div>" +
                "<div>" +
                    "<h4 class=\"text-sm font-medium text-gray-500 mb-2\">Bitiş</h4>" +
                    "<p class=\"text-gray-900\">" + meeting.end_time + "</p>" +
                "</div>" +
            "</div>" +
            "<div class=\"grid grid-cols-1 md:grid-cols-2 gap-6\">" +
                "<div>" +
                    "<h4 class=\"text-sm font-medium text-gray-500 mb-2\">Moderatör</h4>" +
                    "<p class=\"text-gray-900\">" + meeting.moderator + "</p>" +
                "</div>" +
                "<div>" +
                    "<h4 class=\"text-sm font-medium text-gray-500 mb-2\">Katılımcı Sayısı</h4>" +
                    "<p class=\"text-gray-900\">" + (meeting.participants_count || "Belirtilmemiş") + "</p>" +
                "</div>" +
            "</div>";
    
    if (meeting.description) {
        html += "<div>" +
                "<h4 class=\"text-sm font-medium text-gray-500 mb-2\">Açıklama</h4>" +
                "<p class=\"text-gray-900 whitespace-pre-wrap\">" + meeting.description + "</p>" +
            "</div>";
    }
    
    if (meeting.status === "approved" && (meeting.zoom_join_url || meeting.meeting_link)) {
        html += "<div class=\"bg-green-50 border border-green-200 rounded-lg p-4\">" +
                "<h4 class=\"text-sm font-medium text-green-800 mb-3\">Toplantı Bilgileri</h4>" +
                "<div class=\"space-y-3\">";
        
        // Meeting ID with copy button
        var meetingId = meeting.zoom_meeting_id || meeting.meeting_id || "Bilinmiyor";
        if (meetingId !== "Bilinmiyor") {
            html += "<div class=\"flex items-center justify-between bg-white p-3 rounded border\">" +
                        "<div>" +
                            "<p class=\"text-xs text-gray-500 mb-1\">Meeting ID</p>" +
                            "<p class=\"text-sm font-mono text-gray-900\">" + meetingId + "</p>" +
                        "</div>" +
                        "<button onclick=\"simpleCopyToClipboard(" + JSON.stringify(meetingId) + ")\" " +
                               "class=\"p-2 text-gray-400 hover:text-green-600 transition-colors\" title=\"Meeting ID Kopyala\">" +
                            "<i class=\"fas fa-copy\"></i>" +
                        "</button>" +
                    "</div>";
        }
        
        // Password with copy button
        if (meeting.zoom_password) {
            html += "<div class=\"flex items-center justify-between bg-white p-3 rounded border\">" +
                        "<div>" +
                            "<p class=\"text-xs text-gray-500 mb-1\">Toplantı Şifresi</p>" +
                            "<p class=\"text-sm font-mono text-gray-900\">" + meeting.zoom_password + "</p>" +
                        "</div>" +
                        "<button onclick=\"simpleCopyToClipboard(" + JSON.stringify(meeting.zoom_password) + ")\" " +
                               "class=\"p-2 text-gray-400 hover:text-green-600 transition-colors\" title=\"Şifre Kopyala\">" +
                            "<i class=\"fas fa-copy\"></i>" +
                        "</button>" +
                    "</div>";
        }
        
        // Join URL with copy button
        var joinUrl = meeting.zoom_join_url || meeting.meeting_link;
        if (joinUrl) {
            html += "<div class=\"flex items-center justify-between bg-white p-3 rounded border\">" +
                        "<div class=\"flex-1 min-w-0\">" +
                            "<p class=\"text-xs text-gray-500 mb-1\">Katılımcı Linki</p>" +
                            "<p class=\"text-sm text-gray-900 truncate\">" + joinUrl + "</p>" +
                        "</div>" +
                        "<button onclick=\"simpleCopyToClipboard(" + JSON.stringify(joinUrl) + ")\" " +
                               "class=\"p-2 ml-2 text-gray-400 hover:text-green-600 transition-colors\" title=\"Katılımcı Link Kopyala\">" +
                            "<i class=\"fas fa-copy\"></i>" +
                        "</button>" +
                    "</div>";
        }
        
        // Host URL - Only for meeting owner
        if (meeting.zoom_start_url && meeting.user_id == window.APP_CONFIG.user_id) {
            html += "<div class=\"flex items-center justify-between bg-white p-3 rounded border\">" +
                        "<div class=\"flex-1 min-w-0\">" +
                            "<p class=\"text-xs text-gray-500 mb-1\">Admin Linki (Host)</p>" +
                            "<p class=\"text-sm text-gray-900 truncate\">" + meeting.zoom_start_url + "</p>" +
                        "</div>" +
                        "<button onclick=\"simpleCopyToClipboard(" + JSON.stringify(meeting.zoom_start_url) + ")\" " +
                               "class=\"p-2 ml-2 text-gray-400 hover:text-green-600 transition-colors\" title=\"Admin Link Kopyala\">" +
                            "<i class=\"fas fa-copy\"></i>" +
                        "</button>" +
                    "</div>";
        }
        
        // Action buttons - Sadece kendi toplantısı için
        if (meeting.user_id == window.APP_CONFIG.user_id) {
            html += "<div class=\"flex gap-2 pt-2\">";
            
            // Başlat butonu - Sadece toplantı sahibi için
            if (meeting.zoom_start_url) {
                html += "<a href=\"" + meeting.zoom_start_url + "\" target=\"_blank\" " +
                           "class=\"flex-1 inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700 transition-colors\">" +
                            "<i class=\"fas fa-crown mr-2\"></i>Başlat (Admin)" +
                        "</a>";
            }
            
            html += "</div>";
        }
        html += "</div></div>";
    }
    
    if (meeting.status === "rejected" && meeting.rejection_reason) {
        html += "<div class=\"bg-red-50 border border-red-200 rounded-lg p-4\">" +
                "<h4 class=\"text-sm font-medium text-red-800 mb-2\">Red Nedeni</h4>" +
                "<p class=\"text-sm text-red-700\">" + meeting.rejection_reason + "</p>" +
            "</div>";
    }
    
    html += "<div class=\"text-xs text-gray-500 border-t border-gray-200 pt-4\">" +
                "Oluşturulma: " + new Date(meeting.created_at).toLocaleString("tr-TR") +
            "</div>" +
        "</div>";
    
    return html;
}

// Updated copy function from my-meetings.php
function simpleCopyToClipboard(text) {
    console.log("COPY FUNCTION CALLED");
    
    // Modern Clipboard API deneyelim (HTTPS gerekli)
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            console.log("Modern clipboard SUCCESS");
            showSuccessMessage(null, "Kopyalandı!");
        }).catch(function(err) {
            console.error("Clipboard API failed, trying fallback:", err);
            tryLegacyCopy(text, null);
        });
    } else {
        // Fallback method (HTTP ve eski tarayıcılar için)
        console.log("Using legacy copy method");
        tryLegacyCopy(text, null);
    }
}

function tryLegacyCopy(text, button) {
    try {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-9999px";
        textArea.style.top = "-9999px";
        document.body.appendChild(textArea);
        
        textArea.focus();
        textArea.select();
        
        const successful = document.execCommand("copy");
        
        document.body.removeChild(textArea);
        
        if (successful) {
            console.log("Legacy copy SUCCESS");
            showSuccessMessage(button, "Kopyalandı!");
        } else {
            console.error("Legacy copy FAILED");
            showErrorMessage("Kopyalama başarısız oldu");
        }
    } catch (err) {
        console.error("Legacy copy ERROR:", err);
        showErrorMessage("Tarayıcınız kopyalama işlemini desteklemiyor");
    }
}

function showSuccessMessage(button, message) {
    showToast(message, "success");
    
    if (button) {
        // Button feedback
        const originalHtml = button.innerHTML;
        button.innerHTML = "<i class=\"fas fa-check text-green-600\"></i>";
        button.classList.add("bg-green-50");
        
        setTimeout(function() {
            button.innerHTML = originalHtml;
            button.classList.remove("bg-green-50");
        }, 1500);
    }
}

function showErrorMessage(message) {
    showToast(message, "error");
}

// Toast notification
function showToast(message, type = "info") {
    const existingToast = document.querySelector(".toast-notification");
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement("div");
    toast.className = "toast-notification toast-" + type;
    toast.textContent = message;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add("show");
    }, 100);
    
    setTimeout(() => {
        toast.classList.remove("show");
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }, 3000);
}

// Modal dışına tıklayınca kapat
document.getElementById("meeting-modal").addEventListener("click", function(e) {
    if (e.target === this) {
        closeMeetingModal();
    }
});

// ESC tuşu ile kapat
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") {
        closeMeetingModal();
    }
});
</script>

<!-- Toast Notification Styles -->
<style>
.toast-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 6px;
    color: white;
    font-weight: 500;
    z-index: 10000;
    transform: translateX(100%);
    transition: transform 0.3s ease;
    max-width: 300px;
    word-wrap: break-word;
}

.toast-notification.show {
    transform: translateX(0);
}

.toast-success {
    background-color: #10b981;
}

.toast-error {
    background-color: #ef4444;
}

.toast-info {
    background-color: #3b82f6;
}

.loading-spinner {
    border: 4px solid #f3f4f6;
    border-top: 4px solid #3b82f6;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 2s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
';

echo $additionalScripts;
include 'includes/footer.php';
?>