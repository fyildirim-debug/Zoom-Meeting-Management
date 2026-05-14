<?php
/**
 * MeetingService
 *
 * Toplantı onay/red akışlarının çekirdek mantığı. Hem admin paneli (oturum açık)
 * hem de modüller (örn. SMS ile login'siz onay) tarafından kullanılır.
 *
 * Hook'lar:
 *   - meeting.before_approve(meetingId, meeting, zoomAccountId)
 *   - meeting.after_approve(meetingId, meeting, zoomMeetingData)
 *   - meeting.after_reject(meetingId, meeting, reason)
 *
 * Önemli: $approverId parametresi (session bağımsız). SMS approve.php gibi
 * login'siz akışlar için system admin user id'si geçilebilir.
 */
class MeetingService
{
    /**
     * Toplantıyı onayla, Zoom API üzerinden meeting oluştur, DB'ye yaz.
     *
     * @param int      $meetingId
     * @param int      $zoomAccountId   Hangi Zoom hesabıyla oluşturulacak
     * @param array    $customSettings  Per-meeting custom Zoom ayarları
     * @param int      $approverId      Onayı yapan user id (audit için)
     * @return array   ['success'=>bool, 'message'=>string, 'meeting'=>?array, 'zoom_data'=>?array]
     */
    public static function approve(int $meetingId, $zoomAccountId, array $customSettings = [], int $approverId = 0): array
    {
        global $pdo;

        if (!class_exists('ZoomAPI')) {
            require_once __DIR__ . '/ZoomAPI.php';
        }

        try {
            $pdo->beginTransaction();

            // Toplantıyı çek
            $stmt = $pdo->prepare("SELECT * FROM meetings WHERE id = ?");
            $stmt->execute([$meetingId]);
            $meeting = $stmt->fetch();

            if (!$meeting) {
                throw new Exception('Toplantı bulunamadı.');
            }
            if ($meeting['status'] !== 'pending') {
                throw new Exception('Bu toplantı zaten işlenmiş (durum: ' . $meeting['status'] . ').');
            }

            // Boş string'i normalize
            if ($zoomAccountId === '' || $zoomAccountId === '0' || $zoomAccountId === 0) {
                $zoomAccountId = null;
            }
            if (!$zoomAccountId) {
                throw new Exception('Zoom hesabı seçimi zorunludur.');
            }

            // Çakışma kontrolü
            if (checkZoomAccountConflict($zoomAccountId, $meeting['date'], $meeting['start_time'], $meeting['end_time'])) {
                throw new Exception('Seçilen Zoom hesabı bu saatte başka bir toplantıda kullanılıyor.');
            }

            // Hesap aktif mi?
            $stmt = $pdo->prepare("SELECT * FROM zoom_accounts WHERE id = ? AND status = 'active'");
            $stmt->execute([$zoomAccountId]);
            $zoomAccount = $stmt->fetch();
            if (!$zoomAccount) {
                throw new Exception('Seçilen Zoom hesabı bulunamadı veya aktif değil.');
            }

            // before_approve hook
            if (class_exists('HookEngine')) {
                HookEngine::doAction('meeting.before_approve', $meetingId, $meeting, (int)$zoomAccountId);
            }

            // Zoom API entegrasyonu
            $zoomMeetingData = null;
            $meetingLink = null;
            try {
                $zoomAccountManager = new ZoomAccountManager($pdo);
                $zoomAPI = $zoomAccountManager->getZoomAPI($zoomAccountId);

                $meetingData = [
                    'title'       => $meeting['title'],
                    'description' => $meeting['description'],
                    'date'        => $meeting['date'],
                    'start_time'  => $meeting['start_time'],
                    'end_time'    => $meeting['end_time'],
                    'host_email'  => $zoomAccount['email'],
                ];

                $apiResult = $zoomAPI->createMeeting($meetingData, $customSettings);
                if (!$apiResult['success']) {
                    throw new Exception('Zoom API hatası: ' . $apiResult['message']);
                }
                $zoomMeetingData = $apiResult['data'];
                $meetingLink = $zoomMeetingData['join_url'];

                writeLog("Zoom meeting created via MeetingService: Meeting ID {$zoomMeetingData['meeting_id']}", 'info');

            } catch (Exception $apiException) {
                writeLog("Zoom API error in MeetingService: " . $apiException->getMessage(), 'error');
                // Fallback: basit link
                $meetingLink = self::generateFallbackLink($zoomAccount, $meeting);
                $zoomMeetingData = null;
            }

            // DB'yi güncelle
            if ($zoomMeetingData) {
                $stmt = $pdo->prepare("
                    UPDATE meetings
                    SET status = 'approved',
                        zoom_account_id = ?,
                        meeting_link = ?,
                        zoom_meeting_id = ?,
                        zoom_uuid = ?,
                        zoom_join_url = ?,
                        zoom_start_url = ?,
                        zoom_password = ?,
                        zoom_host_id = ?,
                        api_created_at = NOW(),
                        approved_at = NOW(),
                        approved_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $zoomAccountId,
                    $meetingLink,
                    $zoomMeetingData['meeting_id'],
                    $zoomMeetingData['uuid'],
                    $zoomMeetingData['join_url'],
                    $zoomMeetingData['start_url'],
                    $zoomMeetingData['password'],
                    $zoomMeetingData['host_id'],
                    $approverId ?: null,
                    $meetingId,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE meetings
                    SET status = 'approved',
                        zoom_account_id = ?,
                        meeting_link = ?,
                        approved_at = NOW(),
                        approved_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([$zoomAccountId, $meetingLink, $approverId ?: null, $meetingId]);
            }

            $pdo->commit();

            writeLog("Meeting approved via MeetingService: ID $meetingId, Zoom Account: $zoomAccountId, By: $approverId", 'info');
            logActivity('approved', 'meeting', $meetingId,
                'Toplantı onaylandı: ' . $meeting['title'] . ' (' . $meeting['date'] . ')',
                $approverId);

            // Güncel meeting'i çek
            $stmt = $pdo->prepare("SELECT * FROM meetings WHERE id = ?");
            $stmt->execute([$meetingId]);
            $updatedMeeting = $stmt->fetch() ?: $meeting;

            // after_approve hook
            if (class_exists('HookEngine')) {
                HookEngine::doAction('meeting.after_approve', $meetingId, $updatedMeeting, $zoomMeetingData);
            }

            return [
                'success'   => true,
                'message'   => 'Toplantı başarıyla onaylandı.',
                'meeting'   => $updatedMeeting,
                'zoom_data' => $zoomMeetingData,
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            writeLog("MeetingService::approve error: " . $e->getMessage(), 'error');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Verilen tarih+saat için uygun (çakışmasız + aktif) Zoom hesaplarını döner.
     * SMS approve.php tarafından kullanılır.
     */
    public static function getAvailableZoomAccounts(string $date, string $startTime, string $endTime, ?int $excludeMeetingId = null): array
    {
        global $pdo;

        $stmt = $pdo->query("SELECT * FROM zoom_accounts WHERE status = 'active' ORDER BY max_concurrent_meetings DESC, name ASC");
        $accounts = $stmt->fetchAll();

        $available = [];
        foreach ($accounts as $account) {
            if (!checkZoomAccountConflict($account['id'], $date, $startTime, $endTime, $excludeMeetingId)) {
                $available[] = $account;
            }
        }
        return $available;
    }

    /**
     * Fallback meeting link (Zoom API başarısızsa).
     */
    private static function generateFallbackLink(array $zoomAccount, array $meeting): string
    {
        // Basit pseudo-link — gerçek bir meeting değil, ama UI'da gösterilebilir
        $id = '8' . str_pad((string)$meeting['id'], 9, '0', STR_PAD_LEFT);
        return 'https://zoom.us/j/' . $id;
    }
}
