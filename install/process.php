<?php
/**
 * Kurulum İşleme Dosyası - Otomatik Veritabanı Oluşturma
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Response helper
if (!function_exists('jsonResponse')) {
    function jsonResponse($success, $message = '', $data = []) {
    // UTF-8 encoding ve error handling ile güvenli JSON response
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data
    ];
    
    $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if ($json === false) {
        // JSON encoding hatası durumunda basit response
        $json = json_encode([
            'success' => false,
            'message' => 'JSON encoding error: ' . json_last_error_msg(),
            'data' => []
        ]);
    }
    
    echo $json;
    exit();
    }
}

// Include functions
require_once '../includes/functions.php';

// Check if already installed
function checkAlreadyInstalled() {
    if (file_exists('../config/config.php') && file_exists('../config/database.php')) {
        jsonResponse(false, 'Sistem zaten kurulmuş. Yeniden kurmak için mevcut kurulum dosyalarını silin.');
    }
}

// Validate required fields
function validateRequiredFields($fields, $data) {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            jsonResponse(false, "Gerekli alan eksik: {$field}");
        }
    }
}

// Generate unique database name
function generateDatabaseName() {
    return 'zoom_meetings_' . date('Ymd_His');
}

// Create database automatically
function createDatabase($config) {
    try {
        if ($config['type'] === 'mysql') {
            $dbName = $config['database'];
            
            if (isset($config['auto_create_db']) && $config['auto_create_db']) {
                // Auto-create mode: Connect to MySQL server and create database
                $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
                $pdo = new PDO($dsn, $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]);
                
                // Create database
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                
                // Now connect to the created database
                $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]);
            } else {
                // Manual mode: Connect directly to existing database
                $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]);
            }
            
        } else {
            // SQLite
            $dbPath = '../data/' . $config['file'];
            $dbDir = dirname($dbPath);
            
            if (!file_exists($dbDir)) {
                if (!mkdir($dbDir, 0755, true)) {
                    throw new Exception('Data klasörü oluşturulamadı: ' . $dbDir);
                }
            }
            
            $dsn = "sqlite:{$dbPath}";
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        }
        
        // Test connection
        $pdo->query('SELECT 1');
        return ['success' => true, 'pdo' => $pdo, 'database_name' => $config['database']];
        
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Test database connection (without creating database)
function testDatabaseConnection($config) {
    try {
        if ($config['type'] === 'mysql') {
            // First test connection to MySQL server
            $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
            
            // Test if user has proper credentials by running basic query
            $pdo->query('SELECT 1');
            
            // If auto_create_db is enabled, test CREATE privileges
            if (isset($config['auto_create_db']) && $config['auto_create_db']) {
                try {
                    $testDbName = 'test_privileges_' . uniqid();
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
                    return ['success' => true, 'message' => 'MySQL bağlantısı başarılı - Veritabanı oluşturma yetkisi mevcut'];
                } catch (Exception $e) {
                    return ['success' => false, 'message' => 'MySQL bağlantısı başarılı ancak veritabanı oluşturma yetkisi yok. Lütfen "Veritabanı Oluşturma" seçeneğini kapatın ve mevcut veritabanı adını girin.'];
                }
            } else {
                // Manual database mode - test if the specified database exists and is accessible
                try {
                    $dbName = $config['database'];
                    $testDsn = "mysql:host={$config['host']};port={$config['port']};dbname={$dbName};charset=utf8mb4";
                    $testPdo = new PDO($testDsn, $config['username'], $config['password'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                    ]);
                    
                    // Test if user can perform basic operations on the database
                    $testPdo->query('SELECT 1');
                    
                    // Test if user has CREATE TABLE privileges
                    try {
                        $testPdo->exec("CREATE TABLE IF NOT EXISTS test_table_" . uniqid() . " (id INT AUTO_INCREMENT PRIMARY KEY)");
                        $stmt = $testPdo->query("SHOW TABLES LIKE 'test_table_%'");
                        $testTable = $stmt->fetch();
                        if ($testTable) {
                            $tableName = array_values($testTable)[0];
                            $testPdo->exec("DROP TABLE `{$tableName}`");
                        }
                        return ['success' => true, 'message' => 'Veritabanı bağlantısı başarılı - Tablo oluşturma yetkisi mevcut'];
                    } catch (Exception $e) {
                        return ['success' => false, 'message' => 'Veritabanına bağlantı başarılı ancak tablo oluşturma yetkisi yok. Lütfen veritabanı kullanıcısına CREATE, ALTER, DROP yetkilerini verin.'];
                    }
                    
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Unknown database') !== false) {
                        return ['success' => false, 'message' => 'Belirtilen veritabanı bulunamadı: "' . $config['database'] . '". Lütfen doğru veritabanı adını girin veya "Veritabanı Oluşturma" seçeneğini açın.'];
                    } else {
                        return ['success' => false, 'message' => 'Veritabanına bağlantı hatası: ' . $e->getMessage()];
                    }
                }
            }
            
        } else {
            // SQLite - test file creation with minimal requirements
            $dbPath = '../data/test_connection.sqlite';
            $dbDir = dirname($dbPath);
            
            // Try to create data directory if it doesn't exist
            if (!file_exists($dbDir)) {
                if (!mkdir($dbDir, 0755, true)) {
                    throw new Exception('Data klasörü oluşturulamadı: ' . $dbDir);
                }
            }
            
            // Test SQLite file creation and basic operations
            $dsn = "sqlite:{$dbPath}";
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            // Test basic operations
            $pdo->query('SELECT 1');
            $pdo->exec('CREATE TABLE IF NOT EXISTS test_table (id INTEGER PRIMARY KEY AUTOINCREMENT)');
            $pdo->exec('INSERT INTO test_table VALUES (1)');
            $pdo->exec('SELECT * FROM test_table');
            $pdo->exec('DROP TABLE IF EXISTS test_table');
            
            // Clean up test file
            if (file_exists($dbPath)) {
                unlink($dbPath);
            }
            
            return ['success' => true, 'message' => 'SQLite test başarılı'];
        }
        
    } catch (PDOException $e) {
        return ['success' => false, 'message' => formatDatabaseError($e, $config)];
    } catch (Exception $e) {
        return ['success' => false, 'message' => formatGeneralError($e, $config)];
    }
}

// Format database error messages in user-friendly way
function formatDatabaseError($e, $config) {
    $errorCode = $e->getCode();
    $errorMessage = $e->getMessage();
    
    // MySQL specific error handling
    if ($config['type'] === 'mysql') {
        // Connection refused - server not running
        if (strpos($errorMessage, 'Connection refused') !== false || strpos($errorMessage, 'SQLSTATE[HY000] [2002]') !== false) {
            return '🔌 MySQL sunucusuna bağlanılamadı. Lütfen kontrol edin:
• MySQL servisi çalışıyor mu?
• Sunucu adresi doğru mu? (' . $config['host'] . ':' . $config['port'] . ')
• Firewall MySQL portunu (3306) engelliyor mu?';
        }
        
        // Access denied - wrong credentials
        if (strpos($errorMessage, 'Access denied') !== false || strpos($errorMessage, 'SQLSTATE[HY000] [1045]') !== false) {
            return '🔐 Kullanıcı adı veya şifre hatalı. Lütfen kontrol edin:
• Kullanıcı adı: ' . $config['username'] . '
• Şifre doğru girildi mi?
• Bu kullanıcı MySQL\'e bağlanma yetkisine sahip mi?
• Uzaktan bağlantı için kullanıcı yetkisi var mı?';
        }
        
        // Database doesn't exist
        if (strpos($errorMessage, 'Unknown database') !== false || strpos($errorMessage, 'SQLSTATE[42000] [1049]') !== false) {
            return '🗃️ Veritabanı bulunamadı. Sistem otomatik olarak oluşturacaktır.
• Kullanıcının veritabanı oluşturma yetkisi olduğundan emin olun
• CREATE, DROP yetkilerini kontrol edin';
        }
        
        // Host not allowed
        if (strpos($errorMessage, 'not allowed to connect') !== false || strpos($errorMessage, 'SQLSTATE[HY000] [1130]') !== false) {
            return '🚫 Bu sunucudan bağlantı izni yok. Lütfen kontrol edin:
• MySQL kullanıcısı bu IP adresinden bağlanabilir mi?
• Bind-address ayarları doğru mu?
• MySQL kullanıcı yetkilerini kontrol edin';
        }
        
        // Insufficient privileges
        if (strpos($errorMessage, 'Access denied') !== false && strpos($errorMessage, 'SHOW DATABASES') !== false) {
            return '⚠️ Yetersiz kullanıcı yetkisi. Kurulum için gerekli yetkiler:
• SELECT, INSERT, UPDATE, DELETE
• CREATE, DROP (veritabanı ve tablo)
• ALTER (tablo değişiklikleri)
• INDEX (performans optimizasyonu)';
        }
        
        // Unknown host
        if (strpos($errorMessage, 'Unknown host') !== false || strpos($errorMessage, 'SQLSTATE[HY000] [2005]') !== false) {
            return '🌐 Sunucu adresi bulunamadı:
• Sunucu adresi doğru mu? (' . $config['host'] . ')
• DNS ayarları çalışıyor mu?
• localhost yerine 127.0.0.1 deneyin';
        }
        
        // Timeout
        if (strpos($errorMessage, 'timed out') !== false || strpos($errorMessage, 'SQLSTATE[HY000] [2002]') !== false) {
            return '⏱️ Bağlantı zaman aşımı:
• Sunucu adresi: ' . $config['host'] . ':' . $config['port'] . '
• Ağ bağlantısı yavaş olabilir
• Firewall ayarlarını kontrol edin
• MySQL sunucusu aşırı yüklenmiş olabilir';
        }
    }
    
    // SQLite specific error handling
    if ($config['type'] === 'sqlite') {
        if (strpos($errorMessage, 'unable to open database file') !== false) {
            return '📁 SQLite veritabanı dosyası oluşturulamadı:
• Klasör yazma izinleri kontrol edin
• Disk alanı yeterli mi?
• data/ klasörü erişilebilir mi?';
        }
        
        if (strpos($errorMessage, 'database is locked') !== false) {
            return '🔒 SQLite veritabanı kilitli:
• Başka bir process dosyayı kullanıyor olabilir
• Sunucuyu yeniden başlatmayı deneyin
• Dosya izinlerini kontrol edin';
        }
    }
    
    // Generic fallback message
    return '❌ Veritabanı bağlantı hatası oluştu:
• Tüm bilgileri kontrol edin
• Sunucu loglarını inceleyin
• Sistem yöneticinize danışın
• Hata detayı: ' . $errorMessage;
}

// Format general error messages
function formatGeneralError($e, $config) {
    $errorMessage = $e->getMessage();
    
    if (strpos($errorMessage, 'Data klasörü oluşturulamadı') !== false) {
        return '📁 Dosya sistemi hatası:
• Web sunucusunun data/ klasörü oluşturma izni yok
• Klasör izinlerini kontrol edin (755 önerilir)
• Disk alanı yeterli olduğundan emin olun';
    }
    
    return '⚠️ Sistem hatası: ' . $errorMessage;
}

// Create database tables
function createDatabaseTables($pdo, $dbType) {
    $tables = [
        'departments' => "
            CREATE TABLE departments (
                id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                name VARCHAR(255) NOT NULL,
                weekly_limit INTEGER DEFAULT 10,
                created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
            )
        ",
        'users' => "
            CREATE TABLE users (
                id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                name VARCHAR(255) NOT NULL,
                surname VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                department_id INTEGER,
                role " . ($dbType === 'mysql' ? "ENUM('admin', 'user') DEFAULT 'user'" : "VARCHAR(10) DEFAULT 'user' CHECK (role IN ('admin', 'user'))") . ",
                status " . ($dbType === 'mysql' ? "ENUM('active', 'inactive') DEFAULT 'active'" : "VARCHAR(10) DEFAULT 'active' CHECK (status IN ('active', 'inactive'))") . ",
                theme VARCHAR(50) DEFAULT 'light',
                created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
                " . ($dbType === 'mysql' ? ', FOREIGN KEY (department_id) REFERENCES departments(id)' : '') . "
            )
        ",
        'zoom_accounts' => "
            CREATE TABLE zoom_accounts (
                id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                api_key VARCHAR(255) NOT NULL,
                api_secret VARCHAR(255) NOT NULL,
                account_id VARCHAR(255) NOT NULL,
                account_type " . ($dbType === 'mysql' ? "ENUM('basic', 'pro', 'business') DEFAULT 'basic'" : "VARCHAR(20) DEFAULT 'basic' CHECK (account_type IN ('basic', 'pro', 'business'))") . ",
                max_concurrent_meetings INTEGER DEFAULT 1,
                status " . ($dbType === 'mysql' ? "ENUM('active', 'inactive') DEFAULT 'active'" : "VARCHAR(10) DEFAULT 'active' CHECK (status IN ('active', 'inactive'))") . ",
                last_test_at " . ($dbType === 'mysql' ? 'DATETIME NULL' : 'DATETIME NULL') . ",
                client_id VARCHAR(255) DEFAULT '',
                client_secret VARCHAR(255) DEFAULT '',
                webhook_secret VARCHAR(255) DEFAULT '',
                webhook_verification VARCHAR(255) DEFAULT '',
                api_status " . ($dbType === 'mysql' ? "ENUM('active', 'inactive', 'error') DEFAULT 'inactive'" : "VARCHAR(10) DEFAULT 'inactive' CHECK (api_status IN ('active', 'inactive', 'error'))") . ",
                created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
            )
        ",
        'meetings' => "
            CREATE TABLE meetings (
                id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                title VARCHAR(255) NOT NULL,
                date DATE NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                moderator VARCHAR(255) NOT NULL,
                description TEXT,
                participants_count INTEGER DEFAULT 0,
                user_id INTEGER NOT NULL,
                department_id INTEGER NOT NULL,
                status " . ($dbType === 'mysql' ? "ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending'" : "VARCHAR(10) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))") . ",
                zoom_account_id INTEGER NULL,
                meeting_link VARCHAR(500) NULL,
                meeting_id VARCHAR(255) NULL,
                meeting_password VARCHAR(255) NULL,
                rejection_reason TEXT NULL,
                approved_at " . ($dbType === 'mysql' ? 'DATETIME NULL' : 'DATETIME NULL') . ",
                approved_by INTEGER NULL,
                rejected_at " . ($dbType === 'mysql' ? 'DATETIME NULL' : 'DATETIME NULL') . ",
                rejected_by INTEGER NULL,
                cancelled_at " . ($dbType === 'mysql' ? 'DATETIME NULL' : 'DATETIME NULL') . ",
                cancelled_by INTEGER NULL,
                cancel_reason TEXT NULL,
                created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
                " . ($dbType === 'mysql' ? '
                , FOREIGN KEY (user_id) REFERENCES users(id)
                , FOREIGN KEY (department_id) REFERENCES departments(id)
                , FOREIGN KEY (zoom_account_id) REFERENCES zoom_accounts(id)
                , FOREIGN KEY (approved_by) REFERENCES users(id)
                , FOREIGN KEY (rejected_by) REFERENCES users(id)
                , FOREIGN KEY (cancelled_by) REFERENCES users(id)' : '') . "
            )
        ",
        'settings' => "
            CREATE TABLE settings (
                id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                setting_key VARCHAR(255) UNIQUE NOT NULL,
                setting_value TEXT,
                created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
            )
        ",
        'notifications' => "
            CREATE TABLE notifications (
                id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                user_id INTEGER NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(50) DEFAULT 'info',
                is_read BOOLEAN DEFAULT FALSE,
                created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
                " . ($dbType === 'mysql' ? ', FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE' : '') . "
            )
        ",
        'activity_logs' => "
            CREATE TABLE activity_logs (
                id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                user_id INTEGER NOT NULL,
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INTEGER NULL,
                details TEXT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
                " . ($dbType === 'mysql' ? ', FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE' : '') . "
            )
        ",
        'invitation_links' => "
            CREATE TABLE invitation_links (
                id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                token VARCHAR(255) UNIQUE NOT NULL,
                email VARCHAR(255) NULL,
                department_id INTEGER NOT NULL,
                created_by INTEGER NOT NULL,
                expires_at " . ($dbType === 'mysql' ? 'TIMESTAMP NOT NULL' : 'DATETIME NOT NULL') . ",
                used_at " . ($dbType === 'mysql' ? 'TIMESTAMP NULL' : 'DATETIME NULL') . ",
                status " . ($dbType === 'mysql' ? "ENUM('active', 'used', 'expired') DEFAULT 'active'" : "VARCHAR(10) DEFAULT 'active' CHECK (status IN ('active', 'used', 'expired'))") . ",
                invitation_data TEXT NULL,
                created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
                " . ($dbType === 'mysql' ? '
                , INDEX idx_token (token)
                , INDEX idx_expires_at (expires_at)
                , INDEX idx_status (status)
                , FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
                , FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE' : '') . "
            )
        "
    ];
    
    try {
        foreach ($tables as $tableName => $sql) {
            $pdo->exec($sql);
        }
        return true;
    } catch (PDOException $e) {
        throw new Exception("Tablo oluşturma hatası ($tableName): " . $e->getMessage());
    }
}

// Create configuration files
function createConfigFiles($dbConfig, $systemConfig) {
    $configDir = '../config';
    
    // Debug bilgisi
    error_log("Config klasörü oluşturuluyor: " . $configDir);
    error_log("Config klasörü mevcut durumu: " . (file_exists($configDir) ? 'VAR' : 'YOK'));
    
    if (!file_exists($configDir)) {
        if (!mkdir($configDir, 0755, true)) {
            error_log("Config klasörü oluşturulamadı: " . $configDir);
            error_log("Mevcut working directory: " . getcwd());
            error_log("Parent directory writable: " . (is_writable('../') ? 'EVET' : 'HAYIR'));
            throw new Exception('Config klasörü oluşturulamadı: ' . $configDir);
        }
        error_log("Config klasörü başarıyla oluşturuldu: " . $configDir);
    }
    
    // Generate security keys
    $jwtSecret = bin2hex(random_bytes(32)) . md5($configDir);
    $appKey = bin2hex(random_bytes(16)) . md5($systemConfig['site_title'] . date('Y'));
    
    // Skip .env file creation - we'll use direct constants instead
    
    // Create database config with direct constants (no env file)
    $dbConfigContent = "<?php\n";
    $dbConfigContent .= "/**\n * Veritabanı Yapılandırması\n */\n\n";
    $dbConfigContent .= "// Veritabanı yapılandırması (direkt tanımlanmış)\n";
    $dbConfigContent .= "define('DB_TYPE', '" . $dbConfig['type'] . "');\n";
    
    if ($dbConfig['type'] === 'mysql') {
        $dbConfigContent .= "define('DB_HOST', '" . $dbConfig['host'] . "');\n";
        $dbConfigContent .= "define('DB_PORT', '" . $dbConfig['port'] . "');\n";
        $dbConfigContent .= "define('DB_NAME', '" . $dbConfig['database'] . "');\n";
        $dbConfigContent .= "define('DB_USERNAME', '" . $dbConfig['username'] . "');\n";
        $dbConfigContent .= "define('DB_PASSWORD', '" . addslashes($dbConfig['password']) . "');\n";
        $dbConfigContent .= "define('DB_CHARSET', 'utf8mb4');\n\n";
        $dbConfigContent .= "// DSN oluştur\n";
        $dbConfigContent .= "\$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;\n";
        $dbConfigContent .= "\$username = DB_USERNAME;\n";
        $dbConfigContent .= "\$password = DB_PASSWORD;\n";
    } else {
        $dbConfigContent .= "define('DB_FILE', '" . $dbConfig['file'] . "');\n";
        $dbConfigContent .= "define('DB_CHARSET', 'utf8mb4');\n\n";
        $dbConfigContent .= "// DSN oluştur\n";
        $dbConfigContent .= "\$dsn = 'sqlite:' . __DIR__ . '/../data/' . DB_FILE;\n";
        $dbConfigContent .= "\$username = null;\n";
        $dbConfigContent .= "\$password = null;\n";
    }
    
    $dbConfigContent .= "\n// PDO seçenekleri\n";
    $dbConfigContent .= "\$options = [\n";
    $dbConfigContent .= "    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n";
    $dbConfigContent .= "    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n";
    $dbConfigContent .= "    PDO::ATTR_EMULATE_PREPARES => false";
    
    if ($dbConfig['type'] === 'mysql') {
        $dbConfigContent .= ",\n    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . DB_CHARSET . ' COLLATE ' . DB_CHARSET . '_unicode_ci'";
    }
    
    $dbConfigContent .= "\n];\n\n";
    $dbConfigContent .= "// Veritabanı bağlantısı\n";
    $dbConfigContent .= "try {\n";
    $dbConfigContent .= "    \$pdo = new PDO(\$dsn, \$username, \$password, \$options);\n";
    $dbConfigContent .= "    \n";
    $dbConfigContent .= "    // Bağlantı başarılı mesajı (geliştirme için)\n";
    $dbConfigContent .= "    // error_log(\"Veritabanı bağlantısı başarılı: \" . DB_NAME);\n";
    $dbConfigContent .= "    \n";
    $dbConfigContent .= "} catch (PDOException \$e) {\n";
    $dbConfigContent .= "    // Hata logu\n";
    $dbConfigContent .= "    error_log(\"Veritabanı bağlantı hatası: \" . \$e->getMessage());\n";
    $dbConfigContent .= "    \n";
    $dbConfigContent .= "    // Kullanıcıya genel hata mesajı\n";
    $dbConfigContent .= "    die('Veritabanı bağlantı hatası oluştu. Lütfen sistem yöneticisi ile iletişime geçin.');\n";
    $dbConfigContent .= "}\n";
    
    error_log("Database config dosyası oluşturuluyor: ../config/database.php");
    error_log("Database config içeriği uzunluğu: " . strlen($dbConfigContent));
    
    $result = file_put_contents('../config/database.php', $dbConfigContent);
    if (!$result) {
        error_log("Database config dosyası oluşturulamadı! file_put_contents result: " . var_export($result, true));
        error_log("Config dizini yazılabilir mi: " . (is_writable('../config') ? 'EVET' : 'HAYIR'));
        error_log("Config dizini mevcut mu: " . (file_exists('../config') ? 'EVET' : 'HAYIR'));
        throw new Exception('Database config dosyası oluşturulamadı');
    }
    error_log("Database config dosyası başarıyla oluşturuldu. Yazılan byte: " . $result);
    
    // Create main config with direct constants (no env file)
    $mainConfigContent = "<?php\n";
    $mainConfigContent .= "/**\n * Ana Yapılandırma Dosyası\n */\n\n";
    $mainConfigContent .= "// Hata raporlama\n";
    $mainConfigContent .= "error_reporting(E_ALL);\n";
    $mainConfigContent .= "ini_set('display_errors', 0);\n\n";
    $mainConfigContent .= "// Zaman dilimi\n";
    $mainConfigContent .= "date_default_timezone_set('" . $systemConfig['timezone'] . "');\n\n";
    $mainConfigContent .= "// Session ayarları\n";
    $mainConfigContent .= "ini_set('session.cookie_httponly', 1);\n";
    $mainConfigContent .= "ini_set('session.use_only_cookies', 1);\n";
    $mainConfigContent .= "ini_set('session.cookie_secure', 0);\n\n";
    $mainConfigContent .= "// Güvenlik başlıkları\n";
    $mainConfigContent .= "header('X-Content-Type-Options: nosniff');\n";
    $mainConfigContent .= "header('X-Frame-Options: DENY');\n";
    $mainConfigContent .= "header('X-XSS-Protection: 1; mode=block');\n\n";
    $mainConfigContent .= "// Veritabanı bağlantısını dahil et\n";
    $mainConfigContent .= "require_once __DIR__ . '/database.php';\n\n";
    $mainConfigContent .= "// Uygulama sabitleri (direkt tanımlanmış)\n";
    $mainConfigContent .= "define('APP_NAME', '" . addslashes($systemConfig['site_title']) . "');\n";
    $mainConfigContent .= "define('APP_TIMEZONE', '" . $systemConfig['timezone'] . "');\n";
    $mainConfigContent .= "define('WORK_START', '" . $systemConfig['work_start'] . "');\n";
    $mainConfigContent .= "define('WORK_END', '" . $systemConfig['work_end'] . "');\n";
    $mainConfigContent .= "define('JWT_SECRET', '" . $jwtSecret . "');\n";
    $mainConfigContent .= "define('APP_KEY', '" . $appKey . "');\n\n";
    
    // Database constants backup (in case database.php is not loaded)
    if ($dbConfig['type'] === 'mysql') {
        $mainConfigContent .= "\n// Veritabanı ayarları (eğer database.php'de tanımlı değilse)\n";
        $mainConfigContent .= "if (!defined('DB_HOST')) {\n";
        $mainConfigContent .= "    define('DB_HOST', '" . $dbConfig['host'] . "');\n";
        $mainConfigContent .= "    define('DB_NAME', '" . $dbConfig['database'] . "');\n";
        $mainConfigContent .= "    define('DB_USER', '" . $dbConfig['username'] . "');\n";
        $mainConfigContent .= "    define('DB_PASS', '" . addslashes($dbConfig['password']) . "');\n";
        $mainConfigContent .= "    define('DB_CHARSET', 'utf8mb4');\n";
        $mainConfigContent .= "}\n\n";
    }
    
    $mainConfigContent .= "// Email ayarları\n";
    $mainConfigContent .= "define('SMTP_HOST', 'smtp.gmail.com');\n";
    $mainConfigContent .= "define('SMTP_PORT', 587);\n";
    $mainConfigContent .= "define('SMTP_USERNAME', 'your-email@gmail.com');\n";
    $mainConfigContent .= "define('SMTP_PASSWORD', 'your-email-password');\n";
    $mainConfigContent .= "define('SMTP_ENCRYPTION', 'tls');\n";
    $mainConfigContent .= "define('FROM_EMAIL', 'your-email@gmail.com');\n";
    $mainConfigContent .= "define('FROM_NAME', APP_NAME);\n\n";
    $mainConfigContent .= "// Yardımcı fonksiyonları dahil et\n";
    $mainConfigContent .= "require_once __DIR__ . '/../includes/functions.php';\n";
    
    error_log("Main config dosyası oluşturuluyor: ../config/config.php");
    error_log("Main config içeriği uzunluğu: " . strlen($mainConfigContent));
    
    $result = file_put_contents('../config/config.php', $mainConfigContent);
    if (!$result) {
        error_log("Main config dosyası oluşturulamadı! file_put_contents result: " . var_export($result, true));
        error_log("Config dizini yazılabilir mi: " . (is_writable('../config') ? 'EVET' : 'HAYIR'));
        error_log("Config dizini mevcut mu: " . (file_exists('../config') ? 'EVET' : 'HAYIR'));
        throw new Exception('Main config dosyası oluşturulamadı');
    }
    error_log("Main config dosyası başarıyla oluşturuldu. Yazılan byte: " . $result);
}

// No longer needed - we use direct constants instead of env files

// Create admin user
function createAdminUser($pdo, $adminData) {
    try {
        // First create default department
        $stmt = $pdo->prepare("INSERT INTO departments (name, weekly_limit) VALUES (?, ?)");
        $stmt->execute(['Yönetim', 50]);
        $departmentId = $pdo->lastInsertId();
        
        // Create admin user
        $hashedPassword = password_hash($adminData['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, surname, email, password, department_id, role) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $adminData['name'],
            $adminData['surname'],
            $adminData['email'],
            $hashedPassword,
            $departmentId,
            'admin'
        ]);
        
        return true;
    } catch (PDOException $e) {
        throw new Exception("Admin kullanıcı oluşturma hatası: " . $e->getMessage());
    }
}

// Insert default settings
function insertDefaultSettings($pdo, $systemConfig) {
    $settings = [
        'site_title' => $systemConfig['site_title'],
        'work_start' => $systemConfig['work_start'],
        'work_end' => $systemConfig['work_end'],
        'timezone' => $systemConfig['timezone'],
        'email_notifications' => '1',
        'maintenance_mode' => '0',
        'default_theme' => 'light'
    ];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        return true;
    } catch (PDOException $e) {
        throw new Exception("Varsayılan ayarlar oluşturma hatası: " . $e->getMessage());
    }
}

// Insert sample data
function insertSampleData($pdo) {
    try {
        // Sample departments
        $departments = [
            ['İnsan Kaynakları', 15],
            ['Bilgi İşlem', 20],
            ['Satış ve Pazarlama', 25],
            ['Muhasebe', 10]
        ];
        
        $stmt = $pdo->prepare("INSERT INTO departments (name, weekly_limit) VALUES (?, ?)");
        foreach ($departments as $dept) {
            $stmt->execute($dept);
        }
        
        // Sample users - bazıları aktif bazıları pasif
        $users = [
            ['John', 'Doe', 'john@company.com', 'password123', 2, 'user', 'active'],
            ['Jane', 'Smith', 'jane@company.com', 'password123', 3, 'user', 'inactive'],
            ['Bob', 'Johnson', 'bob@company.com', 'password123', 4, 'user', 'active'],
            ['Sarah', 'Wilson', 'sarah@company.com', 'password123', 2, 'user', 'inactive'],
            ['Mike', 'Davis', 'mike@company.com', 'password123', 3, 'user', 'active']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO users (name, surname, email, password, department_id, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($users as $user) {
            $user[3] = password_hash($user[3], PASSWORD_DEFAULT);
            $stmt->execute($user);
        }
        
        // Sample zoom accounts with OAuth structure
        $zoomAccounts = [
            ['Zoom Hesabı 1', 'zoom1@company.com', 'client_id_demo_1', 'client_secret_demo_1', 'VpV8nqkuTW-O2TM9vZVsxg', 'pro', 2, 'active', 'client_id_demo_1', 'client_secret_demo_1', 'webhook_secret_demo_1', 'webhook_verification_demo_1'],
            ['Zoom Hesabı 2', 'zoom2@company.com', 'client_id_demo_2', 'client_secret_demo_2', 'account_id_demo_2', 'business', 5, 'active', 'client_id_demo_2', 'client_secret_demo_2', 'webhook_secret_demo_2', 'webhook_verification_demo_2'],
            ['Zoom Hesabı 3', 'zoom3@company.com', 'client_id_demo_3', 'client_secret_demo_3', 'account_id_demo_3', 'basic', 1, 'inactive', 'client_id_demo_3', 'client_secret_demo_3', '', '']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO zoom_accounts (name, email, api_key, api_secret, account_id, account_type, max_concurrent_meetings, status, client_id, client_secret, webhook_secret, webhook_verification) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($zoomAccounts as $account) {
            $stmt->execute($account);
        }
        
        // Sample meetings - çeşitli durumlar
        $meetings = [
            // Bekleyen toplantılar
            ['Aylık Performans Değerlendirmesi', '2025-01-15', '09:00:00', '10:30:00', 'Ahmet Yılmaz', 'Aylık departman performans toplantısı', 8, 2, 2, 'pending'],
            ['Yeni Proje Sunumu', '2025-01-16', '14:00:00', '15:30:00', 'Ayşe Kaya', 'Q1 projelerinin sunumu ve değerlendirmesi', 12, 3, 3, 'pending'],
            ['Müşteri Görüşmesi', '2025-01-17', '11:00:00', '12:00:00', 'Mehmet Demir', 'Önemli müşteri ile strateji toplantısı', 6, 4, 4, 'pending'],
            
            // Onaylanmış toplantılar
            ['Haftalık Standup', '2025-01-20', '10:00:00', '10:30:00', 'Ali Veli', 'Haftalık takım durumu toplantısı', 5, 5, 3, 'approved', 1, 'https://zoom.us/j/123456789?pwd=abc123', '123456789'],
            ['Eğitim Semineri', '2025-01-22', '13:00:00', '16:00:00', 'Fatma Özkan', 'Yeni sistem eğitimi', 20, 4, 2, 'approved', 2, 'https://zoom.us/j/987654321?pwd=xyz456', '987654321'],
            ['Bütçe Planlama', '2025-01-25', '15:00:00', '17:00:00', 'Ahmet Yılmaz', '2025 yıl sonu bütçe değerlendirmesi', 8, 2, 2, 'approved', 1, 'https://zoom.us/j/456789123?pwd=def789', '456789123'],
            
            // Reddedilen toplantılar
            ['Sosyal Etkinlik', '2025-01-30', '18:00:00', '20:00:00', 'Ayşe Kaya', 'Takım building etkinliği', 15, 3, 3, 'rejected'],
            
            // İptal edilen toplantılar
            ['Acil Toplantı', '2025-01-12', '16:00:00', '17:00:00', 'Mehmet Demir', 'Acil durum değerlendirmesi', 10, 4, 4, 'cancelled'],
            
            // Bugünkü ve yaklaşan toplantılar
            ['Günlük Scrum', date('Y-m-d'), '09:30:00', '10:00:00', 'Ali Veli', 'Günlük takım toplantısı', 6, 5, 3, 'approved', 2, 'https://zoom.us/j/111222333?pwd=scrum123', '111222333'],
            ['Müşteri Sunumu', date('Y-m-d', strtotime('+1 day')), '14:00:00', '15:30:00', 'Fatma Özkan', 'Ürün demo sunumu', 8, 4, 2, 'pending'],
            ['Stratejik Planlama', date('Y-m-d', strtotime('+3 days')), '10:00:00', '12:00:00', 'Ahmet Yılmaz', 'Q2 stratejik hedefler', 12, 2, 2, 'approved', 1, 'https://zoom.us/j/444555666?pwd=strat456', '444555666'],
            ['Teknik Review', date('Y-m-d', strtotime('+5 days')), '11:00:00', '12:30:00', 'Ayşe Kaya', 'Teknik dokümantasyon incelemesi', 7, 3, 3, 'pending']
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO meetings (
                title, date, start_time, end_time, moderator, description,
                participants_count, user_id, department_id, status,
                zoom_account_id, meeting_link, meeting_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($meetings as $meeting) {
            // Eksik alanları null ile doldur
            while (count($meeting) < 13) {
                $meeting[] = null;
            }
            $stmt->execute($meeting);
            
            // Onaylanmış toplantılar için approval bilgilerini güncelle
            if ($meeting[9] === 'approved') {
                $meetingId = $pdo->lastInsertId();
                $updateStmt = $pdo->prepare("
                    UPDATE meetings
                    SET approved_at = NOW(), approved_by = 1
                    WHERE id = ?
                ");
                $updateStmt->execute([$meetingId]);
            }
            
            // Reddedilen toplantılar için rejection bilgilerini güncelle
            if ($meeting[9] === 'rejected') {
                $meetingId = $pdo->lastInsertId();
                $updateStmt = $pdo->prepare("
                    UPDATE meetings
                    SET rejected_at = NOW(), rejected_by = 1, rejection_reason = 'Kapasite yetersizliği nedeniyle reddedildi'
                    WHERE id = ?
                ");
                $updateStmt->execute([$meetingId]);
            }
            
            // İptal edilen toplantılar için cancellation bilgilerini güncelle
            if ($meeting[9] === 'cancelled') {
                $meetingId = $pdo->lastInsertId();
                $updateStmt = $pdo->prepare("
                    UPDATE meetings
                    SET cancelled_at = NOW(), cancelled_by = 1, cancel_reason = 'Ani değişiklik nedeniyle iptal edildi'
                    WHERE id = ?
                ");
                $updateStmt->execute([$meetingId]);
            }
        }
        
        return true;
    } catch (PDOException $e) {
        throw new Exception("Örnek veri ekleme hatası: " . $e->getMessage());
    }
}

// Run database migrations for existing installations
function runDatabaseMigrations($pdo, $dbType) {
    $migrationLog = [];
    
    try {
        $migrationLog[] = "Veritabani migrationlari kontrol ediliyor...";
        
        // 1. Check and add missing columns to meetings table
        $stmt = $pdo->query("DESCRIBE meetings");
        $existingColumns = array_column($stmt->fetchAll(), 'Field');
        
        $meetingColumns = [
            'approved_at' => 'DATETIME NULL',
            'approved_by' => 'INTEGER NULL',
            'rejected_at' => 'DATETIME NULL',
            'rejected_by' => 'INTEGER NULL',
            'cancelled_at' => 'DATETIME NULL',
            'cancelled_by' => 'INTEGER NULL',
            'cancel_reason' => 'TEXT NULL'
        ];
        
        foreach ($meetingColumns as $column => $definition) {
            if (!in_array($column, $existingColumns)) {
                $sql = "ALTER TABLE meetings ADD COLUMN $column $definition";
                $pdo->exec($sql);
                $migrationLog[] = "meetings.$column eklendi";
            }
        }
        
        // 2. Check and add missing columns to zoom_accounts table
        try {
            $stmt = $pdo->query("DESCRIBE zoom_accounts");
            $existingZoomColumns = array_column($stmt->fetchAll(), 'Field');
            
            $zoomColumns = [
                'name' => 'VARCHAR(255) NOT NULL DEFAULT "Zoom Hesabı"',
                'email' => 'VARCHAR(255) NOT NULL',
                'account_id' => 'VARCHAR(255) NOT NULL DEFAULT ""',
                'account_type' => ($dbType === 'mysql' ? "ENUM('basic', 'pro', 'business') DEFAULT 'basic'" : "VARCHAR(20) DEFAULT 'basic'"),
                'max_concurrent_meetings' => 'INTEGER DEFAULT 1',
                'last_test_at' => 'DATETIME NULL'
            ];
            
            foreach ($zoomColumns as $column => $definition) {
                if (!in_array($column, $existingZoomColumns)) {
                    if ($column === 'name') {
                        $pdo->exec("ALTER TABLE zoom_accounts ADD COLUMN $column $definition FIRST");
                    } elseif ($column === 'email') {
                        $pdo->exec("ALTER TABLE zoom_accounts ADD COLUMN $column $definition AFTER name");
                    } elseif ($column === 'account_id') {
                        $pdo->exec("ALTER TABLE zoom_accounts ADD COLUMN $column $definition AFTER api_secret");
                    } else {
                        $pdo->exec("ALTER TABLE zoom_accounts ADD COLUMN $column $definition");
                    }
                    $migrationLog[] = "zoom_accounts.$column eklendi";
                }
            }
            
            // Mevcut verilerde name alanı boşsa güncelle
            $stmt = $pdo->query("SELECT COUNT(*) FROM zoom_accounts WHERE name = '' OR name IS NULL");
            $emptyNameCount = $stmt->fetchColumn();
            if ($emptyNameCount > 0) {
                $pdo->exec("UPDATE zoom_accounts SET name = CONCAT('Zoom Hesabı - ', email) WHERE name = '' OR name IS NULL");
                $migrationLog[] = "Boş name alanları güncellendi ($emptyNameCount adet)";
            }
            
        } catch (Exception $e) {
            $migrationLog[] = "Zoom accounts tablosu kontrol hatasi: " . $e->getMessage();
            
            // Eğer tablo yoksa oluştur
            try {
                $createZoomAccountsSQL = "
                    CREATE TABLE zoom_accounts (
                        id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                        name VARCHAR(255) NOT NULL DEFAULT 'Zoom Hesabı',
                        email VARCHAR(255) NOT NULL,
                        api_key VARCHAR(255) NOT NULL,
                        api_secret VARCHAR(255) NOT NULL,
                        account_id VARCHAR(255) NOT NULL DEFAULT '',
                        account_type " . ($dbType === 'mysql' ? "ENUM('basic', 'pro', 'business') DEFAULT 'basic'" : "VARCHAR(20) DEFAULT 'basic'") . ",
                        max_concurrent_meetings INTEGER DEFAULT 1,
                        status " . ($dbType === 'mysql' ? "ENUM('active', 'inactive') DEFAULT 'active'" : "VARCHAR(10) DEFAULT 'active'") . ",
                        last_test_at DATETIME NULL,
                        created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
                    )
                ";
                $pdo->exec($createZoomAccountsSQL);
                $migrationLog[] = "zoom_accounts tablosu oluşturuldu";
            } catch (Exception $createErr) {
                $migrationLog[] = "zoom_accounts tablosu oluşturulamadı: " . $createErr->getMessage();
            }
        }
        
        // 3. Check and add status column to users table
        $stmt = $pdo->query("DESCRIBE users");
        $existingUserColumns = array_column($stmt->fetchAll(), 'Field');
        
        if (!in_array('status', $existingUserColumns)) {
            $statusDef = $dbType === 'mysql' ?
                "ENUM('active', 'inactive') DEFAULT 'active'" :
                "VARCHAR(10) DEFAULT 'active' CHECK (status IN ('active', 'inactive'))";
            $pdo->exec("ALTER TABLE users ADD COLUMN status $statusDef");
            $migrationLog[] = "users.status eklendi";
        }
        
        // 4. Add foreign key constraints for MySQL
        if ($dbType === 'mysql') {
            try {
                $stmt = $pdo->query("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_NAME = 'meetings'
                    AND TABLE_SCHEMA = DATABASE()
                    AND CONSTRAINT_NAME LIKE 'fk_meetings_%'
                ");
                $existingFKs = array_column($stmt->fetchAll(), 'CONSTRAINT_NAME');
                
                $foreignKeys = [
                    'fk_meetings_approved_by' => 'ADD CONSTRAINT fk_meetings_approved_by FOREIGN KEY (approved_by) REFERENCES users(id)',
                    'fk_meetings_rejected_by' => 'ADD CONSTRAINT fk_meetings_rejected_by FOREIGN KEY (rejected_by) REFERENCES users(id)',
                    'fk_meetings_cancelled_by' => 'ADD CONSTRAINT fk_meetings_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id)'
                ];
                
                foreach ($foreignKeys as $fkName => $fkSql) {
                    if (!in_array($fkName, $existingFKs)) {
                        try {
                            $pdo->exec("ALTER TABLE meetings $fkSql");
                            $migrationLog[] = "$fkName foreign key eklendi";
                        } catch (Exception $e) {
                            $migrationLog[] = "$fkName foreign key eklenemedi (zaten var olabilir)";
                        }
                    }
                }
            } catch (Exception $e) {
                $migrationLog[] = "Foreign key kontrolu atlandi";
            }
        }
        
        // 5. Check and create activity_logs table if missing
        try {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='activity_logs'");
            $tableExists = $stmt->fetch();
            
            if (!$tableExists) {
                // MySQL için farklı kontrol
                if ($dbType === 'mysql') {
                    $stmt = $pdo->query("SHOW TABLES LIKE 'activity_logs'");
                    $tableExists = $stmt->fetch();
                }
                
                if (!$tableExists) {
                    $activityLogsSQL = "
                        CREATE TABLE activity_logs (
                            id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                            user_id INTEGER NOT NULL,
                            action VARCHAR(100) NOT NULL,
                            entity_type VARCHAR(50) NOT NULL,
                            entity_id INTEGER NULL,
                            details TEXT NULL,
                            ip_address VARCHAR(45) NULL,
                            user_agent TEXT NULL,
                            created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
                            " . ($dbType === 'mysql' ? ', FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE' : '') . "
                        )
                    ";
                    $pdo->exec($activityLogsSQL);
                    $migrationLog[] = "activity_logs tablosu oluşturuldu";
                }
            } else {
                $migrationLog[] = "activity_logs tablosu zaten mevcut";
            }
        } catch (Exception $e) {
            $migrationLog[] = "activity_logs tablosu kontrolu hatasi: " . $e->getMessage();
        }
        
        // 6. Zoom API entegrasyonu için gerekli alanları ekle
        try {
            // zoom_accounts tablosuna Zoom API entegrasyon alanları ekle
            $stmt = $pdo->query("DESCRIBE zoom_accounts");
            $existingZoomColumns = array_column($stmt->fetchAll(), 'Field');
            
            $zoomApiColumns = [
                'client_id' => 'VARCHAR(255) DEFAULT ""',
                'client_secret' => 'VARCHAR(255) DEFAULT ""',
                'webhook_secret' => 'VARCHAR(255) DEFAULT ""',
                'webhook_verification' => 'VARCHAR(255) DEFAULT ""',
                'encrypted_api_key' => 'TEXT NULL',
                'encrypted_api_secret' => 'TEXT NULL',
                'encryption_iv' => 'VARCHAR(32) NULL',
                'api_status' => ($dbType === 'mysql' ? "ENUM('active', 'inactive', 'error') DEFAULT 'inactive'" : "VARCHAR(10) DEFAULT 'inactive' CHECK (api_status IN ('active', 'inactive', 'error'))"),
                'api_last_test' => 'DATETIME NULL',
                'api_error_count' => 'INTEGER DEFAULT 0',
                'api_last_error' => 'TEXT NULL',
                'webhook_url' => 'VARCHAR(500) NULL',
                'rate_limit_remaining' => 'INTEGER DEFAULT 100',
                'rate_limit_reset' => ($dbType === 'mysql' ? 'TIMESTAMP NULL' : 'DATETIME NULL')
            ];
            
            foreach ($zoomApiColumns as $column => $definition) {
                if (!in_array($column, $existingZoomColumns)) {
                    $pdo->exec("ALTER TABLE zoom_accounts ADD COLUMN $column $definition");
                    $migrationLog[] = "zoom_accounts.$column eklendi (Zoom API)";
                }
            }
            
            // meetings tablosuna Zoom API entegrasyon alanları ekle
            $stmt = $pdo->query("DESCRIBE meetings");
            $existingMeetingColumns = array_column($stmt->fetchAll(), 'Field');
            
            $meetingApiColumns = [
                'zoom_meeting_id' => 'VARCHAR(100) NULL',
                'zoom_uuid' => 'VARCHAR(100) NULL',
                'zoom_join_url' => 'TEXT NULL',
                'zoom_start_url' => 'TEXT NULL',
                'zoom_password' => 'VARCHAR(50) NULL',
                'zoom_host_id' => 'VARCHAR(100) NULL',
                'parent_meeting_id' => 'VARCHAR(100) NULL',
                'is_recurring_occurrence' => ($dbType === 'mysql' ? 'BOOLEAN DEFAULT FALSE' : 'INTEGER DEFAULT 0'),
                'occurrence_id' => 'VARCHAR(100) NULL',
                'recurrence_type' => 'VARCHAR(20) NULL',
                'api_created_at' => 'DATETIME NULL',
                'api_updated_at' => 'DATETIME NULL',
                'api_error_log' => 'TEXT NULL',
                'meeting_link_sent' => ($dbType === 'mysql' ? 'BOOLEAN DEFAULT FALSE' : 'INTEGER DEFAULT 0'),
                'reminder_sent' => ($dbType === 'mysql' ? 'BOOLEAN DEFAULT FALSE' : 'INTEGER DEFAULT 0')
            ];
            
            foreach ($meetingApiColumns as $column => $definition) {
                if (!in_array($column, $existingMeetingColumns)) {
                    $pdo->exec("ALTER TABLE meetings ADD COLUMN $column $definition");
                    $migrationLog[] = "meetings.$column eklendi (Zoom API)";
                }
            }
            
        } catch (Exception $e) {
            $migrationLog[] = "Zoom API alanları ekleme hatası: " . $e->getMessage();
        }
        
        // 7. Zoom API logs tablosunu oluştur
        try {
            $stmt = $pdo->query($dbType === 'mysql' ? "SHOW TABLES LIKE 'zoom_api_logs'" : "SELECT name FROM sqlite_master WHERE type='table' AND name='zoom_api_logs'");
            $tableExists = $stmt->fetch();
            
            if (!$tableExists) {
                $zoomApiLogsSQL = "
                    CREATE TABLE zoom_api_logs (
                        id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                        zoom_account_id INTEGER NOT NULL,
                        meeting_id INTEGER NULL,
                        action VARCHAR(50) NOT NULL,
                        endpoint VARCHAR(200) NOT NULL,
                        request_data TEXT NULL,
                        response_data TEXT NULL,
                        http_code INTEGER NULL,
                        success " . ($dbType === 'mysql' ? 'BOOLEAN DEFAULT FALSE' : 'INTEGER DEFAULT 0') . ",
                        error_message TEXT NULL,
                        execution_time DECIMAL(8,3) NULL,
                        created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
                        " . ($dbType === 'mysql' ? '
                        , INDEX idx_zoom_account_id (zoom_account_id)
                        , INDEX idx_meeting_id (meeting_id)
                        , INDEX idx_action (action)
                        , INDEX idx_created_at (created_at)
                        , FOREIGN KEY (zoom_account_id) REFERENCES zoom_accounts(id) ON DELETE CASCADE
                        , FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE SET NULL' : '') . "
                    )
                ";
                $pdo->exec($zoomApiLogsSQL);
                $migrationLog[] = "zoom_api_logs tablosu oluşturuldu";
            } else {
                $migrationLog[] = "zoom_api_logs tablosu zaten mevcut";
            }
        } catch (Exception $e) {
            $migrationLog[] = "zoom_api_logs tablosu oluşturma hatası: " . $e->getMessage();
        }

        // 8. Test verilerini sadece ilk kurulumda ekle (migration'da değil)
        // Migration işleminde test verisi eklenmez
        $migrationLog[] = "Test verisi ekleme atlandi (sadece migration yapiliyor)";
        
        // Eğer test verisi istiyorsanız, manuel olarak admin panelinden ekleyebilirsiniz
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM meetings");
            $meetingCount = $stmt->fetchColumn();
            
            if (false) { // Bu koşul hiçbir zaman true olmayacak - test verisi eklenmez
                $migrationLog[] = "Meetings tablosu bos, test verileri ekleniyor...";
                
                // Önce gerekli bağımlılıkları kontrol et
                $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'");
                $userCount = $stmt->fetchColumn();
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM departments");
                $deptCount = $stmt->fetchColumn();
                
                if ($userCount > 0 && $deptCount > 0) {
                    // Kullanıcı ve department ID'lerini al - Güvenli ID assignment
                    $stmt = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 5");
                    $userIds = array_column($stmt->fetchAll(), 'id');
                    
                    $stmt = $pdo->query("SELECT id FROM departments ORDER BY id ASC LIMIT 4");
                    $deptIds = array_column($stmt->fetchAll(), 'id');
                    
                    $migrationLog[] = "Kullanici IDs: " . implode(',', $userIds);
                    $migrationLog[] = "Department IDs: " . implode(',', $deptIds);
                    
                    if (!empty($userIds) && !empty($deptIds)) {
                        // Güvenli ID seçimi için helper function
                        $getUserId = function($index) use ($userIds) {
                            return isset($userIds[$index]) ? $userIds[$index] : $userIds[0];
                        };
                        
                        $getDeptId = function($index) use ($deptIds) {
                            return isset($deptIds[$index]) ? $deptIds[$index] : $deptIds[0];
                        };
                        
                        $sampleMeetings = [
                            // Son 7 günde yapılan toplantılar
                            ['Haftalık Değerlendirme', date('Y-m-d', strtotime('-5 days')), '09:00:00', '10:30:00', 'Takım Lideri', 'Haftalık departman performans toplantısı', 8, $getUserId(0), $getDeptId(0), 'approved'],
                            ['Proje Review', date('Y-m-d', strtotime('-4 days')), '14:00:00', '15:30:00', 'Proje Yöneticisi', 'Proje ilerleme gözden geçirmesi', 12, $getUserId(1), $getDeptId(1), 'approved'],
                            ['Müşteri Görüşmesi', date('Y-m-d', strtotime('-3 days')), '11:00:00', '12:00:00', 'Satış Temsilcisi', 'Müşteri ile strateji toplantısı', 6, $getUserId(2), $getDeptId(2), 'approved'],
                            ['Teknik Toplantı', date('Y-m-d', strtotime('-2 days')), '15:00:00', '16:00:00', 'Teknik Lead', 'Teknik altyapı tartışması', 4, $getUserId(3), $getDeptId(1), 'approved'],
                            ['İK Toplantısı', date('Y-m-d', strtotime('-1 day')), '10:00:00', '11:00:00', 'İK Müdürü', 'Personel değerlendirmesi', 5, $getUserId(4), $getDeptId(0), 'approved'],
                            
                            // Bugünkü toplantılar
                            ['Günlük Scrum', date('Y-m-d'), '09:30:00', '10:00:00', 'Scrum Master', 'Günlük takım toplantısı', 6, $getUserId(0), $getDeptId(1), 'approved'],
                            ['Yönetim Toplantısı', date('Y-m-d'), '14:00:00', '15:30:00', 'Genel Müdür', 'Üst yönetim değerlendirmesi', 3, $getUserId(1), $getDeptId(0), 'approved'],
                            
                            // Yakın gelecek - bekleyen
                            ['Müşteri Sunumu', date('Y-m-d', strtotime('+1 day')), '14:00:00', '15:30:00', 'Ürün Yöneticisi', 'Ürün demo sunumu', 8, $getUserId(2), $getDeptId(2), 'pending'],
                            ['Eğitim Semineri', date('Y-m-d', strtotime('+2 days')), '13:00:00', '16:00:00', 'Eğitim Sorumlusu', 'Yeni sistem eğitimi', 20, $getUserId(3), $getDeptId(1), 'pending'],
                            ['Stratejik Planlama', date('Y-m-d', strtotime('+3 days')), '10:00:00', '12:00:00', 'Planlama Müdürü', 'Q1 hedefleri planlaması', 15, $getUserId(4), $getDeptId(0), 'pending'],
                            
                            // Reddedilen ve iptal edilen (geçmiş)
                            ['Sosyal Etkinlik', date('Y-m-d', strtotime('-6 days')), '18:00:00', '20:00:00', 'İK Sorumlusu', 'Takım building etkinliği', 15, $getUserId(0), $getDeptId(0), 'rejected'],
                            ['Acil Toplantı', date('Y-m-d', strtotime('-7 days')), '16:00:00', '17:00:00', 'Yönetici', 'Acil durum değerlendirmesi', 10, $getUserId(1), $getDeptId(1), 'cancelled']
                        ];
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO meetings (
                                title, date, start_time, end_time, moderator, description,
                                participants_count, user_id, department_id, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        foreach ($sampleMeetings as $meeting) {
                            $stmt->execute($meeting);
                            $meetingId = $pdo->lastInsertId();
                            
                            // Onaylanmış toplantılar için ek bilgiler
                            if ($meeting[9] === 'approved') {
                                $updateStmt = $pdo->prepare("
                                    UPDATE meetings
                                    SET approved_at = NOW(), approved_by = 1,
                                        meeting_link = CONCAT('https://zoom.us/j/', ?, '?pwd=test123'),
                                        meeting_id = ?
                                    WHERE id = ?
                                ");
                                $zoomId = '123456' . str_pad($meetingId, 3, '0', STR_PAD_LEFT);
                                $updateStmt->execute([$zoomId, $zoomId, $meetingId]);
                            }
                            
                            // Reddedilen toplantılar için ek bilgiler
                            if ($meeting[9] === 'rejected') {
                                $updateStmt = $pdo->prepare("
                                    UPDATE meetings
                                    SET rejected_at = NOW(), rejected_by = 1,
                                        rejection_reason = 'Kapasite yetersizliği nedeniyle reddedildi'
                                    WHERE id = ?
                                ");
                                $updateStmt->execute([$meetingId]);
                            }
                            
                            // İptal edilen toplantılar için ek bilgiler
                            if ($meeting[9] === 'cancelled') {
                                $updateStmt = $pdo->prepare("
                                    UPDATE meetings
                                    SET cancelled_at = NOW(), cancelled_by = 1,
                                        cancel_reason = 'Ani değişiklik nedeniyle iptal edildi'
                                    WHERE id = ?
                                ");
                                $updateStmt->execute([$meetingId]);
                            }
                        }
                        
                        $migrationLog[] = "Test meeting verileri eklendi (" . count($sampleMeetings) . " adet)";
                    } else {
                        $migrationLog[] = "Test verileri icin gerekli kullanici/departman bulunamadi";
                    }
                } else {
                    $migrationLog[] = "Test verileri icin gerekli tablo verileri eksik (user: $userCount, dept: $deptCount)";
                }
            } else {
                $migrationLog[] = "Meetings tablosunda $meetingCount adet veri mevcut, test verisi eklenmedi";
            }
        } catch (Exception $e) {
            $migrationLog[] = "Test verisi ekleme hatasi: " . $e->getMessage();
        }
        
        // 9. Check and create invitation_links table if missing (Admin Kontrollü Kayıt Sistemi)
        try {
            $stmt = $pdo->query($dbType === 'mysql' ? "SHOW TABLES LIKE 'invitation_links'" : "SELECT name FROM sqlite_master WHERE type='table' AND name='invitation_links'");
            $tableExists = $stmt->fetch();
            
            if (!$tableExists) {
                $invitationLinksSQL = "
                    CREATE TABLE invitation_links (
                        id INTEGER " . ($dbType === 'mysql' ? 'AUTO_INCREMENT PRIMARY KEY' : 'PRIMARY KEY AUTOINCREMENT') . ",
                        token VARCHAR(255) UNIQUE NOT NULL,
                        email VARCHAR(255) NULL,
                        department_id INTEGER NOT NULL,
                        created_by INTEGER NOT NULL,
                        expires_at " . ($dbType === 'mysql' ? 'TIMESTAMP NOT NULL' : 'DATETIME NOT NULL') . ",
                        used_at " . ($dbType === 'mysql' ? 'TIMESTAMP NULL' : 'DATETIME NULL') . ",
                        status " . ($dbType === 'mysql' ? "ENUM('active', 'used', 'expired') DEFAULT 'active'" : "VARCHAR(10) DEFAULT 'active' CHECK (status IN ('active', 'used', 'expired'))") . ",
                        invitation_data TEXT NULL,
                        created_at " . ($dbType === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'DATETIME DEFAULT CURRENT_TIMESTAMP') . "
                        " . ($dbType === 'mysql' ? '
                        , INDEX idx_token (token)
                        , INDEX idx_expires_at (expires_at)
                        , INDEX idx_status (status)
                        , FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
                        , FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE' : '') . "
                    )
                ";
                $pdo->exec($invitationLinksSQL);
                $migrationLog[] = "invitation_links tablosu oluşturuldu (Admin Kontrollü Kayıt Sistemi)";
            } else {
                $migrationLog[] = "invitation_links tablosu zaten mevcut";
            }
        } catch (Exception $e) {
            $migrationLog[] = "invitation_links tablosu oluşturma hatası: " . $e->getMessage();
        }
        
        $migrationLog[] = "Migrationlar tamamlandi!";
        return ['success' => true, 'log' => $migrationLog];
        
    } catch (Exception $e) {
        $migrationLog[] = "Migration hatasi: " . $e->getMessage();
        return ['success' => false, 'log' => $migrationLog, 'error' => $e->getMessage()];
    }
}

// Create security files
function createSecurityFiles() {
    // Create .htaccess for install directory
    $htaccessContent = "# Kurulum tamamlandıktan sonra bu klasöre erişimi engelle\n";
    $htaccessContent .= "Order Deny,Allow\n";
    $htaccessContent .= "Deny from all\n";
    
    if (!file_put_contents('.htaccess', $htaccessContent)) {
        throw new Exception('Install .htaccess oluşturulamadı');
    }
}

// Main processing
try {
    error_log("INSTALL PROCESS STARTED - REQUEST METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNDEFINED'));
    error_log("INSTALL PROCESS - POST DATA: " . print_r($_POST, true));
    
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        error_log("INSTALL PROCESS - INVALID REQUEST METHOD");
        jsonResponse(false, 'Sadece POST istekleri kabul edilir.');
    }
    
    $action = $_POST['action'] ?? '';
    error_log("INSTALL PROCESS - ACTION: " . $action);
    
    if ($action === 'test_db') {
        $dbType = sanitizeInput($_POST['db_type'] ?? '');
        
        if ($dbType === 'mysql') {
            validateRequiredFields(['db_host', 'db_port', 'db_name', 'db_username'], $_POST);
            
            $config = [
                'type' => 'mysql',
                'host' => sanitizeInput($_POST['db_host']),
                'port' => sanitizeInput($_POST['db_port']),
                'database' => sanitizeInput($_POST['db_name']),
                'username' => sanitizeInput($_POST['db_username']),
                'password' => $_POST['db_password'] ?? '',
                'auto_create_db' => isset($_POST['auto_create_db']) && $_POST['auto_create_db'] === '1'
            ];
            
            $result = testDatabaseConnection($config);
            
            if ($result['success']) {
                jsonResponse(true, 'Veritabanı sunucusu bağlantısı başarılı!');
            } else {
                jsonResponse(false, $result['message']);
            }
        } else {
            // SQLite - test file creation and permissions
            $config = [
                'type' => 'sqlite',
                'file' => 'test_connection_' . date('Ymd_His') . '.sqlite'
            ];
            
            $result = testDatabaseConnection($config);
            
            if ($result['success']) {
                jsonResponse(true, 'SQLite veritabanı test edildi - dosya oluşturma ve yazma izinleri başarılı!');
            } else {
                jsonResponse(false, $result['message']);
            }
        }
        
    } elseif ($action === 'install') {
        error_log("INSTALL PROCESS - INSTALL ACTION STARTED");
        
        checkAlreadyInstalled();
        error_log("INSTALL PROCESS - Already installed check passed");
        
        // Validate all required fields
        $requiredFields = ['db_type', 'admin_name', 'admin_surname', 'admin_email', 'admin_password', 'site_title', 'work_start', 'work_end', 'timezone'];
        error_log("INSTALL PROCESS - Validating required fields");
        validateRequiredFields($requiredFields, $_POST);
        error_log("INSTALL PROCESS - Required fields validation passed");
        
        // Prepare configurations
        $dbType = sanitizeInput($_POST['db_type']);
        
        if ($dbType === 'mysql') {
            validateRequiredFields(['db_host', 'db_port', 'db_name', 'db_username'], $_POST);
            
            $autoCreateDb = isset($_POST['auto_create_db']) && $_POST['auto_create_db'] === '1';
            
            $dbConfig = [
                'type' => 'mysql',
                'host' => sanitizeInput($_POST['db_host']),
                'port' => sanitizeInput($_POST['db_port']),
                'database' => sanitizeInput($_POST['db_name']),
                'username' => sanitizeInput($_POST['db_username']),
                'password' => $_POST['db_password'] ?? '',
                'auto_create_db' => $autoCreateDb
            ];
        } else {
            $dbConfig = [
                'type' => 'sqlite',
                'file' => 'zoom_meetings_' . date('Ymd_His') . '.sqlite'
            ];
        }
        
        $adminData = [
            'name' => sanitizeInput($_POST['admin_name']),
            'surname' => sanitizeInput($_POST['admin_surname']),
            'email' => sanitizeInput($_POST['admin_email']),
            'password' => $_POST['admin_password']
        ];
        
        $systemConfig = [
            'site_title' => sanitizeInput($_POST['site_title']),
            'work_start' => sanitizeInput($_POST['work_start']),
            'work_end' => sanitizeInput($_POST['work_end']),
            'timezone' => sanitizeInput($_POST['timezone'])
        ];
        
        $sampleData = isset($_POST['sample_data']) && $_POST['sample_data'] === '1';
        
        // Validate admin email
        if (!filter_var($adminData['email'], FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Geçersiz e-posta adresi.');
        }
        
        // Validate admin password
        if (strlen($adminData['password']) < 6) {
            jsonResponse(false, 'Şifre en az 6 karakter olmalıdır.');
        }
        
        // Start installation
        try {
            // Create database automatically
            $dbResult = createDatabase($dbConfig);
            if (!$dbResult['success']) {
                jsonResponse(false, 'Veritabanı oluşturma hatası: ' . $dbResult['message']);
            }
            
            $pdo = $dbResult['pdo'];
            $databaseName = $dbResult['database_name'];
            
            // Create database tables
            createDatabaseTables($pdo, $dbConfig['type']);
            
            // Run migrations to ensure all fields exist
            runDatabaseMigrations($pdo, $dbConfig['type']);
            
            // Create configuration files
            createConfigFiles($dbConfig, $systemConfig);
            
            // Create admin user
            createAdminUser($pdo, $adminData);
            
            // Insert default settings
            insertDefaultSettings($pdo, $systemConfig);
            
            // Insert sample data if requested
            if ($sampleData) {
                insertSampleData($pdo);
            }
            
            // Create security files
            createSecurityFiles();
            
            jsonResponse(true, 'Kurulum başarıyla tamamlandı!', [
                'admin_email' => $adminData['email'],
                'database_name' => $databaseName
            ]);
            
        } catch (Exception $e) {
            jsonResponse(false, $e->getMessage());
        }
        
    } elseif ($action === 'migrate') {
        // Sadece migration çalıştır (mevcut kurulumlar için)
        if (!file_exists('../config/config.php')) {
            jsonResponse(false, 'Sistem henüz kurulmamış. Önce kurulum yapın.');
        }
        
        try {
            // Config dosyasını yükle
            require_once '../config/config.php';
            require_once '../config/database.php';
            
            // Veritabanı türünü belirle
            $dbType = defined('DB_TYPE') ? DB_TYPE : 'mysql';
            
            // Migration'ları çalıştır
            $result = runDatabaseMigrations($pdo, $dbType);
            
            if ($result['success']) {
                jsonResponse(true, 'Veritabani migration\'lari basariyla tamamlandi!', [
                    'log' => $result['log']
                ]);
            } else {
                jsonResponse(false, 'Migration\'lar sirasinda hata olustu: ' . ($result['error'] ?? 'Bilinmeyen hata'), [
                    'log' => $result['log']
                ]);
            }
            
        } catch (Exception $e) {
            jsonResponse(false, 'Migration hatası: ' . $e->getMessage());
        }
        
    } elseif ($action === 'reinstall') {
        // Yeniden kurulum - tüm verileri sil ve temiz başla
        $confirmCode = sanitizeInput($_POST['confirm_code'] ?? '');
        
        if ($confirmCode !== 'YENIDEN_KUR_ONAYI') {
            jsonResponse(false, 'Yeniden kurulum için onay kodu gerekli.');
        }
        
        try {
            // Mevcut kurulum varsa veritabanını temizle
            if (file_exists('../config/config.php')) {
                try {
                    require_once '../config/config.php';
                    if (file_exists('../config/database.php')) {
                        require_once '../config/database.php';
                    }
                    
                    if (defined('DB_TYPE') && DB_TYPE === 'mysql' && defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
                        // MySQL veritabanını drop et
                        try {
                            $dsn = "mysql:host=" . DB_HOST . ";port=" . (defined('DB_PORT') ? DB_PORT : 3306) . ";charset=utf8mb4";
                            $tempPdo = new PDO($dsn, DB_USER, DB_PASS, [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                            ]);
                            $dbName = DB_NAME;
                            $tempPdo->exec("DROP DATABASE IF EXISTS `$dbName`");
                            $tempPdo = null;
                        } catch (Exception $e) {
                            // Veritabanı silinemedi (devam ediliyor)
                            error_log("MySQL database drop failed: " . $e->getMessage());
                        }
                    } else {
                        // SQLite dosyasını sil
                        $dbFile = '';
                        if (defined('DB_FILE')) {
                            $dbFile = '../data/' . DB_FILE;
                        } elseif (defined('DATABASE_FILE')) {
                            $dbFile = DATABASE_FILE;
                        } else {
                            // Default locations to check
                            $possibleFiles = [
                                '../database/zoom_meetings.db',
                                '../data/zoom_meetings.sqlite',
                                '../zoom_meetings.sqlite'
                            ];
                            foreach ($possibleFiles as $file) {
                                if (file_exists($file)) {
                                    $dbFile = $file;
                                    break;
                                }
                            }
                        }
                        
                        if ($dbFile && file_exists($dbFile)) {
                            unlink($dbFile);
                        }
                    }
                } catch (Exception $e) {
                    // Config dosyası okunamadı, devam et
                }
            }
            
            // Config dosyalarını sil (check-installation.php hariç)
            $configFiles = [
                '../config/config.php',
                '../config/database.php'
            ];
            
            foreach ($configFiles as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            
            // Config klasörünü kontrol et (check-installation.php varsa bırak)
            if (file_exists('../config') && is_dir('../config')) {
                $files = scandir('../config');
                $hasOtherFiles = false;
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && $file !== 'check-installation.php' && $file !== '.htaccess') {
                        $hasOtherFiles = true;
                        break;
                    }
                }
                // Sadece check-installation.php ve .htaccess varsa klasörü silme
                if (!$hasOtherFiles && !file_exists('../config/check-installation.php')) {
                    rmdir('../config');
                }
            }
            
            // Data klasörünü sil (SQLite için)
            if (file_exists('../data') && is_dir('../data')) {
                $files = glob('../data/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                if (count(glob('../data/*')) === 0) {
                    rmdir('../data');
                }
            }
            
            // Database klasörünü sil (SQLite için)
            if (file_exists('../database') && is_dir('../database')) {
                $files = glob('../database/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                if (count(glob('../database/*')) === 0) {
                    rmdir('../database');
                }
            }
            
            // Log dosyalarını temizle
            if (file_exists('../logs') && is_dir('../logs')) {
                $logFiles = glob('../logs/*.log');
                foreach ($logFiles as $logFile) {
                    unlink($logFile);
                }
            }
            
            // Install .htaccess dosyasını sil (yeniden erişim için)
            if (file_exists('.htaccess')) {
                unlink('.htaccess');
            }
            
            jsonResponse(true, 'Sistem başarıyla sıfırlandı! Artık yeni kurulum yapabilirsiniz.', [
                'redirect' => 'index.php'
            ]);
            
        } catch (Exception $e) {
            jsonResponse(false, 'Yeniden kurulum hatası: ' . $e->getMessage());
        }
        
    } elseif ($action === 'clean_config') {
        // Clean config işlemi - sadece config dosyalarını sil
        try {
            $configFiles = [
                '../config/config.php',
                '../config/database.php'
            ];
            
            $deletedFiles = [];
            $errors = [];
            
            foreach ($configFiles as $file) {
                if (file_exists($file)) {
                    if (unlink($file)) {
                        $deletedFiles[] = $file;
                    } else {
                        $errors[] = "Dosya silinemedi: $file";
                    }
                }
            }
            
            // Config klasörünü kontrol et ve boşsa sil
            if (is_dir('../config')) {
                $files = array_diff(scandir('../config'), array('.', '..'));
                if (empty($files)) {
                    rmdir('../config');
                    $deletedFiles[] = '../config (klasör)';
                }
            }
            
            // Install .htaccess dosyasını sil (yeniden erişim için)
            if (file_exists('.htaccess')) {
                unlink('.htaccess');
                $deletedFiles[] = 'install/.htaccess';
            }
            
            if (empty($errors)) {
                // JSON response yerine HTML response
                header('Content-Type: text/html; charset=UTF-8');
                echo '<script>alert("Config dosyaları başarıyla temizlendi!"); window.location.href = "index.php";</script>';
                exit;
            } else {
                throw new Exception('Bazı dosyalar silinemedi: ' . implode(', ', $errors));
            }
            
        } catch (Exception $e) {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<script>alert("Hata: ' . addslashes($e->getMessage()) . '"); window.history.back();</script>';
            exit;
        }
    } else {
        jsonResponse(false, 'Geçersiz işlem.');
    }
    
} catch (Exception $e) {
    jsonResponse(false, 'Beklenmeyen hata: ' . $e->getMessage());
}