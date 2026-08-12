<?php
// logout.php

// Session başlatıcıyı ve gerekli dosyaları çağır
require_once __DIR__ . "/../app/includes/session.php";

if (isset($_SESSION['user_id'])) {
    try {
        // Veritabanı bağlantısı gerekli olabilir (loglama vs. için)
        if (!function_exists('db') && file_exists(__DIR__ . "/../app/config/db.php")) {
            require_once __DIR__ . "/../app/config/db.php";
        }

        $pdo = function_exists('db') ? db() : null;
        $user_id = $_SESSION['user_id'];

        if ($pdo) {
            // ONLINE DURUMUNU SIFIRLA
            $stmt = $pdo->prepare("UPDATE users SET is_online = 0 WHERE id = ?");
            $stmt->execute([$user_id]);

            // --- LOGLAMA ---
            $reason = $_GET['reason'] ?? '';
            $logMessage = ($reason === 'inactivity') ? "OTO CIKIS (INACTIVITY)" : "CIKIS YAPTI";
            if (function_exists('girisCikisLogAl')) {
                girisCikisLogAl($pdo, $user_id, $logMessage);
            }
        }

    } catch (Exception $e) {
        // Veritabanı hatası olsa bile kullanıcıyı çıkış yaptırtmaya devam et
    }
}

// 4. OTURUMU SONLANDIR
if (isset($_SESSION['lang'])) {
    setcookie('lang', $_SESSION['lang'], time() + (365 * 24 * 60 * 60), '/');
}
session_unset();    // RAM'deki session değişkenlerini temizle
session_destroy();  // Sunucudaki session dosyasını sil

// 5. GİRİŞ SAYFASINA YÖNLENDİR
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_dir = rtrim(str_replace('/public', '', dirname($script_name)), '/\\');

$reason = $_GET['reason'] ?? '';
$redirectUrl = ($reason === 'inactivity') ? $base_dir . '/giris?timeout=inactivity' : $base_dir . '/giris';
header("Location: " . $redirectUrl);
exit;
?>
