<?php
/**
 * HookEngine
 *
 * WordPress benzeri event/filter mekanizması.
 *
 * İki tür hook:
 *   - Action  → bir olayda bir şey yap (return değeri yok)
 *               örn: meeting.after_create, user.after_login
 *   - Filter  → bir değeri modifiye et (callback değer alır, döner)
 *               örn: dashboard.widgets, sidebar.admin_menu
 *
 * Modüllerin boot.php'si bootstrap aşamasında dahil edilir ve şöyle çağrılır:
 *
 *     HookEngine::addAction('meeting.after_create', function($meetingId, $data) {
 *         // SMS gönder
 *     });
 *
 *     HookEngine::addFilter('sidebar.admin_menu', function($items) {
 *         $items[] = ['title'=>'SMS', 'url'=>'modules/sms/pages/settings.php', 'icon'=>'fas fa-sms'];
 *         return $items;
 *     });
 *
 * Çekirdek tarafından şöyle tetiklenir:
 *
 *     HookEngine::doAction('meeting.after_create', $meetingId, $meetingData);
 *     $menu = HookEngine::applyFilters('sidebar.admin_menu', $menu);
 */
class HookEngine
{
    /** @var array<string, array<int, array<int, callable>>> */
    private static $actions = [];

    /** @var array<string, array<int, array<int, callable>>> */
    private static $filters = [];

    /**
     * Action callback'i kaydet.
     *
     * @param string   $hook       Örn: 'meeting.after_create'
     * @param callable $callback   Çağrılacak fonksiyon
     * @param int      $priority   Düşük öncelikli önce çalışır (default: 10)
     */
    public static function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        self::$actions[$hook][$priority][] = $callback;
    }

    /**
     * Filter callback'i kaydet.
     */
    public static function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        self::$filters[$hook][$priority][] = $callback;
    }

    /**
     * Action'ı tetikle. Tüm callback'ler sırayla çalışır.
     *
     * @param string $hook
     * @param mixed  ...$args Callback'lere geçirilecek argümanlar
     */
    public static function doAction(string $hook, ...$args): void
    {
        if (empty(self::$actions[$hook])) {
            return;
        }

        $priorities = self::$actions[$hook];
        ksort($priorities);

        foreach ($priorities as $callbacks) {
            foreach ($callbacks as $callback) {
                try {
                    $callback(...$args);
                } catch (Throwable $e) {
                    // Bir modülün hatası diğerlerini durdurmasın.
                    // writeLog mevcut olabilir (includes/functions.php yüklü ise)
                    if (function_exists('writeLog')) {
                        writeLog("HookEngine action error [{$hook}]: " . $e->getMessage(), 'error');
                    } else {
                        error_log("HookEngine action error [{$hook}]: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Filter'ı uygula. Her callback değeri alır ve modifiye edilmiş halini döner.
     *
     * @param string $hook
     * @param mixed  $value İlk değer
     * @param mixed  ...$args Ek argümanlar (her callback'e geçer)
     * @return mixed Tüm callback'lerden geçmiş değer
     */
    public static function applyFilters(string $hook, $value, ...$args)
    {
        if (empty(self::$filters[$hook])) {
            return $value;
        }

        $priorities = self::$filters[$hook];
        ksort($priorities);

        foreach ($priorities as $callbacks) {
            foreach ($callbacks as $callback) {
                try {
                    $value = $callback($value, ...$args);
                } catch (Throwable $e) {
                    if (function_exists('writeLog')) {
                        writeLog("HookEngine filter error [{$hook}]: " . $e->getMessage(), 'error');
                    } else {
                        error_log("HookEngine filter error [{$hook}]: " . $e->getMessage());
                    }
                    // Hata varsa o callback'i atla, value değişmez.
                }
            }
        }

        return $value;
    }

    /**
     * Bir hook'a kayıtlı callback var mı?
     */
    public static function hasAction(string $hook): bool
    {
        return !empty(self::$actions[$hook]);
    }

    public static function hasFilter(string $hook): bool
    {
        return !empty(self::$filters[$hook]);
    }

    /**
     * Kayıtlı tüm hook'ların listesi — debug için.
     */
    public static function getRegisteredHooks(): array
    {
        return [
            'actions' => array_keys(self::$actions),
            'filters' => array_keys(self::$filters),
        ];
    }

    /**
     * Test/temizlik amaçlı — sadece testlerde kullan.
     */
    public static function reset(): void
    {
        self::$actions = [];
        self::$filters = [];
    }
}
