<?php
$pageTitle = 'Modüller';
require_once '../config/config.php';
require_once '../config/auth.php';
require_once '../modules/_core/HookEngine.php';
require_once '../modules/_core/ModuleAPI.php';
require_once '../modules/_core/ModuleManager.php';

requireLogin();
if (!isAdmin()) {
    redirect('../dashboard.php');
}

$currentUser = getCurrentUser();
$manager = new ModuleManager($pdo);

$message = '';
$messageType = '';
$installLog = $_SESSION['module_install_log'] ?? null;
unset($_SESSION['module_install_log']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Güvenlik token hatası. Sayfayı yenileyip tekrar deneyin.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        $moduleId = $_POST['module_id'] ?? '';

        switch ($action) {
            case 'activate':
                $result = $manager->activate($moduleId);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                if (!empty($result['log'])) {
                    $_SESSION['module_install_log'] = $result['log'];
                }
                if ($result['success']) {
                    logActivity('module_activate', 'module', null,
                        'Modül aktifleştirildi: ' . $moduleId, $currentUser['id']);
                }
                break;

            case 'deactivate':
                $result = $manager->deactivate($moduleId);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                if ($result['success']) {
                    logActivity('module_deactivate', 'module', null,
                        'Modül pasifleştirildi: ' . $moduleId, $currentUser['id']);
                }
                break;
        }
    }
}

$modules = $manager->discover();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content flex-1 p-6">
    <div class="max-w-6xl mx-auto">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center space-x-4">
                <div class="w-12 h-12 bg-gradient-to-r from-pink-500 to-purple-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-puzzle-piece text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars(t('modules.title')); ?></h1>
                    <p class="text-gray-600"><?php echo htmlspecialchars(t('modules.subtitle')); ?></p>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg border <?php echo $messageType === 'success' ? 'bg-green-50 border-green-300 text-green-800' : 'bg-red-50 border-red-300 text-red-800'; ?>">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($installLog)): ?>
            <div class="mb-6 bg-white rounded-xl shadow border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-list-alt mr-2 text-indigo-600"></i>
                        İşlem Log'u
                    </h3>
                    <button onclick="this.closest('.bg-white').remove()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-6">
                    <pre class="bg-gray-50 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto"><?php
                        echo htmlspecialchars(implode("\n", $installLog));
                    ?></pre>
                </div>
            </div>
        <?php endif; ?>

        <!-- Module List -->
        <div class="bg-white rounded-xl shadow border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-list mr-2 text-gray-600"></i>
                    <?php echo htmlspecialchars(t('modules.available')); ?>
                    <span class="ml-2 text-sm text-gray-500 font-normal">(<?php echo count($modules); ?>)</span>
                </h2>
            </div>

            <?php if (empty($modules)): ?>
                <div class="p-8 text-center text-gray-500">
                    <i class="fas fa-puzzle-piece text-4xl mb-3 opacity-30"></i>
                    <p><?php echo htmlspecialchars(t('modules.no_modules')); ?></p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($modules as $mod): ?>
                        <div class="p-6 hover:bg-gray-50">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2 flex-wrap">
                                        <h3 class="text-lg font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($mod['name']); ?>
                                        </h3>
                                        <span class="text-xs font-mono bg-gray-100 text-gray-600 px-2 py-1 rounded">
                                            v<?php echo htmlspecialchars($mod['version']); ?>
                                        </span>
                                        <?php
                                        $statusColors = [
                                            'active'       => 'bg-green-100 text-green-700',
                                            'inactive'     => 'bg-gray-100 text-gray-600',
                                            'installed'    => 'bg-yellow-100 text-yellow-700',
                                            'not_installed'=> 'bg-blue-100 text-blue-700',
                                        ];
                                        $statusLabels = [
                                            'active'        => t('modules.status_active'),
                                            'inactive'      => t('modules.status_inactive'),
                                            'installed'     => t('modules.status_installed'),
                                            'not_installed' => t('modules.status_not_installed'),
                                        ];
                                        $color = $statusColors[$mod['status']] ?? 'bg-gray-100 text-gray-600';
                                        $label = $statusLabels[$mod['status']] ?? $mod['status'];
                                        ?>
                                        <span class="text-xs font-semibold px-2 py-1 rounded <?php echo $color; ?>">
                                            <?php echo $label; ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-600 mb-1">
                                        <?php echo htmlspecialchars($mod['description'] ?: '—'); ?>
                                    </p>
                                    <div class="flex items-center gap-4 text-xs text-gray-500 mt-2 flex-wrap">
                                        <span><i class="fas fa-fingerprint mr-1"></i> <code><?php echo htmlspecialchars($mod['id']); ?></code></span>
                                        <?php if (!empty($mod['author'])): ?>
                                            <span><i class="fas fa-user mr-1"></i> <?php echo htmlspecialchars($mod['author']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($mod['activated_at'])): ?>
                                            <span><i class="fas fa-bolt mr-1"></i> Son aktivasyon: <?php echo htmlspecialchars($mod['activated_at']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <?php
                                    // Ayarlar butonu — manifest'te settings_url varsa ve modül aktifse
                                    $settingsUrl = $mod['manifest']['settings_url'] ?? null;
                                    if ($settingsUrl && $mod['status'] === 'active'):
                                        $settingsHref = url('modules/' . $mod['id'] . '/' . ltrim($settingsUrl, '/'));
                                    ?>
                                        <a href="<?php echo htmlspecialchars($settingsHref); ?>" class="bg-indigo-500 hover:bg-indigo-600 text-white text-sm px-4 py-2 rounded-lg">
                                            <i class="fas fa-cog mr-1"></i> <?php echo htmlspecialchars(t('modules.settings')); ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($mod['status'] === 'active'): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="module_id" value="<?php echo htmlspecialchars($mod['id']); ?>">
                                            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded-lg">
                                                <i class="fas fa-pause mr-1"></i> <?php echo htmlspecialchars(t('modules.deactivate')); ?>
                                            </button>
                                        </form>
                                    <?php else: /* not_installed, installed veya inactive — hepsi tek butonla aktif olur */ ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="module_id" value="<?php echo htmlspecialchars($mod['id']); ?>">
                                            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white text-sm px-4 py-2 rounded-lg">
                                                <i class="fas fa-play mr-1"></i> <?php echo htmlspecialchars(t('modules.activate')); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
