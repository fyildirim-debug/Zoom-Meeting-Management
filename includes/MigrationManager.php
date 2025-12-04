<?php
/**
 * Migration Yönetim Sistemi
 * 
 * Veritabanı güncellemelerini otomatik olarak yönetir.
 * Giriş yapıldığında eksik migration'ları sırayla çalıştırır.
 */

class MigrationManager {
    private $pdo;
    private $migrationsPath;
    private $dbType;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->migrationsPath = __DIR__ . '/migrations/';
        $this->dbType = defined('DB_TYPE') ? DB_TYPE : 'mysql';
    }
    
    /**
     * Migration tablosunu oluştur (yoksa)
     */
    public function ensureMigrationTable() {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER " . ($this->dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                migration_name VARCHAR(255) UNIQUE NOT NULL,
                batch INTEGER NOT NULL DEFAULT 1,
                executed_at " . ($this->dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
            )";
            
            $this->pdo->exec($sql);
            return true;
        } catch (Exception $e) {
            writeLog("Migration tablosu oluşturulamadı: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Çalıştırılmış migration'ları al
     */
    public function getExecutedMigrations() {
        try {
            $stmt = $this->pdo->query("SELECT migration_name FROM migrations ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            // Tablo yoksa boş dizi döndür
            return [];
        }
    }
    
    /**
     * Bekleyen migration'ları al
     */
    public function getPendingMigrations() {
        $executed = $this->getExecutedMigrations();
        $all = $this->getAllMigrations();
        
        return array_diff($all, $executed);
    }
    
    /**
     * Tüm migration dosyalarını al (sıralı)
     */
    public function getAllMigrations() {
        $migrations = [];
        
        // Yerleşik migration'lar (kod içinde tanımlı)
        $builtInMigrations = [
            '001_add_zoom_fields',
            '002_add_recording_fields',
            '003_add_meeting_indexes',
            '004_add_system_closures'
        ];
        
        // Dosya bazlı migration'lar (varsa)
        if (is_dir($this->migrationsPath)) {
            $files = glob($this->migrationsPath . '*.php');
            foreach ($files as $file) {
                $name = basename($file, '.php');
                if (!in_array($name, $builtInMigrations)) {
                    $builtInMigrations[] = $name;
                }
            }
        }
        
        sort($builtInMigrations);
        return $builtInMigrations;
    }
    
    /**
     * Bekleyen tüm migration'ları çalıştır
     */
    public function runPendingMigrations() {
        // Migration tablosunu oluştur
        $this->ensureMigrationTable();
        
        $pending = $this->getPendingMigrations();
        
        if (empty($pending)) {
            return [
                'success' => true,
                'message' => 'Tüm migration\'lar güncel',
                'executed' => []
            ];
        }
        
        $executed = [];
        $errors = [];
        $batch = $this->getNextBatch();
        
        foreach ($pending as $migration) {
            $result = $this->runMigration($migration, $batch);
            
            if ($result['success']) {
                $executed[] = $migration;
                $this->markAsExecuted($migration, $batch);
                writeLog("✅ Migration başarılı: $migration", 'info');
            } else {
                $errors[] = [
                    'migration' => $migration,
                    'error' => $result['message']
                ];
                writeLog("❌ Migration hatası: $migration - " . $result['message'], 'error');
                // Hata durumunda devam et (diğer migration'lar çalışabilir)
            }
        }
        
        return [
            'success' => empty($errors),
            'message' => count($executed) . ' migration çalıştırıldı' . (count($errors) > 0 ? ', ' . count($errors) . ' hata' : ''),
            'executed' => $executed,
            'errors' => $errors
        ];
    }
    
    /**
     * Tek bir migration'ı çalıştır
     */
    private function runMigration($name, $batch) {
        try {
            // Yerleşik migration'ları kontrol et
            $method = 'migrate_' . $name;
            if (method_exists($this, $method)) {
                return $this->$method();
            }
            
            // Dosya bazlı migration
            $file = $this->migrationsPath . $name . '.php';
            if (file_exists($file)) {
                $migrationClass = require $file;
                if (is_callable($migrationClass)) {
                    return $migrationClass($this->pdo, $this->dbType);
                }
            }
            
            return [
                'success' => false,
                'message' => "Migration bulunamadı: $name"
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Migration'ı çalıştırıldı olarak işaretle
     */
    private function markAsExecuted($name, $batch) {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO migrations (migration_name, batch) VALUES (?, ?)");
            $stmt->execute([$name, $batch]);
        } catch (Exception $e) {
            // Duplicate hatası olabilir, yoksay
        }
    }
    
    /**
     * Sonraki batch numarasını al
     */
    private function getNextBatch() {
        try {
            $stmt = $this->pdo->query("SELECT MAX(batch) FROM migrations");
            $max = $stmt->fetchColumn();
            return ($max ?? 0) + 1;
        } catch (Exception $e) {
            return 1;
        }
    }
    
    /**
     * Sütun var mı kontrol et
     */
    private function columnExists($table, $column) {
        try {
            if ($this->dbType === 'mysql') {
                $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                $stmt->execute([$column]);
                return $stmt->fetch() !== false;
            } else {
                // SQLite
                $stmt = $this->pdo->query("PRAGMA table_info($table)");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
                return in_array($column, $columns);
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Sütun ekle (yoksa)
     */
    private function addColumnIfNotExists($table, $column, $definition) {
        if (!$this->columnExists($table, $column)) {
            $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
            $this->pdo->exec($sql);
            return true;
        }
        return false;
    }
    
    /**
     * Index var mı kontrol et (MySQL)
     */
    private function indexExists($table, $indexName) {
        if ($this->dbType !== 'mysql') {
            return true; // SQLite için index kontrolü atla
        }
        
        try {
            $stmt = $this->pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    // ============================================
    // YERLEŞİK MIGRATION'LAR
    // ============================================
    
    /**
     * Migration 001: Zoom alanlarını ekle
     */
    private function migrate_001_add_zoom_fields() {
        try {
            $fields = [
                'zoom_meeting_id' => 'VARCHAR(255) NULL',
                'zoom_uuid' => 'VARCHAR(500) NULL',
                'zoom_join_url' => 'VARCHAR(1000) NULL',
                'zoom_start_url' => 'TEXT NULL',
                'zoom_password' => 'VARCHAR(255) NULL',
                'zoom_host_id' => 'VARCHAR(255) NULL',
                'parent_meeting_id' => 'VARCHAR(255) NULL',
                'is_recurring_occurrence' => 'TINYINT DEFAULT 0'
            ];
            
            $added = 0;
            foreach ($fields as $field => $definition) {
                if ($this->addColumnIfNotExists('meetings', $field, $definition)) {
                    $added++;
                }
            }
            
            return [
                'success' => true,
                'message' => "$added alan eklendi"
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Migration 002: Kayıt alanlarını ekle
     */
    private function migrate_002_add_recording_fields() {
        try {
            $fields = [
                'has_recording' => 'TINYINT DEFAULT 0',
                'recording_url' => 'TEXT NULL',
                'recording_password' => 'VARCHAR(255) NULL',
                'recording_size' => 'BIGINT DEFAULT 0',
                'actual_duration' => 'INTEGER DEFAULT 0',
                'actual_participants' => 'INTEGER DEFAULT 0'
            ];
            
            $added = 0;
            foreach ($fields as $field => $definition) {
                if ($this->addColumnIfNotExists('meetings', $field, $definition)) {
                    $added++;
                }
            }
            
            return [
                'success' => true,
                'message' => "$added alan eklendi"
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Migration 003: Index'leri ekle
     */
    private function migrate_003_add_meeting_indexes() {
        if ($this->dbType !== 'mysql') {
            return ['success' => true, 'message' => 'SQLite - index atlandı'];
        }
        
        try {
            $indexes = [
                'idx_zoom_meeting_id' => 'zoom_meeting_id',
                'idx_date_status' => 'date, status',
                'idx_user_department' => 'user_id, department_id'
            ];
            
            $added = 0;
            foreach ($indexes as $name => $columns) {
                if (!$this->indexExists('meetings', $name)) {
                    try {
                        $this->pdo->exec("ALTER TABLE meetings ADD INDEX `$name` ($columns)");
                        $added++;
                    } catch (Exception $e) {
                        // Index zaten varsa veya başka hata, devam et
                    }
                }
            }
            
            return [
                'success' => true,
                'message' => "$added index eklendi"
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Migration 004: Sistem kapatma tablosu
     * Belirli tarih aralıklarında toplantı oluşturmayı engeller
     */
    private function migrate_004_add_system_closures() {
        try {
            // Tablo var mı kontrol et
            $tableExists = false;
            try {
                $this->pdo->query("SELECT 1 FROM system_closures LIMIT 1");
                $tableExists = true;
            } catch (Exception $e) {
                $tableExists = false;
            }
            
            if (!$tableExists) {
                if ($this->dbType === 'mysql') {
                    $sql = "CREATE TABLE system_closures (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        title VARCHAR(255) NOT NULL,
                        start_date DATE NOT NULL,
                        end_date DATE NOT NULL,
                        reason TEXT NULL,
                        is_active TINYINT DEFAULT 1,
                        created_by INT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_dates (start_date, end_date),
                        INDEX idx_active (is_active),
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                } else {
                    // SQLite
                    $sql = "CREATE TABLE system_closures (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        title VARCHAR(255) NOT NULL,
                        start_date DATE NOT NULL,
                        end_date DATE NOT NULL,
                        reason TEXT NULL,
                        is_active INTEGER DEFAULT 1,
                        created_by INTEGER NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                    )";
                }
                
                $this->pdo->exec($sql);
                
                return [
                    'success' => true,
                    'message' => 'system_closures tablosu oluşturuldu'
                ];
            }
            
            return [
                'success' => true,
                'message' => 'system_closures tablosu zaten mevcut'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

/**
 * Otomatik migration kontrolü - giriş sonrası çağrılır
 */
function runAutoMigrations() {
    global $pdo;
    
    if (!isset($pdo)) {
        return false;
    }
    
    try {
        $manager = new MigrationManager($pdo);
        $pending = $manager->getPendingMigrations();
        
        if (!empty($pending)) {
            $result = $manager->runPendingMigrations();
            
            if (!empty($result['executed'])) {
                writeLog("🔄 Otomatik migration: " . implode(', ', $result['executed']), 'info');
            }
            
            return $result;
        }
        
        return ['success' => true, 'message' => 'Migration gerekmedi'];
        
    } catch (Exception $e) {
        writeLog("Migration hatası: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
