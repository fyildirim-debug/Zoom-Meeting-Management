<?php
/**
 * SMS Modülü — Kurulum
 *
 * 3 tablo oluşturur:
 *   - module_sms_settings        — anahtar/değer ayar deposu
 *   - module_sms_logs            — gönderim log'u
 *   - module_sms_approval_tokens — login'siz onay link token'ları
 */

return [
    'install' => function (ModuleAPI $api) {
        $dbType = $api->getDbType();
        $pdo    = $api->getPdo();

        // ─── Self-heal: yarım kalmış önceki kurulumdan tablolar varsa temizle ───
        // Modül `modules` tablosunda kayıtlı değilken bu install.php çalışıyorsa
        // demek ki ya ilk kurulum ya da önceki kurulum yarıda kalmış. Her iki
        // durumda da temizden başlamak güvenli (veri yoksa kayıp da yok).
        try { $pdo->exec("DROP TABLE IF EXISTS module_sms_approval_tokens"); } catch (Exception $e) {}
        try { $pdo->exec("DROP TABLE IF EXISTS module_sms_logs"); }            catch (Exception $e) {}
        try { $pdo->exec("DROP TABLE IF EXISTS module_sms_settings"); }        catch (Exception $e) {}

        // settings: key/value
        $api->createTable('module_sms_settings', [
            'mysql' => "
                CREATE TABLE module_sms_settings (
                    setting_key VARCHAR(64) PRIMARY KEY,
                    setting_value TEXT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'sqlite' => "
                CREATE TABLE module_sms_settings (
                    setting_key VARCHAR(64) PRIMARY KEY,
                    setting_value TEXT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ",
        ]);

        // logs
        $api->createTable('module_sms_logs', [
            'mysql' => "
                CREATE TABLE module_sms_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    to_number VARCHAR(20) NOT NULL,
                    message TEXT NOT NULL,
                    provider VARCHAR(32) NOT NULL DEFAULT 'generic_form_post',
                    status VARCHAR(16) NOT NULL DEFAULT 'pending',
                    response_data TEXT NULL,
                    error_message TEXT NULL,
                    meeting_id INT NULL,
                    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_status (status),
                    INDEX idx_meeting (meeting_id),
                    INDEX idx_sent (sent_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'sqlite' => "
                CREATE TABLE module_sms_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    to_number VARCHAR(20) NOT NULL,
                    message TEXT NOT NULL,
                    provider VARCHAR(32) NOT NULL DEFAULT 'generic_form_post',
                    status VARCHAR(16) NOT NULL DEFAULT 'pending',
                    response_data TEXT NULL,
                    error_message TEXT NULL,
                    meeting_id INTEGER NULL,
                    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ",
        ]);

        // approval tokens
        $api->createTable('module_sms_approval_tokens', [
            'mysql' => "
                CREATE TABLE module_sms_approval_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    token VARCHAR(64) UNIQUE NOT NULL,
                    meeting_id INT NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    used_from_ip VARCHAR(45) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_token (token),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'sqlite' => "
                CREATE TABLE module_sms_approval_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    token VARCHAR(64) UNIQUE NOT NULL,
                    meeting_id INTEGER NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    used_from_ip VARCHAR(45) NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ",
        ]);

        // Varsayılan ayarlar — idempotent (zaten varsa dokunma)
        // NOT: api_url, api_key, sender_title gibi değerler kurumunuza özeldir
        // ve admin panelinden girilmelidir. Bu varsayılanlar boş bırakılmıştır.
        $pdo = $api->getPdo();
        $defaults = [
            'provider'        => 'generic_form_post',
            'api_url'         => '',
            'api_key'         => '',
            'sender_title'    => '',
            'admin_phone'     => '',
            'message_template'=> 'Yeni toplanti talebi: {title} {date} {time}. Onay: {link}',
            'token_ttl_hours' => '24',
            'approver_user_id'=> '1',
        ];

        $check  = $pdo->prepare("SELECT 1 FROM module_sms_settings WHERE setting_key = ?");
        $insert = $pdo->prepare("INSERT INTO module_sms_settings (setting_key, setting_value) VALUES (?, ?)");

        foreach ($defaults as $k => $v) {
            $check->execute([$k]);
            if ($check->fetchColumn()) {
                continue; // bu ayar zaten var, koru
            }
            $insert->execute([$k, $v]);
        }
    },
];
