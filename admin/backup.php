<?php
$pageTitle = 'Yedekleme';
require_once '../config/config.php';
require_once '../config/auth.php';
require_once '../includes/BackupManager.php';

requireLogin();
if (!isAdmin()) {
    redirect('../dashboard.php');
}

$currentUser = getCurrentUser();
$backupDir = dirname(__DIR__) . '/data/backups';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['action']) && !isset($_POST['action_export'])) {
        $action = '';
    } else {
        $action = $_POST['action'] ?? $_POST['action_export'] ?? '';
    }

    // CSRF
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Güvenlik token hatası. Sayfayı yenileyip tekrar deneyin.';
        $messageType = 'error';
    } else {
        switch ($action) {
            case 'export':
                $result = handleExport($backupDir);
                if ($result['success'] && !empty($result['file'])) {
                    // Dosyayı stream'le
                    streamDownload($result['file']);
                    exit;
                }
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;

            case 'import':
                $result = handleImport($backupDir);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                if (!empty($result['log'])) {
                    $_SESSION['backup_restore_log'] = $result['log'];
                }
                break;

            case 'delete_backup':
                $filename = basename($_POST['filename'] ?? '');
                $result = handleDelete($backupDir, $filename);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;

            case 'download_backup':
                $filename = basename($_POST['filename'] ?? '');
                $filePath = $backupDir . DIRECTORY_SEPARATOR . $filename;
                if (preg_match('/^backup-[\w\-]+\.zip$/', $filename) && file_exists($filePath)) {
                    streamDownload($filePath);
                    exit;
                }
                $message = 'Dosya bulunamadı.';
                $messageType = 'error';
                break;

            case 'restore_existing':
                $filename = basename($_POST['filename'] ?? '');
                $filePath = $backupDir . DIRECTORY_SEPARATOR . $filename;
                if (!preg_match('/^backup-[\w\-]+\.zip$/', $filename) || !file_exists($filePath)) {
                    $message = 'Geçersiz yedek dosyası.';
                    $messageType = 'error';
                    break;
                }
                $manager = new BackupManager($pdo);
                $result = $manager->importFromZip($filePath);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                if (!empty($result['log'])) {
                    $_SESSION['backup_restore_log'] = $result['log'];
                }
                if ($result['success']) {
                    logActivity('restore_backup', 'system', null,
                        'Mevcut yedek geri yüklendi: ' . $filename, $currentUser['id']);
                }
                break;
        }
    }
}

function handleExport(string $backupDir): array
{
    global $pdo, $currentUser;
    $manager = new BackupManager($pdo);
    $result = $manager->exportToZip($backupDir);
    if ($result['success']) {
        logActivity('export_backup', 'system', null,
            'Veritabanı yedeği oluşturuldu: ' . basename($result['file']), $currentUser['id']);
    }
    return $result;
}

function handleImport(string $backupDir): array
{
    global $pdo, $currentUser;

    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        switch ($errCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $msg = 'Dosya boyutu limiti aşıldı.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $msg = 'Dosya kısmen yüklendi, tekrar deneyin.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $msg = 'Lütfen bir yedek dosyası seçin.';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $msg = 'Sunucu geçici dizini yok.';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $msg = 'Dosya diske yazılamadı.';
                break;
            default:
                $msg = 'Dosya yükleme hatası.';
        }
        return ['success' => false, 'message' => $msg, 'log' => []];
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        return ['success' => false, 'message' => 'Geçersiz dosya yüklemesi.', 'log' => []];
    }

    // Kalıcı yere taşı (audit + sonradan tekrar restore)
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }
    $name = 'upload-' . date('Y-m-d-His') . '.zip';
    $dest = $backupDir . DIRECTORY_SEPARATOR . $name;

    if (!@move_uploaded_file($tmp, $dest)) {
        return ['success' => false, 'message' => 'Yüklenen dosya kaydedilemedi.', 'log' => []];
    }

    $manager = new BackupManager($pdo);
    $result = $manager->importFromZip($dest);
    if ($result['success']) {
        logActivity('import_backup', 'system', null,
            'Yedekten geri yükleme yapıldı: ' . $name, $currentUser['id']);
    }
    return $result;
}

function handleDelete(string $backupDir, string $filename): array
{
    if (!preg_match('/^(backup|upload)-[\w\-]+\.zip$/', $filename)) {
        return ['success' => false, 'message' => 'Geçersiz dosya adı.'];
    }
    $path = $backupDir . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['success' => false, 'message' => 'Dosya bulunamadı.'];
    }
    if (@unlink($path)) {
        return ['success' => true, 'message' => 'Yedek silindi: ' . $filename];
    }
    return ['success' => false, 'message' => 'Dosya silinemedi.'];
}

function streamDownload(string $filePath): void
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($filePath);
}

// Mevcut yedekleri listele
$existingBackups = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . DIRECTORY_SEPARATOR . '*.zip') ?: [];
    foreach ($files as $f) {
        $existingBackups[] = [
            'name' => basename($f),
            'size' => filesize($f) ?: 0,
            'mtime' => filemtime($f) ?: 0,
            'manifest' => BackupManager::peekManifest($f),
        ];
    }
    usort($existingBackups, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

$restoreLog = $_SESSION['backup_restore_log'] ?? null;
unset($_SESSION['backup_restore_log']);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content flex-1 p-6">
    <div class="max-w-6xl mx-auto">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center space-x-4">
                <div class="w-12 h-12 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-database text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Veritabanı Yedekleme</h1>
                    <p class="text-gray-600">Sistemin tam yedeğini oluşturun veya bir yedekten geri yükleyin.</p>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg border <?php echo $messageType === 'success' ? 'bg-green-50 border-green-300 text-green-800' : 'bg-red-50 border-red-300 text-red-800'; ?>">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($restoreLog)): ?>
            <div class="mb-6 bg-white rounded-xl shadow border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-list-alt mr-2 text-indigo-600"></i>
                        Restore Log
                    </h3>
                    <button onclick="this.closest('.bg-white').remove()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-6">
                    <pre class="bg-gray-50 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto"><?php
                        echo htmlspecialchars(implode("\n", $restoreLog));
                    ?></pre>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

            <!-- Export Card -->
            <div class="bg-white rounded-xl shadow border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-download mr-2 text-green-600"></i>
                        Yedek Al (Export)
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-sm text-gray-600">
                        Sistemdeki tüm tabloların (kullanıcılar, toplantılar, Zoom hesapları, ayarlar...) anlık görüntüsünü ZIP+JSON formatında indirir.
                    </p>
                    <ul class="text-sm text-gray-700 space-y-1">
                        <li><i class="fas fa-check text-green-500 mr-2"></i>Format: <strong>JSON + ZIP</strong> (taşınabilir)</li>
                        <li><i class="fas fa-check text-green-500 mr-2"></i>MySQL ↔ SQLite arası geri yüklenebilir</li>
                        <li><i class="fas fa-check text-green-500 mr-2"></i>İndirildikten sonra sunucuda da bir kopya saklanır</li>
                    </ul>
                    <form method="POST" class="pt-2">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="export">
                        <button type="submit" class="w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-semibold py-3 rounded-lg transition">
                            <i class="fas fa-cloud-download-alt mr-2"></i>
                            Yedek Oluştur ve İndir
                        </button>
                    </form>
                </div>
            </div>

            <!-- Import Card -->
            <div class="bg-white rounded-xl shadow border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-upload mr-2 text-red-600"></i>
                        Yedekten Geri Yükle (Restore)
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-800">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <strong>Dikkat:</strong> Bu işlem mevcut tüm verileri (kullanıcılar dahil) silip yedekteki verilerle değiştirir.
                        İşlem geri alınamaz. Önce mevcut sistemin bir yedeğini almanız tavsiye edilir.
                    </div>

                    <form method="POST" enctype="multipart/form-data" onsubmit="return confirmRestore(event)">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="import">

                        <label class="block">
                            <span class="text-sm font-medium text-gray-700 mb-2 block">Yedek dosyası (.zip):</span>
                            <input type="file" name="backup_file" accept=".zip" required
                                   class="block w-full text-sm text-gray-700 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:font-semibold file:bg-indigo-100 file:text-indigo-700 hover:file:bg-indigo-200 cursor-pointer">
                        </label>

                        <label class="flex items-start mt-4 cursor-pointer">
                            <input type="checkbox" required class="mt-1 mr-2">
                            <span class="text-sm text-gray-700">
                                Mevcut verilerin silineceğini ve yedekteki verilerle değiştirileceğini anlıyorum.
                            </span>
                        </label>

                        <button type="submit" class="mt-4 w-full bg-gradient-to-r from-red-500 to-rose-600 hover:from-red-600 hover:to-rose-700 text-white font-semibold py-3 rounded-lg transition">
                            <i class="fas fa-redo-alt mr-2"></i>
                            Geri Yükle
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Existing Backups -->
        <div class="bg-white rounded-xl shadow border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-archive mr-2 text-gray-600"></i>
                    Sunucudaki Yedekler
                    <span class="ml-2 text-sm text-gray-500 font-normal">(<?php echo count($existingBackups); ?> dosya)</span>
                </h2>
            </div>

            <?php if (empty($existingBackups)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-folder-open text-4xl mb-3"></i>
                    <p>Sunucuda henüz yedek dosyası yok. Yukarıdan ilk yedeği oluşturabilirsiniz.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Dosya</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Tarih</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Boyut</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Sürüm / DB</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">İçerik</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">İşlem</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($existingBackups as $b): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm font-mono text-gray-900">
                                        <i class="fas fa-file-archive text-indigo-500 mr-2"></i>
                                        <?php echo htmlspecialchars($b['name']); ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        <?php echo $b['mtime'] ? date('d.m.Y H:i', $b['mtime']) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        <?php echo formatFileSize($b['size']); ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        <?php if ($b['manifest']): ?>
                                            <span class="font-semibold">v<?php echo htmlspecialchars($b['manifest']['app_version'] ?? '?'); ?></span>
                                            <span class="text-gray-400">/</span>
                                            <span class="uppercase text-xs"><?php echo htmlspecialchars($b['manifest']['db_type'] ?? '?'); ?></span>
                                        <?php else: ?>
                                            <span class="text-red-500 text-xs">manifest yok</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        <?php if ($b['manifest'] && !empty($b['manifest']['row_counts'])): ?>
                                            <?php
                                            $totalRows = array_sum($b['manifest']['row_counts']);
                                            $tableCount = count($b['manifest']['tables'] ?? []);
                                            ?>
                                            <span title="<?php echo htmlspecialchars(implode(', ', array_map(fn($t,$c)=>"$t:$c", array_keys($b['manifest']['row_counts']), $b['manifest']['row_counts']))); ?>">
                                                <?php echo $tableCount; ?> tablo, <?php echo $totalRows; ?> satır
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-right space-x-2 whitespace-nowrap">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="download_backup">
                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['name']); ?>">
                                            <button type="submit" class="text-indigo-600 hover:text-indigo-800" title="İndir">
                                                <i class="fas fa-download"></i>
                                            </button>
                                        </form>
                                        <form method="POST" class="inline" onsubmit="return confirmExistingRestore(event)">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="restore_existing">
                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['name']); ?>">
                                            <button type="submit" class="text-orange-600 hover:text-orange-800" title="Bu yedeği geri yükle">
                                                <i class="fas fa-redo-alt"></i>
                                            </button>
                                        </form>
                                        <form method="POST" class="inline" onsubmit="return confirm('Bu yedek dosyasını silmek istediğinize emin misiniz?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="delete_backup">
                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['name']); ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800" title="Sil">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function confirmRestore(e) {
    if (!confirm('UYARI: Mevcut tüm veriler (kullanıcı hesapları dahil) silinecek ve yedekteki verilerle değiştirilecek.\n\nBu işlem GERİ ALINAMAZ.\n\nDevam etmek istediğinize emin misiniz?')) {
        e.preventDefault();
        return false;
    }
    return true;
}
function confirmExistingRestore(e) {
    return confirmRestore(e);
}
</script>

<?php include '../includes/footer.php'; ?>
