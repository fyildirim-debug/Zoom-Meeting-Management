<?php
/**
 * SMS Modülü — Gönderim Log'ları
 */
$pageTitle = 'SMS Log\'ları';
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth.php';

requireLogin();
if (!isAdmin()) {
    redirect('../../../dashboard.php');
}

$currentUser = getCurrentUser();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Defensive: tablo yoksa boş state
$total = 0;
$totalPages = 1;
$logs = [];
$tableReady = true;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM module_sms_logs");
    $total = (int)$stmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $stmt = $pdo->prepare("
        SELECT * FROM module_sms_logs
        ORDER BY sent_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tableReady = false;
}

include __DIR__ . '/../../../includes/header.php';
include __DIR__ . '/../../../includes/sidebar.php';
?>

<div class="main-content flex-1 p-6">
    <div class="max-w-6xl mx-auto">
        <div class="mb-8 flex items-center space-x-4">
            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center">
                <i class="fas fa-history text-white text-xl"></i>
            </div>
            <div>
                <h1 class="text-3xl font-bold text-gray-900">SMS Log'ları</h1>
                <p class="text-gray-600"><?php echo $total; ?> kayıt</p>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow border border-gray-200">
            <?php if (!$tableReady): ?>
                <div class="p-8 text-center text-yellow-700 bg-yellow-50 rounded-xl">
                    <i class="fas fa-exclamation-triangle text-4xl mb-3"></i>
                    <p class="mb-2">SMS log tablosu bulunamadı.</p>
                    <a href="<?php echo url('modules/sms/pages/settings.php'); ?>" class="text-indigo-600 hover:underline">SMS Ayarları sayfasından "Tabloları Şimdi Kur"a tıklayın</a>
                </div>
            <?php elseif (empty($logs)): ?>
                <div class="p-8 text-center text-gray-500">
                    <i class="fas fa-inbox text-4xl mb-3 opacity-30"></i>
                    <p>Henüz SMS gönderilmemiş.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Tarih</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Numara</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Mesaj</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Durum</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Meeting</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($logs as $log): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                                        <?php echo htmlspecialchars($log['sent_at']); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-mono text-gray-900">
                                        <?php echo htmlspecialchars($log['to_number']); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-700">
                                        <div class="max-w-md truncate" title="<?php echo htmlspecialchars($log['message']); ?>">
                                            <?php echo htmlspecialchars($log['message']); ?>
                                        </div>
                                        <?php if (!empty($log['error_message'])): ?>
                                            <div class="text-xs text-red-600 mt-1">
                                                <?php echo htmlspecialchars($log['error_message']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($log['status'] === 'sent'): ?>
                                            <span class="text-xs px-2 py-1 rounded bg-green-100 text-green-700 font-semibold">
                                                <i class="fas fa-check mr-1"></i> Gönderildi
                                            </span>
                                        <?php else: ?>
                                            <span class="text-xs px-2 py-1 rounded bg-red-100 text-red-700 font-semibold">
                                                <i class="fas fa-times mr-1"></i> Başarısız
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <?php if ($log['meeting_id']): ?>
                                            <a href="../../../meeting-details.php?id=<?php echo (int)$log['meeting_id']; ?>" class="text-blue-600 hover:underline">
                                                #<?php echo (int)$log['meeting_id']; ?>
                                            </a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between text-sm text-gray-600">
                        <div>Sayfa <?php echo $page; ?> / <?php echo $totalPages; ?></div>
                        <div class="flex gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-50">← Önceki</a>
                            <?php endif; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-50">Sonraki →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
