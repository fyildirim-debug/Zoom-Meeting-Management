<?php
/**
 * SMS Modülü — Ayarlar
 *
 * Modül sayfaları proje root'una göre relative.
 * /modules/sms/pages/settings.php  →  ../../../config/config.php
 */
$pageTitle = 'SMS Ayarları';
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../lib/SmsService.php';
require_once __DIR__ . '/../../_core/HookEngine.php';
require_once __DIR__ . '/../../_core/ModuleAPI.php';
require_once __DIR__ . '/../../_core/ModuleManager.php';

requireLogin();
if (!isAdmin()) {
    redirect('../../../dashboard.php');
}

$currentUser = getCurrentUser();
$sms = new SmsService($pdo);

$message = '';
$messageType = '';

// ─── Self-heal: SMS tabloları yoksa, "Tabloları Kur" aksiyonu ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'init_tables') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Güvenlik token hatası.';
        $messageType = 'error';
    } else {
        try {
            // install.php'yi manuel çağır
            $manager = new ModuleManager($pdo);
            $api = new ModuleAPI($pdo, defined('DB_TYPE') ? DB_TYPE : 'mysql',
                'sms', dirname(__DIR__), dirname(__DIR__, 3));
            $installScript = require __DIR__ . '/../install.php';
            if (is_array($installScript) && isset($installScript['install']) && is_callable($installScript['install'])) {
                $installScript['install']($api);
                $message = 'SMS modülü tabloları başarıyla oluşturuldu.';
                $messageType = 'success';
                logActivity('module_sms_repair', 'module', null,
                    'SMS modülü tabloları yeniden kuruldu', $currentUser['id']);
            } else {
                throw new Exception('install.php geçersiz dönüş yapısı.');
            }
        } catch (Exception $e) {
            $message = 'Kurulum hatası: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Tablolar hâlâ yoksa → friendly mesaj göster, ana akışa girme
if (!$sms->isReady()) {
    include __DIR__ . '/../../../includes/header.php';
    include __DIR__ . '/../../../includes/sidebar.php';
    ?>
    <div class="main-content flex-1 p-6">
        <div class="max-w-2xl mx-auto">
            <div class="mb-8 flex items-center space-x-4">
                <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-lg flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">SMS Modülü Hazır Değil</h1>
                    <p class="text-gray-600">Modül tabloları veritabanında bulunmuyor.</p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="mb-6 p-4 rounded-lg border <?php echo $messageType === 'success' ? 'bg-green-50 border-green-300 text-green-800' : 'bg-red-50 border-red-300 text-red-800'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-xl shadow border border-gray-200 p-6 space-y-4">
                <p class="text-gray-700">
                    SMS modülü için gerekli veritabanı tabloları (<code>module_sms_settings</code>,
                    <code>module_sms_logs</code>, <code>module_sms_approval_tokens</code>) bulunmuyor.
                    Bu, modül daha önce kaldırılmış ya da DB sıfırlanmış olabilir.
                </p>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="init_tables">
                    <button type="submit" class="bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600 text-white font-semibold px-5 py-3 rounded-lg">
                        <i class="fas fa-wrench mr-2"></i>
                        Tabloları Şimdi Kur
                    </button>
                </form>

                <div class="pt-4 border-t border-gray-100 text-sm text-gray-500">
                    Veya: <a href="<?php echo url('admin/modules.php'); ?>" class="text-indigo-600 hover:underline">Modüller sayfasına</a> dönüp modülü pasifleştir → tekrar aktifleştir.
                </div>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/../../../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Güvenlik token hatası.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $fields = [
                'api_url' => trim($_POST['api_url'] ?? ''),
                'api_key' => trim($_POST['api_key'] ?? ''),
                'sender_title' => trim($_POST['sender_title'] ?? ''),
                'admin_phone' => preg_replace('/[^0-9]/', '', $_POST['admin_phone'] ?? ''),
                'message_template' => trim($_POST['message_template'] ?? ''),
                'token_ttl_hours' => (string)max(1, min(168, (int)($_POST['token_ttl_hours'] ?? 24))),
                'approver_user_id' => (string)max(0, (int)($_POST['approver_user_id'] ?? 0)),
            ];
            foreach ($fields as $k => $v) {
                $sms->setSetting($k, $v);
            }
            $message = 'Ayarlar kaydedildi.';
            $messageType = 'success';
            logActivity('module_sms_settings', 'module', null, 'SMS ayarları güncellendi', $currentUser['id']);
        } elseif ($action === 'send_test') {
            $phone = preg_replace('/[^0-9]/', '', $_POST['test_phone'] ?? '');
            if ($phone === '') {
                $message = 'Test telefon numarası gerekli.';
                $messageType = 'error';
            } else {
                $result = $sms->send($phone, 'Bu bir test mesajidir. SMS modulu calisiyor.');
                $message = $result['success']
                    ? 'Test SMS gönderildi. Başarılı adet: ' . $result['success_count']
                    : 'Test başarısız: ' . $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
            }
        }
    }
}

$settings = $sms->getSettings();

// Adminler listesi (yedek onaylayıcı için)
$admins = [];
try {
    $stmt = $pdo->query("SELECT id, name, surname, email FROM users WHERE role = 'admin' AND status = 'active' ORDER BY name");
    $admins = $stmt->fetchAll();
} catch (Exception $e) { /* ignore */ }

include __DIR__ . '/../../../includes/header.php';
include __DIR__ . '/../../../includes/sidebar.php';
?>

<div class="main-content flex-1 p-6">
    <div class="max-w-4xl mx-auto">
        <div class="mb-8 flex items-center space-x-4">
            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center">
                <i class="fas fa-sms text-white text-xl"></i>
            </div>
            <div>
                <h1 class="text-3xl font-bold text-gray-900">SMS Ayarları</h1>
                <p class="text-gray-600">SMS sağlayıcı API yapılandırması.</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg border <?php echo $messageType === 'success' ? 'bg-green-50 border-green-300 text-green-800' : 'bg-red-50 border-red-300 text-red-800'; ?>">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="bg-white rounded-xl shadow border border-gray-200">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="save">

            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">API Yapılandırması</h2>
            </div>
            <div class="p-6 space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">API URL</label>
                    <input type="url" name="api_url" value="<?php echo htmlspecialchars($settings['api_url'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="https://kurumunuz.example.com/sms-endpoint.php">
                    <p class="text-xs text-gray-500 mt-1">Kurumunuzun SMS gönderim endpoint URL'sini girin. Bu modül, form-urlencoded POST kabul eden ve JSON dönen sağlayıcılarla uyumludur (api_key, telefonlar, mesaj, baslik alanları).</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">API Anahtarı</label>
                    <input type="text" name="api_key" value="<?php echo htmlspecialchars($settings['api_key'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono"
                           placeholder="<api-key-buraya>"
                           autocomplete="off">
                    <p class="text-xs text-gray-500 mt-1">Sağlayıcınızın size verdiği API anahtarı.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Gönderici Başlığı</label>
                    <input type="text" name="sender_title" value="<?php echo htmlspecialchars($settings['sender_title'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="ORG" maxlength="11">
                    <p class="text-xs text-gray-500 mt-1">SMS'in gönderici adı (sağlayıcıda kayıtlı olmalı). Maksimum 11 karakter.</p>
                </div>
            </div>

            <div class="px-6 py-4 border-y border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-900">Bildirim Hedefi</h2>
            </div>
            <div class="p-6 space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Yönetici Telefon Numarası</label>
                    <input type="text" name="admin_phone" value="<?php echo htmlspecialchars($settings['admin_phone'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="05XXXXXXXXX">
                    <p class="text-xs text-gray-500 mt-1">Yeni toplantı talebi geldiğinde SMS bu numaraya gider.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Onaylayıcı Yönetici (audit için)</label>
                    <select name="approver_user_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <?php foreach ($admins as $a): ?>
                            <option value="<?php echo (int)$a['id']; ?>" <?php echo ((int)($settings['approver_user_id'] ?? 0) === (int)$a['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['name'] . ' ' . $a['surname'] . ' (' . $a['email'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">SMS linkiyle yapılan onaylar bu kullanıcı adına kaydedilir.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Token Geçerlilik Süresi (saat)</label>
                    <input type="number" name="token_ttl_hours" value="<?php echo (int)($settings['token_ttl_hours'] ?? 24); ?>" min="1" max="168"
                           class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">SMS'teki onay linki ne kadar süre sonra geçersiz olsun? (1-168 saat)</p>
                </div>
            </div>

            <div class="px-6 py-4 border-y border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-900">Mesaj Şablonu</h2>
            </div>
            <div class="p-6 space-y-3">
                <textarea name="message_template" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"><?php
                    echo htmlspecialchars($settings['message_template'] ?? 'Yeni toplanti talebi: {title} {date} {time}. Onay: {link}');
                ?></textarea>
                <div class="text-xs text-gray-500">
                    Kullanılabilir placeholder'lar:
                    <code>{title}</code> <code>{date}</code> <code>{time}</code> <code>{end_time}</code>
                    <code>{link}</code> <code>{user}</code> <code>{department}</code>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
                <button type="submit" class="bg-gradient-to-r from-blue-500 to-cyan-500 hover:from-blue-600 hover:to-cyan-600 text-white px-6 py-2 rounded-lg font-semibold">
                    <i class="fas fa-save mr-2"></i> Kaydet
                </button>
            </div>
        </form>

        <!-- Test SMS -->
        <form method="POST" class="bg-white rounded-xl shadow border border-gray-200 mt-6">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="send_test">

            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-paper-plane mr-2 text-cyan-600"></i> Test SMS Gönder
                </h2>
            </div>
            <div class="p-6 flex gap-3 items-end">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Test telefon</label>
                    <input type="text" name="test_phone" placeholder="05XXXXXXXXX"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                </div>
                <button type="submit" class="bg-cyan-500 hover:bg-cyan-600 text-white px-5 py-2 rounded-lg font-semibold">
                    <i class="fas fa-paper-plane mr-1"></i> Gönder
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
