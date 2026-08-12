<?php
// includes/session.php

// Çıktı tamponlamasını başlat
if (!function_exists('normalize_turkish_mojibake')) {
    function normalize_turkish_mojibake(string $content): string
    {
        static $map = [
            'Ä±' => 'ı', 'Ä°' => 'İ', 'ÅŸ' => 'ş', 'Åž' => 'Ş', 'ÄŸ' => 'ğ', 'Äž' => 'Ğ',
            'Ã¼' => 'ü', 'Ãœ' => 'Ü', 'Ã¶' => 'ö', 'Ã–' => 'Ö', 'Ã§' => 'ç', 'Ã‡' => 'Ç',
            'â‚º' => '₺', 'ÃƒÂ¼' => 'ü', 'ÃƒÅ“' => 'Ü', 'ÃƒÂ¶' => 'ö', 'Ãƒâ€“' => 'Ö',
            'ÃƒÂ§' => 'ç', 'Ãƒâ€¡' => 'Ç', 'Ã„Â±' => 'ı', 'Ã„Â°' => 'İ', 'Ã…Å¸' => 'ş',
            'Ã…Å¾' => 'Ş', 'Ã„Å¸' => 'ğ', 'Ã„Å¾' => 'Ğ'
        ];
        return str_replace(array_keys($map), array_values($map), $content);
    }
}

if (!function_exists('normalize_output_buffer')) {
    function normalize_output_buffer(string $content): string { return normalize_turkish_mojibake($content); }
}

ini_set('default_charset', 'UTF-8');
if (!headers_sent()) { header('Content-Type: text/html; charset=UTF-8'); }
// Not: ob_start callback içinde strtr() PHP 8.1+ ile uyumsuz (Fatal error).
// UTF-8 charset header yeterli; mojibake düzültme kaldırıldı.
ob_start('normalize_output_buffer');

define('SESSION_TIMEOUT_SECONDS', 3600); // 1 Hour Inactivity Timeout
$customSessionPath = __DIR__ . '/../sessions';
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', SESSION_TIMEOUT_SECONDS);
    if (file_exists($customSessionPath) && is_dir($customSessionPath) && is_writable($customSessionPath)) {
        session_save_path($customSessionPath);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

    $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0, // Keep session cookie until browser is closed
        'path' => '/',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    $customSessionPath = __DIR__ . '/../sessions';
    if (file_exists($customSessionPath) && is_dir($customSessionPath) && is_writable($customSessionPath)) {
        session_save_path($customSessionPath);
    }
    session_start();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}



require_once __DIR__ . '/lang.php';

function requireLogin()
{
    global $pdo;
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $base_dir = rtrim(str_replace('/public', '', dirname($script_name)), '/\\');

    if (!isset($_SESSION['user_id'])) {
        if (basename($_SERVER['PHP_SELF']) != 'login.php') {
            header("Location: " . $base_dir . "/giris"); // Subdirectory-compatible redirect
            exit;
        }
        return;
    }

    $now = time();
    if (!isset($_SESSION['last_online_check']) || ($now - $_SESSION['last_online_check'] > 30)) {
        $_SESSION['last_online_check'] = $now;
        $conn = (isset($pdo)) ? $pdo : (function_exists('db') ? db() : null);
        if ($conn) {
            try {
                $stmtStatus = $conn->prepare("SELECT is_online FROM users WHERE id = ?");
                $stmtStatus->execute([$_SESSION['user_id']]);
                $user_data = $stmtStatus->fetch(PDO::FETCH_ASSOC);
                if (!$user_data || $user_data['is_online'] == 0) {
                    if (isset($_SESSION['lang'])) {
                        setcookie('lang', $_SESSION['lang'], time() + (365 * 24 * 60 * 60), '/');
                    }
                    session_unset(); session_destroy();
                    header("Location: " . $base_dir . "/giris?msg=session_expired");
                    exit;
                }
            } catch (Throwable $e) {}
        }
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT_SECONDS)) {
        if (isset($_SESSION['lang'])) {
            setcookie('lang', $_SESSION['lang'], time() + (365 * 24 * 60 * 60), '/');
        }
        session_unset(); session_destroy();
        header("Location: " . $base_dir . "/giris?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time();

    if (isset($_SESSION['user_id']) && (!isset($_SESSION['theme']) || !isset($_SESSION['lang']))) {
        $conn = (isset($pdo)) ? $pdo : (function_exists('db') ? db() : null);
        if ($conn) {
            try {
                $stmtPrefs = $conn->prepare("SELECT theme, lang FROM users WHERE id = ?");
                $stmtPrefs->execute([$_SESSION['user_id']]);
                $prefs = $stmtPrefs->fetch(PDO::FETCH_ASSOC);
                if ($prefs) {
                    if (!isset($_SESSION['theme'])) {
                        $_SESSION['theme'] = !empty($prefs['theme']) ? $prefs['theme'] : 'light';
                    }
                    if (!isset($_SESSION['lang'])) {
                        $sysLang = 'tr';
                        try {
                            $stmtSys = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'system_lang' LIMIT 1");
                            $stmtSys->execute();
                            $sysVal = $stmtSys->fetchColumn();
                            if (!empty($sysVal) && in_array($sysVal, ['tr', 'en'])) {
                                $sysLang = $sysVal;
                            }
                        } catch (Exception $ex) {}
                        $_SESSION['lang'] = !empty($prefs['lang']) ? $prefs['lang'] : $sysLang;
                    }
                }
            } catch (Exception $e) {}
        }
    }

    if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
}

function isLoggedIn() { return isset($_SESSION['user_id']); }
function csrf_token() { return $_SESSION['csrf_token'] ?? ''; }

function csrf_field()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

function verify_csrf_token($token = null)
{
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    
    $session_token = $_SESSION['csrf_token'] ?? null;
    $result = false;
    if (!empty($session_token) && !empty($token)) {
        $result = hash_equals($session_token, (string) $token);
    }
    
    // Log details for debugging
    $logFile = __DIR__ . '/../logs/csrf_debug.log';
    $logData = sprintf(
        "[%s] Method: %s | URI: %s | SessionID: %s | SessionToken: %s | FormToken: %s | Matches: %s | UserID: %s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
        session_id(),
        $session_token ?? 'NULL',
        $token,
        $result ? 'YES' : 'NO',
        $_SESSION['user_id'] ?? 'NULL'
    );
    @file_put_contents($logFile, $logData, FILE_APPEND);
    
    return $result;
}

function require_csrf_token()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' &&
        $_SERVER['REQUEST_METHOD'] !== 'PUT' &&
        $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        $postMax = ini_get('post_max_size');
        http_response_code(413);
        $isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';
        $errorMsg = $isTr 
            ? "Yüklemeye çalıştığınız dosya veya verilerin toplam boyutu sunucu limitini ({$postMax}) aşıyor."
            : "The total size of the uploaded files or data exceeds the server limit ({$postMax}).";
            
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $errorMsg]);
        } else {
            echo $errorMsg;
        }
        exit;
    }

    if (!verify_csrf_token()) {
        http_response_code(403);
        $formToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        $formPrefix = substr((string)$formToken, 0, 6);
        $sessionPrefix = substr((string)$sessionToken, 0, 6);
        $formLen = strlen((string)$formToken);
        $sessionLen = strlen((string)$sessionToken);
        
        $errorMsg = sprintf(
            "Güvenlik hatası: CSRF token geçersiz. (Form: %d-%s..., Session: %d-%s...)",
            $formLen,
            $formPrefix,
            $sessionLen,
            $sessionPrefix
        );
        
        // AJAX istekleri için JSON döndür
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $errorMsg]);
        } else {
            // Oturum sonlanmışsa giriş sayfasına yönlendir
            if (!isset($_SESSION['user_id'])) {
                $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
                $base_dir = rtrim(str_replace('/public', '', dirname($script_name)), '/\\');
                header("Location: " . $base_dir . "/giris?msg=session_expired");
                exit;
            }
            
            // Oturum devam ediyorsa referer sayfasına yönlendirip mesaj göster
            if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
                $_SESSION['mesaj'] = "⚠️ Güvenlik uyarısı: Oturum doğrulama anahtarı uyuşmadı. Lütfen sayfayı yenileyip tekrar deneyiniz.";
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
            }
            
            echo $errorMsg;
        }
        exit;
    }
}

if (!defined('CURRENT_TENANT_ID')) {
    define('CURRENT_TENANT_ID', 1);
}

/**
 * Rate Limiter (Throttle)
 * Returns true if request is allowed, false if limit exceeded.
 */
function checkRateLimit(string $actionKey, int $maxRequests = 10, int $periodSeconds = 60): bool
{
    $userId = $_SESSION['user_id'] ?? 'guest';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    $rateKey = 'rate_' . $actionKey . '_' . md5($userId . '_' . $ip);
    $now = time();
    
    if (!isset($_SESSION[$rateKey]) || !is_array($_SESSION[$rateKey])) {
        $_SESSION[$rateKey] = [];
    }
    
    // Remove expired timestamps
    $_SESSION[$rateKey] = array_filter($_SESSION[$rateKey], function ($timestamp) use ($now, $periodSeconds) {
        return ($now - $timestamp) < $periodSeconds;
    });
    
    if (count($_SESSION[$rateKey]) >= $maxRequests) {
        return false; // Limit exceeded
    }
    
    $_SESSION[$rateKey][] = $now;
    return true; // Allowed
}
?>
