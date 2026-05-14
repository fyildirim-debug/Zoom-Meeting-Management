<?php
/**
 * SMS Modülü — Token-tabanlı Login'siz Onay Sayfası
 *
 * Flow:
 *   1. ?token=... ile gelir
 *   2. Token doğrulanır (var, expire olmamış, kullanılmamış, meeting hâlâ pending)
 *   3. O tarih/saat için çakışmasız aktif Zoom hesapları listelenir
 *   4. Yönetici hesap seçer → POST
 *   5. MeetingService::approve çağrılır → Zoom meeting oluşturulur
 *   6. Token kullanıldı işaretlenir
 *   7. Başarı sayfası gösterilir
 *
 * GÜVENLİK:
 *   - Login YOK — sadece token (64 hex random) yeterli
 *   - Token tek kullanımlık + 24 saat (settings'ten) expiry
 *   - Meeting'in pending olduğu her aşamada kontrol edilir
 *   - HTTPS şart (kullanıcıya uyarı gösterilir HTTP'de)
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth.php';     // getCurrentUser, isLoggedIn vb. tanımlı olsun
require_once __DIR__ . '/../lib/SmsService.php';
require_once __DIR__ . '/../../../includes/MeetingService.php';

// CSRF için session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sms = new SmsService($pdo);

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$success = '';
$meeting = null;
$availableAccounts = [];

if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
    $error = 'Geçersiz veya eksik onay token\'ı.';
} else {
    $tokenData = $sms->validateApprovalToken($token);
    if ($tokenData === null) {
        $error = 'Onay linki geçersiz, süresi dolmuş veya bu toplantı başka biri tarafından zaten işlenmiş.';
    } else {
        $meeting = $tokenData['meeting'];

        // POST: onay gönderildi
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // CSRF kontrolü
            $postToken = $_POST['csrf'] ?? '';
            $sessionToken = $_SESSION['sms_approve_csrf_' . $token] ?? '';
            if ($postToken === '' || !hash_equals($sessionToken, $postToken)) {
                $error = 'Güvenlik token hatası. Lütfen sayfayı yenileyin.';
            } else {
                $zoomAccountId = (int)($_POST['zoom_account_id'] ?? 0);
                if ($zoomAccountId <= 0) {
                    $error = 'Lütfen bir Zoom hesabı seçin.';
                } else {
                    // Yine çakışma kontrolü (yarış durumuna karşı)
                    if (checkZoomAccountConflict($zoomAccountId, $meeting['date'], $meeting['start_time'], $meeting['end_time'])) {
                        $error = 'Bu Zoom hesabı bu saatte artık uygun değil. Lütfen başka bir hesap seçin.';
                    } else {
                        $approverId = (int)$sms->getSetting('approver_user_id', '1');
                        if ($approverId === 0) {
                            $approverId = 1; // güvenli fallback
                        }

                        $result = MeetingService::approve(
                            (int)$meeting['id'],
                            $zoomAccountId,
                            [], // SMS akışında custom Zoom ayarı yok — global defaults
                            $approverId
                        );

                        if ($result['success']) {
                            // Token'ı kullanıldı işaretle
                            $sms->markTokenUsed($token, $_SERVER['REMOTE_ADDR'] ?? null);
                            $success = 'Toplantı başarıyla onaylandı. Zoom toplantısı oluşturuldu.';
                            // Meeting'i güncel haliyle çek (Zoom URL'leri için)
                            $meeting = $result['meeting'] ?? $meeting;
                        } else {
                            $error = 'Onay hatası: ' . $result['message'];
                        }
                    }
                }
            }
        }

        // Hâlâ pending ise (POST yapılmadıysa veya hata olduysa) — uygun hesapları listele
        if ($success === '' && $meeting && $meeting['status'] === 'pending') {
            $availableAccounts = MeetingService::getAvailableZoomAccounts(
                $meeting['date'],
                $meeting['start_time'],
                $meeting['end_time']
            );
        }

        // CSRF token üret
        if (!isset($_SESSION['sms_approve_csrf_' . $token])) {
            $_SESSION['sms_approve_csrf_' . $token] = bin2hex(random_bytes(16));
        }
        $csrfToken = $_SESSION['sms_approve_csrf_' . $token];

        // Kullanıcı + birim bilgisini de çek
        try {
            $stmt = $pdo->prepare("
                SELECT u.name AS user_name, u.surname AS user_surname, u.email AS user_email,
                       d.name AS department_name
                FROM meetings m
                LEFT JOIN users u ON u.id = m.user_id
                LEFT JOIN departments d ON d.id = m.department_id
                WHERE m.id = ?
            ");
            $stmt->execute([$meeting['id']]);
            $extra = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($extra) {
                $meeting = array_merge($meeting, $extra);
            }
        } catch (Exception $e) { /* ignore */ }
    }
}

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$appName = defined('APP_NAME') ? APP_NAME : 'Zoom Toplantı Yönetimi';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Toplantı Onayı - <?php echo htmlspecialchars($appName); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
    </style>
</head>
<body class="py-6 sm:py-12">
    <div class="max-w-2xl mx-auto px-4">
        <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">

            <!-- Header -->
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 p-6 text-white">
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <i class="fas fa-mobile-alt"></i>
                    SMS ile Toplantı Onayı
                </h1>
                <p class="text-indigo-100 text-sm mt-1">Login olmadan doğrudan onay verebilirsiniz.</p>
            </div>

            <?php if (!$isHttps): ?>
                <div class="bg-yellow-50 border-y border-yellow-200 px-6 py-3 text-yellow-800 text-sm">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Uyarı:</strong> Bu sayfaya HTTP üzerinden bağlandınız. Üretim ortamında HTTPS kullanılmalıdır.
                </div>
            <?php endif; ?>

            <div class="p-6">

                <?php if ($error !== ''): ?>
                    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-4">
                        <i class="fas fa-times-circle mr-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-4">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>

                    <?php if (!empty($meeting['zoom_join_url'])): ?>
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 space-y-2">
                            <h3 class="font-semibold text-blue-900 mb-2">Zoom Toplantı Linkleri:</h3>
                            <div class="text-sm">
                                <span class="text-gray-600">Katılım (Join URL):</span><br>
                                <a href="<?php echo htmlspecialchars($meeting['zoom_join_url']); ?>" class="text-blue-600 break-all underline" target="_blank">
                                    <?php echo htmlspecialchars($meeting['zoom_join_url']); ?>
                                </a>
                            </div>
                            <?php if (!empty($meeting['zoom_password'])): ?>
                                <div class="text-sm">
                                    <span class="text-gray-600">Şifre:</span>
                                    <code class="bg-blue-100 px-2 py-1 rounded font-mono"><?php echo htmlspecialchars($meeting['zoom_password']); ?></code>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($meeting && $success === ''): ?>
                        <!-- Toplantı Detayları -->
                        <div class="bg-gray-50 rounded-lg p-5 mb-5">
                            <h2 class="font-semibold text-gray-900 text-lg mb-3"><?php echo htmlspecialchars($meeting['title']); ?></h2>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt class="text-gray-500">Tarih</dt>
                                    <dd class="font-medium"><?php echo htmlspecialchars($meeting['date']); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Saat</dt>
                                    <dd class="font-medium">
                                        <?php echo substr($meeting['start_time'], 0, 5); ?>
                                        - <?php echo substr($meeting['end_time'], 0, 5); ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Talep Eden</dt>
                                    <dd class="font-medium"><?php
                                        echo htmlspecialchars(trim(($meeting['user_name'] ?? '') . ' ' . ($meeting['user_surname'] ?? '')));
                                    ?></dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Birim</dt>
                                    <dd class="font-medium"><?php echo htmlspecialchars($meeting['department_name'] ?? '—'); ?></dd>
                                </div>
                                <?php if (!empty($meeting['moderator'])): ?>
                                    <div>
                                        <dt class="text-gray-500">Moderatör</dt>
                                        <dd class="font-medium"><?php echo htmlspecialchars($meeting['moderator']); ?></dd>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($meeting['participants_count'])): ?>
                                    <div>
                                        <dt class="text-gray-500">Katılımcı sayısı</dt>
                                        <dd class="font-medium"><?php echo (int)$meeting['participants_count']; ?></dd>
                                    </div>
                                <?php endif; ?>
                            </dl>
                            <?php if (!empty($meeting['description'])): ?>
                                <div class="mt-3 pt-3 border-t border-gray-200">
                                    <dt class="text-gray-500 text-sm">Açıklama</dt>
                                    <dd class="text-sm mt-1"><?php echo nl2br(htmlspecialchars($meeting['description'])); ?></dd>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Zoom Hesap Seçimi -->
                        <?php if (empty($availableAccounts)): ?>
                            <div class="bg-orange-50 border border-orange-200 text-orange-800 rounded-lg p-4">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                Bu tarih ve saatte uygun (çakışmasız) bir Zoom hesabı bulunamadı. Lütfen panelden manuel onaylayın.
                            </div>
                        <?php else: ?>
                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Uygun Zoom Hesapları (<?php echo count($availableAccounts); ?> hesap çakışmasız)
                                    </label>
                                    <div class="space-y-2">
                                        <?php foreach ($availableAccounts as $i => $acc): ?>
                                            <label class="block p-3 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-indigo-400 hover:bg-indigo-50 transition">
                                                <input type="radio" name="zoom_account_id" value="<?php echo (int)$acc['id']; ?>" <?php echo $i === 0 ? 'checked' : ''; ?> class="mr-2">
                                                <span class="font-semibold"><?php echo htmlspecialchars($acc['name']); ?></span>
                                                <span class="text-sm text-gray-500 ml-2"><?php echo htmlspecialchars($acc['email']); ?></span>
                                                <span class="ml-2 text-xs bg-gray-100 px-2 py-1 rounded uppercase font-semibold"><?php echo htmlspecialchars($acc['account_type'] ?? 'basic'); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="bg-blue-50 border border-blue-200 text-blue-800 rounded-lg p-3 text-sm">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Onay verildikten sonra Zoom toplantısı otomatik oluşturulur ve toplantı sahibine bildirilir.
                                    Global varsayılan Zoom ayarları kullanılır.
                                </div>

                                <button type="submit" class="w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-semibold py-3 rounded-lg transition shadow-lg">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    Toplantıyı Onayla
                                </button>
                            </form>
                        <?php endif; ?>
                <?php endif; ?>

                <?php if ($error !== '' && !$meeting): ?>
                    <div class="text-center text-gray-500 mt-4 text-sm">
                        Bu sayfa SMS ile gönderilmiş tek-kullanımlık bir onay linkidir.
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-gray-50 px-6 py-3 text-xs text-gray-500 text-center border-t border-gray-200">
                <?php echo htmlspecialchars($appName); ?>
            </div>
        </div>
    </div>
</body>
</html>
