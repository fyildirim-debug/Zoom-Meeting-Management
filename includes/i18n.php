<?php
/**
 * I18n — basit JSON tabanlı çoklu dil desteği.
 *
 * Kullanım:
 *
 *     // Bootstrap (functions.php tarafından otomatik):
 *     I18n::init();
 *
 *     // Şablonlarda:
 *     <?= t('sidebar.dashboard') ?>
 *     <?= t('greeting', ['name' => 'Furkan']) ?>     // → "Merhaba, Furkan!"
 *
 *     // Locale değiştir (URL'den, kalıcı):
 *     <a href="?lang=en">English</a>
 *
 * Locale tespiti sırası:
 *   1. URL parametresi ?lang=XX  (session ve cookie'ye yazılır)
 *   2. $_SESSION['lang']
 *   3. $_COOKIE['lang']
 *   4. Browser Accept-Language
 *   5. Default ('tr')
 *
 * Çeviri dosyaları:
 *   lang/tr.json
 *   lang/en.json
 *
 * Format:
 *   { "common": { "save": "Kaydet" }, "sidebar": { ... } }
 *
 * `t('common.save')` dot notation ile derinlere iner.
 */
class I18n
{
    private const SUPPORTED  = ['tr', 'en'];
    private const DEFAULT    = 'tr';
    private const COOKIE_KEY = 'app_lang';

    private static string $locale = self::DEFAULT;
    private static array $translations = [];
    private static bool $initialized = false;

    /**
     * Dil tespiti + JSON yükleme.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // 1) URL parametresi — kalıcı seçim
        if (isset($_GET['lang']) && in_array($_GET['lang'], self::SUPPORTED, true)) {
            $chosen = $_GET['lang'];
            if (session_status() !== PHP_SESSION_NONE) {
                $_SESSION['lang'] = $chosen;
            }
            @setcookie(self::COOKIE_KEY, $chosen, time() + 365 * 86400, '/');
            self::$locale = $chosen;
        }
        // 2) Session
        elseif (isset($_SESSION['lang']) && in_array($_SESSION['lang'], self::SUPPORTED, true)) {
            self::$locale = $_SESSION['lang'];
        }
        // 3) Cookie
        elseif (isset($_COOKIE[self::COOKIE_KEY]) && in_array($_COOKIE[self::COOKIE_KEY], self::SUPPORTED, true)) {
            self::$locale = $_COOKIE[self::COOKIE_KEY];
        }
        // 4) Browser Accept-Language
        elseif (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            foreach (self::SUPPORTED as $lang) {
                if (stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'], $lang) !== false) {
                    self::$locale = $lang;
                    break;
                }
            }
        }

        self::loadCatalog(self::$locale);
        if (self::$locale !== self::DEFAULT) {
            self::loadCatalog(self::DEFAULT); // fallback için
        }
    }

    /**
     * Bir locale için JSON dosyasını yükle.
     */
    private static function loadCatalog(string $locale): void
    {
        if (isset(self::$translations[$locale])) {
            return;
        }
        $path = __DIR__ . '/../lang/' . $locale . '.json';
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    self::$translations[$locale] = $data;
                    return;
                }
            }
        }
        self::$translations[$locale] = [];
    }

    /**
     * Çevrilmiş metni döner. Anahtar yoksa fallback locale, o da yoksa anahtarın kendisi.
     *
     * @param string $key    Dot notation: 'sidebar.dashboard'
     * @param array  $params {placeholder} → değer
     */
    public static function t(string $key, array $params = []): string
    {
        if (!self::$initialized) {
            self::init();
        }

        $value = self::lookup($key, self::$locale);
        if ($value === null && self::$locale !== self::DEFAULT) {
            $value = self::lookup($key, self::DEFAULT);
        }
        if ($value === null) {
            $value = $key; // anahtar olduğu gibi (eksik çeviriyi geliştiricinin görmesi için)
        }

        if (!empty($params)) {
            foreach ($params as $k => $v) {
                $value = str_replace('{' . $k . '}', (string)$v, $value);
            }
        }
        return $value;
    }

    /**
     * Bir locale içinden dot notation ile değer çek.
     */
    private static function lookup(string $key, string $locale): ?string
    {
        if (empty(self::$translations[$locale])) {
            return null;
        }
        $parts = explode('.', $key);
        $node = self::$translations[$locale];
        foreach ($parts as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return is_string($node) ? $node : null;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function supported(): array
    {
        return self::SUPPORTED;
    }

    /**
     * Locale'in insan okunabilir adı (kendi dilinde).
     */
    public static function nativeName(string $locale): string
    {
        $names = ['tr' => 'Türkçe', 'en' => 'English'];
        return $names[$locale] ?? strtoupper($locale);
    }
}

// ─── Global helpers ─────────────────────────────────────────────
if (!function_exists('t')) {
    function t(string $key, array $params = []): string
    {
        return I18n::t($key, $params);
    }
}

if (!function_exists('__')) {
    function __(string $key, array $params = []): string
    {
        return I18n::t($key, $params);
    }
}

if (!function_exists('current_locale')) {
    function current_locale(): string
    {
        return I18n::locale();
    }
}
