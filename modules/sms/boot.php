<?php
/**
 * SMS Modülü — Boot
 *
 * Her request başında ModuleManager tarafından yüklenir. Hook'ları register eder.
 */

require_once __DIR__ . '/lib/SmsService.php';

// ─── Hook: yeni toplantı talebi geldiğinde admin'e SMS gönder ───
HookEngine::addAction('meeting.after_create', function ($meetingId, array $meeting) {
    global $pdo;

    try {
        $sms = new SmsService($pdo);
        $settings = $sms->getSettings();

        $adminPhone = trim($settings['admin_phone'] ?? '');
        if ($adminPhone === '') {
            writeLog('[SMS] admin_phone yapılandırılmamış — SMS atlandı.', 'warning');
            return;
        }
        if (empty($settings['api_key'])) {
            writeLog('[SMS] api_key yapılandırılmamış — SMS atlandı.', 'warning');
            return;
        }

        // Approval token üret
        $ttlHours = (int)($settings['token_ttl_hours'] ?? 24);
        $token = $sms->createApprovalToken((int)$meetingId, $ttlHours > 0 ? $ttlHours : 24);

        // Onay link'i (mutlak URL — APP_BASE_PATH ile)
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
        $approveLink = $scheme . '://' . $host . $basePath . '/modules/sms/pages/approve.php?token=' . $token;

        // Meeting'i kullanıcı + birim adıyla zenginleştir (template için)
        try {
            $stmt = $pdo->prepare("
                SELECT m.*, u.name AS user_name, u.surname AS user_surname, d.name AS department_name
                FROM meetings m
                LEFT JOIN users u ON u.id = m.user_id
                LEFT JOIN departments d ON d.id = m.department_id
                WHERE m.id = ?
            ");
            $stmt->execute([$meetingId]);
            $enriched = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($enriched) {
                $meeting = array_merge($meeting, $enriched);
            }
        } catch (Exception $e) { /* ignore */ }

        $template = $settings['message_template']
            ?? 'Yeni toplanti talebi: {title} {date} {time}. Onay: {link}';
        $message = $sms->renderTemplate($template, $meeting, $approveLink);

        $result = $sms->send($adminPhone, $message, (int)$meetingId);

        if ($result['success']) {
            writeLog("[SMS] Yöneticiye gönderildi: meeting #{$meetingId} → {$adminPhone}", 'info');
        } else {
            writeLog("[SMS] Gönderim başarısız: " . ($result['message'] ?? 'unknown'), 'error');
        }
    } catch (Throwable $e) {
        writeLog('[SMS] hook error: ' . $e->getMessage(), 'error');
    }
});

// ─── Hook: Admin sidebar'a SMS menü öğeleri ekle (absolute URL) ───
HookEngine::addFilter('sidebar.admin_menu', function (array $items, string $adminPrefix) {
    // url() helper'ı APP_BASE_PATH ile absolute URL üretir — her sayfadan çalışır
    $items[] = [
        'title' => 'SMS Ayarları',
        'icon' => 'fas fa-sms',
        'url' => function_exists('url') ? url('modules/sms/pages/settings.php') : '/modules/sms/pages/settings.php',
        'badge' => null,
    ];
    $items[] = [
        'title' => 'SMS Log\'ları',
        'icon' => 'fas fa-history',
        'url' => function_exists('url') ? url('modules/sms/pages/logs.php') : '/modules/sms/pages/logs.php',
        'badge' => null,
    ];

    return $items;
});
