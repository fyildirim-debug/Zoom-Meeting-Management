<?php
$pageTitle = 'Yeni Toplantı Talebi';
require_once 'config/config.php';
require_once 'config/auth.php';

requireLogin();

$currentUser = getCurrentUser();
$error = '';
$success = '';

// Birim bilgilerini al
$departments = [];
try {
    $stmt = $pdo->query("SELECT id, name, weekly_limit FROM departments ORDER BY name");
    $departments = $stmt->fetchAll();
} catch (Exception $e) {
    writeLog("Error fetching departments: " . $e->getMessage(), 'error');
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
            // Sistem kapatma kontrolü
            $closureCheck = checkMeetingDateAllowed($date);
            if (!$closureCheck['allowed']) {
                $error = $closureCheck['message'];
            }
            // Kullanıcının kendi çakışması
            elseif (checkUserConflict($currentUser['id'], $date, $startTime, $endTime)) {
                $error = 'Bu tarih ve saatte zaten bir toplantınız bulunuyor.';
            }
            // Birim haftalık limit kontrolü
            elseif (!checkDepartmentWeeklyLimit($currentUser['department_id'], $date)) {
                $error = 'Biriminizin haftalık toplantı limiti dolmuş.';
            } else {
                // Tekrarlı toplantı mı kontrol et
                $isRecurring = isset($_POST['is_recurring']) && $_POST['is_recurring'] == '1';
                $recurringDays = $_POST['recurring_days'] ?? [];
                $recurringWeeks = min(8, max(1, (int)($_POST['recurring_weeks'] ?? 2)));
                
                // Toplantı tarihlerini hesapla
                $meetingDates = [];
                
                if ($isRecurring && !empty($recurringDays)) {
                    // Tekrarlı toplantı - tarihleri hesapla
                    $startDateObj = new DateTime($date);
                    $endDateObj = clone $startDateObj;
                    $endDateObj->modify('+' . ($recurringWeeks * 7) . ' days');
                    
                    $currentDate = clone $startDateObj;
                    while ($currentDate < $endDateObj) {
                        $dayOfWeek = (int)$currentDate->format('w'); // 0=Pazar, 1=Pazartesi...
                        if (in_array($dayOfWeek, $recurringDays)) {
                            $dateStr = $currentDate->format('Y-m-d');
                            // Kapatma kontrolü
                            $closureCheck = checkMeetingDateAllowed($dateStr);
                            if ($closureCheck['allowed']) {
                                // Çakışma kontrolü
                                if (!checkUserConflict($currentUser['id'], $dateStr, $startTime, $endTime)) {
                                    $meetingDates[] = $dateStr;
                                }
                            }
                        }
                        $currentDate->modify('+1 day');
                    }
                    
                    if (empty($meetingDates)) {
                        $error = 'Seçilen tarih aralığında uygun gün bulunamadı (kapalı günler veya çakışmalar nedeniyle).';
                    }
                } else {
                    // Tek toplantı
                    $meetingDates[] = $date;
                }
                
                // Toplantıları oluştur
                if (empty($error) && !empty($meetingDates)) {
                    try {
                        $createdCount = 0;
                        $stmt = $pdo->prepare("
                            INSERT INTO meetings (
                                title, date, start_time, end_time, moderator, description, 
                                participants_count, user_id, department_id, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                        ");
                        
                        foreach ($meetingDates as $meetingDate) {
                            $result = $stmt->execute([
                                $title, $meetingDate, $startTime, $endTime, $moderator, 
                                $description, $participantsCount, $currentUser['id'], $currentUser['department_id']
                            ]);
                            
                            if ($result) {
                                $meetingId = $pdo->lastInsertId();
                                $createdCount++;
                                writeLog("New meeting request created: ID $meetingId by user " . $currentUser['id'], 'info');
                                
                                logActivity('create_meeting', 'meeting', $meetingId,
                                    'Yeni toplantı talebi: ' . $title . ' (' . $meetingDate . ' ' . $startTime . ')',
                                    $currentUser['id']);
                            }
                        }
                        
                        if ($createdCount > 0) {
                            // Admin'lere tek bildirim gönder
                            $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin'");
                            $stmt->execute();
                            $admins = $stmt->fetchAll();
                            
                            $notifMessage = $currentUser['name'] . ' ' . $currentUser['surname'] . 
                                ' tarafından ' . $createdCount . ' toplantı talebi oluşturuldu.';
                            
                            foreach ($admins as $admin) {
                                sendNotification($admin['id'], 'Yeni Toplantı Talepleri', $notifMessage, 'info');
                            }
                            
                            if ($createdCount == 1) {
                                $success = 'Toplantı talebiniz başarıyla oluşturuldu. Onay beklemektedir.';
                            } else {
                                $success = $createdCount . ' toplantı talebi başarıyla oluşturuldu. Onay beklemektedir.';
                            }
                            
                            // Formu temizle
                            $title = $date = $startTime = $endTime = $moderator = $description = '';
                            $participantsCount = 0;
                        } else {
                            $error = 'Toplantı talebi oluşturulurken hata oluştu.';
                        }
                    } catch (Exception $e) {
                        writeLog("Error creating meeting: " . $e->getMessage(), 'error');
                        $error = 'Veritabanı hatası oluştu.';
                    }
                }
            }
        }
    }
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
                <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-plus text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 ">Yeni Toplantı Talebi</h1>
                    <p class="text-gray-600 ">Yeni bir toplantı oluşturmak için formu doldurun</p>
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

        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 animate-fade-in" id="success-message">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-3"></i>
                        <div>
                            <p><?php echo $success; ?></p>
                            <p class="text-sm mt-1">
                                <span id="countdown">5</span> saniye sonra "Toplantılarım" sayfasına yönlendirileceksiniz...
                            </p>
                        </div>
                    </div>
                    <button onclick="redirectToMyMeetings()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">
                        Şimdi Git
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Meeting Form -->
        <div class="bg-white  rounded-xl shadow-lg border border-gray-200 ">
            <div class="p-6 border-b border-gray-200 ">
                <h2 class="text-xl font-semibold text-gray-900 ">Toplantı Bilgileri</h2>
                <p class="text-sm text-gray-600  mt-1">* ile işaretli alanlar zorunludur</p>
            </div>
            
            <form method="POST" class="p-6 space-y-6" id="meeting-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <!-- Meeting Title -->
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700  mb-2">
                        Toplantı Başlığı *
                    </label>
                    <input 
                        type="text" 
                        id="title" 
                        name="title" 
                        value="<?php echo htmlspecialchars($title ?? ''); ?>"
                        class="w-full px-4 py-3 border border-gray-300  rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white  text-gray-900 "
                        placeholder="Örn: Haftalık Proje Değerlendirme Toplantısı"
                        required
                        maxlength="255"
                    >
                </div>

                <!-- Date and Time -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Date -->
                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700  mb-2">
                            Tarih *
                        </label>
                        <input 
                            type="date" 
                            id="date" 
                            name="date" 
                            value="<?php echo htmlspecialchars($date ?? ''); ?>"
                            class="w-full px-4 py-3 border border-gray-300  rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white  text-gray-900 "
                            required
                            min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                        >
                        <p class="text-xs text-gray-500  mt-1">Tüm günler için toplantı planlanabilir</p>
                    </div>

                    <!-- Start Time -->
                    <div>
                        <label for="start_time" class="block text-sm font-medium text-gray-700  mb-2">
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
                        <input type="hidden" id="start_time" name="start_time" value="<?php echo $startTime ?? ''; ?>">
                        <p class="text-xs text-gray-500 mt-1">Sadece 15 dakika aralıklarla seçim yapılabilir</p>
                    </div>

                    <!-- End Time -->
                    <div>
                        <label for="end_time" class="block text-sm font-medium text-gray-700  mb-2">
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
                        <input type="hidden" id="end_time" name="end_time" value="<?php echo $endTime ?? ''; ?>">
                        <p class="text-xs text-gray-500 mt-1">Sadece 15 dakika aralıklarla seçim yapılabilir</p>
                    </div>
                </div>

                <!-- Duration Display -->
                <div class="bg-blue-50  border border-blue-200  rounded-lg p-4">
                    <div class="flex items-center">
                        <i class="fas fa-clock text-blue-600  mr-3"></i>
                        <span class="text-blue-800 ">
                            Toplantı Süresi: <span id="duration-display" class="font-semibold">-</span>
                        </span>
                    </div>
                </div>

                <!-- Moderator -->
                <div>
                    <label for="moderator" class="block text-sm font-medium text-gray-700  mb-2">
                        Moderatör Ad Soyad *
                    </label>
                    <input 
                        type="text" 
                        id="moderator" 
                        name="moderator" 
                        value="<?php echo htmlspecialchars($moderator ?? ($currentUser['name'] . ' ' . $currentUser['surname'])); ?>"
                        class="w-full px-4 py-3 border border-gray-300  rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white  text-gray-900 "
                        placeholder="Toplantı moderatörünün adı soyadı"
                        required
                        maxlength="255"
                    >
                </div>

                <!-- Participants Count -->
                <div>
                    <label for="participants_count" class="block text-sm font-medium text-gray-700  mb-2">
                        Tahmini Katılımcı Sayısı
                    </label>
                    <input 
                        type="number" 
                        id="participants_count" 
                        name="participants_count" 
                        value="<?php echo htmlspecialchars($participantsCount ?? ''); ?>"
                        class="w-full px-4 py-3 border border-gray-300  rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white  text-gray-900 "
                        placeholder="0"
                        min="1"
                        max="500"
                    >
                    <p class="text-xs text-gray-500  mt-1">Zoom hesap seçimi için önemlidir</p>
                </div>

                <!-- Tekrarlama Seçenekleri -->
                <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                    <div class="flex items-center mb-4">
                        <input type="checkbox" id="is_recurring" name="is_recurring" value="1" 
                               class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                               onchange="toggleRecurringOptions()">
                        <label for="is_recurring" class="ml-2 text-sm font-medium text-gray-700">
                            <i class="fas fa-redo mr-1"></i>Tekrarlayan Toplantı
                        </label>
                    </div>
                    
                    <div id="recurring-options" class="hidden space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Hangi Günler?</label>
                            <div class="flex flex-wrap gap-2">
                                <label class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-lg cursor-pointer hover:bg-blue-50">
                                    <input type="checkbox" name="recurring_days[]" value="1" class="mr-2 text-blue-600">
                                    <span class="text-sm">Pazartesi</span>
                                </label>
                                <label class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-lg cursor-pointer hover:bg-blue-50">
                                    <input type="checkbox" name="recurring_days[]" value="2" class="mr-2 text-blue-600">
                                    <span class="text-sm">Salı</span>
                                </label>
                                <label class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-lg cursor-pointer hover:bg-blue-50">
                                    <input type="checkbox" name="recurring_days[]" value="3" class="mr-2 text-blue-600">
                                    <span class="text-sm">Çarşamba</span>
                                </label>
                                <label class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-lg cursor-pointer hover:bg-blue-50">
                                    <input type="checkbox" name="recurring_days[]" value="4" class="mr-2 text-blue-600">
                                    <span class="text-sm">Perşembe</span>
                                </label>
                                <label class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-lg cursor-pointer hover:bg-blue-50">
                                    <input type="checkbox" name="recurring_days[]" value="5" class="mr-2 text-blue-600">
                                    <span class="text-sm">Cuma</span>
                                </label>
                                <label class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-lg cursor-pointer hover:bg-blue-50">
                                    <input type="checkbox" name="recurring_days[]" value="6" class="mr-2 text-blue-600">
                                    <span class="text-sm">Cumartesi</span>
                                </label>
                                <label class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-lg cursor-pointer hover:bg-blue-50">
                                    <input type="checkbox" name="recurring_days[]" value="0" class="mr-2 text-blue-600">
                                    <span class="text-sm">Pazar</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Kaç Hafta Tekrarlansın?</label>
                                <input type="number" name="recurring_weeks" id="recurring_weeks" min="1" max="8" value="2"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <p class="text-xs text-gray-500 mt-1">Başlangıç tarihinden itibaren</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Önizleme</label>
                                <div id="recurring-preview" class="text-sm text-gray-600 bg-white border border-gray-200 rounded-lg p-2 max-h-24 overflow-y-auto">
                                    Gün seçin...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Department Info -->
                <?php if ($currentUser['department_id']): ?>
                    <div class="bg-gray-50  border border-gray-200  rounded-lg p-4">
                        <h3 class="text-sm font-medium text-gray-700  mb-2">Birim Bilgileri</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-gray-600 ">Birim:</span>
                                <span class="font-medium text-gray-900  ml-2">
                                    <?php echo htmlspecialchars($_SESSION['user_department_name'] ?? 'Bilinmiyor'); ?>
                                </span>
                            </div>
                            <div id="weekly-limit-info">
                                <span class="text-gray-600 ">Haftalık Limit:</span>
                                <span class="font-medium text-gray-900  ml-2" id="limit-display">Yükleniyor...</span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Working Hours Info -->
                <div class="bg-yellow-50  border border-yellow-200  rounded-lg p-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-yellow-600  mr-3 mt-0.5"></i>
                        <div class="text-sm text-yellow-800 ">
                            <p class="font-medium">Önemli Bilgiler:</p>
                            <ul class="mt-2 space-y-1 list-disc list-inside">
                                <li>Aynı tarih ve saatte başka toplantınız olamaz</li>
                                <li>Onay sonrasında toplantı linki ve bilgileri size iletilecektir</li>
                                <li>Toplantılar 7/24 planlanabilir</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-4 sm:space-y-0 pt-6 border-t border-gray-200 ">
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
                        <a href="dashboard.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                            İptal
                        </a>
                        <button 
                            type="submit" 
                            class="btn-primary px-6 py-3"
                            id="submit-btn"
                        >
                            <i class="fas fa-paper-plane mr-2"></i>
                            Talep Gönder
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

// Working hours restrictions removed

?>
<script>
// Success redirect functionality
function redirectToMyMeetings() {
    window.location.href = "my-meetings.php";
}

function startCountdown() {
    let countdown = 5;
    const countdownElement = document.getElementById("countdown");
    
    if (countdownElement) {
        const timer = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                redirectToMyMeetings();
            }
        }, 1000);
    }
}

// Form validation and interactivity
document.addEventListener("DOMContentLoaded", function() {
    // Start countdown if success message exists
    if (document.getElementById("success-message")) {
        startCountdown();
    }
    var form = document.getElementById("meeting-form");
    var dateInput = document.getElementById("date");
    var startTimeInput = document.getElementById("start_time");
    var endTimeInput = document.getElementById("end_time");
    var descriptionInput = document.getElementById("description");
    var durationDisplay = document.getElementById("duration-display");
    var descriptionCount = document.getElementById("description-count");
    
    // Null kontrolü - description alanı yoksa atla
    if (!descriptionInput) descriptionInput = { value: '', addEventListener: function() {} };
    if (!descriptionCount) descriptionCount = { textContent: '', classList: { add: function(){}, remove: function(){} } };
    
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
    loadWeeklyLimitInfo();
    
    // Form submission
    form.addEventListener("submit", function(e) {
        if (!validateForm()) {
            e.preventDefault();
        }
    });
});

// Load weekly limit info
function loadWeeklyLimitInfo() {
    fetch("api/department-limit.php")
        .then(response => response.json())
        .then(data => {
            console.log("Department limit response:", data); // Debug
            if (data.success && data.data) {
                const limitDisplay = document.getElementById("limit-display");
                const limitData = data.data;
                limitDisplay.textContent = `${limitData.total_allocated}/${limitData.limit} kullanıldı (${limitData.remaining} kalan)`;
                
                if (limitData.remaining <= 0) {
                    limitDisplay.classList.add("text-red-600");
                    document.getElementById("submit-btn").disabled = true;
                    showToast("Biriminizin haftalık toplantı limiti dolmuş.", "error");
                } else if (limitData.remaining <= 2) {
                    limitDisplay.classList.add("text-yellow-600");
                } else {
                    limitDisplay.classList.add("text-green-600");
                }
                
                // Ek bilgi göster
                if (limitData.pending > 0) {
                    limitDisplay.textContent += ` (${limitData.pending} onay bekliyor)`;
                }
            } else {
                document.getElementById("limit-display").textContent = "Limit bilgisi alınamadı";
            }
        })
        .catch(error => {
            console.error("Limit info error:", error);
            document.getElementById("limit-display").textContent = "Yüklenemedi";
        });
}

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
            end_time: endTime
        })
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        console.log("API Response:", data); // Debug için
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
        showToast("Geçmiş tarihler için toplantı planlayamazsınız.", "error");
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

// Auto-save as draft (optional feature)
let autoSaveTimeout;
function autoSave() {
    clearTimeout(autoSaveTimeout);
    autoSaveTimeout = setTimeout(() => {
        const formData = new FormData(document.getElementById("meeting-form"));
        
        fetch("api/save-draft.php", {
            method: "POST",
            body: formData
        }).catch(console.error);
    }, 5000);
}

// Add auto-save to form inputs
document.querySelectorAll("#meeting-form input, #meeting-form textarea").forEach(input => {
    input.addEventListener("input", autoSave);
});

// Tekrarlama seçenekleri toggle
function toggleRecurringOptions() {
    var isRecurring = document.getElementById("is_recurring").checked;
    var options = document.getElementById("recurring-options");
    options.classList.toggle("hidden", !isRecurring);
    if (isRecurring) {
        updateRecurringPreview();
    }
}

// Tekrarlama önizlemesi güncelle
function updateRecurringPreview() {
    var dateInput = document.getElementById("date").value;
    if (!dateInput) {
        document.getElementById("recurring-preview").innerHTML = "Önce başlangıç tarihi seçin";
        return;
    }
    
    var selectedDays = [];
    document.querySelectorAll("input[name=\"recurring_days[]\"]").forEach(function(cb) {
        if (cb.checked) selectedDays.push(parseInt(cb.value));
    });
    
    if (selectedDays.length === 0) {
        document.getElementById("recurring-preview").innerHTML = "Gün seçin...";
        return;
    }
    
    var weeks = parseInt(document.getElementById("recurring_weeks").value) || 2;
    var startDate = new Date(dateInput);
    var dates = [];
    var dayNames = ["Pazar", "Pazartesi", "Salı", "Çarşamba", "Perşembe", "Cuma", "Cumartesi"];
    
    // Başlangıç tarihinden itibaren haftalık olarak seçili günleri bul
    for (var w = 0; w < weeks; w++) {
        for (var d = 0; d < 7; d++) {
            var checkDate = new Date(startDate);
            checkDate.setDate(startDate.getDate() + (w * 7) + d);
            
            if (selectedDays.includes(checkDate.getDay()) && checkDate >= startDate) {
                var formatted = checkDate.toLocaleDateString("tr-TR", {day: "2-digit", month: "2-digit", year: "numeric"});
                dates.push(dayNames[checkDate.getDay()] + " - " + formatted);
            }
        }
    }
    
    if (dates.length > 0) {
        document.getElementById("recurring-preview").innerHTML = 
            "<strong>" + dates.length + " toplantı:</strong><br>" + dates.join("<br>");
    } else {
        document.getElementById("recurring-preview").innerHTML = "Uygun tarih bulunamadı";
    }
}

// Event listener for recurring options
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll("input[name=\"recurring_days[]\"]").forEach(function(cb) {
        cb.addEventListener("change", updateRecurringPreview);
    });
    document.getElementById("recurring_weeks").addEventListener("change", updateRecurringPreview);
    document.getElementById("date").addEventListener("change", function() {
        if (document.getElementById("is_recurring").checked) {
            updateRecurringPreview();
        }
    });
    document.getElementById("is_recurring").addEventListener("change", toggleRecurringOptions);
});
</script>

<?php
include 'includes/footer.php';
?>