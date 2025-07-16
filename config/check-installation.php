<?php
/**
 * Kurulum kontrolü
 */

function checkInstallation() {
    // Config dosyası var mı?
    if (!file_exists(__DIR__ . '/config.php')) {
        return false;
    }
    
    // Database config dosyası var mı?
    if (!file_exists(__DIR__ . '/database.php')) {
        return false;
    }
    
    // .env dosyası artık kullanılmıyor - direct constants kullanılıyor
    // Bu kontrol kaldırıldı
    
    // Install klasörü hala var mı? (güvenlik)
    if (file_exists(dirname(__DIR__) . '/install') && !file_exists(dirname(__DIR__) . '/install/.htaccess')) {
        return false;
    }
    
    return true;
}

function redirectToInstall() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['REQUEST_URI']);
    $installUrl = $protocol . '://' . $host . $path . '/install/';
    header('Location: ' . $installUrl);
    exit();
}

function getInstallationStatus() {
    $status = [
        'config_exists' => file_exists(__DIR__ . '/config.php'),
        'database_exists' => file_exists(__DIR__ . '/database.php'),
        'env_exists' => true, // .env dosyası artık kullanılmıyor - direct constants kullanılıyor
        'install_secure' => !file_exists(dirname(__DIR__) . '/install') || file_exists(dirname(__DIR__) . '/install/.htaccess')
    ];
    
    $status['is_installed'] = $status['config_exists'] && $status['database_exists'] && $status['install_secure'];
    
    return $status;
}