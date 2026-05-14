<?php
/**
 * ModuleInstaller
 *
 * Yüklenen ZIP'i güvenle aç, modules/<id>/ altına yerleştir, ModuleManager::install() çağır.
 *
 * Güvenlik kontrolleri:
 *   1. ZIP içinde module.json olmalı
 *   2. Manifest doğrula (id formatı, name, version)
 *   3. ZIP entry path'lerinde traversal (..) yok
 *   4. ZIP entry'ler izinli uzantılar: php, json, md, txt, sql, html, css, js, png, jpg, gif, svg, ico
 *   5. PHP dosyalarında tehlikeli fonksiyonlar kara listede (exec, shell_exec, eval, system, passthru, popen, proc_open, ...)
 *   6. ZIP içinde aynı isimde modül zaten kuruluysa hata
 */
class ModuleInstaller
{
    private ModuleManager $manager;

    /** İzinli dosya uzantıları */
    private const ALLOWED_EXTS = [
        'php', 'json', 'md', 'txt', 'sql', 'html', 'htm',
        'css', 'js',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp',
        'htaccess', // sadece .htaccess olarak gelmez ama yedek
    ];

    /** Yasak PHP fonksiyonları (regex pattern) */
    private const DANGEROUS_FUNCTIONS = [
        'exec', 'shell_exec', 'passthru', 'system',
        'eval', 'assert',
        'popen', 'proc_open', 'proc_close',
        'pcntl_exec', 'pcntl_fork',
        'putenv',
        'create_function', // PHP 7.2+ deprecated; eval gibi davranıyor
    ];

    public function __construct(ModuleManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Yüklenen ZIP'i kur.
     *
     * @param string $zipPath  Yüklenen ZIP'in geçici yolu
     * @return array ['success'=>bool, 'message'=>string, 'module_id'=>?string, 'log'=>array]
     */
    public function installFromZip(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'ZipArchive PHP eklentisi yüklü değil.', 'log' => []];
        }
        if (!is_file($zipPath)) {
            return ['success' => false, 'message' => 'ZIP dosyası bulunamadı.', 'log' => []];
        }

        $log = [];

        // 1. ZIP'i aç
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'message' => 'ZIP dosyası açılamadı (bozuk olabilir).', 'log' => $log];
        }

        try {
            // 2. module.json'u oku
            $manifestRaw = $zip->getFromName('module.json');
            if ($manifestRaw === false) {
                throw new RuntimeException('ZIP içinde module.json bulunamadı.');
            }
            $manifest = json_decode($manifestRaw, true);
            if (!is_array($manifest)) {
                throw new RuntimeException('module.json bozuk (JSON parse hatası).');
            }
            if (empty($manifest['id']) || empty($manifest['name']) || empty($manifest['version'])) {
                throw new RuntimeException('module.json eksik alan: id, name ve version zorunlu.');
            }
            if (!preg_match('/^[a-z0-9_\-]{2,64}$/', $manifest['id'])) {
                throw new RuntimeException('Geçersiz modül id formatı: ' . $manifest['id']);
            }
            $moduleId = $manifest['id'];
            $log[] = "Manifest doğrulandı: {$manifest['name']} v{$manifest['version']}";

            // 3. Zaten kurulu mu?
            $existing = $this->manager->find($moduleId);
            if ($existing !== null && $existing['status'] !== 'not_installed') {
                throw new RuntimeException("Modül zaten kurulu: {$moduleId}. Önce kaldırın.");
            }
            // 'not_installed' (disk'te dosyalar var, DB'de kayıt yok) = yarım kurulum
            // → izin ver, aşağıda hedef dizin temizlenecek
            $isHalfInstalled = ($existing !== null && $existing['status'] === 'not_installed');

            // 4. ZIP entry'lerini doğrula
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];

                // Path traversal kontrolü
                if (strpos($name, '..') !== false || strpos($name, ':') !== false || strpos($name, "\0") !== false) {
                    throw new RuntimeException("Güvensiz path: {$name}");
                }
                if (substr($name, 0, 1) === '/' || substr($name, 0, 1) === '\\') {
                    throw new RuntimeException("Mutlak path izin verilmez: {$name}");
                }

                // Dizin entry'leri
                if (substr($name, -1) === '/') {
                    continue;
                }

                // Uzantı kontrolü
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($ext === '' && basename($name) === '.htaccess') {
                    $ext = 'htaccess';
                }
                if (!in_array($ext, self::ALLOWED_EXTS, true)) {
                    throw new RuntimeException("İzin verilmeyen dosya uzantısı: {$name}");
                }

                // PHP dosyaları için içerik kontrolü
                if ($ext === 'php') {
                    $content = $zip->getFromIndex($i);
                    if ($content === false) {
                        throw new RuntimeException("PHP dosyası okunamadı: {$name}");
                    }
                    $this->scanPhpForDangerousFunctions($name, $content);
                }

                $entries[] = $name;
            }
            $log[] = count($entries) . " dosya doğrulandı.";

            // 5. Hedef dizini oluştur (yarım kurulum varsa önce sil)
            $targetDir = $this->manager->getModulesDir() . DIRECTORY_SEPARATOR . $moduleId;
            if (is_dir($targetDir)) {
                if ($isHalfInstalled) {
                    $this->removeDir($targetDir);
                    $log[] = "Önceki yarım kurulum temizlendi: modules/{$moduleId}";
                } else {
                    throw new RuntimeException("Hedef dizin zaten var: modules/{$moduleId}. Önce silin.");
                }
            }
            if (!@mkdir($targetDir, 0755, true)) {
                throw new RuntimeException("Hedef dizin oluşturulamadı: modules/{$moduleId}");
            }

            // 6. Dosyaları çıkar
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $outPath = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);

                if (substr($name, -1) === '/') {
                    if (!is_dir($outPath)) {
                        @mkdir($outPath, 0755, true);
                    }
                    continue;
                }

                $outDir = dirname($outPath);
                if (!is_dir($outDir)) {
                    @mkdir($outDir, 0755, true);
                }
                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    throw new RuntimeException("Dosya çıkarılamadı: {$name}");
                }
                if (file_put_contents($outPath, $content) === false) {
                    throw new RuntimeException("Dosya yazılamadı: {$name}");
                }
            }
            $log[] = "Dosyalar çıkarıldı: modules/{$moduleId}";

            $zip->close();

            // 7. ModuleManager::install çağır
            $installResult = $this->manager->install($moduleId);
            if (!$installResult['success']) {
                // Rollback: dosyaları sil
                $this->removeDir($targetDir);
                return [
                    'success'   => false,
                    'message'   => 'Modül kurulum çağrısı başarısız: ' . $installResult['message'],
                    'module_id' => $moduleId,
                    'log'       => array_merge($log, $installResult['log'] ?? []),
                ];
            }

            return [
                'success'   => true,
                'message'   => 'Modül başarıyla kuruldu.',
                'module_id' => $moduleId,
                'log'       => array_merge($log, $installResult['log'] ?? []),
            ];

        } catch (Throwable $e) {
            $zip->close();
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'log'     => $log,
            ];
        }
    }

    /**
     * PHP içeriğini tehlikeli fonksiyonlar için tara.
     * Sadece basit pattern matching — gerçek bir sandbox değil, kötü niyetli kodu yavaşlatır.
     */
    private function scanPhpForDangerousFunctions(string $filename, string $content): void
    {
        // Yorum satırlarını kabaca temizle (false positive'leri azalt)
        $stripped = preg_replace('!//.*?$|#.*?$!m', '', $content);
        $stripped = preg_replace('!/\*.*?\*/!s', '', $stripped);

        foreach (self::DANGEROUS_FUNCTIONS as $fn) {
            // \b\fn\s*\( pattern — namespace içinde \fn de yakalar
            if (preg_match('/\\\\?\b' . preg_quote($fn, '/') . '\s*\(/i', $stripped)) {
                throw new RuntimeException(
                    "Yasak fonksiyon tespit edildi ({$filename}): {$fn}(). " .
                    "Güvenlik nedeniyle bu modül yüklenemez."
                );
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
