<?php
// Ana giriş dosyası
require_once 'config/check-installation.php';

// Kurulum kontrolü
if (!checkInstallation()) {
    header('Location: install/index.php');
    exit();
}

require_once 'config/config.php';
require_once 'config/auth.php';

// Kullanıcı kontrolü
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Dashboard'a yönlendir
header('Location: dashboard.php');
exit();