<?php
require_once 'config/config.php';
require_once 'config/auth.php';

// CSRF token kontrolü (GET isteği için basit kontrol)
if (isset($_GET['token'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'])) {
        writeLog("Invalid CSRF token for logout attempt", 'warning', 'security.log');
        redirect('dashboard.php');
    }
}

// Kullanıcı çıkış işlemi
$result = logoutUser();

// Session mesajını ayarla
session_start();
$_SESSION['logout_message'] = $result['message'];

// Admin sayfasındaysa prefix düzeltmesi ile login sayfasına yönlendir
$loginPath = 'login.php';
if (strpos($_SERVER['HTTP_REFERER'] ?? '', '/admin/') !== false) {
    $loginPath = '../login.php';
}

redirect($loginPath);