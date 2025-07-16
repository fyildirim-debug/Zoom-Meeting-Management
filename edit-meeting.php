<?php
$pageTitle = 'Toplantı Düzenle';
require_once 'config/config.php';
require_once 'config/auth.php';

requireLogin();

$currentUser = getCurrentUser();
$meetingId = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';

if (!$meetingId) {
    header('Location: my-meetings.php');
    exit;
}

// Toplantı bilgilerini al
try {
    $stmt = $pdo->prepare("
        SELECT * FROM meetings 
        WHERE id = ? AND user_id = ? AND status = 'pending'
    ");
    $stmt->execute([$meetingId, $currentUser['id']]);
    $meeting = $stmt->fetch();
    
    if (!$meeting) {
        header('Location: my-meetings.php?error=' . urlencode('Bu toplantıya sadece toplantıyı oluşturan kullanıcı erişebilir.'));
        exit;
    }
} catch (Exception $e) {
    writeLog("Edit meeting fetch error: " . $e->getMessage(), 'error');
    header('Location: my-meetings.php?error=' . urlencode('Toplantı bilgileri alınırken hata oluştu.'));
    exit;
}

// Form işleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF kontrolü
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Güvenlik hatası. Lütfen sayfayı yenileyin.';
    } else {
        $title = cleanInput($_POST['title'] ?? '');
        $date = cleanInput($_POST['date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $moderator = cleanInput($_POST['moderator'] ?? '');
        $description = cleanInput($_POST['description'] ?? '');
        $participantsCount = (int)($_POST['participants_count'] ?? 0);
        
        // Validation
        if (empty($title) || empty($date) || empty($startTime) || empty($endTime) || empty($moderator)) {
            $error = 'Lütfen zorunlu alanları doldurun.';
        } elseif (!validateDate($date)) {
            $error = 'Geçerli bir tarih girin.';
        } elseif (!validateTime($startTime) || !validateTime($endTime)) {
            $error = 'Geçerli saat formatı girin (HH:MM).';
        } elseif (strtotime($date . ' ' . $startTime) <= time()) {
            $error = 'Geçmiş tarih için toplantı oluşturamazsınız.';
        } elseif ($startTime >= $endTime) {
            $error = 'Bitiş saati başlangıç saatinden sonra olmalıdır.';
        } else {
            // Çakışma kontrolleri (kendi toplantısını hariç tut)
            if (checkUserConflict($currentUser['id'], $date, $startTime, $endTime, $meetingId)) {
                $error = 'Bu tarih ve saatte zaten başka bir toplantınız bulunuyor.';
            } elseif (!checkDepartmentWeeklyLimit($currentUser['department_id'], $date, $meetingId)) {
                $error = 'Biriminizin haftalık toplantı limiti dolmuş.';
            } else {
                // Toplantıyı güncelle
                try {
                    $stmt = $pdo->prepare("
                        UPDATE meetings SET
                            title = ?, date = ?, start_time = ?, end_time = ?,
                            moderator = ?, description = ?, participants_count = ?
                        WHERE id = ? AND user_id = ?
                    ");
                    
                    $result = $stmt->execute([
                        $title, $date, $startTime, $endTime, $moderator, 
                        $description, $participantsCount, $meetingId, $currentUser['id']
                    ]);
                    
                    if ($result) {
                        writeLog("Meeting updated: ID $meetingId by user " . $currentUser['id'], 'info');
                        header('Location: my-meetings.php?success=' . urlencode('Toplantı başarıyla güncellendi.'));
                        exit;
                    } else {
                        $error = 'Toplantı güncellenirken hata oluştu.';
                    }
                } catch (Exception $e) {
                    writeLog("Error updating meeting: " . $e->getMessage(), 'error');
                    $error = 'Veritabanı hatası oluştu.';
                }
            }
        }
    }
} else {
    // Form verilerini mevcut toplantı bilgileriyle doldur
    $title = $meeting['title'];
    $date = $meeting['date'];
    $startTime = $meeting['start_time'];
    $endTime = $meeting['end_time'];
    $moderator = $meeting['moderator'];
    $description = $meeting['description'];
    $participantsCount = $meeting['participants_count'];
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
                <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-edit text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Toplantı Düzenle</h1>
                    <p class="text-gray-600">Toplantı bilgilerinizi düzenleyin</p>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-3"></i>
                    <?php echo $error; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Meeting Form -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">Toplantı Bilgileri</h2>
                <p class="text-sm text-gray-600 mt-1">* ile işaretli alanlar zorunludur</p>
            </div>
            
            <form method="POST" class="p-6 space-y-6" id="meeting-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <!-- Meeting Title -->
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                        Toplantı Başlığı *
                    </label>
                    <input 
                        type="text" 
                        id="title" 
                        name="title" 
                        value="<?php echo htmlspecialchars($title ?? ''); ?>"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-gray-900"
                        placeholder="Örn: Haftalık Proje Değerlendirme Toplantısı"
                        required
                        maxlength="255"
                    >
                </div>

                <!-- Date and Time -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Date -->
                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700 mb-2">
                            Tarih *
                        </label>
                        <input 
                            type="date" 
                            id="date" 
                            name="date" 
                            value="<?php echo htmlspecialchars($date ?? ''); ?>"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-gray-900"
                            required
                            min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                        >
                        <p class="text-xs text-gray-500 mt-1">Tüm günler için toplantı planlanabilir</p>
                    </div>

                    <!-- Start Time -->
                    <div>
                        <label for="start_time" class="block text-sm font-medium text-gray-700 mb-2">
                            Başlangıç Saati *
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <select id="start_hour" name="start_hour" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-gray-900">
                                    <option value="">Saat</option>
                                    <?php
                                    $selectedStartHour = '';
                                    $selectedStartMinute = '';
                                    if (!empty($startTime)) {
                                        list($selectedStartHour, $selectedStartMinute) = explode(':', $startTime);
                                    }
                                    for ($i = 0; $i < 24; $i++):
                                        $hour = str_pad($i, 2, '0', STR_PAD_LEFT);
                                        $selected = ($selectedStartHour === $hour) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $hour; ?>" <?php echo $selected; ?>><?php echo $hour; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <select id="start_minute" name="start_minute" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-gray-900">
                                    <option value="">Dakika</option>
                                    <?php
                                    $minutes = ['00', '15', '30', '45'];
                                    foreach ($minutes as $minute):
                                        $selected = ($selectedStartMinute === $minute) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $minute; ?>" <?php echo $selected; ?>><?php echo $minute; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" id="start_time" name="start_time" value="<?php echo htmlspecialchars($startTime ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <p class="text-xs text-gray-500 mt-1">Sadece 15 dakika aralıklarla seçim yapılabilir</p>
                    </div>

                    <!-- End Time -->
                    <div>
                        <label for="end_time" class="block text-sm font-medium text-gray-700 mb-2">
                            Bitiş Saati *
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <select id="end_hour" name="end_hour" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-gray-900">
                                    <option value="">Saat</option>
                                    <?php
                                    $selectedEndHour = '';
                                    $selectedEndMinute = '';
                                    if (!empty($endTime)) {
                                        list($selectedEndHour, $selectedEndMinute) = explode(':', $endTime);
                                    }
                                    for ($i = 0; $i < 24; $i++):
                                        $hour = str_pad($i, 2, '0', STR_PAD_LEFT);
                                        $selected = ($selectedEndHour === $hour) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $hour; ?>" <?php echo $selected; ?>><?php echo $hour; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <select id="end_minute" name="end_minute" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-gray-900">
                                    <option value="">Dakika</option>
                                    <?php
                                    foreach ($minutes as $minute):
                                        $selected = ($selectedEndMinute === $minute) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $minute; ?>" <?php echo $selected; ?>><?php echo $minute; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" id="end_time" name="end_time" value="<?php echo htmlspecialchars($endTime ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <p class="text-xs text-gray-500 mt-1">Sadece 15 dakika aralıklarla seçim yapılabilir</p>
                    </div>
                </div>

                <!-- Duration Display -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <i class="fas fa-clock text-blue-600 mr-3"></i>
                        <span class="text-blue-800">
                            Toplantı Süresi: <span id="duration-display" class="font-semibold">-</span>
                        </span>
                    </div>
                </div>

                <!-- Moderator -->
                <div>
                    <label for="moderator" class="block text-sm font-medium text-gray-700 mb-2">
                        Moderatör Ad Soyad *
                    </label>
                    <input 
                        type="text" 
                        id="moderator" 
                        name="moderator" 
                        value="<?php echo htmlspecialchars($moderator ?? ''); ?>"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-gray-900"
                        placeholder="Toplantı moderatörünün adı soyadı"
                        required
                        maxlength="255"
                    >
                </div>

                <!-- Participants Count -->
                <div>
                    <label for="participants_count" class="block text-sm font-medium text-gray-700 mb-2">
                        Tahmini Katılımcı Sayısı
                    </label>
                    <input 
                        type="number" 
                        id="participants_count" 
                        name="participants_count" 
                        value="<?php echo htmlspecialchars($participantsCount ?? ''); ?>"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-gray-900"
                        placeholder="0"
                        min="1"
                        max="500"
                    >
                    <p class="text-xs text-gray-500 mt-1">Zoom hesap seçimi için önemlidir</p>
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                        Toplantı Açıklaması
                    </label>
                    <textarea 
                        id="description" 
                        name="description" 
                        rows="4"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-gray-900"
                        placeholder="Toplantının amacı, gündem maddeleri ve diğer önemli bilgiler..."
                        maxlength="1000"
                    ><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                    <p class="text-xs text-gray-500 mt-1">
                        <span id="description-count">0</span>/1000 karakter
                    </p>
                </div>

                <!-- Working Hours Info -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-yellow-600 mr-3 mt-0.5"></i>
                        <div class="text-sm text-yellow-800">
                            <p class="font-medium">Önemli Bilgiler:</p>
                            <ul class="mt-2 space-y-1 list-disc list-inside">
                                <li>Aynı tarih ve saatte başka toplantınız olamaz</li>
                                <li>Değişiklikler sonrasında yeniden onay gerekebilir</li>
                                <li>Toplantılar 7/24 planlanabilir</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-4 sm:space-y-0 pt-6 border-t border-gray-200">
                    <div class="flex items-center space-x-4">
                        <button 
                            type="button" 
                            onclick="checkAvailability()" 
                            class="btn-secondary px-4 py-2 text-sm"
                            id="check-availability-btn"
                        >
                            <i class="fas fa-search mr-2"></i>
                            Uygunluk Kontrol Et
                        </button>
                        <span id="availability-result" class="text-sm"></span>
                    </div>
                    
                    <div class="flex space-x-4">
                        <a href="my-meetings.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                            İptal
                        </a>
                        <button 
                            type="submit" 
                            class="btn-primary px-6 py-3"
                            id="submit-btn"
                        >
                            <i class="fas fa-save mr-2"></i>
                            Değişiklikleri Kaydet
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// PHP sabitlerini güvenli değişkenlere ata
$workStartTime = defined('WORK_START') ? WORK_START : '09:00';
$workEndTime = defined('WORK_END') ? WORK_END : '18:00';

$additionalScripts = '
<script>
// Form validation and interactivity
document.addEventListener("DOMContentLoaded", function() {
    var form = document.getElementById("meeting-form");
    var dateInput = document.getElementById("date");
    var startTimeInput = document.getElementById("start_time");
    var endTimeInput = document.getElementById("end_time");
    var descriptionInput = document.getElementById("description");
    var durationDisplay = document.getElementById("duration-display");
    var descriptionCount = document.getElementById("description-count");
    
    // Update duration when time changes
    function updateDuration() {
        var startTime = startTimeInput.value;
        var endTime = endTimeInput.value;
        
        if (startTime && endTime) {
            var start = new Date("2000-01-01 " + startTime);
            var end = new Date("2000-01-01 " + endTime);
            
            if (end > start) {
                var diffMs = end - start;
                var diffHours = Math.floor(diffMs / (1000 * 60 * 60));
                var diffMinutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
                
                var durationText = "";
                if (diffHours > 0) {
                    durationText += diffHours + " saat ";
                }
                if (diffMinutes > 0) {
                    durationText += diffMinutes + " dakika";
                }
                
                durationDisplay.textContent = durationText || "0 dakika";
                durationDisplay.parentElement.parentElement.classList.remove("bg-red-50", "border-red-200");
                durationDisplay.parentElement.parentElement.classList.add("bg-blue-50", "border-blue-200");
            } else {
                durationDisplay.textContent = "Geçersiz saat aralığı";
                durationDisplay.parentElement.parentElement.classList.remove("bg-blue-50", "border-blue-200");
                durationDisplay.parentElement.parentElement.classList.add("bg-red-50", "border-red-200");
            }
        } else {
            durationDisplay.textContent = "-";
        }
    }
    
    // Update character count
    function updateCharacterCount() {
        var length = descriptionInput.value.length;
        descriptionCount.textContent = length;
        
        if (length > 900) {
            descriptionCount.classList.add("text-red-600");
        } else if (length > 800) {
            descriptionCount.classList.add("text-yellow-600");
            descriptionCount.classList.remove("text-red-600");
        } else {
            descriptionCount.classList.remove("text-red-600", "text-yellow-600");
        }
    }
    
    // Weekend check disabled - meetings can be scheduled 7/24
    function checkWeekend() {
        // No restrictions for weekends anymore
    }
    
    // Time select change handlers
    function updateTimeInputs() {
        var startHour = document.getElementById("start_hour").value;
        var startMinute = document.getElementById("start_minute").value;
        var endHour = document.getElementById("end_hour").value;
        var endMinute = document.getElementById("end_minute").value;
        
        if (startHour && startMinute) {
            startTimeInput.value = startHour + ":" + startMinute;
        }
        if (endHour && endMinute) {
            endTimeInput.value = endHour + ":" + endMinute;
        }
        
        updateDuration();
    }
    
    // Event listeners
    document.getElementById("start_hour").addEventListener("change", updateTimeInputs);
    document.getElementById("start_minute").addEventListener("change", updateTimeInputs);
    document.getElementById("end_hour").addEventListener("change", updateTimeInputs);
    document.getElementById("end_minute").addEventListener("change", updateTimeInputs);
    descriptionInput.addEventListener("input", updateCharacterCount);
    dateInput.addEventListener("change", checkWeekend);
    
    // Initialize
    updateDuration();
    updateCharacterCount();
    
    // Form submission
    form.addEventListener("submit", function(e) {
        if (!validateForm()) {
            e.preventDefault();
        }
    });
});

// Check availability
function checkAvailability() {
    var date = document.getElementById("date").value;
    var startTime = document.getElementById("start_time").value;
    var endTime = document.getElementById("end_time").value;
    
    if (!date || !startTime || !endTime) {
        showToast("Lütfen tarih ve saat bilgilerini girin.", "warning");
        return;
    }
    
    var button = document.getElementById("check-availability-btn");
    var result = document.getElementById("availability-result");
    
    button.disabled = true;
    button.innerHTML = "<i class=\"fas fa-spinner fa-spin mr-2\"></i>Kontrol ediliyor...";
    
    fetch("api/check-availability.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": window.APP_CONFIG.csrf_token
        },
        body: JSON.stringify({
            date: date,
            start_time: startTime,
            end_time: endTime,
            exclude_meeting_id: ' . json_encode($meetingId, JSON_NUMERIC_CHECK) . '
        })
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        if (data.success) {
            if (data.data && data.data.available) {
                result.innerHTML = "<span class=\"text-green-600\"><i class=\"fas fa-check mr-1\"></i>Uygun</span>";
                showToast("Seçilen tarih ve saat uygun.", "success");
            } else {
                result.innerHTML = "<span class=\"text-red-600\"><i class=\"fas fa-times mr-1\"></i>Çakışma</span>";
                showToast("Seçilen tarih ve saatte çakışma bulundu: " + data.message, "error");
            }
        } else {
            result.innerHTML = "<span class=\"text-red-600\"><i class=\"fas fa-exclamation mr-1\"></i>Hata</span>";
            showToast("Kontrol sırasında hata: " + data.message, "error");
        }
    })
    .catch(function(error) {
        console.error("Availability check error:", error);
        result.innerHTML = "<span class=\"text-red-600\"><i class=\"fas fa-exclamation mr-1\"></i>Hata</span>";
        showToast("Uygunluk kontrolü sırasında hata oluştu.", "error");
    })
    .finally(function() {
        button.disabled = false;
        button.innerHTML = "<i class=\"fas fa-search mr-2\"></i>Uygunluk Kontrol Et";
    });
}

// Form validation
function validateForm() {
    var title = document.getElementById("title").value.trim();
    var date = document.getElementById("date").value;
    var startTime = document.getElementById("start_time").value;
    var endTime = document.getElementById("end_time").value;
    var moderator = document.getElementById("moderator").value.trim();
    
    if (!title || !date || !startTime || !endTime || !moderator) {
        showToast("Lütfen zorunlu alanları doldurun.", "error");
        return false;
    }
    
    // Date validation
    var selectedDate = new Date(date);
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    
    if (selectedDate <= today) {
        showToast("Geçmiş tarihler için toplantı oluşturamazsınız.", "error");
        return false;
    }
    
    // Weekend check removed - meetings can be scheduled 7/24
    
    // Time validation
    if (startTime >= endTime) {
        showToast("Bitiş saati başlangıç saatinden sonra olmalıdır.", "error");
        return false;
    }
    
    // Working hours check removed - meetings can be scheduled 7/24
    
    return true;
}
</script>
';

include 'includes/footer.php';
?>