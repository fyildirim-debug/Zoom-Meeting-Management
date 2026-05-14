<?php
/**
 * Module Bootstrap
 *
 * config/config.php'nin sonunda dahil edilir. Yapısı:
 *
 *   1. Çekirdek modül sınıflarını yükle (HookEngine, ModuleManager, vs.)
 *   2. Global $moduleManager nesnesini oluştur
 *   3. Aktif modüllerin boot.php'lerini yükle (hook'lar register edilir)
 *
 * Bu dosya try/catch ile sarılır — modül sistemi hatası uygulamayı çökertmesin.
 */

if (!class_exists('HookEngine', false)) {
    require_once __DIR__ . '/HookEngine.php';
}
if (!class_exists('ModuleAPI', false)) {
    require_once __DIR__ . '/ModuleAPI.php';
}
if (!class_exists('ModuleManager', false)) {
    require_once __DIR__ . '/ModuleManager.php';
}
if (!class_exists('ModuleInstaller', false)) {
    require_once __DIR__ . '/ModuleInstaller.php';
}

// Global module manager — $pdo config/config.php'de tanımlanmış olmalı
global $pdo, $moduleManager;

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $moduleManager = new ModuleManager($pdo);
        $moduleManager->bootActive();
    } catch (Throwable $e) {
        // Loglama dene
        if (function_exists('writeLog')) {
            writeLog('Module bootstrap error: ' . $e->getMessage(), 'error');
        } else {
            error_log('Module bootstrap error: ' . $e->getMessage());
        }
    }
}
