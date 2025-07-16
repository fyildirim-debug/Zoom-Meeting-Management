<?php
$pageTitle = 'Dashboard';
require_once 'config/config.php';
require_once 'config/auth.php';

requireLogin();

$currentUser = getCurrentUser();
$isAdmin = isAdmin();

// Dashboard istatistikleri
$stats = [];

if ($isAdmin) {
    // Admin istatistikleri
    try {
        // Toplam toplantı sayısı (iptal edilenler hariç)
        $stmt = $pdo->query("SELECT COUNT(*) FROM meetings WHERE status != 'cancelled'");
        $stats['total_meetings'] = $stmt->fetchColumn();
        
        // Bekleyen talep sayısı
        $stmt = $pdo->query("SELECT COUNT(*) FROM meetings WHERE status = 'pending'");
        $stats['pending_meetings'] = $stmt->fetchColumn();
        
        // Onaylanmış toplantı sayısı
        $stmt = $pdo->query("SELECT COUNT(*) FROM meetings WHERE status = 'approved'");
        $stats['approved_meetings'] = $stmt->fetchColumn();
        
        // Bu ay toplantı sayısı (iptal edilenler hariç) - MySQL/SQLite uyumlu
        if (DB_TYPE === 'mysql') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM meetings WHERE DATE_FORMAT(date, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') AND status != 'cancelled'");
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM meetings WHERE strftime('%Y-%m', date) = strftime('%Y-%m', 'now') AND status != 'cancelled'");
        }
        $stats['this_month_meetings'] = $stmt->fetchColumn();
        
        // Aktif kullanıcı sayısı
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'");
        $stats['total_users'] = $stmt->fetchColumn();
        
        // Toplam birim sayısı
        $stmt = $pdo->query("SELECT COUNT(*) FROM departments");
        $stats['total_departments'] = $stmt->fetchColumn();
        
        // Aktif Zoom hesap sayısı
        $stmt = $pdo->query("SELECT COUNT(*) FROM zoom_accounts WHERE status = 'active'");
        $stats['active_zoom_accounts'] = $stmt->fetchColumn();
        
        // Son 7 günün toplantı dağılımı (iptal edilenler hariç) - gelecek 7 gün dahil
        if (DB_TYPE === 'mysql') {
            $stmt = $pdo->query("
                SELECT DATE(date) as meeting_date, COUNT(*) as meeting_count
                FROM meetings
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    AND date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                    AND status != 'cancelled'
                GROUP BY DATE(date)
                ORDER BY DATE(date) ASC
            ");
        } else {
            $stmt = $pdo->query("
                SELECT DATE(date) as meeting_date, COUNT(*) as meeting_count
                FROM meetings
                WHERE date >= DATE('now', '-7 days')
                    AND date <= DATE('now', '+7 days')
                    AND status != 'cancelled'
                GROUP BY DATE(date)
                ORDER BY DATE(date) ASC
            ");
        }
        $stats['weekly_chart'] = $stmt->fetchAll();
        
        // Birim bazlı toplantı dağılımı (iptal edilenler hariç)
        $stmt = $pdo->query("
            SELECT d.name, COUNT(m.id) as meeting_count
            FROM departments d
            LEFT JOIN meetings m ON d.id = m.department_id AND m.status != 'cancelled'
            GROUP BY d.id, d.name
            ORDER BY meeting_count DESC
            LIMIT 5
        ");
        $stats['department_chart'] = $stmt->fetchAll();
        
        // Son aktiviteler - yeni aktivite kayıt sistemi
        $stats['recent_activities'] = getRecentActivities(15);
        
    } catch (Exception $e) {
        writeLog("Dashboard admin stats error: " . $e->getMessage(), 'error');
    }
} else {
    // Kullanıcı istatistikleri
    try {
        $userId = $currentUser['id'];
        
        // Kullanıcının toplam toplantı sayısı (iptal edilenler hariç)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE user_id = ? AND status != 'cancelled'");
        $stmt->execute([$userId]);
        $stats['my_total_meetings'] = $stmt->fetchColumn();
        
        // Bekleyen toplantı sayısı
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        $stats['my_pending_meetings'] = $stmt->fetchColumn();
        
        // Onaylanmış toplantı sayısı
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE user_id = ? AND status = 'approved'");
        $stmt->execute([$userId]);
        $stats['my_approved_meetings'] = $stmt->fetchColumn();
        
        // Bu ay toplantı sayısı (iptal edilenler hariç) - MySQL/SQLite uyumlu
        if (DB_TYPE === 'mysql') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM meetings
                WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') AND status != 'cancelled'
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM meetings
                WHERE user_id = ? AND strftime('%Y-%m', date) = strftime('%Y-%m', 'now') AND status != 'cancelled'
            ");
        }
        $stmt->execute([$userId]);
        $stats['my_this_month_meetings'] = $stmt->fetchColumn();
        
        // Yaklaşan toplantılar (7 gün) - Birim bazlı gösterim (bekleyen + onaylı)
        $stmt = $pdo->prepare("
            SELECT * FROM meetings
            WHERE department_id = ? AND status IN ('pending', 'approved') AND date >= CURDATE() AND date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ORDER BY date ASC, start_time ASC
            LIMIT 10
        ");
        $stmt->execute([$currentUser['department_id']]);
        $stats['upcoming_meetings'] = $stmt->fetchAll();
        
        // Birim haftalık limit kontrolü
        if ($currentUser['department_id']) {
            $stmt = $pdo->prepare("SELECT weekly_limit FROM departments WHERE id = ?");
            $stmt->execute([$currentUser['department_id']]);
            $weeklyLimit = $stmt->fetchColumn();
            
            // Bu haftanın başlangıcı
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            $weekEnd = date('Y-m-d', strtotime('sunday this week'));
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM meetings
                WHERE department_id = ? AND status = 'approved' AND date BETWEEN ? AND ?
            ");
            $stmt->execute([$currentUser['department_id'], $weekStart, $weekEnd]);
            $weeklyUsed = $stmt->fetchColumn();
            
            $stats['weekly_limit'] = $weeklyLimit;
            $stats['weekly_used'] = $weeklyUsed;
            $stats['weekly_remaining'] = max(0, $weeklyLimit - $weeklyUsed);
        }
        
        // Kişisel istatistikler
        
        // Onay oranı hesaplama (iptal edilenler hariç)
        $stmt = $pdo->prepare("
            SELECT status, COUNT(*) as count
            FROM meetings
            WHERE user_id = ? AND status != 'cancelled'
            GROUP BY status
        ");
        $stmt->execute([$userId]);
        $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $totalRequests = array_sum($statusCounts);
        $approvedCount = $statusCounts['approved'] ?? 0;
        $stats['approval_rate'] = $totalRequests > 0 ? round(($approvedCount / $totalRequests) * 100) : 0;
        
        
        // En popüler saat dilimi
        $stmt = $pdo->prepare("
            SELECT HOUR(start_time) as hour, COUNT(*) as count
            FROM meetings
            WHERE user_id = ? AND status = 'approved'
            GROUP BY HOUR(start_time)
            ORDER BY count DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $popularHour = $stmt->fetch();
        $stats['popular_hour'] = $popularHour ? $popularHour['hour'] . ':00' : null;
        
        // Ortalama toplantı süresi
        $stmt = $pdo->prepare("
            SELECT AVG(TIMESTAMPDIFF(MINUTE, start_time, end_time)) as avg_duration
            FROM meetings
            WHERE user_id = ? AND status = 'approved'
        ");
        $stmt->execute([$userId]);
        $avgDuration = $stmt->fetchColumn();
        $stats['avg_duration'] = $avgDuration ? round($avgDuration) : 0;
        
        // Bu ayki günlük ortalama (iptal edilenler hariç) - MySQL/SQLite uyumlu
        if (DB_TYPE === 'mysql') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total_days
                FROM (
                    SELECT DISTINCT DATE(date)
                    FROM meetings
                    WHERE user_id = ? AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE()) AND status != 'cancelled'
                ) as unique_days
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total_days
                FROM (
                    SELECT DISTINCT DATE(date)
                    FROM meetings
                    WHERE user_id = ? AND strftime('%m', date) = strftime('%m', 'now') AND strftime('%Y', date) = strftime('%Y', 'now') AND status != 'cancelled'
                ) as unique_days
            ");
        }
        $stmt->execute([$userId]);
        $activeDays = $stmt->fetchColumn();
        $stats['active_days_this_month'] = $activeDays;
        
    } catch (Exception $e) {
        writeLog("Dashboard user stats error: " . $e->getMessage(), 'error');
    }
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
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 ">
                        Hoş geldiniz, <?php echo $currentUser['name']; ?>! 👋
                    </h1>
                    <p class="mt-2 text-gray-600 ">
                        <?php if ($isAdmin): ?>
                            Sistem genel durumunu ve istatistikleri buradan takip edebilirsiniz.
                        <?php else: ?>
                            Toplantılarınızı ve yaklaşan etkinlikleri buradan takip edebilirsiniz.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="mt-4 sm:mt-0">
                    <a href="new-meeting.php" class="btn-primary inline-flex items-center px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>
                        Yeni Toplantı
                    </a>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <!-- Admin Dashboard -->
            
            <!-- Quick Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Meetings -->
                <div class="bg-white  rounded-xl shadow-lg p-6 border border-gray-200 ">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 ">Toplam Toplantı</p>
                            <p class="text-3xl font-bold text-gray-900 "><?php echo $stats['total_meetings'] ?? 0; ?></p>
                            <p class="text-sm text-green-600">Bu ay: <?php echo $stats['this_month_meetings'] ?? 0; ?></p>
                        </div>
                        <div class="w-16 h-16 bg-blue-100  rounded-full flex items-center justify-center">
                            <i class="fas fa-video text-2xl text-blue-600 "></i>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Meetings -->
                <div class="bg-white  rounded-xl shadow-lg p-6 border border-gray-200 ">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 ">Bekleyen Talepler</p>
                            <p class="text-3xl font-bold text-gray-900 "><?php echo $stats['pending_meetings'] ?? 0; ?></p>
                            <p class="text-sm text-orange-600">Onay bekliyor</p>
                        </div>
                        <div class="w-16 h-16 bg-orange-100  rounded-full flex items-center justify-center">
                            <i class="fas fa-clock text-2xl text-orange-600 "></i>
                        </div>
                    </div>
                </div>
                
                <!-- Active Users -->
                <div class="bg-white  rounded-xl shadow-lg p-6 border border-gray-200 ">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 ">Aktif Kullanıcı</p>
                            <p class="text-3xl font-bold text-gray-900 "><?php echo $stats['total_users'] ?? 0; ?></p>
                            <p class="text-sm text-blue-600"><?php echo $stats['total_departments'] ?? 0; ?> birim</p>
                        </div>
                        <div class="w-16 h-16 bg-green-100  rounded-full flex items-center justify-center">
                            <i class="fas fa-users text-2xl text-green-600 "></i>
                        </div>
                    </div>
                </div>
                
                <!-- Zoom Accounts -->
                <div class="bg-white  rounded-xl shadow-lg p-6 border border-gray-200 ">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 ">Zoom Hesapları</p>
                            <p class="text-3xl font-bold text-gray-900 "><?php echo $stats['active_zoom_accounts'] ?? 0; ?></p>
                            <p class="text-sm text-green-600">Aktif hesap</p>
                        </div>
                        <div class="w-16 h-16 bg-purple-100  rounded-full flex items-center justify-center">
                            <i class="fas fa-camera text-2xl text-purple-600 "></i>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Recent Activities -->
            <div class="bg-white  rounded-xl shadow-lg border border-gray-200  mb-8">
                <div class="px-6 py-4 border-b border-gray-200 ">
                    <h3 class="text-lg font-semibold text-gray-900 ">Son Aktiviteler</h3>
                </div>
                <div class="divide-y divide-gray-200 ">
                    <?php if (!empty($stats['recent_activities'])): ?>
                        <?php foreach (array_slice($stats['recent_activities'], 0, 10) as $activity): ?>
                            <div class="p-6 flex items-center justify-between hover:bg-gray-50 transition-colors">
                                <div class="flex items-center space-x-4">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center bg-gray-100">
                                        <i class="fas <?php echo getActivityIcon($activity['action'], $activity['entity_type']); ?>"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">
                                            <?php echo htmlspecialchars($activity['name'] . ' ' . $activity['surname']); ?>
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            <?php echo getActivityDescription($activity['action'], $activity['entity_type'], $activity['entity_name'], $activity['details']); ?>
                                        </p>
                                        <p class="text-xs text-gray-400 mt-1">
                                            <?php echo formatDateTimeTurkish($activity['created_at']); ?>
                                        </p>
                                    </div>
                                </div>
                                <?php if ($activity['entity_type'] === 'meeting' && $activity['entity_id']): ?>
                                    <a href="meeting-details.php?id=<?php echo $activity['entity_id']; ?>"
                                       class="text-blue-600 hover:text-blue-500 text-sm">
                                        <i class="fas fa-info-circle"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-6 text-center text-gray-500 ">
                            <i class="fas fa-history text-4xl mb-4 text-gray-300"></i>
                            <p>Henüz aktivite bulunmuyor.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 ">
                    <a href="admin/meeting-approvals.php" class="text-blue-600 hover:text-blue-500 text-sm font-medium">
                        Tüm aktiviteleri görüntüle →
                    </a>
                </div>
            </div>

        <?php else: ?>
            <!-- User Dashboard -->
            
            <!-- Quick Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- My Total Meetings -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Toplam Toplantılarım</p>
                            <p class="text-3xl font-bold text-gray-900"><?php echo $stats['my_total_meetings'] ?? 0; ?></p>
                            <p class="text-sm text-blue-600">Bu ay: <?php echo $stats['my_this_month_meetings'] ?? 0; ?></p>
                        </div>
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-video text-2xl text-blue-600"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Approval Rate -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Onay Oranım</p>
                            <p class="text-3xl font-bold text-gray-900"><?php echo $stats['approval_rate'] ?? 0; ?>%</p>
                            <p class="text-sm text-green-600">Başarı oranı</p>
                        </div>
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-percentage text-2xl text-green-600"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Average Duration -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Ortalama Süre</p>
                            <p class="text-3xl font-bold text-gray-900"><?php echo $stats['avg_duration'] ?? 0; ?></p>
                            <p class="text-sm text-purple-600">dakika</p>
                        </div>
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-clock text-2xl text-purple-600"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Popular Hour -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Favori Saatim</p>
                            <p class="text-3xl font-bold text-gray-900"><?php echo $stats['popular_hour'] ?? '--:--'; ?></p>
                            <p class="text-sm text-indigo-600">En çok tercih</p>
                        </div>
                        <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-user-clock text-2xl text-indigo-600"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Secondary Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Pending Meetings -->
                <div class="bg-gradient-to-r from-orange-400 to-orange-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-orange-100 text-sm">Bekleyen Talepler</p>
                            <p class="text-3xl font-bold"><?php echo $stats['my_pending_meetings'] ?? 0; ?></p>
                            <p class="text-orange-200 text-sm">Onay bekliyor</p>
                        </div>
                        <div class="w-12 h-12 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                            <i class="fas fa-hourglass-half text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Approved Meetings -->
                <div class="bg-gradient-to-r from-green-400 to-green-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-green-100 text-sm">Onaylı Toplantılar</p>
                            <p class="text-3xl font-bold"><?php echo $stats['my_approved_meetings'] ?? 0; ?></p>
                            <p class="text-green-200 text-sm">Hazır</p>
                        </div>
                        <div class="w-12 h-12 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Active Days -->
                <div class="bg-gradient-to-r from-blue-400 to-blue-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-blue-100 text-sm">Bu Ay Aktif Gün</p>
                            <p class="text-3xl font-bold"><?php echo $stats['active_days_this_month'] ?? 0; ?></p>
                            <p class="text-blue-200 text-sm">Toplantı günü</p>
                        </div>
                        <div class="w-12 h-12 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                            <i class="fas fa-calendar-check text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Weekly Limit Card (if exists) -->
            <?php if (isset($stats['weekly_limit'])): ?>
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Haftalık Kullanım Durumu</h3>
                    <div class="text-sm text-gray-500">
                        <?php echo $stats['weekly_used']; ?> / <?php echo $stats['weekly_limit']; ?> kullanıldı
                    </div>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-4 mb-4">
                    <?php $percentage = $stats['weekly_limit'] > 0 ? ($stats['weekly_used'] / $stats['weekly_limit']) * 100 : 0; ?>
                    <div class="bg-gradient-to-r from-purple-500 to-indigo-600 h-4 rounded-full" style="width: <?php echo min(100, $percentage); ?>%"></div>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600">Kalan: <?php echo $stats['weekly_remaining']; ?> toplantı</span>
                    <span class="text-gray-600"><?php echo round($percentage); ?>% kullanıldı</span>
                </div>
            </div>
            <?php endif; ?>


            <!-- Department Meetings -->
            <div class="bg-white  rounded-xl shadow-lg border border-gray-200  mb-8">
                <div class="px-6 py-4 border-b border-gray-200 ">
                    <h3 class="text-lg font-semibold text-gray-900 ">Birim Toplantıları</h3>
                    <p class="text-sm text-gray-600 mt-1">Biriminizin yaklaşan tüm toplantıları</p>
                </div>
                <div class="divide-y divide-gray-200 ">
                    <?php if (!empty($stats['upcoming_meetings'])): ?>
                        <?php foreach ($stats['upcoming_meetings'] as $meeting): ?>
                            <div class="p-6 flex items-center justify-between">
                                <div class="flex items-center space-x-4">
                                    <div class="w-12 h-12 <?php echo $meeting['status'] === 'approved' ? 'bg-green-100' : 'bg-orange-100'; ?> rounded-lg flex items-center justify-center">
                                        <i class="fas fa-video <?php echo $meeting['status'] === 'approved' ? 'text-green-600' : 'text-orange-600'; ?>"></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($meeting['title']); ?></h4>
                                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full
                                                <?php echo $meeting['status'] === 'approved' ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800'; ?>">
                                                <?php echo $meeting['status'] === 'approved' ? 'Onaylı' : 'Bekliyor'; ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-500">
                                            <?php echo formatDateTurkish($meeting['date']); ?> - 
                                            <?php echo formatTime($meeting['start_time']); ?> - <?php echo formatTime($meeting['end_time']); ?>
                                        </p>
                                        
                                        <?php if ($meeting['user_id'] == $currentUser['id']): ?>
                                            <!-- Kendi toplantımız - tüm detayları göster -->
                                            <p class="text-sm text-gray-500 mt-1">
                                                Moderatör: <?php echo htmlspecialchars($meeting['moderator']); ?>
                                            </p>
                                            <?php if (!empty($meeting['description'])): ?>
                                                <p class="text-sm text-gray-600 mt-2 bg-gray-50 p-2 rounded">
                                                    <?php echo htmlspecialchars($meeting['description']); ?>
                                                </p>
                                            <?php endif; ?>
                                            <p class="text-xs text-blue-600 mt-1">
                                                <i class="fas fa-user mr-1"></i>Toplantı talebiniz
                                            </p>
                                        <?php else: ?>
                                            <!-- Başkasının toplantısı - sadece temel bilgiler -->
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-users mr-1"></i>Birim toplantısı
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <?php if ($meeting['meeting_link'] && $meeting['status'] === 'approved'): ?>
                                        <?php if ($meeting['user_id'] == $currentUser['id']): ?>
                                            <!-- Kendi toplantımız - Başlat -->
                                            <a href="<?php echo htmlspecialchars($meeting['meeting_link']); ?>"
                                               target="_blank"
                                               class="btn-primary px-3 py-2 text-sm">
                                                <i class="fas fa-play mr-1"></i>Başlat
                                            </a>
                                        <?php else: ?>
                                            <!-- Başkasının toplantısı - Katıl -->
                                            <a href="<?php echo htmlspecialchars($meeting['meeting_link']); ?>"
                                               target="_blank"
                                               class="btn-secondary px-3 py-2 text-sm">
                                                <i class="fas fa-sign-in-alt mr-1"></i>Katıl
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($meeting['user_id'] == $currentUser['id']): ?>
                                        <a href="meeting-details.php?id=<?php echo $meeting['id']; ?>"
                                           class="text-gray-400 hover:text-gray-600">
                                            <i class="fas fa-info-circle"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-6 text-center text-gray-500 ">
                            <i class="fas fa-calendar-times text-4xl mb-4 text-gray-300"></i>
                            <p>Birimde yaklaşan toplantı bulunmuyor.</p>
                            <a href="new-meeting.php" class="btn-primary inline-flex items-center px-4 py-2 mt-4">
                                <i class="fas fa-plus mr-2"></i>
                                Yeni Toplantı Talep Et
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 ">
                    <a href="my-meetings.php" class="text-blue-600 hover:text-blue-500 text-sm font-medium">
                        Tüm toplantıları görüntüle →
                    </a>
                </div>
            </div>

        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- New Meeting -->
            <div class="bg-gradient-to-r from-blue-500 to-purple-600 rounded-xl p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold mb-2">Yeni Toplantı</h3>
                        <p class="text-blue-100 text-sm mb-4">Hızla yeni bir toplantı talebi oluşturun</p>
                        <a href="new-meeting.php" class="bg-white text-blue-600 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-100 transition-colors">
                            Talep Oluştur
                        </a>
                    </div>
                    <i class="fas fa-plus-circle text-4xl text-blue-200"></i>
                </div>
            </div>
            
            <!-- Calendar View -->
            <div class="bg-gradient-to-r from-green-500 to-teal-600 rounded-xl p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold mb-2">Takvim Görünümü</h3>
                        <p class="text-green-100 text-sm mb-4">Tüm toplantıları takvim üzerinde görün</p>
                        <a href="calendar.php" class="bg-white text-green-600 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-100 transition-colors">
                            Takvimi Aç
                        </a>
                    </div>
                    <i class="fas fa-calendar-alt text-4xl text-green-200"></i>
                </div>
            </div>
            
            <!-- Reports -->
            <div class="bg-gradient-to-r from-orange-500 to-red-600 rounded-xl p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold mb-2">
                            <?php echo $isAdmin ? 'Raporlar' : 'Geçmiş'; ?>
                        </h3>
                        <p class="text-orange-100 text-sm mb-4">
                            <?php echo $isAdmin ? 'Detaylı sistem raporlarını görüntüleyin' : 'Geçmiş toplantılarınızı inceleyin'; ?>
                        </p>
                        <a href="<?php echo $isAdmin ? 'admin/reports.php' : 'my-meetings.php?filter=past'; ?>" 
                           class="bg-white text-orange-600 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-100 transition-colors">
                            <?php echo $isAdmin ? 'Raporları Görüntüle' : 'Geçmişi Görüntüle'; ?>
                        </a>
                    </div>
                    <i class="fas <?php echo $isAdmin ? 'fa-chart-bar' : 'fa-history'; ?> text-4xl text-orange-200"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$additionalScripts = '
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Chart.js konfigürasyonu
Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue("--text-secondary");
Chart.defaults.borderColor = getComputedStyle(document.documentElement).getPropertyValue("--border-color");

' . ($isAdmin ? '
// Weekly Chart
const weeklyCtx = document.getElementById("weeklyChart");
if (weeklyCtx) {
    const weeklyData = ' . json_encode($stats['weekly_chart'] ?? []) . ';
    
    new Chart(weeklyCtx, {
        type: "line",
        data: {
            labels: weeklyData.map(item => {
                const date = new Date(item.meeting_date);
                return date.toLocaleDateString("tr-TR", {day: "2-digit", month: "2-digit"});
            }),
            datasets: [{
                label: "Toplantı Sayısı",
                data: weeklyData.map(item => item.meeting_count),
                borderColor: "rgb(99, 102, 241)",
                backgroundColor: "rgba(99, 102, 241, 0.1)",
                tension: 0.1,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
}

// Department Chart
const deptCtx = document.getElementById("departmentChart");
if (deptCtx) {
    const deptData = ' . json_encode($stats['department_chart'] ?? []) . ';
    
    new Chart(deptCtx, {
        type: "doughnut",
        data: {
            labels: deptData.map(item => item.name),
            datasets: [{
                data: deptData.map(item => item.meeting_count),
                backgroundColor: [
                    "rgba(99, 102, 241, 0.8)",
                    "rgba(16, 185, 129, 0.8)",
                    "rgba(245, 101, 101, 0.8)",
                    "rgba(251, 191, 36, 0.8)",
                    "rgba(139, 92, 246, 0.8)"
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: "bottom"
                }
            }
        }
    });
}
' : '
') . '

// Auto refresh dashboard data every 5 minutes
setInterval(function() {
    location.reload();
}, 5 * 60 * 1000);

// Real-time notifications check
function checkNotifications() {
    fetch("api/check-notifications.php")
        .then(response => response.json())
        .then(data => {
            if (data.success && data.notifications.length > 0) {
                data.notifications.forEach(notification => {
                    showToast(notification.message, notification.type);
                });
            }
        })
        .catch(error => console.error("Notification check error:", error));
}

// Check notifications every 30 seconds
setInterval(checkNotifications, 30000);
</script>
';

include 'includes/footer.php';
?>