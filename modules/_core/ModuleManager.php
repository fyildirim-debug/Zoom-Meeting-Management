<?php
/**
 * ModuleManager
 *
 * Modül yaşam döngüsü:
 *   discover()    — modules/ klasöründeki tüm modülleri tara
 *   install()     — module.json oku, install.php çalıştır, DB'ye kaydet (status=installed)
 *   activate()    — status=active yap, boot.php sonraki request'te yüklenir
 *   deactivate()  — status=inactive
 *   uninstall()   — uninstall.php çalıştır, DB'den sil (klasörü silmek opsiyonel)
 *   bootActive()  — her request başında: aktif modüllerin boot.php'sini yükle
 *
 * DB tablosu: modules (migration_005 ile gelir)
 */
class ModuleManager
{
    private PDO $pdo;
    private string $dbType;
    private string $modulesDir;
    private string $appRoot;

    /** Çekirdek için ayrılmış module id'ler */
    private const RESERVED_IDS = ['_core', 'core'];

    public function __construct(PDO $pdo, ?string $dbType = null, ?string $modulesDir = null, ?string $appRoot = null)
    {
        $this->pdo = $pdo;
        $this->dbType = $dbType ?? (defined('DB_TYPE') ? DB_TYPE : 'mysql');
        $this->appRoot = $appRoot ?? realpath(__DIR__ . '/../..');
        $this->modulesDir = $modulesDir ?? ($this->appRoot . DIRECTORY_SEPARATOR . 'modules');

        // Self-heal: modules tablosu yoksa hemen oluştur (migration_005'in çalışmasını bekleme)
        $this->ensureModulesTable();
    }

    /**
     * modules ana tablosu yoksa oluştur. Constructor'da çağrılır — sistem
     * "modules tablosu yok" hatası vermeden çalışsın diye.
     */
    private function ensureModulesTable(): void
    {
        try {
            $this->pdo->query("SELECT 1 FROM modules LIMIT 1");
            return; // tablo var
        } catch (Exception $e) {
            // tablo yok, oluştur
        }

        try {
            if ($this->dbType === 'mysql') {
                $sql = "CREATE TABLE IF NOT EXISTS modules (
                    id VARCHAR(64) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    version VARCHAR(32) NOT NULL,
                    description TEXT NULL,
                    author VARCHAR(255) NULL,
                    status ENUM('installed','active','inactive') NOT NULL DEFAULT 'installed',
                    config TEXT NULL,
                    installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    activated_at DATETIME NULL,
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            } else {
                $sql = "CREATE TABLE IF NOT EXISTS modules (
                    id VARCHAR(64) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    version VARCHAR(32) NOT NULL,
                    description TEXT NULL,
                    author VARCHAR(255) NULL,
                    status VARCHAR(16) NOT NULL DEFAULT 'installed' CHECK (status IN ('installed','active','inactive')),
                    config TEXT NULL,
                    installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    activated_at DATETIME NULL
                )";
            }
            $this->pdo->exec($sql);

            // migrations tablosuna da işaretle (eğer varsa) — MigrationManager
            // bir dahaki çalışmada tekrar denemesin
            try {
                $check = $this->pdo->prepare("SELECT 1 FROM migrations WHERE migration_name = ?");
                $check->execute(['005_add_modules_table']);
                if (!$check->fetchColumn()) {
                    $ins = $this->pdo->prepare("INSERT INTO migrations (migration_name, batch) VALUES (?, ?)");
                    $ins->execute(['005_add_modules_table', 1]);
                }
            } catch (Exception $e) {
                // migrations tablosu yoksa görmezden gel
            }
        } catch (Exception $e) {
            if (function_exists('writeLog')) {
                writeLog('ModuleManager::ensureModulesTable() failed: ' . $e->getMessage(), 'error');
            }
        }
    }

    public function getModulesDir(): string
    {
        return $this->modulesDir;
    }

    public function getAppRoot(): string
    {
        return $this->appRoot;
    }

    /**
     * modules/ klasörünü tara, manifest'i okunabilen tüm modülleri listele.
     * DB'deki kayıtlarla birleştirip durumlarını ekler.
     *
     * @return array<int, array> Her öğe: id, name, version, description, author, status, manifest, dir
     */
    public function discover(): array
    {
        $found = [];
        if (!is_dir($this->modulesDir)) {
            return $found;
        }

        $dbRecords = $this->fetchAllRecords();
        $dbById = [];
        foreach ($dbRecords as $row) {
            $dbById[$row['id']] = $row;
        }

        $entries = @scandir($this->modulesDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::RESERVED_IDS, true)) {
                continue;
            }
            $dir = $this->modulesDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($dir)) {
                continue;
            }

            $manifest = $this->readManifest($dir);
            if ($manifest === null) {
                // module.json yok veya bozuk — atla
                continue;
            }

            $id = $manifest['id'];
            $rec = $dbById[$id] ?? null;

            $found[] = [
                'id'          => $id,
                'name'        => $manifest['name'] ?? $id,
                'version'     => $manifest['version'] ?? '0.0.0',
                'description' => $manifest['description'] ?? '',
                'author'      => $manifest['author'] ?? '',
                'status'      => $rec['status'] ?? 'not_installed',
                'installed_at'=> $rec['installed_at'] ?? null,
                'activated_at'=> $rec['activated_at'] ?? null,
                'manifest'    => $manifest,
                'dir'         => $dir,
            ];
        }

        return $found;
    }

    /**
     * Tek bir modülün durumunu çek (DB + manifest).
     */
    public function find(string $moduleId): ?array
    {
        foreach ($this->discover() as $mod) {
            if ($mod['id'] === $moduleId) {
                return $mod;
            }
        }
        return null;
    }

    /**
     * DB kayıtlarını çek.
     */
    public function fetchAllRecords(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT * FROM modules ORDER BY id");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            // Tablo yoksa boş döner — migration henüz çalışmamış olabilir
            return [];
        }
    }

    /**
     * Modülü kur. install.php çağrılır, DB'ye 'installed' status'la eklenir.
     *
     * @return array ['success'=>bool, 'message'=>string, 'log'=>array]
     */
    public function install(string $moduleId): array
    {
        try {
            $mod = $this->find($moduleId);
            if ($mod === null) {
                return ['success' => false, 'message' => "Modül bulunamadı: {$moduleId}", 'log' => []];
            }

            if (in_array($mod['status'], ['installed', 'active', 'inactive'], true)) {
                return ['success' => false, 'message' => 'Modül zaten kurulu.', 'log' => []];
            }

            $log = [];
            $log[] = "Kurulum başlıyor: {$mod['name']} v{$mod['version']}";

            // install.php çağır
            $installFile = $mod['dir'] . DIRECTORY_SEPARATOR . 'install.php';
            if (is_file($installFile)) {
                $api = new ModuleAPI($this->pdo, $this->dbType, $mod['id'], $mod['dir'], $this->appRoot);
                $result = $this->safeIncludeReturning($installFile);

                if (is_array($result) && isset($result['install']) && is_callable($result['install'])) {
                    $result['install']($api);
                    foreach ($api->getLog() as $entry) {
                        $log[] = $entry;
                    }
                }
            }

            // DB'ye kaydet
            $stmt = $this->pdo->prepare(
                "INSERT INTO modules (id, name, version, description, author, status, installed_at) " .
                "VALUES (?, ?, ?, ?, ?, 'installed', " . $this->nowExpr() . ")"
            );
            $stmt->execute([
                $mod['id'],
                $mod['name'],
                $mod['version'],
                $mod['description'],
                $mod['author'],
            ]);

            $log[] = "Modül kuruldu.";
            return ['success' => true, 'message' => 'Modül başarıyla kuruldu.', 'log' => $log];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Kurulum hatası: ' . $e->getMessage(),
                'log'     => $log ?? [],
            ];
        }
    }

    /**
     * Modülü aktif et. status='active' yapılır; sonraki request'te boot.php yüklenir.
     * Eğer modül henüz kurulu değilse otomatik olarak install() de çağrılır.
     */
    public function activate(string $moduleId): array
    {
        $rec = $this->getRecord($moduleId);

        // Henüz kurulu değilse önce install (install.php çalışır, tablolar oluşur)
        if ($rec === null) {
            $installResult = $this->install($moduleId);
            if (!$installResult['success']) {
                return $installResult;
            }
            $rec = $this->getRecord($moduleId);
            if ($rec === null) {
                return ['success' => false, 'message' => 'Kurulum sonrası DB kaydı bulunamadı.'];
            }
        }

        if ($rec['status'] === 'active') {
            return ['success' => false, 'message' => 'Modül zaten aktif.'];
        }

        $stmt = $this->pdo->prepare(
            "UPDATE modules SET status='active', activated_at=" . $this->nowExpr() . " WHERE id = ?"
        );
        $stmt->execute([$moduleId]);

        return ['success' => true, 'message' => 'Modül aktifleştirildi.'];
    }

    /**
     * Pasifleştir.
     */
    public function deactivate(string $moduleId): array
    {
        $rec = $this->getRecord($moduleId);
        if ($rec === null) {
            return ['success' => false, 'message' => 'Modül bulunamadı.'];
        }
        if ($rec['status'] !== 'active') {
            return ['success' => false, 'message' => 'Modül zaten aktif değil.'];
        }

        $stmt = $this->pdo->prepare("UPDATE modules SET status='inactive' WHERE id = ?");
        $stmt->execute([$moduleId]);

        return ['success' => true, 'message' => 'Modül pasifleştirildi.'];
    }

    /**
     * Modülü kaldır. uninstall.php çağrılır, DB'den silinir, opsiyonel olarak klasör de silinir.
     *
     * @param string $moduleId
     * @param bool   $keepData     true → uninstall.php'ye keepData=true geç (DB tabloları korunsun)
     * @param bool   $removeFiles  true → modül klasörünü de sil
     */
    public function uninstall(string $moduleId, bool $keepData = false, bool $removeFiles = false): array
    {
        try {
            $mod = $this->find($moduleId);
            $rec = $this->getRecord($moduleId);

            $log = [];

            // uninstall.php çağır
            if ($mod !== null) {
                $uninstallFile = $mod['dir'] . DIRECTORY_SEPARATOR . 'uninstall.php';
                if (is_file($uninstallFile)) {
                    $api = new ModuleAPI($this->pdo, $this->dbType, $mod['id'], $mod['dir'], $this->appRoot);
                    $result = $this->safeIncludeReturning($uninstallFile);
                    if (is_array($result) && isset($result['uninstall']) && is_callable($result['uninstall'])) {
                        $result['uninstall']($api, $keepData);
                        foreach ($api->getLog() as $entry) {
                            $log[] = $entry;
                        }
                    }
                }
            }

            // DB'den sil
            if ($rec !== null) {
                $stmt = $this->pdo->prepare("DELETE FROM modules WHERE id = ?");
                $stmt->execute([$moduleId]);
                $log[] = "DB kaydı silindi.";
            }

            // Dosyaları sil (opsiyonel)
            if ($removeFiles && $mod !== null && is_dir($mod['dir'])) {
                $this->removeDir($mod['dir']);
                $log[] = "Modül klasörü silindi.";
            }

            return ['success' => true, 'message' => 'Modül kaldırıldı.', 'log' => $log];

        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Kaldırma hatası: ' . $e->getMessage(), 'log' => $log ?? []];
        }
    }

    /**
     * Aktif modüllerin boot.php'lerini dahil et — bootstrap.php'den çağrılır.
     */
    public function bootActive(): void
    {
        try {
            $stmt = $this->pdo->query("SELECT id FROM modules WHERE status = 'active'");
            $activeIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
        } catch (Exception $e) {
            // Tablo henüz yoksa boot etme
            return;
        }

        foreach ($activeIds as $id) {
            $bootFile = $this->modulesDir . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'boot.php';
            if (is_file($bootFile)) {
                try {
                    require_once $bootFile;
                } catch (Throwable $e) {
                    if (function_exists('writeLog')) {
                        writeLog("Module boot error [{$id}]: " . $e->getMessage(), 'error');
                    } else {
                        error_log("Module boot error [{$id}]: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Modülün module.json'unu oku ve şemayı doğrula.
     *
     * @return array|null Manifest dizisi, null = okunamadı/geçersiz
     */
    public function readManifest(string $moduleDir): ?array
    {
        $manifestPath = $moduleDir . DIRECTORY_SEPARATOR . 'module.json';
        if (!is_file($manifestPath)) {
            return null;
        }

        $raw = @file_get_contents($manifestPath);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        // Zorunlu alanlar
        if (empty($data['id']) || empty($data['name']) || empty($data['version'])) {
            return null;
        }

        // id formatı: küçük harf, rakam, tire, alt çizgi
        if (!preg_match('/^[a-z0-9_\-]{2,64}$/', $data['id'])) {
            return null;
        }

        return $data;
    }

    /**
     * DB kaydını çek.
     */
    public function getRecord(string $moduleId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM modules WHERE id = ?");
            $stmt->execute([$moduleId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Modülün config JSON'unu döner (kendi ayarları için).
     */
    public function getConfig(string $moduleId): array
    {
        $rec = $this->getRecord($moduleId);
        if ($rec === null || empty($rec['config'])) {
            return [];
        }
        $data = json_decode($rec['config'], true);
        return is_array($data) ? $data : [];
    }

    /**
     * Modülün config JSON'unu güncelle (merge).
     */
    public function updateConfig(string $moduleId, array $patch): void
    {
        $current = $this->getConfig($moduleId);
        $merged = array_replace_recursive($current, $patch);
        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare("UPDATE modules SET config = ? WHERE id = ?");
        $stmt->execute([$json, $moduleId]);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function nowExpr(): string
    {
        return $this->dbType === 'mysql' ? 'NOW()' : "datetime('now')";
    }

    /**
     * Bir dosyayı try/catch ile dahil et, dönüş değerini al.
     */
    private function safeIncludeReturning(string $file)
    {
        return (static function ($__file) {
            return require $__file;
        })($file);
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
