<?php
// app/includes/lang.php

if (session_status() === PHP_SESSION_NONE) {
    // Session start burada session.php içinde zaten yapıldı, ama bağımsız kullanım ihtimaline karşı kalsın
    $customSessionPath = __DIR__ . '/../sessions';
    if (file_exists($customSessionPath) && is_dir($customSessionPath) && is_writable($customSessionPath)) {
        session_save_path($customSessionPath);
    }
    session_start();
}

// Dil değiştirme isteği
if (isset($_GET['lang'])) {
    $requested_lang = $_GET['lang'];
    if (in_array($requested_lang, ['tr', 'en'])) {
        $_SESSION['lang'] = $requested_lang;
        setcookie('lang', $requested_lang, time() + (365 * 24 * 60 * 60), '/'); // 1 year expiry

        if (isset($_SESSION['user_id'])) {
            try {
                require_once __DIR__ . "/../config/db.php";
                $pdo = db();
                $pdo->prepare("UPDATE users SET lang = ? WHERE id = ?")->execute([$requested_lang, $_SESSION['user_id']]);
            } catch (Exception $e) {
                // Ignore errors
            }
        }

        // ADMIN is changing language? Sync it with global mail_default_lang setting
        if (isset($_SESSION['role']) && $_SESSION['role'] == 1) {
            try {
                require_once __DIR__ . "/../config/db.php";
                $pdo = db();
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'mail_default_lang'")->execute([$requested_lang]);
            } catch (Exception $e) {
                // Ignore errors
            }
        }
    }

    // Değişikliği hemen kaydet ve çık
    session_write_close();

    // Mevcut sayfaya geri dön (parametre olan dili temizle)
    $uri = $_SERVER['REQUEST_URI'];
    $clean_uri = preg_replace('/([?&])lang=[^&]*(&|$)/', '$1', $uri);
    $clean_uri = rtrim($clean_uri, '?&');
    header("Location: " . $clean_uri);
    exit;
}

// Varsayılan dil
if (!isset($_SESSION['lang'])) {
    if (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], ['tr', 'en'])) {
        $_SESSION['lang'] = $_COOKIE['lang'];
    } else {
        // Fallback to system_lang setting from database
        $system_lang = null;
        try {
            require_once __DIR__ . "/../config/db.php";
            $pdo = db();
            $stmtSys = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'system_lang' LIMIT 1");
            $stmtSys->execute();
            $sysVal = $stmtSys->fetchColumn();
            if (!empty($sysVal) && in_array($sysVal, ['tr', 'en'])) {
                $system_lang = $sysVal;
            }
        } catch (Exception $e) {
            // Ignore database connection/table errors
        }

        if ($system_lang) {
            $_SESSION['lang'] = $system_lang;
        } else {
            $browser_lang = 'tr';
            if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                $accept_langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
                $primary_lang = strtolower(substr(trim($accept_langs[0]), 0, 2));
                if (in_array($primary_lang, ['tr', 'en'])) {
                    $browser_lang = $primary_lang;
                }
            }
            $_SESSION['lang'] = $browser_lang;
        }
    }
}
$current_lang = $_SESSION['lang'];

// Her yüklemede cookie'yi de tazeleyelim/eşitleyelim ki kaybolmasın!
if (!isset($_COOKIE['lang']) || $_COOKIE['lang'] !== $current_lang) {
    setcookie('lang', $current_lang, time() + (365 * 24 * 60 * 60), '/');
}

// Dil dosyasını yükle
$translations = [];
$lang_file = __DIR__ . "/../languages/{$current_lang}.php";
if (file_exists($lang_file)) {
    $translations = include $lang_file;
}

// Çeviri fonksiyonu
function __($key, $replacements = [])
{
    global $translations;
    $text = $translations[$key] ?? $key;

    if (!empty($replacements) && is_array($replacements)) {
        foreach ($replacements as $search => $replace) {
            $text = str_replace(':' . $search, $replace, $text);
            $text = str_replace('{{' . $search . '}}', $replace, $text);
        }
    }
    return $text;
}

// t() alias for __()
function t($key, $replacements = [])
{
    return __($key, $replacements);
}

// JavaScript için dili dışa aktar (isteğe bağlı)
// NOTE: Bu script tag'i AJAX/JSON cevaplarını bozduğu için kaldırıldı
// Gerekirse template dosyalarında manuel olarak ekleyin