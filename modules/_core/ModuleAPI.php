<?php
/**
 * ModuleAPI
 *
 * install.php / uninstall.php / boot.php betikleri tarafından çağrılan
 * güvenli yardımcı API. Modülün doğrudan PDO erişimi olmadan tablo
 * oluşturmasına, asset kopyalamasına, log yazmasına izin verir.
 *
 * Kullanım (install.php içinde):
 *
 *     return [
 *         'install' => function (ModuleAPI $api) {
 *             $api->createTable('module_sms_logs', [
 *                 'mysql'  => 'CREATE TABLE ...',
 *                 'sqlite' => 'CREATE TABLE ...',
 *             ]);
 *             $api->copyAsset('assets/icon.png', 'public/modules/sms/');
 *         },
 *     ];
 */
class ModuleAPI
{
    private PDO $pdo;
    private string $dbType;
    private string $moduleId;
    private string $moduleDir;     // mutlak yol: .../modules/<id>
    private string $appRoot;       // proje kök yolu
    private array $log = [];

    public function __construct(PDO $pdo, string $dbType, string $moduleId, string $moduleDir, string $appRoot)
    {
        $this->pdo = $pdo;
        $this->dbType = $dbType;
        $this->moduleId = $moduleId;
        $this->moduleDir = rtrim($moduleDir, DIRECTORY_SEPARATOR);
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getDbType(): string
    {
        return $this->dbType;
    }

    public function getModuleId(): string
    {
        return $this->moduleId;
    }

    /**
     * Modülün kendi dizini içinde mutlak yol döner.
     */
    public function getModulePath(string $relative = ''): string
    {
        return $this->moduleDir . ($relative === '' ? '' : DIRECTORY_SEPARATOR . ltrim($relative, '/\\'));
    }

    /**
     * Tablo oluştur. Dialect'e göre uygun SQL'i seç.
     * Idempotent: tablo zaten varsa sessizce atlanır (yarım kurulumların
     * tekrar çalıştırılabilmesi için).
     *
     * @param string $tableName Loglama için
     * @param array $sqlByDialect ['mysql'=>'CREATE TABLE...', 'sqlite'=>'CREATE TABLE...']
     */
    public function createTable(string $tableName, array $sqlByDialect): void
    {
        if (!isset($sqlByDialect[$this->dbType])) {
            throw new RuntimeException("Tablo {$tableName} için {$this->dbType} dialect SQL'i tanımlı değil.");
        }

        if ($this->tableExists($tableName)) {
            $this->log[] = "Tablo zaten mevcut, atlandı: {$tableName}";
            return;
        }

        $sql = $sqlByDialect[$this->dbType];
        $this->pdo->exec($sql);
        $this->log[] = "Tablo oluşturuldu: {$tableName}";
    }

    /**
     * Belirtilen tablo veritabanında var mı?
     */
    public function tableExists(string $tableName): bool
    {
        try {
            if ($this->dbType === 'mysql') {
                $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$tableName]);
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
            $stmt->execute([$tableName]);
            return $stmt->fetchColumn() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Tablo sil (uninstall'da kullanılır).
     */
    public function dropTable(string $tableName): void
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
        if ($clean === '' || $clean !== $tableName) {
            throw new InvalidArgumentException("Geçersiz tablo adı: {$tableName}");
        }
        $this->pdo->exec("DROP TABLE IF EXISTS " . $this->quoteIdent($clean));
        $this->log[] = "Tablo silindi: {$tableName}";
    }

    /**
     * Modüldeki bir dosyayı proje kökünde başka bir yere kopyala.
     * Hedef whitelist'le sınırlı: assets/, public/, uploads/, modules/
     * config/, includes/, admin/, api/ gibi çekirdek dizinlere yazılamaz.
     */
    public function copyAsset(string $sourceRelative, string $destinationRelative): void
    {
        $src = $this->getModulePath($sourceRelative);
        if (!is_file($src)) {
            throw new RuntimeException("Kaynak dosya bulunamadı: {$sourceRelative}");
        }

        // Hedef: appRoot içinde, whitelist'te
        $destBase = $this->resolveSafeDestination($destinationRelative);
        if (!is_dir($destBase)) {
            if (!@mkdir($destBase, 0755, true) && !is_dir($destBase)) {
                throw new RuntimeException("Hedef dizin oluşturulamadı: {$destinationRelative}");
            }
        }

        $destFile = $destBase . DIRECTORY_SEPARATOR . basename($src);
        if (!@copy($src, $destFile)) {
            throw new RuntimeException("Dosya kopyalanamadı: {$sourceRelative} → {$destinationRelative}");
        }
        $this->log[] = "Asset kopyalandı: {$sourceRelative} → {$destinationRelative}";
    }

    /**
     * Hedef path'i güvenli mi kontrol et:
     * - appRoot içinde olmalı
     * - whitelist'te olmalı (assets, public, uploads, data/uploads, modules/<id>)
     */
    private function resolveSafeDestination(string $relative): string
    {
        // Normalize
        $rel = ltrim(str_replace(['\\', '..'], ['/', ''], $relative), '/');
        $abs = $this->appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

        // Çözümle (parent yoksa parent'ı kontrol et)
        $checkPath = is_dir($abs) ? realpath($abs) : realpath(dirname($abs));
        $rootReal = realpath($this->appRoot);
        if ($checkPath === false || strpos($checkPath, $rootReal) !== 0) {
            throw new RuntimeException("Hedef proje dizini dışında: {$relative}");
        }

        // Whitelist
        $allowed = [
            'assets',
            'public',
            'uploads',
            'data' . DIRECTORY_SEPARATOR . 'uploads',
            'modules' . DIRECTORY_SEPARATOR . $this->moduleId,
        ];

        $relPath = substr($checkPath, strlen($rootReal) + 1);
        $relPath = str_replace('/', DIRECTORY_SEPARATOR, $relPath);

        $isAllowed = false;
        foreach ($allowed as $prefix) {
            if ($relPath === $prefix || strpos($relPath, $prefix . DIRECTORY_SEPARATOR) === 0) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            throw new RuntimeException("Hedef dizin izinli değil: {$relative}. İzinli: " . implode(', ', $allowed));
        }

        return $abs;
    }

    /**
     * Kayıt yaz (settings tablosu — KEY/VALUE).
     * Modül kendi settings tablosunu kullanır; bu helper genel settings için.
     */
    public function setSystemSetting(string $key, string $value): void
    {
        $key = preg_replace('/[^A-Za-z0-9_\.]/', '', $key);
        $stmt = $this->pdo->prepare("SELECT id FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        if ($stmt->fetch()) {
            $stmt = $this->pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
    }

    /**
     * Bir identifier'ı dialect'e göre quote et.
     */
    private function quoteIdent(string $identifier): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $identifier);
        return $this->dbType === 'mysql' ? '`' . $clean . '`' : '"' . $clean . '"';
    }

    /**
     * İşlem log'unu döner.
     */
    public function getLog(): array
    {
        return $this->log;
    }
}
