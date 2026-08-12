<?php
// config/db.php

// Application Version Constant (Centralized & Immutable across all deployments)
if (!defined('EAPRIMUS_VERSION')) {
    define('EAPRIMUS_VERSION', 'v1.0.0');
}

// 1. .env dosyasını yükle güvenli şekilde (öncelik: app/config/.env, ardından proje kökü .env)
$env_paths = [
    __DIR__ . '/.env',
    __DIR__ . '/../.env'
];

function parse_env_file(string $path): array
{
    $result = [];
    if (!file_exists($path) || !is_readable($path)) {
        return $result;
    }
    $content = trim(file_get_contents($path));
    $secretKey = "EaPrImUs_SeCrEt_DeCrYpT_KeY_2026_dB_sEcUrItY_lAyEr";
    
    $method = "AES-256-CBC";
    $ivLength = openssl_cipher_iv_length($method);

    if (strpos($content, "EAPRIMUS_ENCRYPTED_CONFIG:") === 0) {
        $raw = base64_decode(substr($content, 26));
        if (strlen($raw) > $ivLength) {
            $iv = substr($raw, 0, $ivLength);
            $encrypted = substr($raw, $ivLength);
            $decryptedContent = openssl_decrypt($encrypted, $method, $secretKey, 0, $iv);
            $lines = explode("\n", $decryptedContent);
        } else {
            $lines = [];
        }
    } else {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Auto-encrypt: Rebuild plain text and write encrypted back to the file
        if (count($lines) > 0) {
            $plainTextToEncrypt = implode("\n", $lines);
            $iv = openssl_random_pseudo_bytes($ivLength);
            $encrypted = openssl_encrypt($plainTextToEncrypt, $method, $secretKey, 0, $iv);
            $encryptedContent = "EAPRIMUS_ENCRYPTED_CONFIG:" . base64_encode($iv . $encrypted);
            @file_put_contents($path, $encryptedContent);
        }
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $key = trim($parts[0]);
        $val = trim($parts[1]);
        if ((substr($val, 0, 1) === '"' && substr($val, -1) === '"') || (substr($val, 0, 1) === "'" && substr($val, -1) === "'")) {
            $val = substr($val, 1, -1);
        }
        $result[$key] = $val;
    }
    return $result;
}

$env_loaded = false;
foreach ($env_paths as $p) {
    if (file_exists($p)) {
        $env = parse_env_file($p);
        if (is_array($env)) {
            foreach ($env as $key => $value) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $env_loaded = true;
        break;
    }
}

$reqUri = $_SERVER['REQUEST_URI'] ?? '';
if (PHP_SAPI !== 'cli' && (!$env_loaded || !file_exists(__DIR__ . '/installed.lock')) && strpos($reqUri, '/install') === false && strpos($reqUri, '/favicon') === false && strpos($reqUri, 'plugins') === false && strpos($reqUri, 'dist') === false) {
    $base_url = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    if (strpos($base_url, '/public') !== false) {
        $base_url = substr($base_url, 0, strpos($base_url, '/public') + 7);
    }
    header("Location: " . $base_url . "/install/");
    exit;
}

// 2. PHP Saat Dilimini Ayarla (Çok Önemli)
date_default_timezone_set('Europe/Istanbul');

// Şifreleme anahtarını Environment Variable veya DB ayarlarından oku
if (!defined('EAPRIMUS_KEY')) {
    $envKey = getenv('EAPRIMUS_KEY');
    if ($envKey) {
        define('EAPRIMUS_KEY', $envKey);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $customSessionPath = __DIR__ . '/../sessions';
    if (file_exists($customSessionPath) && is_dir($customSessionPath) && is_writable($customSessionPath)) {
        session_save_path($customSessionPath);
    }
    session_start();
}

function db()
{
    $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
    $dbName = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? '');
    $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
    $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
    $charset = 'utf8mb4';

    try {
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
        $pdo->exec("SET NAMES '{$charset}'; SET CHARSET '{$charset}'; SET time_zone = '+03:00';");
        
        if (empty($_SESSION['system_schema_checked_v7'])) {
            // tickets columns
            try { $pdo->exec("ALTER TABLE tickets ADD COLUMN closed_by INT DEFAULT NULL"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE tickets ADD COLUMN closed_date DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE tickets ADD COLUMN resolved_date DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE tickets ADD COLUMN agent_read TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE tickets ADD COLUMN unread_replies_count INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
            
            // users columns
            try { $pdo->exec("ALTER TABLE users ADD COLUMN onboarding_done TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE users ADD COLUMN can_login TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}

            // announcements columns
            try { $pdo->exec("ALTER TABLE announcements ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE announcements ADD COLUMN is_banner TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE announcements ADD COLUMN target_role VARCHAR(50) NOT NULL DEFAULT 'all'"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE announcements ADD COLUMN target_team_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}

            // canned_responses columns
            try { $pdo->exec("ALTER TABLE canned_responses ADD COLUMN user_id INT DEFAULT NULL"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE canned_responses ADD COLUMN is_global TINYINT DEFAULT 1"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE canned_responses ADD COLUMN sharing_type VARCHAR(20) DEFAULT 'global'"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE canned_responses ADD COLUMN team_id INT DEFAULT NULL"); } catch (Throwable $e) {}

            // notes columns
            try { $pdo->exec("ALTER TABLE asset_consumable_checkouts ADD COLUMN notes TEXT DEFAULT NULL"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE asset_accessory_checkouts ADD COLUMN notes TEXT DEFAULT NULL"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE asset_signatures ADD COLUMN notes TEXT DEFAULT NULL"); } catch (Throwable $e) {}

            // Deleted tickets registry to prevent email replies spawning new tickets
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS deleted_tickets (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        ticket_no VARCHAR(50) UNIQUE,
                        deleted_at DATETIME
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            } catch (Throwable $e) {}

            // Migrate past rating reply types from system to rating
            try { $pdo->exec("UPDATE ticket_replies SET reply_type = 'rating' WHERE reply_type = 'system' AND (message LIKE '%Bilet Değerlendirildi%' OR message LIKE '%Ticket Rated%')"); } catch (Throwable $e) {}

            // Ensure announcements table exists
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS announcements (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        title VARCHAR(255) NOT NULL,
                        content TEXT NOT NULL,
                        status TINYINT(1) DEFAULT 1,
                        start_date DATETIME DEFAULT NULL,
                        end_date DATETIME DEFAULT NULL,
                        priority VARCHAR(20) DEFAULT 'info',
                        created_by INT NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            } catch (Throwable $e) {}

            $_SESSION['system_schema_checked_v7'] = true;
        }

        return $pdo;
    } catch (PDOException $e) {
        // Handle rapid F5 refreshes
        if (strpos($e->getMessage(), 'Too many connections') !== false) {
            header("HTTP/1.1 503 Service Unavailable");
            die("Sistem şu an çok yoğun, lütfen bir saniye bekleyip tekrar deneyiniz.");
        }
        // If .env doesn't exist, it means we are not installed yet, redirect to install wizard.
        if (!file_exists(__DIR__ . '/.env')) {
            $base_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            if (strpos($base_url, '/public') !== false) {
                $base_url = substr($base_url, 0, strpos($base_url, '/public') + 7);
            }
            header("Location: " . $base_url . "/install/");
            exit;
        }

        error_log("Database Connection Error: " . $e->getMessage());
        // Get dynamic logo and favicon
        $logoUrl = '/public/logo.png';
        $faviconUrl = '/public/favicon.png';

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Database Error</title><style>body { font-family: sans-serif; background: #0f1b3d; color: #fff; text-align: center; padding-top: 20%; }</style></head><body><h2>Veritabanı Bağlantı Hatası / Database Connection Error</h2><p>Lütfen sistem yöneticinizle iletişime geçin.</p></body></html>';
        die($html);
    }
}

$pdo = db();

/**
 * Pulse Sistem Ayarları Global Önbellek ve Fonksiyon
 */
$allSettings = [];
try {
    $stmtAll = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmtAll->fetch(PDO::FETCH_ASSOC)) {
        $allSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Tablo henüz yoksa hata verme
}

if (!function_exists('s')) {
    function s($key, $default = '')
    {
        global $allSettings;
        $val = $allSettings[$key] ?? $default;
        return htmlspecialchars((string) $val);
    }
}

if (!defined('EAPRIMUS_KEY')) {
    $eaprimusKeyFromDb = $allSettings['eaprimus_key'] ?? '';
    define('EAPRIMUS_KEY', !empty($eaprimusKeyFromDb) ? $eaprimusKeyFromDb : 'sbABk64ppqN2Uuy-Eaprimus');
}

if (!function_exists('getAllowedUploadExtensions')) {
    function getAllowedUploadExtensions(): array
    {
        $raw = s('allowed_upload_extensions', 'pdf, png, jpg, jpeg, webp, gif, doc, docx, xls, xlsx, txt, zip, rar, 7z, csv');
        $exts = array_map('trim', explode(',', strtolower(htmlspecialchars_decode($raw))));
        $dangerous = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh', 'pl', 'cgi', 'py', 'js', 'vbs', 'asp', 'aspx', 'jsp'];
        return array_values(array_filter($exts, function($ext) use ($dangerous) {
            return !empty($ext) && !in_array($ext, $dangerous);
        }));
    }
}

if (!function_exists('tenantWhere')) {
    function tenantWhere(string $alias = ''): string
    {
        return '1=1';
    }
}



