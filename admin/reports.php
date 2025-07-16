<?php
$pageTitle = 'Raporlar ve İstatistikler';
require_once '../config/config.php';
require_once '../config/auth.php';

requireLogin();
if (!isAdmin()) {
    redirect('../dashboard.php');
}

$currentUser = getCurrentUser();

// Tarih aralığı filtreleri
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // Bu ayın başı
$endDate = $_GET['end_date'] ?? date('Y-m-d'); // Bugün
$reportType = $_GET['report_type'] ?? 'overview';

// Export işlemleri
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportCSV($reportType, $startDate, $endDate);
    exit;
}

// Raporları oluştur
try {
    $reports = generateReports($startDate, $endDate, $reportType);
} catch (Exception $e) {
    writeLog("Reports page error: " . $e->getMessage(), 'error');
    $reports = [];
}

// Helper functions
function generateReports($startDate, $endDate, $reportType) {
    global $pdo;
    
    $reports = [];
    
    // Genel istatistikler
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_meetings,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_meetings,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_meetings,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_meetings,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_meetings,
            AVG(TIMESTAMPDIFF(MINUTE, start_time, end_time)) as avg_duration
        FROM meetings 
        WHERE date BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $reports['overview'] = $stmt->fetch();
    
    // Günlük dağılım
    $stmt = $pdo->prepare("
        SELECT 
            DATE(date) as report_date,
            COUNT(*) as meeting_count,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count
        FROM meetings 
        WHERE date BETWEEN ? AND ?
        GROUP BY DATE(date)
        ORDER BY report_date
    ");
    $stmt->execute([$startDate, $endDate]);
    $reports['daily_distribution'] = $stmt->fetchAll();
    
    // Birim bazlı rapor
    $stmt = $pdo->prepare("
        SELECT 
            d.name as department_name,
            COUNT(m.id) as meeting_count,
            COUNT(CASE WHEN m.status = 'approved' THEN 1 END) as approved_count,
            COUNT(CASE WHEN m.status = 'rejected' THEN 1 END) as rejected_count,
            AVG(TIMESTAMPDIFF(MINUTE, m.start_time, m.end_time)) as avg_duration
        FROM departments d
        LEFT JOIN meetings m ON d.id = m.department_id AND m.date BETWEEN ? AND ?
        GROUP BY d.id, d.name
        ORDER BY meeting_count DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $reports['department_stats'] = $stmt->fetchAll();
    
    // Kullanıcı aktiviteleri
    $stmt = $pdo->prepare("
        SELECT 
            u.name, u.surname, u.email,
            d.name as department_name,
            COUNT(m.id) as meeting_count,
            COUNT(CASE WHEN m.status = 'approved' THEN 1 END) as approved_count,
            MAX(m.created_at) as last_activity
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN meetings m ON u.id = m.user_id AND m.date BETWEEN ? AND ?
        WHERE u.role = 'user'
        GROUP BY u.id
        ORDER BY meeting_count DESC
        LIMIT 20
    ");
    $stmt->execute([$startDate, $endDate]);
    $reports['user_activities'] = $stmt->fetchAll();
    
    // Zoom hesap kullanımı
    $stmt = $pdo->prepare("
        SELECT 
            za.email,
            za.account_type,
            COUNT(m.id) as meeting_count,
            COUNT(CASE WHEN m.date >= CURDATE() THEN 1 END) as upcoming_meetings
        FROM zoom_accounts za
        LEFT JOIN meetings m ON za.id = m.zoom_account_id AND m.date BETWEEN ? AND ?
        GROUP BY za.id
        ORDER BY meeting_count DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $reports['zoom_usage'] = $stmt->fetchAll();
    
    // Saatlik dağılım
    $stmt = $pdo->prepare("
        SELECT 
            HOUR(start_time) as hour,
            COUNT(*) as meeting_count
        FROM meetings 
        WHERE date BETWEEN ? AND ? AND status = 'approved'
        GROUP BY HOUR(start_time)
        ORDER BY hour
    ");
    $stmt->execute([$startDate, $endDate]);
    $reports['hourly_distribution'] = $stmt->fetchAll();
    
    // Haftalık trend
    $stmt = $pdo->prepare("
        SELECT 
            YEARWEEK(date) as week,
            COUNT(*) as meeting_count,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count
        FROM meetings 
        WHERE date BETWEEN ? AND ?
        GROUP BY YEARWEEK(date)
        ORDER BY week
    ");
    $stmt->execute([$startDate, $endDate]);
    $reports['weekly_trend'] = $stmt->fetchAll();
    
    return $reports;
}

function exportCSV($reportType, $startDate, $endDate) {
    global $pdo;
    
    $filename = "report_{$reportType}_{$startDate}_to_{$endDate}.csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    switch ($reportType) {
        case 'meetings':
            fputcsv($output, ['Tarih', 'Başlık', 'Talep Eden', 'Birim', 'Başlama', 'Bitiş', 'Durum']);
            
            $stmt = $pdo->prepare("
                SELECT m.date, m.title, u.name, u.surname, d.name as dept, m.start_time, m.end_time, m.status
                FROM meetings m
                JOIN users u ON m.user_id = u.id
                LEFT JOIN departments d ON m.department_id = d.id
                WHERE m.date BETWEEN ? AND ?
                ORDER BY m.date DESC
            ");
            $stmt->execute([$startDate, $endDate]);
            
            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['date'],
                    $row['title'],
                    $row['name'] . ' ' . $row['surname'],
                    $row['dept'] ?? '',
                    $row['start_time'],
                    $row['end_time'],
                    $row['status']
                ]);
            }
            break;
            
        case 'users':
            fputcsv($output, ['Ad Soyad', 'E-posta', 'Birim', 'Toplantı Sayısı', 'Onaylanan', 'Son Aktivite']);
            
            $stmt = $pdo->prepare("
                SELECT 
                    u.name, u.surname, u.email,
                    d.name as department_name,
                    COUNT(m.id) as meeting_count,
                    COUNT(CASE WHEN m.status = 'approved' THEN 1 END) as approved_count,
                    MAX(m.created_at) as last_activity
                FROM users u
                LEFT JOIN departments d ON u.department_id = d.id
                LEFT JOIN meetings m ON u.id = m.user_id AND m.date BETWEEN ? AND ?
                WHERE u.role = 'user'
                GROUP BY u.id
                ORDER BY meeting_count DESC
            ");
            $stmt->execute([$startDate, $endDate]);
            
            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['name'] . ' ' . $row['surname'],
                    $row['email'],
                    $row['department_name'] ?? '',
                    $row['meeting_count'],
                    $row['approved_count'],
                    $row['last_activity'] ?? ''
                ]);
            }
            break;
    }
    
    fclose($output);
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
                <h1 class="text-3xl font-bold text-gray-900">Raporlar ve İstatistikler</h1>
                <p class="mt-2 text-gray-600">Detaylı sistem raporları ve analitik veriler</p>
            </div>
            <div class="mt-4 sm:mt-0 flex space-x-3">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                   class="btn-secondary">
                    <i class="fas fa-download mr-2"></i>
                    CSV Export
                </a>
                <button onclick="window.print()" class="btn-primary">
                    <i class="fas fa-print mr-2"></i>
                    Yazdır
                </button>
            </div>
        </div>

        <!-- Date Range Filter -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 mb-8">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Başlangıç Tarihi</label>
                    <input type="date" name="start_date" value="<?php echo $startDate; ?>" class="form-input">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Bitiş Tarihi</label>
                    <input type="date" name="end_date" value="<?php echo $endDate; ?>" class="form-input">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Rapor Tipi</label>
                    <select name="report_type" class="form-select">
                        <option value="overview" <?php echo $reportType === 'overview' ? 'selected' : ''; ?>>Genel Bakış</option>
                        <option value="meetings" <?php echo $reportType === 'meetings' ? 'selected' : ''; ?>>Toplantı Detayları</option>
                        <option value="users" <?php echo $reportType === 'users' ? 'selected' : ''; ?>>Kullanıcı Aktiviteleri</option>
                        <option value="departments" <?php echo $reportType === 'departments' ? 'selected' : ''; ?>>Birim Raporları</option>
                    </select>
                </div>
                
                <button type="submit" class="btn-primary">
                    <i class="fas fa-search mr-2"></i>
                    Rapor Oluştur
                </button>
            </form>
        </div>

        <!-- Overview Stats -->
        <div class="grid grid-cols-1 md:grid-cols-6 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-600">Toplam</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo $reports['overview']['total_meetings'] ?? 0; ?></p>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-600">Bekleyen</p>
                    <p class="text-3xl font-bold text-orange-600"><?php echo $reports['overview']['pending_meetings'] ?? 0; ?></p>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-600">Onaylı</p>
                    <p class="text-3xl font-bold text-green-600"><?php echo $reports['overview']['approved_meetings'] ?? 0; ?></p>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-600">Reddedilen</p>
                    <p class="text-3xl font-bold text-red-600"><?php echo $reports['overview']['rejected_meetings'] ?? 0; ?></p>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-600">İptal</p>
                    <p class="text-3xl font-bold text-gray-600"><?php echo $reports['overview']['cancelled_meetings'] ?? 0; ?></p>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-600">Ort. Süre</p>
                    <p class="text-3xl font-bold text-blue-600"><?php echo round($reports['overview']['avg_duration'] ?? 0); ?><span class="text-sm">dk</span></p>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Daily Distribution Chart -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Günlük Dağılım</h3>
                <div class="h-64">
                    <canvas id="dailyChart"></canvas>
                </div>
            </div>
            
            <!-- Hourly Distribution Chart -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Saatlik Dağılım</h3>
                <div class="h-64">
                    <canvas id="hourlyChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Department Stats Table -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Birim İstatistikleri</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Birim</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Toplam</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Onaylı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reddedilen</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Onay Oranı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ort. Süre</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($reports['department_stats'] ?? [] as $dept): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $dept['meeting_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600">
                                    <?php echo $dept['approved_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600">
                                    <?php echo $dept['rejected_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php 
                                    $rate = $dept['meeting_count'] > 0 ? round(($dept['approved_count'] / $dept['meeting_count']) * 100, 1) : 0;
                                    echo $rate . '%';
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo round($dept['avg_duration'] ?? 0); ?> dk
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- User Activities Table -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">En Aktif Kullanıcılar</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Kullanıcı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Birim</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Toplantı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Onaylı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Son Aktivite</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($reports['user_activities'] ?? [] as $user): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($user['name'] . ' ' . $user['surname']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($user['department_name'] ?? '-'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $user['meeting_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600">
                                    <?php echo $user['approved_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo $user['last_activity'] ? formatDate($user['last_activity']) : '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Zoom Usage Stats -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Zoom Hesap Kullanımı</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Hesap</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tip</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Kullanım</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Yaklaşan</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($reports['zoom_usage'] ?? [] as $zoom): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($zoom['email']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                        <?php echo ucfirst($zoom['account_type']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $zoom['meeting_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600">
                                    <?php echo $zoom['upcoming_meetings']; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Daily Distribution Chart
    const dailyCtx = document.getElementById('dailyChart');
    if (dailyCtx) {
        const dailyData = <?php echo json_encode($reports['daily_distribution'] ?? []); ?>;
        
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: dailyData.map(item => {
                    const date = new Date(item.report_date);
                    return date.toLocaleDateString('tr-TR', {day: '2-digit', month: '2-digit'});
                }),
                datasets: [{
                    label: 'Toplam Toplantı',
                    data: dailyData.map(item => item.meeting_count),
                    borderColor: 'rgb(99, 102, 241)',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    tension: 0.1,
                    fill: true
                }, {
                    label: 'Onaylı Toplantı',
                    data: dailyData.map(item => item.approved_count),
                    borderColor: 'rgb(16, 185, 129)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
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
    
    // Hourly Distribution Chart
    const hourlyCtx = document.getElementById('hourlyChart');
    if (hourlyCtx) {
        const hourlyData = <?php echo json_encode($reports['hourly_distribution'] ?? []); ?>;
        
        // 24 saatlik veri hazırla
        const hours = Array.from({length: 24}, (_, i) => i);
        const hourlyValues = hours.map(hour => {
            const found = hourlyData.find(item => parseInt(item.hour) === hour);
            return found ? found.meeting_count : 0;
        });
        
        new Chart(hourlyCtx, {
            type: 'bar',
            data: {
                labels: hours.map(hour => hour.toString().padStart(2, '0') + ':00'),
                datasets: [{
                    label: 'Toplantı Sayısı',
                    data: hourlyValues,
                    backgroundColor: 'rgba(99, 102, 241, 0.8)',
                    borderColor: 'rgb(99, 102, 241)',
                    borderWidth: 1
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
</script>

<?php include '../includes/footer.php'; ?>