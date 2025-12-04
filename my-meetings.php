<?php
$pageTitle = 'Toplantılarım';
require_once 'config/config.php';
require_once 'config/auth.php';

requireLogin();

$currentUser = getCurrentUser();

// Filtreleme parametreleri
$status = cleanInput($_GET['status'] ?? 'all_including_rejected');
$dateFilter = cleanInput($_GET['date'] ?? 'recent');
$viewMode = cleanInput($_GET['view'] ?? 'upcoming'); // Yeni: Yaklaşan/Geçmiş/Tümü sekmeleri
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

// Birim bazlı görüntüleme - sadece kullanıcının birimindeki toplantıları göster
$whereConditions = ['m.department_id = ?'];
$params = [$currentUser['department_id']];

// DEBUG: Birim filtreleme aktif - geçici olarak tüm toplantıları görmek için yorumla
// $whereConditions = ['1 = 1'];
// $params = [];

// View Mode filtresi (üst sekmeler) - önce kontrol et
switch ($viewMode) {
    case 'upcoming':
        $whereConditions[] = 'm.date >= CURDATE()';
        // Yaklaşan sekmesinde sadece aktif toplantılar (onaylı ve bekleyen)
        $whereConditions[] = "m.status IN ('approved', 'pending')";
        // Yaklaşan sekmesinde status filtresi ignore edilir
        break;
    case 'past':
        $whereConditions[] = 'm.date < CURDATE()';
        // Geçmiş sekmesinde normal status filtreleri uygulanır
        // Varsayılan olarak reddedilenleri hariç tut
        if ($status === 'all') {
            $whereConditions[] = "m.status != 'rejected'";
        } elseif ($status === 'all_including_rejected') {
            // Tümü dahil reddedilenler - ek koşul ekleme
        } elseif ($status === 'past_meetings') {
            // Geçmiş toplantılar - sadece onaylı ve geçmiş tarihliler
            $whereConditions[] = "m.status = 'approved'";
        } elseif ($status !== '') {
            $whereConditions[] = 'm.status = ?';
            $params[] = $status;
        }
        break;
    case 'all':
        // Tümü sekmesinde normal status filtreleri uygulanır
        // Varsayılan olarak reddedilenleri hariç tut
        if ($status === 'all') {
            $whereConditions[] = "m.status != 'rejected'";
        } elseif ($status === 'all_including_rejected') {
            // Tümü dahil reddedilenler - ek koşul ekleme
        } elseif ($status === 'past_meetings') {
            // Geçmiş toplantılar - sadece onaylı ve geçmiş tarihliler
            $whereConditions[] = "m.status = 'approved'";
            $whereConditions[] = 'm.date < CURDATE()';
        } elseif ($status !== '') {
            $whereConditions[] = 'm.status = ?';
            $params[] = $status;
        }
        break;
}

// Alt seviye tarih filtresi (geleneksel filtreler korunuyor)
switch ($dateFilter) {
    case 'recent':
        // Son 1 ay (view mode'a ek olarak)
        if ($viewMode === 'all') {
            $whereConditions[] = 'm.date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)';
            $whereConditions[] = 'm.date <= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)';
        }
        break;
    case 'this_week':
        $whereConditions[] = 'm.date >= DATE(NOW() - INTERVAL WEEKDAY(NOW()) DAY)';
        $whereConditions[] = 'm.date <= DATE(NOW() - INTERVAL WEEKDAY(NOW()) DAY + INTERVAL 6 DAY)';
        break;
    case 'this_month':
        $whereConditions[] = 'YEAR(m.date) = YEAR(CURDATE())';
        $whereConditions[] = 'MONTH(m.date) = MONTH(CURDATE())';
        break;
    case 'all':
        // Tarihe göre ek filtre yok
        break;
}

$whereClause = implode(' AND ', $whereConditions);

try {
    // Toplam kayıt sayısı
    $countWhereClause = str_replace(['m.department_id', 'm.date', 'm.status'], ['department_id', 'date', 'status'], $whereClause);
    $countParams = $params;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE {$countWhereClause}");
    $stmt->execute($countParams);
    $totalMeetings = $stmt->fetchColumn();
    
    // Sayfalama hesaplama
    $pagination = calculatePagination($totalMeetings, $perPage, $page);
    
    // Toplantıları al - user bilgilerini de dahil et - En yakın aktifden başlat
    $stmt = $pdo->prepare("
        SELECT m.*, d.name as department_name, u.name as creator_name, u.surname as creator_surname,
               ABS(DATEDIFF(m.date, CURDATE())) as days_from_today,
               CASE WHEN m.date < CURDATE() THEN 'past'
                    WHEN m.date = CURDATE() THEN 'today'
                    ELSE 'future' END as time_category
        FROM meetings m
        LEFT JOIN departments d ON m.department_id = d.id
        LEFT JOIN users u ON m.user_id = u.id
        WHERE {$whereClause}
        ORDER BY ABS(DATEDIFF(m.date, CURDATE())) ASC,
                 CASE WHEN m.date >= CURDATE() THEN 0 ELSE 1 END ASC,
                 m.date ASC, m.start_time ASC
        LIMIT {$pagination['offset']}, {$perPage}
    ");
    $stmt->execute($params);
    $meetings = $stmt->fetchAll();
    
    // Toplantıları tarihe göre grupla
    $groupedMeetings = [];
    foreach ($meetings as $meeting) {
        $dateKey = $meeting['date'];
        if (!isset($groupedMeetings[$dateKey])) {
            $groupedMeetings[$dateKey] = [];
        }
        $groupedMeetings[$dateKey][] = $meeting;
    }
    
    // Her grup içinde saate göre sırala
    foreach ($groupedMeetings as $date => &$dayMeetings) {
        usort($dayMeetings, function($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });
    }
    unset($dayMeetings); // Reference temizle
    
    // İstatistikleri al - SADECE kullanıcının birimindeki toplantılar
    $stats = [];
    
    // Durum bazlı sayılar (birim bazlı)
    foreach (['pending', 'approved', 'rejected', 'cancelled'] as $st) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE status = ? AND department_id = ?");
        $stmt->execute([$st, $currentUser['department_id']]);
        $stats[$st] = $stmt->fetchColumn();
    }
    
    // Yaklaşan toplantılar (birim bazlı)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM meetings
        WHERE status = 'approved' AND date >= CURDATE() AND department_id = ?
    ");
    $stmt->execute([$currentUser['department_id']]);
    $stats['upcoming'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    writeLog("My meetings error: " . $e->getMessage(), 'error');
    $meetings = [];
    $stats = [];
    $totalMeetings = 0;
    $pagination = calculatePagination(0, $perPage, $page);
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content flex-1 p-6">
    <div class="max-w-7xl mx-auto">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar-check text-white text-xl"></i>
                    </div>
                <div>
                        <h1 class="text-3xl font-bold text-gray-900">Birim Toplantıları</h1>
                        <p class="text-gray-600">
                        Biriminizin tüm toplantılarını görüntüleyebilirsiniz. Sadece kendi oluşturduğunuz toplantıları düzenleyebilirsiniz.
                    </p>
                    </div>
                </div>
                <div class="mt-4 sm:mt-0">
                    <a href="new-meeting.php" class="btn-primary inline-flex items-center px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>
                        Yeni Toplantı
                    </a>
                </div>
            </div>
        </div>

        <!-- View Mode Tabs -->
        <div class="mb-6">
            <div class="flex border-b border-gray-200">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'upcoming'])); ?>"
                   class="px-6 py-3 text-sm font-medium border-b-2 transition-colors <?php echo $viewMode === 'upcoming' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    <i class="fas fa-arrow-up mr-2"></i>Yaklaşan
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'past'])); ?>"
                   class="px-6 py-3 text-sm font-medium border-b-2 transition-colors <?php echo $viewMode === 'past' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    <i class="fas fa-history mr-2"></i>Geçmiş
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'all'])); ?>"
                   class="px-6 py-3 text-sm font-medium border-b-2 transition-colors <?php echo $viewMode === 'all' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    <i class="fas fa-calendar mr-2"></i>Tümü
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
            <!-- Total -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Toplam</p>
                        <p class="text-3xl font-bold text-gray-900">
                            <?php echo ($stats['pending'] ?? 0) + ($stats['approved'] ?? 0) + ($stats['cancelled'] ?? 0); ?>
                        </p>
                    </div>
                    <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-video text-2xl text-blue-600"></i>
                    </div>
                </div>
            </div>

            <!-- Pending -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Bekliyor</p>
                        <p class="text-3xl font-bold text-gray-900">
                            <?php echo $stats['pending'] ?? 0; ?>
                        </p>
                    </div>
                    <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-clock text-2xl text-orange-600"></i>
                    </div>
                </div>
            </div>

            <!-- Approved -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Onaylı</p>
                        <p class="text-3xl font-bold text-gray-900">
                            <?php echo $stats['approved'] ?? 0; ?>
                        </p>
                    </div>
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-check text-2xl text-green-600"></i>
                    </div>
                </div>
            </div>

            <!-- Upcoming -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Yaklaşan</p>
                        <p class="text-3xl font-bold text-gray-900">
                            <?php echo $stats['upcoming'] ?? 0; ?>
                        </p>
                    </div>
                    <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-calendar-alt text-2xl text-purple-600"></i>
                    </div>
                </div>
            </div>

            <!-- Rejected -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Reddedilen</p>
                        <p class="text-3xl font-bold text-gray-900">
                            <?php echo $stats['rejected'] ?? 0; ?>
                        </p>
                    </div>
                    <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-times text-2xl text-red-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 mb-8">
            <form method="GET" class="flex flex-col sm:flex-row sm:items-end sm:space-x-4 space-y-4 sm:space-y-0">
                <!-- Hidden view mode input -->
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode); ?>">
                
                <!-- Status Filter -->
                <div class="flex-1">
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                        Durum
                    </label>
                    <select id="status" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-white text-gray-900">
                        <option value="all_including_rejected" <?php echo $status === 'all_including_rejected' ? 'selected' : ''; ?>>Tümü</option>
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>Aktif Toplantılar</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Bekliyor</option>
                        <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Onaylı</option>
                        <option value="past_meetings" <?php echo $status === 'past_meetings' ? 'selected' : ''; ?>>Geçmiş Toplantılar</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>İptal Edildi</option>
                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Reddedilen</option>
                    </select>
                </div>

                <!-- Date Filter -->
                <div class="flex-1">
                    <label for="date" class="block text-sm font-medium text-gray-700 mb-2">
                        Tarih
                    </label>
                    <select id="date" name="date" class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-white text-gray-900">
                        <option value="recent" <?php echo $dateFilter === 'recent' ? 'selected' : ''; ?>>Son 1 Ay (Varsayılan)</option>
                        <option value="all" <?php echo $dateFilter === 'all' ? 'selected' : ''; ?>>Tümü</option>
                        <option value="upcoming" <?php echo $dateFilter === 'upcoming' ? 'selected' : ''; ?>>Yaklaşan</option>
                        <option value="past" <?php echo $dateFilter === 'past' ? 'selected' : ''; ?>>Geçmiş</option>
                        <option value="this_week" <?php echo $dateFilter === 'this_week' ? 'selected' : ''; ?>>Bu Hafta</option>
                        <option value="this_month" <?php echo $dateFilter === 'this_month' ? 'selected' : ''; ?>>Bu Ay</option>
                    </select>
                </div>

                <!-- Filter Buttons -->
                <div class="flex space-x-2">
                    <button type="submit" class="btn-primary px-4 py-2">
                        <i class="fas fa-search mr-2"></i>
                        Filtrele
                    </button>
                    <a href="my-meetings.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-undo mr-2"></i>
                        Temizle
                    </a>
                </div>
            </form>
        </div>

        <!-- Meetings List - Date Grouped -->
        <?php if (empty($meetings)): ?>
            <!-- Empty State -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-12 text-center">
                <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-calendar-times text-4xl text-gray-400"></i>
                </div>
                <h3 class="text-xl font-medium text-gray-900 mb-2">
                    <?php echo $status === 'all' ? 'Toplantı bulunamadı' : 'Bu filtreye uygun toplantı bulunamadı'; ?>
                </h3>
                <p class="text-gray-600 mb-6">
                    <?php if ($status === 'all'): ?>
                        Henüz hiç toplantı talebinde bulunmamışsınız. İlk toplantınızı oluşturmak için aşağıdaki butonu kullanın.
                    <?php else: ?>
                        Farklı filtre seçeneklerini deneyebilir veya yeni bir toplantı talebi oluşturabilirsiniz.
                    <?php endif; ?>
                </p>
                <a href="new-meeting.php" class="btn-primary inline-flex items-center px-6 py-3">
                    <i class="fas fa-plus mr-2"></i>
                    Yeni Toplantı Oluştur
                </a>
            </div>
        <?php else: ?>
            <!-- Date Grouped Meetings -->
            <div class="space-y-6">
                <?php
                // Tarihleri sırala (bugüne yakın olanlar önce)
                uksort($groupedMeetings, function($a, $b) {
                    return strtotime($a) - strtotime($b);
                });
                
                foreach ($groupedMeetings as $date => $dayMeetings):
                    // Türkçe tarih formatını hazırla
                    $dateTime = new DateTime($date);
                    $today = new DateTime();
                    $tomorrow = new DateTime('+1 day');
                    $yesterday = new DateTime('-1 day');
                    
                    if ($dateTime->format('Y-m-d') === $today->format('Y-m-d')) {
                        $dateLabel = 'Bugün';
                    } elseif ($dateTime->format('Y-m-d') === $tomorrow->format('Y-m-d')) {
                        $dateLabel = 'Yarın';
                    } elseif ($dateTime->format('Y-m-d') === $yesterday->format('Y-m-d')) {
                        $dateLabel = 'Dün';
                    } else {
                        // Türkçe gün ve ay adları
                        $dayNames = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];
                        $monthNames = ['', 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
                        $dayName = $dayNames[$dateTime->format('w')];
                        $monthName = $monthNames[(int)$dateTime->format('n')];
                        $dateLabel = $dayName . ', ' . $dateTime->format('j') . ' ' . $monthName;
                    }
                ?>
                    <!-- Date Header -->
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 rounded-t-xl">
                            <h3 class="text-lg font-semibold text-gray-900">
                                <?php echo $dateLabel; ?>
                                <span class="text-sm font-normal text-gray-500 ml-2">
                                    (<?php echo count($dayMeetings); ?> toplantı)
                                </span>
                            </h3>
                        </div>
                        
                        <!-- Day Meetings -->
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($dayMeetings as $meeting): 
                                // 1 gün geçmiş kontrol
                                $meetingDate = new DateTime($meeting['date']);
                                $oneDayAgo = new DateTime('-1 day');
                                $isPastMoreThanOneDay = $meetingDate < $oneDayAgo;
                            ?>
                                <div class="p-6 hover:bg-gray-50 transition-colors">
                                    <div class="flex items-start space-x-4">
                                        <!-- Time Column -->
                                        <div class="flex-shrink-0">
                                            <div class="bg-gradient-to-br from-blue-500 to-indigo-600 text-white p-3 rounded-lg shadow text-center min-w-[80px]">
                                                <div class="text-sm font-bold">
                                                <?php echo formatTime($meeting['start_time']); ?>
                                            </div>
                                                <div class="text-xs opacity-90">
                                                <?php echo formatTime($meeting['end_time']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Meeting Content -->
                                        <div class="flex-1 min-w-0">
                                            <!-- Header Row: Title + Status -->
                                            <div class="flex items-start justify-between mb-2">
                                                <div class="flex-1 pr-4">
                                                    <h4 class="text-base font-medium text-gray-900 mb-1">
                                                        <?php echo htmlspecialchars($meeting['title']); ?>
                                                        <?php if ($meeting['user_id'] != $currentUser['id']): ?>
                                                            <span class="inline-flex items-center px-2 py-1 ml-2 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">
                                                                <i class="fas fa-users mr-1"></i>Birim
                                                            </span>
                                                        <?php endif; ?>
                                                    </h4>
                                                </div>
                                                
                                                <!-- Status Badge -->
                                                <div class="flex-shrink-0">
                                                    <span class="badge badge-<?php echo $meeting['status'] === 'approved' ? 'success' :
                                                        ($meeting['status'] === 'pending' ? 'warning' :
                                                        ($meeting['status'] === 'rejected' ? 'error' : 'info')); ?>">
                                                        <?php
                                                        $statusLabels = [
                                                            'pending' => 'Bekliyor',
                                                            'approved' => 'Onaylı',
                                                            'rejected' => 'Reddedildi',
                                                            'cancelled' => 'İptal Edildi'
                                                        ];
                                                        echo $statusLabels[$meeting['status']] ?? $meeting['status'];
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <!-- Meeting Details Row -->
                                                    <div class="flex items-center space-x-4 text-sm text-gray-500 mb-2">
                                                        <span>
                                                            <i class="fas fa-user mr-1"></i>
                                                            <?php echo htmlspecialchars($meeting['moderator']); ?>
                                                        </span>
                                                        
                                                        <?php if ($meeting['participants_count'] > 0): ?>
                                                            <span>
                                                                <i class="fas fa-users mr-1"></i>
                                                                <?php echo $meeting['participants_count']; ?> katılımcı
                                                            </span>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($meeting['user_id'] != $currentUser['id']): ?>
                                                            <span class="text-blue-600">
                                                                <i class="fas fa-user-circle mr-1"></i>
                                                                <?php echo htmlspecialchars($meeting['creator_name'] . ' ' . $meeting['creator_surname']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <?php if ($meeting['description']): ?>
                                                        <p class="text-sm text-gray-600 mb-3 line-clamp-2">
                                                            <?php echo htmlspecialchars(substr($meeting['description'], 0, 150)) . (strlen($meeting['description']) > 150 ? '...' : ''); ?>
                                                        </p>
                                                    <?php endif; ?>
                                            
                                            <!-- Actions Row -->
                                            <div class="flex items-center justify-between mt-4">
                                                <div class="text-xs text-gray-400">
                                                    <span>Toplantı Kimliği: <?php echo $meeting['zoom_meeting_id'] ?? 'Atanmamış'; ?></span>
                                                </div>
                                                
                                                <div class="flex items-center space-x-2">
                                                    <!-- View Details -->
                                                    <button
                                                        onclick="openMeetingModal(<?php echo $meeting['id']; ?>)"
                                                        class="text-blue-600 hover:text-blue-500 p-2 rounded-lg hover:bg-blue-50 transition-colors"
                                                        title="Detayları Gör"
                                                    >
                                                        <i class="fas fa-eye"></i>
                                                    </button>

                                                    <?php if ($meeting['status'] === 'approved' && !$isPastMoreThanOneDay && ($meeting['zoom_start_url'] || $meeting['zoom_meeting_id'] || $meeting['meeting_id']) && $meeting['user_id'] == $currentUser['id']): ?>
                                                        <!-- Start Meeting (Host) - Güncel Token ile -->
                                                        <button
                                                            onclick="startMeetingAsHost(<?php echo $meeting['id']; ?>, this)"
                                                            class="inline-flex items-center px-3 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors disabled:opacity-50 disabled:cursor-wait"
                                                            title="Toplantıyı Yönetici Olarak Başlat"
                                                            type="button"
                                                        >
                                                            <i class="fas fa-crown mr-1 start-icon"></i>
                                                            <i class="fas fa-spinner fa-spin mr-1 loading-icon hidden"></i>
                                                            <span class="btn-text">Başlat</span>
                                                        </button>
                                                    <?php elseif ($meeting['status'] === 'approved' && !$isPastMoreThanOneDay && $meeting['zoom_join_url']): ?>
                                                        <!-- Join Meeting (Participant) - Davetiye Linki -->
                                                        <a
                                                            href="<?php echo htmlspecialchars($meeting['zoom_join_url']); ?>"
                                                            target="_blank"
                                                            class="inline-flex items-center px-3 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors"
                                                            title="Toplantıya Katılımcı Olarak Katıl"
                                                        >
                                                            <i class="fas fa-video mr-1"></i>Katıl
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if ($meeting['status'] === 'pending' && $meeting['user_id'] == $currentUser['id']): ?>
                                                        <!-- Edit -->
                                                        <a
                                                            href="edit-meeting.php?id=<?php echo $meeting['id']; ?>"
                                                            class="text-yellow-600 hover:text-yellow-500 p-2 rounded-lg hover:bg-yellow-50 transition-colors"
                                                            title="Düzenle"
                                                        >
                                                            <i class="fas fa-edit"></i>
                                                        </a>

                                                        <!-- Cancel -->
                                                        <button
                                                            onclick="event.preventDefault(); event.stopPropagation(); cancelMeeting(<?php echo $meeting['id']; ?>); return false;"
                                                            class="text-red-600 hover:text-red-500 p-2 rounded-lg hover:bg-red-50 transition-colors"
                                                            title="İptal Et"
                                                            type="button"
                                                        >
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($meeting['status'] === 'rejected' && $meeting['user_id'] == $currentUser['id']): ?>
                                                        <!-- Delete -->
                                                        <button
                                                            onclick="event.preventDefault(); event.stopPropagation(); deleteMeeting(<?php echo $meeting['id']; ?>); return false;"
                                                            class="text-red-600 hover:text-red-500 p-2 rounded-lg hover:bg-red-50 transition-colors"
                                                            title="Sil"
                                                            type="button"
                                                        >
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 px-6 py-4 mt-6">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            <span class="font-medium"><?php echo $pagination['total_items']; ?></span> toplantıdan
                            <span class="font-medium"><?php echo min($pagination['offset'] + 1, $pagination['total_items']); ?></span> -
                            <span class="font-medium"><?php echo min($pagination['offset'] + $perPage, $pagination['total_items']); ?></span>
                            arası gösteriliyor
                        </div>
                        
                        <nav class="flex space-x-2">
                            <?php if ($pagination['has_prev']): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['prev_page']])); ?>"
                                   class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                                    Önceki
                                </a>
                            <?php endif; ?>
                            
                            <span class="px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-lg">
                                <?php echo $pagination['current_page']; ?> / <?php echo $pagination['total_pages']; ?>
                            </span>
                            
                            <?php if ($pagination['has_next']): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['next_page']])); ?>"
                                   class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                                    Sonraki
                                </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
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
// Global config for JavaScript
window.APP_CONFIG = {
    user_id: ' . (int)$currentUser['id'] . ',
    user_name: "' . htmlspecialchars($currentUser['name'] . ' ' . $currentUser['surname'], ENT_QUOTES) . '",
    csrf_token: "' . ($_SESSION['csrf_token'] ?? '') . '"
};

// Zoom URL\'ye kullanıcı adı ekle
function addUserNameToZoomUrl(url) {
    if (!url) return url;
    try {
        var urlObj = new URL(url);
        urlObj.searchParams.set("uname", window.APP_CONFIG.user_name);
        return urlObj.toString();
    } catch (e) {
        // URL parse edilemezse orijinali döndür
        return url + (url.includes("?") ? "&" : "?") + "uname=" + encodeURIComponent(window.APP_CONFIG.user_name);
    }
}


</script>';

include 'includes/footer.php';
?>

<script>
/**
 * Toplantıyı Yönetici (Host) Olarak Başlat
 * API'den güncel start URL alır ve yeni sekmede açar
 * Bu sayede eski/geçersiz token sorunu çözülür
 */
async function startMeetingAsHost(meetingId, buttonElement) {
    console.log('🚀 Starting meeting as host:', meetingId);
    
    // Buton durumunu güncelle
    const startIcon = buttonElement.querySelector('.start-icon');
    const loadingIcon = buttonElement.querySelector('.loading-icon');
    const btnText = buttonElement.querySelector('.btn-text');
    
    buttonElement.disabled = true;
    if (startIcon) startIcon.classList.add('hidden');
    if (loadingIcon) loadingIcon.classList.remove('hidden');
    if (btnText) btnText.textContent = 'Hazırlanıyor...';
    
    try {
        // API'den güncel start URL al
        const response = await fetch('api/refresh-start-url.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ meeting_id: meetingId })
        });
        
        const data = await response.json();
        console.log('📦 API Response:', data);
        
        if (data.success && data.data && data.data.start_url) {
            // Güncel URL ile toplantıyı başlat
            console.log('✅ Opening meeting with fresh URL');
            if (btnText) btnText.textContent = 'Açılıyor...';
            
            // URL'ye kullanıcı adını ekle ve yeni sekmede aç
            var startUrl = addUserNameToZoomUrl(data.data.start_url);
            console.log('🔗 URL with user name:', startUrl);
            window.open(startUrl, '_blank');
            
            // Başarı bildirimi
            showNotification('Toplantı yeni sekmede açılıyor...', 'success');
            
            // Buton durumunu resetle
            setTimeout(() => {
                resetStartButton(buttonElement);
            }, 2000);
            
        } else {
            // Hata durumunda fallback - mevcut URL'yi dene
            console.warn('⚠️ API failed, trying fallback');
            showNotification(data.message || 'Güncel URL alınamadı, mevcut URL deneniyor...', 'warning');
            
            // Sayfayı yenile ve eski URL'yi kullanmayı dene
            resetStartButton(buttonElement);
        }
        
    } catch (error) {
        console.error('❌ Start meeting error:', error);
        showNotification('Bağlantı hatası: ' + error.message, 'error');
        resetStartButton(buttonElement);
    }
}

/**
 * Başlat butonunu varsayılan durumuna döndür
 */
function resetStartButton(buttonElement) {
    const startIcon = buttonElement.querySelector('.start-icon');
    const loadingIcon = buttonElement.querySelector('.loading-icon');
    const btnText = buttonElement.querySelector('.btn-text');
    
    buttonElement.disabled = false;
    if (startIcon) startIcon.classList.remove('hidden');
    if (loadingIcon) loadingIcon.classList.add('hidden');
    if (btnText) btnText.textContent = 'Başlat';
}

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
    
    var html =         "<div class=\"space-y-6\">" +
 +
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
                        "<button onclick=\"copyToClipboard('Meeting ID: " + meetingId.replace(/'/g, "\\'") + "', this)\" " +
                               "class=\"p-2 text-gray-400 hover:text-green-600 transition-colors copy-btn\" title=\"Meeting ID Kopyala\">" +
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
                        "<button onclick=\"copyToClipboard('Toplantı Şifresi: " + meeting.zoom_password.replace(/'/g, "\\'") + "', this)\" " +
                               "class=\"p-2 text-gray-400 hover:text-green-600 transition-colors copy-btn\" title=\"Şifre Kopyala\">" +
                            "<i class=\"fas fa-copy\"></i>" +
                        "</button>" +
                    "</div>";
        }
        
        // Join URL with copy button (Participant Link)
        var joinUrl = meeting.zoom_join_url || meeting.meeting_link;
        if (joinUrl) {
            html += "<div class=\"flex items-center justify-between bg-white p-3 rounded border\">" +
                        "<div class=\"flex-1 min-w-0\">" +
                            "<p class=\"text-xs text-gray-500 mb-1\">Katılımcı Linki</p>" +
                            "<p class=\"text-sm text-gray-900 truncate\">" + joinUrl + "</p>" +
                        "</div>" +
                        "<button onclick=\"copyToClipboard('Katılımcı Linki: " + joinUrl.replace(/'/g, "\\'") + "', this)\" " +
                               "class=\"p-2 ml-2 text-gray-400 hover:text-green-600 transition-colors copy-btn\" title=\"Katılımcı Link Kopyala\">" +
                            "<i class=\"fas fa-copy\"></i>" +
                        "</button>" +
                    "</div>";
        }
        
        // Host URL with copy button (Admin Link) - Only for meeting owner
        if (meeting.zoom_start_url && meeting.user_id == window.APP_CONFIG.user_id) {
            html += "<div class=\"flex items-center justify-between bg-white p-3 rounded border\">" +
                        "<div class=\"flex-1 min-w-0\">" +
                            "<p class=\"text-xs text-gray-500 mb-1\">Admin Linki (Host)</p>" +
                            "<p class=\"text-sm text-gray-900 truncate\">" + meeting.zoom_start_url + "</p>" +
                        "</div>" +
                        "<button onclick=\"copyToClipboard('Admin Linki (Host): " + meeting.zoom_start_url.replace(/'/g, "\\'") + "', this)\" " +
                               "class=\"p-2 ml-2 text-gray-400 hover:text-green-600 transition-colors copy-btn\" title=\"Admin Link Kopyala\">" +
                            "<i class=\"fas fa-copy\"></i>" +
                        "</button>" +
                    "</div>";
        }
        
        // Copy all info button - Safe string building
        var allInfo = "Toplantı: " + (meeting.title || "Bilinmiyor") + "\\n" +
                     "Tarih: " + new Date(meeting.date).toLocaleDateString("tr-TR") + "\\n" +
                     "Saat: " + (meeting.start_time || "Bilinmiyor") + " - " + (meeting.end_time || "Bilinmiyor") + "\\n" +
                     (meetingId !== "Bilinmiyor" ? "Meeting ID: " + meetingId + "\\n" : "") +
                     (meeting.zoom_password ? "Şifre: " + meeting.zoom_password + "\\n" : "") +
                     (joinUrl ? "Katılımcı Link: " + joinUrl + "\\n" : "") +
                     (meeting.zoom_start_url && meeting.user_id == window.APP_CONFIG.user_id ? "Admin Link: " + meeting.zoom_start_url : "");
        
        // Elegant copy info section - Build clean text for textarea
        var copyText = "Toplantı: " + meeting.title + "\n" +
                      "Tarih: " + new Date(meeting.date).toLocaleDateString("tr-TR") + "\n" +
                      "Saat: " + meeting.start_time + " - " + meeting.end_time + "\n";
        
        if (meetingId !== "Bilinmiyor") {
            copyText += "Meeting ID: " + meetingId + "\n";
        }
        if (meeting.zoom_password) {
            copyText += "Şifre: " + meeting.zoom_password + "\n";
        }
        if (joinUrl) {
            copyText += "Katılımcı Link: " + joinUrl;
        }
        // NOT ADDING Admin Link for security reasons
        
        html += "<div class=\"pt-4 border-t border-gray-200\">" +
                    "<div class=\"bg-gray-50 rounded-lg p-4\">" +
                        "<div class=\"flex items-center justify-between mb-3\">" +
                            "<h5 class=\"text-sm font-medium text-gray-700 flex items-center\">" +
                                "<i class=\"fas fa-info-circle mr-2 text-blue-500\"></i>" +
                                "Paylaşılabilir Bilgiler" +
                            "</h5>" +
                            "<button onclick=\"copyElegantInfo(this)\" " +
                                   "class=\"inline-flex items-center px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors duration-200 shadow-sm hover:shadow-md\" " +
                                   "title=\"Tüm bilgileri kopyala\">" +
                                "<i class=\"fas fa-copy mr-1.5\"></i>Kopyala" +
                            "</button>" +
                        "</div>" +
                        "<textarea id=\"meeting-info-text\" readonly " +
                                 "class=\"w-full h-32 px-3 py-2 text-sm bg-white border border-gray-200 rounded-md resize-none font-mono text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent cursor-text select-all\" " +
                                 "onclick=\"this.select()\">" + copyText.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#x27;') + "</textarea>" +
                        "<p class=\"text-xs text-gray-500 mt-2 flex items-center\">" +
                            "<i class=\"fas fa-lightbulb mr-1 text-yellow-500\"></i>" +
                            "Metin alanına tıklayarak seçebilir veya Kopyala butonunu kullanabilirsiniz" +
                        "</p>" +
                    "</div>" +
                "</div>";
        
        // 1 gün geçmiş kontrol
        var meetingDate = new Date(meeting.date);
        var oneDayAgo = new Date();
        oneDayAgo.setDate(oneDayAgo.getDate() - 1);
        var isPastMoreThanOneDay = meetingDate < oneDayAgo;
        
        // Action buttons - Sadece 1 günden eski değilse göster
        if (!isPastMoreThanOneDay) {
        html += "<div class=\"flex gap-2 pt-2\">";
        
        // Katıl butonu - Tüm kullanıcılar için (Normal participant)
        if (joinUrl) {
            var joinUrlWithName = addUserNameToZoomUrl(joinUrl);
            html += "<a href=\"" + joinUrlWithName + "\" target=\"_blank\" " +
                           "class=\"flex-1 inline-flex items-center justify-center px-4 py-2 bg-gradient-to-r from-blue-500 to-indigo-600 text-white text-sm rounded-xl hover:from-blue-600 hover:to-indigo-700 transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl\" " +
                       "title=\"Toplantıya Normal Katılımcı Olarak Katıl\">" +
                        "<i class=\"fas fa-video mr-2\"></i>Katıl" +
                    "</a>";
        }
        
        // Başlat butonu - Sadece toplantı sahibi için (Admin/Host yetkisi)
        if (meeting.zoom_start_url && meeting.user_id == window.APP_CONFIG.user_id) {
            var startUrlWithName = addUserNameToZoomUrl(meeting.zoom_start_url);
            html += "<a href=\"" + startUrlWithName + "\" target=\"_blank\" " +
                           "class=\"flex-1 inline-flex items-center justify-center px-4 py-2 bg-gradient-to-r from-green-500 to-emerald-600 text-white text-sm rounded-xl hover:from-green-600 hover:to-emerald-700 transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl\" " +
                       "title=\"Toplantıyı Admin Olarak Başlat\">" +
                        "<i class=\"fas fa-crown mr-2\"></i>Başlat (Admin)" +
                    "</a>";
            }
            
            html += "</div>";
        } else {
            // Geçmiş toplantı - Kayıt ve rapor bilgileri
            html += "<div class=\"bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200 rounded-xl p-4 mt-4\">" +
                        "<div class=\"flex items-center justify-between\">" +
                            "<div class=\"flex items-center\">" +
                                "<div class=\"w-8 h-8 bg-gradient-to-br from-amber-400 to-orange-500 rounded-full flex items-center justify-center mr-3\">" +
                                    "<i class=\"fas fa-clock text-white text-sm\"></i>" +
                                "</div>" +
                                "<div>" +
                                    "<p class=\"text-sm font-semibold text-amber-800\">Geçmiş Toplantı</p>" +
                                    "<p class=\"text-xs text-amber-700\">Bu toplantı tamamlandı.</p>" +
                                "</div>" +
                            "</div>" +
                            "<button onclick=\"loadMeetingRecordings(" + meeting.id + ", '" + (meeting.zoom_meeting_id || meeting.meeting_id || "") + "')\" " +
                                   "class=\"inline-flex items-center px-3 py-1.5 bg-purple-600 text-white text-xs font-medium rounded-lg hover:bg-purple-700 transition-colors\">" +
                                "<i class=\"fas fa-video mr-1.5\"></i>Kayıtları Gör" +
                            "</button>" +
                        "</div>" +
                    "</div>";
            
            // Kayıt bilgileri container
            html += "<div id=\"recordings-container-" + meeting.id + "\" class=\"hidden mt-4\"></div>";
        }
        
        html += "</div>";
        
        html += "</div></div>";
    }
    
    // Geçmiş toplantılar için de kayıt bilgisi göster (approved değilse bile)
    var meetingDateCheck = new Date(meeting.date);
    var oneDayAgoCheck = new Date();
    oneDayAgoCheck.setDate(oneDayAgoCheck.getDate() - 1);
    var isPastMeeting = meetingDateCheck < oneDayAgoCheck;
    
    if (isPastMeeting && meeting.status !== "approved") {
        html += "<div class=\"bg-gray-50 border border-gray-200 rounded-lg p-4 mt-4\">" +
                    "<div class=\"flex items-center justify-between\">" +
                        "<div class=\"flex items-center\">" +
                            "<i class=\"fas fa-history text-gray-400 mr-2\"></i>" +
                            "<span class=\"text-sm text-gray-600\">Geçmiş Toplantı</span>" +
                        "</div>" +
                        "<button onclick=\"loadMeetingRecordings(" + meeting.id + ", '" + (meeting.zoom_meeting_id || meeting.meeting_id || "") + "')\" " +
                               "class=\"inline-flex items-center px-3 py-1.5 bg-gray-600 text-white text-xs font-medium rounded-lg hover:bg-gray-700 transition-colors\">" +
                            "<i class=\"fas fa-video mr-1.5\"></i>Kayıtları Kontrol Et" +
                        "</button>" +
                    "</div>" +
                "</div>";
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

// Meeting işlemleri
async function cancelMeeting(meetingId) {
    // Debug için log ekle
    console.log('cancelMeeting called with ID:', meetingId);
    
    try {
        // Confirm dialog'ı göster ve sonucunu bekle
        const confirmResult = confirm('Bu toplantıyı iptal etmek istediğinizden emin misiniz?');
        console.log('Confirm result:', confirmResult);
        
        // Promise ise await ile bekle
        const finalResult = confirmResult instanceof Promise ? await confirmResult : confirmResult;
        console.log('Final confirm result:', finalResult);
        
        // Sadece kullanıcı onay verdiyse işlemi yap
        if (finalResult === true) {
            console.log('User confirmed, proceeding with cancellation...');
            
            const response = await fetch('api/cancel-meeting.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meeting_id: meetingId,
                    csrf_token: window.APP_CONFIG.csrf_token
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                showNotification('Toplantı başarıyla iptal edildi!', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showNotification(data.message || 'Toplantı iptal edilirken hata oluştu!', 'error');
            }
        } else {
            console.log('User cancelled the operation');
        }
    } catch (error) {
        console.error('Error in cancelMeeting:', error);
        showNotification('Bir hata oluştu!', 'error');
    }
}

async function deleteMeeting(meetingId) {
    // Debug için log ekle
    console.log('deleteMeeting called with ID:', meetingId);
    
    try {
        // Confirm dialog'ı göster ve sonucunu bekle
        const confirmResult = confirm('Bu toplantıyı kalıcı olarak silmek istediğinizden emin misiniz?');
        console.log('Delete confirm result:', confirmResult);
        
        // Promise ise await ile bekle
        const finalResult = confirmResult instanceof Promise ? await confirmResult : confirmResult;
        console.log('Final delete confirm result:', finalResult);
        
        // Sadece kullanıcı onay verdiyse işlemi yap
        if (finalResult === true) {
            console.log('User confirmed deletion, proceeding...');
            
            const response = await fetch('api/delete-meeting.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meeting_id: meetingId,
                    csrf_token: window.APP_CONFIG.csrf_token
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                showNotification('Toplantı başarıyla silindi!', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showNotification(data.message || 'Toplantı silinirken hata oluştu!', 'error');
            }
        } else {
            console.log('User cancelled the deletion');
        }
    } catch (error) {
        console.error('Error in deleteMeeting:', error);
        showNotification('Bir hata oluştu!', 'error');
    }
}

// Simple and reliable copy function
function copyToClipboard(text, button = null) {
    console.log('🔄 COPY FUNCTION CALLED');
    console.log('📝 Text to copy:', text);
    console.log('🎯 Button element:', button);
    
    if (!text) {
        console.error('❌ No text provided');
        alert('Kopyalanacak metin bulunamadı!');
        return;
    }
    
    // Try modern clipboard first
    if (navigator.clipboard && window.isSecureContext) {
        console.log('🚀 Trying modern clipboard API');
        navigator.clipboard.writeText(text).then(function() {
            console.log('✅ Modern clipboard SUCCESS');
            showSuccessMessage(button, 'Kopyalandı!');
        }).catch(function(err) {
            console.error('❌ Modern clipboard FAILED:', err);
            tryLegacyCopy(text, button);
        });
    } else {
        console.log('⚠️ Modern clipboard not available, using legacy method');
        tryLegacyCopy(text, button);
    }
}

// Legacy copy method
function tryLegacyCopy(text, button) {
    console.log('🔄 Trying legacy copy method');
    
    var textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'absolute';
    textArea.style.left = '-9999px';
    textArea.style.top = '0';
    textArea.setAttribute('readonly', '');
    
    document.body.appendChild(textArea);
    
    try {
        textArea.select();
        textArea.setSelectionRange(0, 99999);
        
        var success = document.execCommand('copy');
        console.log('📋 execCommand result:', success);
        
        if (success) {
            console.log('✅ Legacy copy SUCCESS');
            showSuccessMessage(button, 'Kopyalandı!');
        } else {
            console.error('❌ execCommand returned false');
            showErrorMessage(button, 'Kopyalama başarısız!');
        }
    } catch (err) {
        console.error('❌ Legacy copy EXCEPTION:', err);
        showErrorMessage(button, 'Kopyalama desteklenmiyor!');
    } finally {
        document.body.removeChild(textArea);
    }
}

// Success message
function showSuccessMessage(button, message) {
    console.log('✅ Showing success message');
    
    // Show alert as backup
    alert(message);
    
    // Button visual feedback
    if (button) {
        var originalContent = button.innerHTML;
        var originalClass = button.className;
        
        button.innerHTML = '<i class="fas fa-check"></i>';
        button.style.color = '#10b981';
        
        setTimeout(function() {
            button.innerHTML = originalContent;
            button.style.color = '';
            button.className = originalClass;
        }, 2000);
    }
    
    // Show notification
    showNotification(message, 'success');
}

// Error message
function showErrorMessage(button, message) {
    console.log('❌ Showing error message');
    
    // Show alert as backup
    alert(message);
    
    // Button visual feedback
    if (button) {
        var originalContent = button.innerHTML;
        var originalClass = button.className;
        
        button.innerHTML = '<i class="fas fa-times"></i>';
        button.style.color = '#ef4444';
        
        setTimeout(function() {
            button.innerHTML = originalContent;
            button.style.color = '';
            button.className = originalClass;
        }, 2000);
    }
    
    // Show notification
    showNotification(message, 'error');
}



// Notification system
function showNotification(message, type = 'info') {
    // Var olan notification varsa kaldır
    const existing = document.getElementById('notification');
    if (existing) {
        existing.remove();
    }
    
    const notification = document.createElement('div');
    notification.id = 'notification';
    notification.className = `fixed top-4 right-4 z-[10000] px-6 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full`;
    
    const colors = {
        'success': 'bg-green-600 text-white',
        'error': 'bg-red-600 text-white',
        'warning': 'bg-yellow-600 text-white',
        'info': 'bg-blue-600 text-white'
    };
    
    const icons = {
        'success': 'fas fa-check-circle',
        'error': 'fas fa-times-circle',
        'warning': 'fas fa-exclamation-triangle',
        'info': 'fas fa-info-circle'
    };
    
    notification.className += ` ${colors[type] || colors.info}`;
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="${icons[type] || icons.info} mr-2"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Animasyon ile göster
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);
    
    // 3 saniye sonra kaldır
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
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

// Close modal function
function closeMeetingModal() {
    document.getElementById('meeting-modal').classList.add('hidden');
}

// Elegant copy function for the textarea
function copyElegantInfo(button) {
    console.log('🔄 ELEGANT COPY CALLED');
    
    var textarea = document.getElementById('meeting-info-text');
    if (!textarea) {
        console.error('❌ Textarea not found');
        showErrorMessage(button, 'Metin alanı bulunamadı!');
        return;
    }
    
    var textToCopy = textarea.value;
    if (!textToCopy || textToCopy.trim().length === 0) {
        console.error('❌ No text to copy');
        showErrorMessage(button, 'Kopyalanacak metin yok!');
        return;
    }
    
    // Clean up the text - remove any HTML entities that might have been added
    textToCopy = textToCopy
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#x27;/g, "'");
    
    console.log('📝 Cleaned text to copy:', textToCopy.substring(0, 50) + '...');
    
    // Use main copy function
    copyToClipboard(textToCopy, button);
}

// Ultra simple copy function as backup
function simpleCopy(text) {
    console.log('🔄 SIMPLE COPY CALLED with:', text);
    
    // Method 1: Try modern clipboard
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            console.log('✅ Simple modern copy SUCCESS');
            alert('Kopyalandı: ' + text.substring(0, 20) + '...');
        }).catch(err => {
            console.log('❌ Simple modern copy FAILED:', err);
            legacySimpleCopy(text);
        });
    } else {
        legacySimpleCopy(text);
    }
}

// Legacy simple copy
function legacySimpleCopy(text) {
    console.log('🔄 LEGACY SIMPLE COPY');
    
    var textarea = document.createElement('textarea');
    textarea.value = text;
    document.body.appendChild(textarea);
    textarea.select();
    
    try {
        var success = document.execCommand('copy');
        if (success) {
            alert('Kopyalandı: ' + text.substring(0, 20) + '...');
        } else {
            alert('Kopyalama başarısız!');
        }
    } catch (e) {
        alert('Kopyalama desteklenmiyor!');
    }
    
    document.body.removeChild(textarea);
}

/**
 * Toplantı kayıtlarını yükle ve göster
 */
async function loadMeetingRecordings(localMeetingId, zoomMeetingId) {
    console.log('📹 Loading recordings for meeting:', localMeetingId, zoomMeetingId);
    
    var container = document.getElementById('recordings-container-' + localMeetingId);
    if (!container) {
        console.error('Container not found for meeting:', localMeetingId);
        return;
    }
    
    // Toggle visibility
    if (!container.classList.contains('hidden') && container.innerHTML !== '') {
        container.classList.add('hidden');
        return;
    }
    
    // Show loading
    container.classList.remove('hidden');
    container.innerHTML = '<div class="flex items-center justify-center py-4">' +
                            '<i class="fas fa-spinner fa-spin text-purple-600 mr-2"></i>' +
                            '<span class="text-gray-600">Kayıtlar yükleniyor...</span>' +
                          '</div>';
    
    try {
        // Kayıtları API'den çek
        var response = await fetch('api/get-recordings.php?meeting_id=' + encodeURIComponent(zoomMeetingId));
        var data = await response.json();
        
        if (data.success && data.recordings && data.recordings.length > 0) {
            var html = '<div class="bg-purple-50 border border-purple-200 rounded-lg p-4">' +
                        '<h5 class="text-sm font-semibold text-purple-800 mb-3 flex items-center">' +
                            '<i class="fas fa-video mr-2"></i>Toplantı Kayıtları' +
                        '</h5>' +
                        '<div class="space-y-2">';
            
            data.recordings.forEach(function(recording) {
                var fileSize = recording.file_size ? formatFileSize(recording.file_size) : '';
                var duration = recording.recording_start ? formatDuration(recording.recording_start, recording.recording_end) : '';
                
                html += '<div class="bg-white p-3 rounded border border-purple-100 flex items-center justify-between">' +
                            '<div class="flex items-center">' +
                                '<i class="fas fa-file-video text-purple-500 mr-3"></i>' +
                                '<div>' +
                                    '<p class="text-sm font-medium text-gray-800">' + (recording.recording_type || 'Video') + '</p>' +
                                    '<p class="text-xs text-gray-500">' + fileSize + (duration ? ' • ' + duration : '') + '</p>' +
                                '</div>' +
                            '</div>' +
                            '<div class="flex gap-2">';
                
                if (recording.play_url) {
                    html += '<a href="' + recording.play_url + '" target="_blank" ' +
                               'class="inline-flex items-center px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">' +
                                '<i class="fas fa-play mr-1"></i>İzle' +
                            '</a>';
                }
                
                if (recording.download_url) {
                    html += '<a href="' + recording.download_url + '" target="_blank" ' +
                               'class="inline-flex items-center px-2 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700">' +
                                '<i class="fas fa-download mr-1"></i>İndir' +
                            '</a>';
                }
                
                html += '</div></div>';
            });
            
            html += '</div></div>';
            
            // Toplantı raporu bilgileri
            if (data.report) {
                html += '<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mt-3">' +
                            '<h5 class="text-sm font-semibold text-blue-800 mb-3 flex items-center">' +
                                '<i class="fas fa-chart-bar mr-2"></i>Toplantı Raporu' +
                            '</h5>' +
                            '<div class="grid grid-cols-2 gap-3">' +
                                '<div class="bg-white p-2 rounded text-center">' +
                                    '<p class="text-lg font-bold text-blue-600">' + (data.report.participants_count || 0) + '</p>' +
                                    '<p class="text-xs text-gray-500">Katılımcı</p>' +
                                '</div>' +
                                '<div class="bg-white p-2 rounded text-center">' +
                                    '<p class="text-lg font-bold text-blue-600">' + (data.report.duration || 0) + ' dk</p>' +
                                    '<p class="text-xs text-gray-500">Süre</p>' +
                                '</div>' +
                            '</div>' +
                        '</div>';
            }
            
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center">' +
                                    '<i class="fas fa-info-circle text-gray-400 text-2xl mb-2"></i>' +
                                    '<p class="text-sm text-gray-600">Bu toplantı için kayıt bulunamadı.</p>' +
                                    '<p class="text-xs text-gray-400 mt-1">Zoom Cloud Recording aktif olmayabilir.</p>' +
                                  '</div>';
        }
    } catch (error) {
        console.error('Error loading recordings:', error);
        container.innerHTML = '<div class="bg-red-50 border border-red-200 rounded-lg p-4 text-center">' +
                                '<i class="fas fa-exclamation-circle text-red-400 text-2xl mb-2"></i>' +
                                '<p class="text-sm text-red-600">Kayıtlar yüklenirken hata oluştu.</p>' +
                              '</div>';
    }
}

// Dosya boyutunu formatla
function formatFileSize(bytes) {
    if (!bytes) return '';
    var sizes = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
}

// Süreyi formatla
function formatDuration(start, end) {
    if (!start || !end) return '';
    var startDate = new Date(start);
    var endDate = new Date(end);
    var diffMs = endDate - startDate;
    var diffMins = Math.round(diffMs / 60000);
    return diffMins + ' dakika';
}
</script>