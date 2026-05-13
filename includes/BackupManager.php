<?php
/**
 * BackupManager
 *
 * Veritabanı export/import (JSON + ZIP).
 * MySQL ↔ SQLite arası portable: schema farkları hariç tüm row verileri taşır.
 *
 * Export çıktısı:
 *   backup-YYYY-MM-DD-HHMMSS.zip
 *     ├── manifest.json   (app_version, db_type, generated_at, tables, row_counts)
 *     ├── departments.json
 *     ├── users.json
 *     ├── ...
 *     └── (tablo başına bir dosya)
 *
 * Import: tüm tabloları DELETE eder, JSON'daki satırları aynı ID'lerle INSERT eder,
 * AUTO_INCREMENT'i max(id)+1'e ayarlar.
 */

class BackupManager
{
    private $pdo;
    private $dbType;

    // Backup'a dahil edilecek tablolar — schema sırası önemli (FK için):
    // önce parent tablolar (departments, users), sonra çocuklar.
    public const BACKUP_TABLES = [
        'departments',
        'users',
        'zoom_accounts',
        'meetings',
        'settings',
        'notifications',
        'activity_logs',
        'invitation_links',
        'migrations',
        'zoom_api_logs',
        'system_closures',
    ];

    public function __construct(PDO $pdo, ?string $dbType = null)
    {
        $this->pdo = $pdo;
        $this->dbType = $dbType ?? (defined('DB_TYPE') ? DB_TYPE : 'mysql');
    }

    /**
     * Export: ZIP dosyası oluştur, yolunu döner.
     *
     * @param string $outputDir ZIP'in yazılacağı dizin
     * @return array ['success'=>bool, 'message'=>string, 'file'=>?string, 'size'=>?int]
     */
    public function exportToZip(string $outputDir): array
    {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'ZipArchive eklentisi PHP kurulumunuzda mevcut değil.'];
        }

        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
                return ['success' => false, 'message' => 'Backup dizini oluşturulamadı: ' . $outputDir];
            }
        }
        if (!is_writable($outputDir)) {
            return ['success' => false, 'message' => 'Backup dizini yazılabilir değil: ' . $outputDir];
        }

        $timestamp = date('Y-m-d-His');
        $zipName = 'backup-' . $timestamp . '.zip';
        $zipPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $zipName;

        $existingTables = $this->getExistingTables(self::BACKUP_TABLES);

        $manifest = [
            'app_version' => defined('APP_VERSION') ? APP_VERSION : 'unknown',
            'db_type' => $this->dbType,
            'generated_at' => date('c'),
            'tables' => [],
            'row_counts' => [],
        ];

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['success' => false, 'message' => 'ZIP dosyası oluşturulamadı: ' . $zipPath];
        }

        try {
            foreach ($existingTables as $table) {
                $rows = $this->fetchAllRows($table);
                $manifest['tables'][] = $table;
                $manifest['row_counts'][$table] = count($rows);

                $json = json_encode(
                    ['table' => $table, 'rows' => $rows],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                );

                if ($json === false) {
                    $zip->close();
                    @unlink($zipPath);
                    return [
                        'success' => false,
                        'message' => "Tablo '{$table}' JSON'a çevrilirken hata: " . json_last_error_msg(),
                    ];
                }

                $zip->addFromString($table . '.json', $json);
            }

            $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $zip->addFromString('manifest.json', $manifestJson);

            $zip->close();
        } catch (Throwable $e) {
            $zip->close();
            @unlink($zipPath);
            return ['success' => false, 'message' => 'Export hatası: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Yedek başarıyla oluşturuldu.',
            'file' => $zipPath,
            'size' => filesize($zipPath) ?: 0,
            'manifest' => $manifest,
        ];
    }

    /**
     * Import: ZIP dosyasını oku, mevcut verileri temizle, yedekteki satırları yükle.
     *
     * @param string $zipPath ZIP yolu
     * @return array ['success'=>bool, 'message'=>string, 'log'=>array, 'manifest'=>?array]
     */
    public function importFromZip(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'ZipArchive eklentisi PHP kurulumunuzda mevcut değil.', 'log' => []];
        }

        if (!file_exists($zipPath) || !is_readable($zipPath)) {
            return ['success' => false, 'message' => 'Yedek dosyası bulunamadı veya okunamıyor.', 'log' => []];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'message' => 'Yedek dosyası açılamadı (geçersiz ZIP).', 'log' => []];
        }

        // manifest.json oku
        $manifestRaw = $zip->getFromName('manifest.json');
        if ($manifestRaw === false) {
            $zip->close();
            return ['success' => false, 'message' => 'Geçersiz yedek: manifest.json bulunamadı.', 'log' => []];
        }

        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || empty($manifest['tables'])) {
            $zip->close();
            return ['success' => false, 'message' => 'Geçersiz yedek: manifest.json bozuk.', 'log' => []];
        }

        $log = [];
        $log[] = 'Yedek versiyonu: ' . ($manifest['app_version'] ?? 'unknown');
        $log[] = 'Yedek kaynak DB: ' . ($manifest['db_type'] ?? 'unknown');
        $log[] = 'Hedef DB: ' . $this->dbType;
        $log[] = 'Oluşturulma: ' . ($manifest['generated_at'] ?? 'unknown');

        // Tüm tablo verilerini önce belleğe al (transaction'a girmeden önce parse hatalarını yakala)
        $tableData = [];
        foreach ($manifest['tables'] as $table) {
            // Güvenlik: sadece izinli tablolar
            if (!in_array($table, self::BACKUP_TABLES, true)) {
                $log[] = "[ATLA] Beklenmeyen tablo: {$table}";
                continue;
            }

            $raw = $zip->getFromName($table . '.json');
            if ($raw === false) {
                $zip->close();
                return [
                    'success' => false,
                    'message' => "Yedekte tablo dosyası eksik: {$table}.json",
                    'log' => $log,
                    'manifest' => $manifest,
                ];
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || !isset($decoded['rows'])) {
                $zip->close();
                return [
                    'success' => false,
                    'message' => "Tablo JSON'u bozuk: {$table}.json",
                    'log' => $log,
                    'manifest' => $manifest,
                ];
            }

            $tableData[$table] = $decoded['rows'];
        }
        $zip->close();

        // Hedef tablolar var mı?
        $existingTables = $this->getExistingTables(array_keys($tableData));
        $missingTables = array_diff(array_keys($tableData), $existingTables);
        if (!empty($missingTables)) {
            return [
                'success' => false,
                'message' => 'Hedef veritabanında şu tablolar eksik: ' . implode(', ', $missingTables) . '. Önce kurulumu tamamlayın.',
                'log' => $log,
                'manifest' => $manifest,
            ];
        }

        // Restore — child→parent silme, parent→child ekleme
        try {
            $this->disableForeignKeyChecks();

            // Reverse sırada DELETE (child önce)
            $deleteOrder = array_reverse($existingTables);
            foreach ($deleteOrder as $table) {
                $this->pdo->exec("DELETE FROM " . $this->quoteIdent($table));
                $log[] = "[CLEAR] {$table}";
            }

            // Forward sırada INSERT (parent önce)
            foreach ($existingTables as $table) {
                $rows = $tableData[$table];
                $inserted = $this->insertRows($table, $rows);
                $log[] = "[INSERT] {$table}: {$inserted} satır";
            }

            // AUTO_INCREMENT / sqlite_sequence'ı sıfırla
            foreach ($existingTables as $table) {
                $this->resetAutoIncrement($table);
            }
            $log[] = '[RESET] AUTO_INCREMENT/sqlite_sequence ayarlandı';

            $this->enableForeignKeyChecks();
        } catch (Throwable $e) {
            // FK checks'i tekrar aç (transactional değiliz, çünkü DDL'in transaction'da MySQL'de implicit commit yapma riski var)
            try { $this->enableForeignKeyChecks(); } catch (Throwable $ignore) {}
            return [
                'success' => false,
                'message' => 'Import sırasında hata: ' . $e->getMessage(),
                'log' => $log,
                'manifest' => $manifest,
            ];
        }

        $log[] = 'Restore tamamlandı.';

        return [
            'success' => true,
            'message' => 'Yedek başarıyla geri yüklendi.',
            'log' => $log,
            'manifest' => $manifest,
        ];
    }

    /**
     * Sadece belirtilen tablolardan veritabanında VAR olanları döner (sırayı korur).
     */
    private function getExistingTables(array $candidates): array
    {
        $existing = [];

        if ($this->dbType === 'mysql') {
            $stmt = $this->pdo->query("SHOW TABLES");
            $allTables = array_map(static fn($r) => array_values($r)[0], $stmt->fetchAll());
        } else {
            $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
            $allTables = array_column($stmt->fetchAll(), 'name');
        }

        foreach ($candidates as $table) {
            if (in_array($table, $allTables, true)) {
                $existing[] = $table;
            }
        }
        return $existing;
    }

    private function fetchAllRows(string $table): array
    {
        $stmt = $this->pdo->query("SELECT * FROM " . $this->quoteIdent($table));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function insertRows(string $table, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        // Hedef tablonun gerçek kolonlarını al — yedekte fazlalık varsa atla
        $targetColumns = $this->getTableColumns($table);

        $count = 0;
        $stmt = null;
        $lastSignature = null;

        foreach ($rows as $row) {
            // Yedekteki kolonları hedef tabloyla kesiştir
            $columns = [];
            $values = [];
            foreach ($targetColumns as $col) {
                if (array_key_exists($col, $row)) {
                    $columns[] = $col;
                    $values[] = $row[$col];
                }
            }

            if (empty($columns)) {
                continue;
            }

            // Aynı kolon imzalı satırlar için statement'ı yeniden hazırlama
            $signature = implode(',', $columns);
            if ($stmt === null || $signature !== $lastSignature) {
                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $quotedCols = implode(',', array_map([$this, 'quoteIdent'], $columns));
                $sql = "INSERT INTO " . $this->quoteIdent($table) .
                       " ({$quotedCols}) VALUES ({$placeholders})";
                $stmt = $this->pdo->prepare($sql);
                $lastSignature = $signature;
            }

            $stmt->execute($values);
            $count++;
        }

        return $count;
    }

    private function getTableColumns(string $table): array
    {
        if ($this->dbType === 'mysql') {
            $stmt = $this->pdo->query("DESCRIBE " . $this->quoteIdent($table));
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        }
        $stmt = $this->pdo->query("PRAGMA table_info(" . $this->quoteIdent($table) . ")");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    private function disableForeignKeyChecks(): void
    {
        if ($this->dbType === 'mysql') {
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        } else {
            $this->pdo->exec("PRAGMA foreign_keys = OFF");
        }
    }

    private function enableForeignKeyChecks(): void
    {
        if ($this->dbType === 'mysql') {
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } else {
            $this->pdo->exec("PRAGMA foreign_keys = ON");
        }
    }

    private function resetAutoIncrement(string $table): void
    {
        // id kolonu var mı?
        $columns = $this->getTableColumns($table);
        if (!in_array('id', $columns, true)) {
            return;
        }

        $maxIdStmt = $this->pdo->query("SELECT MAX(id) FROM " . $this->quoteIdent($table));
        $maxId = (int)$maxIdStmt->fetchColumn();

        if ($this->dbType === 'mysql') {
            $next = $maxId + 1;
            $this->pdo->exec("ALTER TABLE " . $this->quoteIdent($table) . " AUTO_INCREMENT = {$next}");
        } else {
            // SQLite: sqlite_sequence'ı güncelle (varsa)
            $check = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sqlite_sequence'")->fetch();
            if ($check) {
                $stmt = $this->pdo->prepare("DELETE FROM sqlite_sequence WHERE name = ?");
                $stmt->execute([$table]);
                if ($maxId > 0) {
                    $stmt = $this->pdo->prepare("INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)");
                    $stmt->execute([$table, $maxId]);
                }
            }
        }
    }

    /**
     * Identifier'ı dialect'e göre quote et.
     * MySQL: backtick, SQLite: çift tırnak.
     */
    private function quoteIdent(string $identifier): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $identifier);
        if ($this->dbType === 'mysql') {
            return '`' . $clean . '`';
        }
        return '"' . $clean . '"';
    }

    /**
     * Yedek dosyasının manifest'ini önizleme için döner (import etmeden).
     */
    public static function peekManifest(string $zipPath): ?array
    {
        if (!class_exists('ZipArchive') || !file_exists($zipPath)) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        $raw = $zip->getFromName('manifest.json');
        $zip->close();
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
