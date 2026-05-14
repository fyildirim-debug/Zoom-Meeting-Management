<?php
/**
 * SmsService
 *
 * Modülün ana iş katmanı:
 *   - Ayarları oku
 *   - Doğru provider'ı seç
 *   - Gönder + log'a yaz
 *   - Approval token oluştur
 */

require_once __DIR__ . '/SmsProvider.php';
require_once __DIR__ . '/GenericFormPostSmsProvider.php';

class SmsService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * SMS tabloları kurulu mu? (defensive helper)
     */
    public function isReady(): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM module_sms_settings LIMIT 1");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Tüm ayarları key/value array olarak döner.
     * Tablo yoksa boş array (fatal değil).
     */
    public function getSettings(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM module_sms_settings");
            $result = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$row['setting_key']] = $row['setting_value'];
            }
            return $result;
        } catch (Exception $e) {
            return [];
        }
    }

    public function getSetting(string $key, string $default = ''): string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM module_sms_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value !== false ? (string)$value : $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    public function setSetting(string $key, string $value): void
    {
        // INSERT OR UPDATE (dialect-safe)
        $stmt = $this->pdo->prepare("SELECT 1 FROM module_sms_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        if ($stmt->fetchColumn()) {
            $stmt = $this->pdo->prepare("UPDATE module_sms_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO module_sms_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
    }

    /**
     * Doğru provider instance'ını oluştur.
     * Şu an yalnızca generic form-post tipinde bir sağlayıcı destekleniyor.
     * api_url / api_key / sender_title admin panelinden alınır.
     */
    public function buildProvider(): SmsProvider
    {
        $providerId = $this->getSetting('provider', 'generic_form_post');

        switch ($providerId) {
            case 'generic_form_post':
            case 'ankara_uni': // geriye uyumluluk — eski kurulumlardaki ayar değeri
            default:
                return new GenericFormPostSmsProvider(
                    $this->getSetting('api_url', ''),
                    $this->getSetting('api_key', ''),
                    $this->getSetting('sender_title', '')
                );
        }
    }

    /**
     * SMS gönder ve log'a yaz.
     *
     * @param string|array $phones
     * @param string $message
     * @param ?int $meetingId  Hangi meeting'le ilgili (opsiyonel)
     * @return array Provider'dan dönen sonuç (success, message, response, success_count)
     */
    public function send($phones, string $message, ?int $meetingId = null): array
    {
        $provider = $this->buildProvider();
        $phoneStr = is_array($phones) ? implode(',', $phones) : (string)$phones;

        $result = $provider->send($phones, $message);

        // Log'a yaz
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO module_sms_logs
                    (to_number, message, provider, status, response_data, error_message, meeting_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $phoneStr,
                $message,
                $provider->getId(),
                $result['success'] ? 'sent' : 'failed',
                json_encode($result['response'] ?? [], JSON_UNESCAPED_UNICODE),
                $result['success'] ? null : ($result['message'] ?? null),
                $meetingId,
            ]);
        } catch (Exception $e) {
            if (function_exists('writeLog')) {
                writeLog('SMS log write error: ' . $e->getMessage(), 'error');
            }
        }

        return $result;
    }

    /**
     * Yeni approval token üret ve DB'ye kaydet.
     * @return string Üretilen token
     */
    public function createApprovalToken(int $meetingId, int $ttlHours = 24): string
    {
        $token = bin2hex(random_bytes(32)); // 64 karakter hex
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlHours * 3600);

        $stmt = $this->pdo->prepare("
            INSERT INTO module_sms_approval_tokens (token, meeting_id, expires_at)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$token, $meetingId, $expiresAt]);

        return $token;
    }

    /**
     * Token'ı doğrula. Geçerli mi (var, expire olmamış, kullanılmamış) ve hangi meeting?
     *
     * @return array|null ['token_row'=>..., 'meeting'=>...] veya null (geçersiz)
     */
    public function validateApprovalToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM module_sms_approval_tokens
            WHERE token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        if (!empty($row['used_at'])) {
            return null;
        }
        if (strtotime($row['expires_at']) < time()) {
            return null;
        }

        // Meeting'i çek
        $stmt = $this->pdo->prepare("SELECT * FROM meetings WHERE id = ?");
        $stmt->execute([$row['meeting_id']]);
        $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$meeting) {
            return null;
        }
        if ($meeting['status'] !== 'pending') {
            return null; // başka yerden onaylanmış/red edilmiş
        }

        return ['token_row' => $row, 'meeting' => $meeting];
    }

    /**
     * Token'ı kullanıldı olarak işaretle.
     */
    public function markTokenUsed(string $token, ?string $ip = null): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE module_sms_approval_tokens
            SET used_at = " . ($this->dialect() === 'mysql' ? 'NOW()' : "datetime('now')") . ",
                used_from_ip = ?
            WHERE token = ?
        ");
        $stmt->execute([$ip, $token]);
    }

    /**
     * Mesaj şablonundaki placeholder'ları doldur.
     *
     * Desteklenen: {title} {date} {time} {link} {user} {department}
     */
    public function renderTemplate(string $template, array $meeting, string $approveLink): string
    {
        $replacements = [
            '{title}'      => $meeting['title'] ?? '',
            '{date}'       => $meeting['date'] ?? '',
            '{time}'       => substr((string)($meeting['start_time'] ?? ''), 0, 5),
            '{end_time}'   => substr((string)($meeting['end_time'] ?? ''), 0, 5),
            '{link}'       => $approveLink,
            '{user}'       => trim(($meeting['user_name'] ?? '') . ' ' . ($meeting['user_surname'] ?? '')),
            '{department}' => $meeting['department_name'] ?? '',
        ];
        return strtr($template, $replacements);
    }

    private function dialect(): string
    {
        return defined('DB_TYPE') ? DB_TYPE : 'mysql';
    }
}
