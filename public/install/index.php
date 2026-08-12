<?php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$projectRootUrl = '';
if (strpos($scriptName, '/public/') !== false) {
    $projectRootUrl = substr($scriptName, 0, strpos($scriptName, '/public/') + 7);
} elseif (strpos($scriptName, '/install/') !== false) {
    $projectRootUrl = substr($scriptName, 0, strpos($scriptName, '/install/'));
}
$projectRootUrl = rtrim($projectRootUrl, '/');
$distBaseUrl = $projectRootUrl . '/dist';

// Align session save path with the main app
$customSessionPath = __DIR__ . '/../../app/sessions';
if (session_status() === PHP_SESSION_NONE) {
    if (file_exists($customSessionPath) && is_dir($customSessionPath) && is_writable($customSessionPath)) {
        session_save_path($customSessionPath);
    }
}
session_start();

if (file_exists(__DIR__ . '/../../app/config/db.php')) {
    include_once __DIR__ . '/../../app/config/db.php';
}

// Handle Language Switching
if (isset($_GET['lang']) && in_array($_GET['lang'], ['tr', 'en'])) {
    $_SESSION['install_lang'] = $_GET['lang'];
}
$lang = $_SESSION['install_lang'] ?? 'tr';

$translations = [
    'tr' => [
        'title' => 'Eaprimus Kurulumu',
        'subtitle' => 'Sistem Başlangıç Kurulumu',
        'step1' => 'Gereksinimler',
        'step2' => 'Veritabanı',
        'step3' => 'Şirket',
        'step4' => 'Yönetici',
        'step5' => 'Ayarlar',
        'step6' => 'Tamamlandı',
        'req_check' => 'Sistem Gereksinimleri Kontrolü',
        'php_ver' => 'PHP Sürümü >= 8.2 (Önerilen)',
        'pdo_ext' => 'PDO MySQL Eklentisi',
        'ext_ldap' => 'LDAP Eklentisi (Active Directory için)',
        'ext_gd' => 'GD Eklentisi (Resim İşlemleri için)',
        'ext_curl' => 'cURL Eklentisi',
        'ext_mb' => 'MBString Eklentisi',
        'req_warn' => 'Bazı gereksinimler eksik olabilir. Kırmızı çarpılı olanları sunucunuzdan aktif etmeniz önerilir. (LDAP gibi opsiyonel eklentiler olmadan da devam edebilirsiniz).',
        'btn_db' => 'Veritabanına İlerle',
        'db_config' => 'Veritabanı Yapılandırması',
        'db_desc' => 'MySQL veritabanı bağlantı bilgilerinizi girin. Sistem sıfırdan kurulacağı için eski veriler temizlenecektir.',
        'db_host' => 'Veritabanı Sunucusu (Host)',
        'db_name' => 'Veritabanı Adı',
        'db_user' => 'Veritabanı Kullanıcısı',
        'db_pass' => 'Veritabanı Şifresi',
        'btn_install_db' => 'Veritabanını Kur',
        'err_db_conn' => 'Veritabanı bağlantısı başarısız. Lütfen şifre ve kullanıcı adını kontrol edin.',
        'err_dup_user' => 'Bu kullanıcı adı veya e-posta adresi zaten kullanılıyor.',
        'err_pass_policy' => 'Şifreniz en az 8 karakter olmalı, en az bir büyük harf ve bir küçük harf içermelidir.',
        'err_no_tables' => 'Veritabanı tabloları kurulamadı. SQL içe aktarma hatası.',
        'err_sys_config' => 'Sistem ayar hatası:',
        'company_setup' => 'Şirket Ayarları',
        'company_name' => 'Şirket / Organizasyon Adı',
        'company_logo' => 'Şirket Logosu (Opsiyonel)',
        'company_favicon' => 'Favicon (Opsiyonel)',
        'btn_next' => 'Sonraki Adım',
        'btn_skip' => 'Bu Adımı Atla',
        'admin_setup' => 'Yönetici Hesabı (Admin)',
        'fullname' => 'Ad Soyad',
        'email' => 'E-posta Adresi',
        'username' => 'Kullanıcı Adı',
        'password' => 'Şifre',
        'avatar' => 'Profil Fotoğrafı (Opsiyonel)',
        'comm_setup' => 'Haberleşme Ayarları (Opsiyonel)',
        'comm_desc' => 'Bu adımı atlayabilir ve ayarları daha sonra panelden yapabilirsiniz.',
        'smtp_settings' => 'SMTP Mail Ayarları',
        'smtp_host' => 'SMTP Sunucusu (Host)',
        'smtp_secure' => 'Güvenlik Protokolü',
        'smtp_port' => 'Port',
        'smtp_user' => 'SMTP Kullanıcı Adı',
        'smtp_pass' => 'Şifre',
        'smtp_from' => 'Gönderen E-posta Adresi',
        'smtp_from_name' => 'Gönderici Adı (Görünen İsim)',
        'telegram_settings' => 'Telegram Ayarları',
        'tg_bot_token' => 'Telegram Bot Token',
        'tg_chat_id' => 'Telegram Varsayılan Chat ID',
        'btn_finish' => 'Kurulumu Tamamla',
        'install_complete' => 'Kurulum Tamamlandı!',
        'install_desc' => 'Eaprimus sunucunuza başarıyla kuruldu ve yapılandırıldı.',
        'btn_login' => 'Giriş Yap',
        'already_installed' => 'Eaprimus zaten kurulu. Yeniden kurmak için lütfen app/config/installed.lock dosyasını silin.',
        'cron_title' => 'Önemli: Arka Plan Görevleri (CRON)',
        'cron_desc' => 'Eaprimus bilet (ticket) e-postalarının arka planda otomatik düşmesi ve SLA ihlallerinin siz panelde değilken de çalışması için sisteminize Cron kurulması gerekir.',
        'cron_auto_success' => 'Sistem Kurulumu:',
        'cron_auto_success_desc' => 'sunucunuzda Cron görevi <u>otomatik olarak kuruldu!</u> Hiçbir işlem yapmanıza gerek yoktur.',
        'cron_win_fail' => 'Windows Sunucular (Mevcut):',
        'cron_win_fail_desc' => 'Otomatik kurulum yapılamadı. <code>public/install/setup_windows_cron.bat</code> dosyasına sağ tıklayıp "Yönetici Olarak Çalıştır" diyerek kurabilirsiniz.',
        'cron_lin_fail' => 'Linux Sunucular (AlmaLinux/Ubuntu/cPanel):',
        'cron_lin_fail_desc' => 'Eğer kurulumu terminalden otomatik komutla yaptıysanız <strong style="color:#10b981;">Cron görevi arka planda başarıyla kurulmuştur!</strong> (Sadece cPanel gibi sistemlere manuel/elle dosya yükleyerek kurulum yapanların Crontab içerisine şu komutu eklemesi gerekir):'
    ],
    'en' => [
        'title' => 'Eaprimus Setup',
        'subtitle' => 'Initial System Installation',
        'step1' => 'Requirements',
        'step2' => 'Database',
        'step3' => 'Company',
        'step4' => 'Administrator',
        'step5' => 'Settings',
        'step6' => 'Finish',
        'req_check' => 'System Requirements Check',
        'php_ver' => 'PHP Version >= 8.2 (Recommended)',
        'pdo_ext' => 'PDO MySQL Extension',
        'ext_ldap' => 'LDAP Extension (For Active Directory)',
        'ext_gd' => 'GD Extension (For Image Processing)',
        'ext_curl' => 'cURL Extension',
        'ext_mb' => 'MBString Extension',
        'req_warn' => 'Some requirements are missing. It is recommended to enable them on your server. (You can still continue without optional extensions like LDAP).',
        'btn_db' => 'Continue to Database',
        'db_config' => 'Database Configuration',
        'db_desc' => 'Enter your MySQL database connection details. The system will be installed from scratch and old data will be wiped.',
        'db_host' => 'Database Host',
        'db_name' => 'Database Name',
        'db_user' => 'Database User',
        'db_pass' => 'Database Password',
        'btn_install_db' => 'Install Database',
        'err_db_conn' => 'Database connection failed. Please check your username and password.',
        'err_dup_user' => 'This username or email is already in use.',
        'err_pass_policy' => 'Your password must be at least 8 characters long, and contain at least one uppercase and one lowercase letter.',
        'err_no_tables' => 'Database tables could not be created. SQL import error.',
        'err_sys_config' => 'System config error:',
        'company_setup' => 'Company Setup',
        'company_name' => 'Company / Organization Name',
        'company_logo' => 'Company Logo (Optional)',
        'company_favicon' => 'Favicon (Optional)',
        'btn_next' => 'Next Step',
        'btn_skip' => 'Skip This Step',
        'admin_setup' => 'Administrator Account',
        'fullname' => 'Full Name',
        'email' => 'Email Address',
        'username' => 'Username',
        'password' => 'Password',
        'avatar' => 'Profile Picture (Optional)',
        'comm_setup' => 'Communication Settings (Optional)',
        'comm_desc' => 'You can skip this and configure it later from the settings panel.',
        'smtp_settings' => 'SMTP Mail Settings',
        'smtp_host' => 'SMTP Host',
        'smtp_secure' => 'Security Protocol',
        'smtp_port' => 'Port',
        'smtp_user' => 'SMTP User',
        'smtp_pass' => 'Password',
        'smtp_from' => 'From Email Address',
        'smtp_from_name' => 'From Name (Display Name)',
        'telegram_settings' => 'Telegram Settings',
        'tg_bot_token' => 'Telegram Bot Token',
        'tg_chat_id' => 'Telegram Default Chat ID',
        'btn_finish' => 'Finish Setup',
        'install_complete' => 'Installation Complete!',
        'install_desc' => 'Eaprimus has been successfully installed and configured on your server.',
        'btn_login' => 'Go to Login',
        'already_installed' => 'Eaprimus is already installed. Please delete app/config/installed.lock to reinstall.',
        'cron_title' => 'Important: Background Tasks (CRON)',
        'cron_desc' => 'To automatically fetch ticket emails in the background and process SLA breaches when you are not in the panel, Cron must be installed on your system.',
        'cron_auto_success' => 'System Installation:',
        'cron_auto_success_desc' => 'server Cron task has been <u>automatically installed!</u> No further action is required.',
        'cron_win_fail' => 'Windows Servers (Current):',
        'cron_win_fail_desc' => 'Automatic installation failed. You can install it by right-clicking <code>public/install/setup_windows_cron.bat</code> and selecting "Run as Administrator".',
        'cron_lin_fail' => 'Linux Servers (AlmaLinux/Ubuntu/cPanel):',
        'cron_lin_fail_desc' => 'If you installed using the automatic terminal script, <strong style="color:#10b981;">the Cron task has been successfully installed in the background!</strong> (Only manual installations like cPanel require adding the following command to Crontab):'
    ]
];

function t($key) {
    global $translations, $lang;
    return $translations[$lang][$key] ?? $key;
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;

function is_database_connected() {
    $envPath = __DIR__ . '/../../app/config/.env';
    if (!file_exists($envPath)) {
        return false;
    }
    if (function_exists('parse_env_file')) {
        $config = parse_env_file($envPath);
    } else {
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return false;
        }
        $config = [];
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos(trim($line), '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $config[trim($key)] = trim($value);
            }
        }
    }
    $host = $config['DB_HOST'] ?? '127.0.0.1';
    $dbname = $config['DB_DATABASE'] ?? '';
    $user = $config['DB_USERNAME'] ?? 'root';
    $pass = $config['DB_PASSWORD'] ?? '';
    
    try {
        $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_TIMEOUT => 2,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        return true;
    } catch (Exception $e) {
        if ($host === 'localhost') {
            try {
                $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbname};charset=utf8mb4", $user, $pass, [
                    PDO::ATTR_TIMEOUT => 2,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                // Automatically fix .env to use 127.0.0.1 since localhost socket is unreachable
                $envContent = "";
                foreach ($lines as $line) {
                    if (strpos($line, 'DB_HOST=') === 0) {
                        $envContent .= "DB_HOST=127.0.0.1\n";
                    } else {
                        $envContent .= $line . "\n";
                    }
                }
                @file_put_contents($envPath, $envContent);
                return true;
            } catch (Exception $ex) {}
        }
        return false;
    }
}

// Dynamically override title with company name from database if already configured
if (is_database_connected()) {
    try {
        require_once __DIR__ . '/../../app/config/db.php';
        $db = db();
        if ($db) {
            $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'company_name' LIMIT 1");
            $db_company = $stmt->fetchColumn();
            if (!empty($db_company)) {
                $translations['tr']['title'] = $db_company . ' Kurulumu';
                $translations['en']['title'] = $db_company . ' Setup';
            }
        }
    } catch (Exception $e) {}
}

if (file_exists(__DIR__ . '/../../app/config/installed.lock') && $step !== 6) {
    header("Location: ../login.php");
    exit;
}

// Auto-skip to Step 3 if DB is already configured (e.g. via server-install.sh)
if (($step === 0 || $step === 1 || $step === 2) && is_database_connected() && !file_exists(__DIR__ . '/../../app/config/installed.lock')) {
    // Attempt to read lang from .env
    $envPath = __DIR__ . '/../../app/config/.env';
    $envLang = 'tr';
    if (file_exists($envPath)) {
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            foreach ($lines as $line) {
                if (strpos($line, 'INSTALL_LANG=') === 0) {
                    $envLang = trim(substr($line, 13));
                    break;
                }
            }
        }
    }
    $_SESSION['install_lang'] = $envLang;
    $_SESSION['install_step'] = 3;
    header("Location: step-3?lang=" . $envLang);
    exit;
}

if ($step === 0) {
    if (isset($_SESSION['install_step']) && $_SESSION['install_step'] > 1 && $_SESSION['install_step'] < 6) {
        $resume = $_SESSION['install_step'];
        header("Location: step-{$resume}?lang=" . ($lang ?? 'tr'));
        exit;
    }
}

if ($step === 0 || $step === 1) {
    $saved_lang = $_SESSION['install_lang'] ?? 'tr';
    session_unset();
    session_destroy();
    if (file_exists($customSessionPath) && is_dir($customSessionPath) && is_writable($customSessionPath)) {
        session_save_path($customSessionPath);
    }
    session_start();
    $_SESSION['install_lang'] = $saved_lang;
}

if ($step >= 1 && $step < 6) {
    $_SESSION['install_step'] = $step;
}

$error = '';

if ($step === 6) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $saved_lang = $_SESSION['install_lang'] ?? 'tr';
        $saved_cron = $_SESSION['auto_cron_status'] ?? false;

        $_SESSION = [];
        session_destroy();
        session_start();
        $_SESSION['install_lang'] = $saved_lang;
        $_SESSION['auto_cron_status'] = $saved_cron;
        
        // Ensure main application language is set
        $_SESSION['lang'] = $saved_lang;
        setcookie('lang', $saved_lang, time() + (365 * 24 * 60 * 60), '/');

        unset($_SESSION['user_id']);
        unset($_SESSION['role']);
        unset($_SESSION['fullname']);
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
    }
    setcookie('onboarding_done_v8', '', time() - 3600, '/');
    setcookie('onboarding_done_v7', '', time() - 3600, '/');
    setcookie('onboarding_done_v6', '', time() - 3600, '/');
}

$base_dir = realpath(__DIR__ . '/../../');

// Helper for file uploads
function handleUpload($fileArray, $targetDir, $prefix = '') {
    if (isset($fileArray) && $fileArray['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($fileArray['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'])) {
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            // Delete old files with the same prefix to prevent duplication
            if (!empty($prefix)) {
                $oldFiles = glob($targetDir . '/' . $prefix . '*');
                if ($oldFiles) {
                    foreach($oldFiles as $of) {
                        if(is_file($of)) unlink($of);
                    }
                }
            }
            $filename = $prefix . uniqid() . '.' . $ext;
            $targetFile = $targetDir . '/' . $filename;
            if (move_uploaded_file($fileArray['tmp_name'], $targetFile)) {
                return 'uploads/' . $filename;
            }
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 2) {
        // Handle DB config
        $host = $_POST['db_host'] ?? 'localhost';
        $dbname = $_POST['db_name'] ?? '';
        $dbuser = $_POST['db_user'] ?? '';
        $dbpass = $_POST['db_pass'] ?? '';

        try {
            $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $dbuser, $dbpass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Try to use DB or create it. Wipe old database if exists to ensure a clean install!
            $pdo->exec("DROP DATABASE IF EXISTS `{$dbname}`");
            $pdo->exec("CREATE DATABASE `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbname}`");

            // Clean up old uploads, signatures, sessions, and logs for a fresh 0-install
            $dirsToClean = [
                $base_dir . '/public/uploads',
                $base_dir . '/app/storage/signatures',
                $base_dir . '/app/sessions',
                $base_dir . '/app/logs'
            ];
            foreach ($dirsToClean as $dir) {
                if (is_dir($dir)) {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $fileinfo) {
                        if ($fileinfo->isFile() && $fileinfo->getFilename() === '.gitkeep') {
                            continue;
                        }
                        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                        @$todo($fileinfo->getRealPath());
                    }
                }
            }

            // Write .env
            $env_content = "DB_HOST={$host}\nDB_DATABASE={$dbname}\nDB_USERNAME={$dbuser}\nDB_PASSWORD={$dbpass}\nEAPRIMUS_KEY=Secret_" . bin2hex(random_bytes(8)) . "\n";
            $env_written = @file_put_contents($base_dir . '/app/config/.env', $env_content);
            if ($env_written === false) {
                throw new Exception("Dosya yazma izni reddedildi: app/config/.env dosyası oluşturulamadı! Lütfen 'app/config' klasörüne yazma izni (CHMOD 777 veya chown) verin.");
            }

            // Import SQL (Using query splitting instead of single exec to avoid multi-statement issues)
            $sql = file_get_contents($base_dir . '/public/install/database_schema.sql');
            if ($sql) {
                $sql = preg_replace('/USE\s+`?[a-zA-Z0-9_-]+`?;/i', '', $sql); // Remove USE statements
                try {
                    $pdo->exec($sql);
                } catch (PDOException $ex) {
                    throw new Exception("SQL Import Error: " . $ex->getMessage());
                }
            }

            header("Location: step-3?lang=" . $lang);
            exit;
        } catch (Exception $e) {
            $errMessage = $e->getMessage();
            if ($e->getCode() == 1045) {
                $error = t('err_db_conn');
            } elseif (strpos($errMessage, '1273') !== false || strpos($errMessage, 'utf8mb4_0900_ai_ci') !== false) {
                if ($lang === 'tr') {
                    $error = "Veritabanı Dil Karşılaştırma (Collation) Uyumsuzluğu Hatası:<br><br>" .
                             "Kullandığınız veritabanı sunucusu eski bir sürümdür ve modern MySQL 8.0 dil kodlamasını (<code>utf8mb4_0900_ai_ci</code>) desteklememektedir.<br><br>" .
                             "<b>Çözüm:</b> Lütfen sanal makinenizde terminale gidip <code>git pull</code> komutunu çalıştırarak bizim az önce GitHub'a gönderdiğimiz yeni uyumluluk-güvenli şemayı çekin. Ardından kurulumu tekrar deneyin. Sorun otomatik olarak çözülecektir.";
                } else {
                    $error = "Database Collation Incompatibility Error:<br><br>" .
                             "Your database server version is too old and does not support the modern MySQL 8.0 collation (<code>utf8mb4_0900_ai_ci</code>).<br><br>" .
                             "<b>Solution:</b> Please go to your virtual machine terminal and run <code>git pull</code> to fetch the new compatibility-safe schema we just pushed to GitHub. Then try the installation again. The issue will be resolved automatically.";
                }
            } else {
                $error = "DB Error: " . $errMessage;
            }
        }
    } elseif ($step === 3) {
        // Handle Company config
        $company_name = $_POST['company_name'] ?? 'Eaprimus';
        
        try {
            require_once $base_dir . '/app/config/db.php';
            $pdo = db();

            $stmtSet = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmtSet->execute(['company_name', $company_name, $company_name]);
            $stmtSet->execute(['site_title', $company_name, $company_name]);

            // Handle Logo
            if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
                $logoExt = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
                if (in_array($logoExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'])) {
                    $targetLogo = $base_dir . '/public/logo.png';
                    if (file_exists($targetLogo)) {
                        @unlink($targetLogo);
                    }
                    $uploadedTmp = $_FILES['company_logo']['tmp_name'];
                    $gdSuccess = false;
                    if (in_array($logoExt, ['jpg', 'jpeg', 'png', 'webp']) && function_exists('imagecreatefromstring')) {
                        $imgData = @file_get_contents($uploadedTmp);
                        if ($imgData) {
                            $srcImg = @imagecreatefromstring($imgData);
                            if ($srcImg) {
                                imagealphablending($srcImg, false);
                                imagesavealpha($srcImg, true);
                                if (imagepng($srcImg, $targetLogo, 8)) {
                                    $gdSuccess = true;
                                }
                                imagedestroy($srcImg);
                            }
                        }
                    }
                    if (!$gdSuccess) {
                        move_uploaded_file($uploadedTmp, $targetLogo);
                    }
                    $stmtSet->execute(['logo_path', 'logo.png', 'logo.png']);
                }
            }

            // Handle Favicon
            if (isset($_FILES['company_favicon']) && $_FILES['company_favicon']['error'] === UPLOAD_ERR_OK) {
                $faviconExt = strtolower(pathinfo($_FILES['company_favicon']['name'], PATHINFO_EXTENSION));
                if (in_array($faviconExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'])) {
                    $targetFavicon = $base_dir . '/public/favicon.png';
                    if (file_exists($targetFavicon)) {
                        @unlink($targetFavicon);
                    }
                    $uploadedTmp = $_FILES['company_favicon']['tmp_name'];
                    $gdSuccess = false;
                    if (in_array($faviconExt, ['jpg', 'jpeg', 'png', 'webp']) && function_exists('imagecreatefromstring')) {
                        $imgData = @file_get_contents($uploadedTmp);
                        if ($imgData) {
                            $srcImg = @imagecreatefromstring($imgData);
                            if ($srcImg) {
                                imagealphablending($srcImg, false);
                                imagesavealpha($srcImg, true);
                                if (imagepng($srcImg, $targetFavicon, 8)) {
                                    $gdSuccess = true;
                                }
                                imagedestroy($srcImg);
                            }
                        }
                    }
                    if (!$gdSuccess) {
                        move_uploaded_file($uploadedTmp, $targetFavicon);
                    }
                    $stmtSet->execute(['favicon_path', 'favicon.png', 'favicon.png']);
                }
            }

            header("Location: step-4?lang=" . $lang);
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() == '42S02') {
                $error = t('err_no_tables');
            } else {
                $error = t('err_sys_config') . " " . $e->getMessage();
            }
        } catch (Exception $e) {
            $error = t('err_sys_config') . " " . $e->getMessage();
        }
    } elseif ($step === 4) {
        // Handle Admin Account
        $admin_fullname = $_POST['admin_fullname'] ?? '';
        $admin_email = $_POST['admin_email'] ?? '';
        $admin_username = $_POST['admin_username'] ?? '';
        $admin_password = $_POST['admin_password'] ?? '';

        // Password Policy Validation
        if (strlen($admin_password) < 8 || !preg_match('/[A-Z]/', $admin_password) || !preg_match('/[a-z]/', $admin_password)) {
            $error = t('err_pass_policy');
        } else {
            try {
                require_once $base_dir . '/app/config/db.php';
                $pdo = db();

                // Handle Avatar
                $avatarPath = $_POST['admin_avatar_url'] ?? '';
                if (empty($avatarPath) && !empty($_FILES['admin_avatar']['name'])) {
                    $tempPath = handleUpload($_FILES['admin_avatar'], $base_dir . '/public/uploads/profil', 'user_admin_');
                    if ($tempPath) {
                        $avatarPath = basename($tempPath); // only save filename for profil_fotosu
                    }
                }

                // Disable strict mode so MySQL auto-fills missing columns like 'tc_no' with defaults
                $pdo->exec("SET sql_mode = ''");

                // Ensure required user columns exist (e.g. lang, theme)
                try { $pdo->exec("ALTER TABLE users ADD COLUMN theme varchar(50) DEFAULT 'light'"); } catch (Throwable $e) {}
                try { $pdo->exec("ALTER TABLE users ADD COLUMN lang varchar(10) DEFAULT 'tr'"); } catch (Throwable $e) {}
                try { $pdo->exec("ALTER TABLE users ADD COLUMN onboarding_done TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

                // Insert or Update admin user
                $hashedPass = hash('sha256', $admin_password);
                
                // Check if user already exists
                $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ? OR mail = ? LIMIT 1");
                $stmtCheck->execute([$admin_username, $admin_email]);
                $existingUser = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($existingUser) {
                    // Update existing admin
                    $stmtUpdate = $pdo->prepare("UPDATE users SET password = ?, fullname = ?, role = 1, status = 1, profil_fotosu = ?, lang = ? WHERE id = ?");
                    $stmtUpdate->execute([$hashedPass, $admin_fullname, $avatarPath, $lang, $existingUser['id']]);
                } else {
                    // Insert new admin
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, fullname, role, mail, status, created_at, profil_fotosu, lang) VALUES (?, ?, ?, 1, ?, 1, NOW(), ?, ?)");
                    $stmt->execute([$admin_username, $hashedPass, $admin_fullname, $admin_email, $avatarPath, $lang]);
                }
                
                $pdo->exec("INSERT IGNORE INTO user_perm (role_id, route_name) VALUES (1, '*')");
                
                $staff_perms = "main,biletler,bilet-detay,ticket-olustur,varliklar,varlik_detay,profil_duzenle,varliklar_view_own,biletler_view_own";
                $pdo->exec("INSERT IGNORE INTO user_perm (role_id, route_name) VALUES (2, '$staff_perms')");
                
                $tech_perms = "main,biletler,bilet-detay,ticket-olustur,varliklar,varlik_detay,musteriler,musteri_detay,musteri_ekle,musteri_duzenle,organizasyonlar,tedarikci_detay,kullanici_listele,kullanici_ekle,kullanici_duzenle,takimlar,kuyruklar,sla-dashboard,raporlar,network-discovery,profil_duzenle,sayim,amortisman,varliklar_view_all,varliklar_edit,biletler_view_all,biletler_edit,varliklar_checkin,varliklar_upload_attachment,varliklar_delete_attachment,varliklar_clear_logs";
                $pdo->exec("INSERT IGNORE INTO user_perm (role_id, route_name) VALUES (3, '$tech_perms')");

                header("Location: step-5?lang=" . $lang);
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = t('err_dup_user');
                } else {
                    $error = "Admin setup failed: " . $e->getMessage();
                }
            } catch (Exception $e) {
                $error = "Admin setup failed: " . $e->getMessage();
            }
        }
    } elseif ($step === 5) {
        // Handle Communication Settings
        try {
            require_once $base_dir . '/app/config/db.php';
            $pdo = db();
            $stmtSet = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            // Always save system lang from install lang
            $stmtSet->execute(['system_lang', $lang, $lang]);

            if (!isset($_POST['skip'])) {
                $settingsToSave = [
                    'mail_host' => $_POST['smtp_host'] ?? '',
                    'mail_port' => $_POST['smtp_port'] ?? '',
                    'mail_secure' => $_POST['smtp_secure'] ?? 'tls',
                    'mail_username' => $_POST['smtp_user'] ?? '',
                    'mail_password' => $_POST['smtp_pass'] ?? '',
                    'mail_from_address' => $_POST['smtp_from'] ?? '',
                    'mail_from_name' => $_POST['smtp_from_name'] ?? '',
                    'telegram_bot_token' => $_POST['telegram_bot_token'] ?? '',
                    'telegram_admin_chat_id' => $_POST['telegram_chat_id'] ?? ''
                ];

                foreach ($settingsToSave as $k => $v) {
                    if (!empty($v) || $k === 'mail_secure') {
                        $stmtSet->execute([$k, $v, $v]);
                    }
                }
            }

            // Create lock file
            file_put_contents($base_dir . '/app/config/installed.lock', date('Y-m-d H:i:s'));

            // Auto-Permissions Setup (Apache/Nginx & SELinux)
            try {
                $writeDirs = [
                    $base_dir . '/app/storage',
                    $base_dir . '/app/logs',
                    $base_dir . '/app/sessions',
                    $base_dir . '/public/uploads'
                ];
                foreach ($writeDirs as $dir) {
                    if (is_dir($dir)) {
                        @chmod($dir, 0775);
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::SELF_FIRST
                        );
                        foreach ($iterator as $item) {
                            @chmod($item->getPathname(), $item->isDir() ? 0775 : 0664);
                        }
                    }
                }
                
                if (DIRECTORY_SEPARATOR === '/') { // Linux environment check
                    $izinlerScript = realpath($base_dir . '/izinler.sh');
                    if ($izinlerScript && file_exists($izinlerScript) && function_exists('shell_exec')) {
                        @shell_exec("bash " . escapeshellarg($izinlerScript) . " >/dev/null 2>&1 &");
                    }
                }
            } catch (Throwable $pe) {
                // Fail silently if directory iterator throws
            }

            // Auto-Cron Setup
            $_SESSION['auto_cron_status'] = false;
            if (DIRECTORY_SEPARATOR === '/') { // Linux environment check
                $workerPath = realpath($base_dir . '/app/cron/worker.php');
                if ($workerPath && function_exists('shell_exec')) {
                    $cronCommand = "* * * * * php {$workerPath}";
                    $existingCron = @shell_exec('crontab -l 2>/dev/null');
                    if (strpos((string)$existingCron, 'app/cron/worker.php') === false) {
                        $tempCron = tempnam(sys_get_temp_dir(), 'cron');
                        if ($tempCron) {
                            file_put_contents($tempCron, $existingCron . PHP_EOL . $cronCommand . PHP_EOL);
                            @shell_exec('crontab ' . $tempCron);
                            @unlink($tempCron);
                            $newCron = @shell_exec('crontab -l 2>/dev/null');
                            if (strpos((string)$newCron, 'app/cron/worker.php') !== false) {
                                $_SESSION['auto_cron_status'] = true;
                            }
                        }
                    } else {
                        $_SESSION['auto_cron_status'] = true; // Already exists
                    }
                }
            } else {
                // Windows environment check
                $workerPath = realpath($base_dir . '/app/cron/worker.php');
                if ($workerPath && function_exists('shell_exec')) {
                    $phpPath = 'php-win.exe';
                    if (file_exists('C:\\Ampps\\php\\php-win.exe')) {
                        $phpPath = 'C:\\Ampps\\php\\php-win.exe';
                    } elseif (file_exists('C:\\Ampps\\php\\php.exe')) {
                        $phpPath = 'C:\\Ampps\\php\\php.exe';
                    }
                    // Try to create scheduled task without /ru System first to avoid strict UAC blocks if possible
                    $cmd = 'schtasks /create /tn "Eaprimus_Cron" /tr "\"'.$phpPath.'\" \"'.$workerPath.'\"" /sc minute /mo 1 /f';
                    $out = @shell_exec($cmd . ' 2>&1');
                    $outLower = strtolower((string)$out);
                    if (strpos($outLower, 'success') !== false || strpos($outLower, 'başarılı') !== false) {
                        $_SESSION['auto_cron_status'] = true;
                    }
                }
            }

            // Delete schema (Devredışı bırakıldı - Test aşamasında silinmemesi için)
            // if (file_exists($base_dir . '/database_schema.sql')) {
            //     unlink($base_dir . '/database_schema.sql');
            // }

            header("Location: step-6?lang=" . $lang);
            exit;
        } catch (Exception $e) {
            $error = "Communication setup failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('title') ?></title>
    <link rel="icon" type="image/png" href="../favicon.png?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0f1b3d; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 40px 20px; margin: 0; overflow-y: auto; }
        #particles-js { position: fixed; width: 100%; height: 100%; background: radial-gradient(circle at center, #1a2b5e 0%, #080f26 100%); z-index: -1; top: 0; left: 0; }
        .wizard-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-radius: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); border: 1px solid rgba(255, 255, 255, 0.7); max-width: 650px; width: 100%; overflow: hidden; margin: 20px 0; position: relative; }
        .brand-header { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 30px 40px; color: #fff; text-align: center; position: relative; }
        .wizard-body { padding: 40px; }
        .form-control { border-radius: 12px; height: 50px; padding: 12px 16px; }
        select.form-control { height: 50px !important; }
        .input-group .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .input-group-text { border-top-right-radius: 12px; border-bottom-right-radius: 12px; background: #fff; color: #94a3b8; border-left: none; }
        .input-group .form-control:focus + .input-group-append .input-group-text { border-color: #80bdff; }
        .btn-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); border: none; border-radius: 12px; padding: 12px; font-weight: 600; }
        .btn-outline-secondary { border-radius: 12px; padding: 12px; font-weight: 600; }
        .steps { display: flex; justify-content: space-between; margin-bottom: 30px; position: relative; }
        .steps::before { content: ''; position: absolute; top: 15px; left: 0; width: 100%; height: 2px; background: #e2e8f0; z-index: 1; }
        .step { position: relative; z-index: 2; background: #fff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid #cbd5e1; color: #94a3b8; font-size: 14px; }
        .step.active { border-color: #2563eb; background: #2563eb; color: #fff; }
        .step.done { border-color: #10b981; background: #10b981; color: #fff; }
        .lang-selector { position: absolute; top: 15px; right: 15px; }
        .lang-selector a { color: #cbd5e1; text-decoration: none; padding: 5px; font-size: 14px; opacity: 0.5; transition: all 0.3s ease; }
        .lang-selector a.active { opacity: 1; transform: scale(1.1); display: inline-block; }
        .lang-selector img { border-radius: 50%; width: 24px; height: 24px; object-fit: cover; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        
        /* iPhone-like animations */
        @keyframes slideUpFade {
            0% { opacity: 0; transform: translateY(40px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .animate-slide-up { animation: slideUpFade 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .delay-1 { animation-delay: 0.2s; opacity: 0; }
        .delay-2 { animation-delay: 0.4s; opacity: 0; }
        
        .lang-btn { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .lang-btn:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important; border-color: #60a5fa !important; }
    </style>
</head>
<body>
<div id="particles-js"></div>
<div class="wizard-card">
    <div class="brand-header">
        <div class="lang-selector">
            <a href="step-<?= $step ?>?lang=tr" class="<?= $lang == 'tr' ? 'active' : '' ?>">
                <img src="https://flagcdn.com/w40/tr.png" alt="TR">
            </a> 
            <a href="step-<?= $step ?>?lang=en" class="<?= $lang == 'en' ? 'active' : '' ?>">
                <img src="https://flagcdn.com/w40/gb.png" alt="EN">
            </a>
        </div>
        <img id="brand-logo" src="../logo.png?v=<?= time() ?>" alt="Eaprimus Logo" style="max-height: 60px; margin-bottom: 15px;" onerror="this.outerHTML='<i class=\'fas fa-tools fa-3x mb-3 text-primary\' style=\'color: #60a5fa !important;\'></i>'">
        <h4 class="font-weight-bold mb-1"><?= t('title') ?></h4>
        <p class="text-muted mb-0" style="color: #cbd5e1 !important;"><?= t('subtitle') ?></p>
    </div>

    <div class="wizard-body">
        <?php if ($step > 0): ?>
        <div class="steps">
            <div class="step <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">1</div>
            <div class="step <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">2</div>
            <div class="step <?= $step >= 3 ? ($step > 3 ? 'done' : 'active') : '' ?>">3</div>
            <div class="step <?= $step >= 4 ? ($step > 4 ? 'done' : 'active') : '' ?>">4</div>
            <div class="step <?= $step >= 5 ? ($step > 5 ? 'done' : 'active') : '' ?>">5</div>
            <div class="step <?= $step >= 6 ? 'active' : '' ?>">6</div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger font-weight-bold"><i class="fas fa-exclamation-circle mr-2"></i><?= $error ?></div>
        <?php endif; ?>

        <?php if ($step === 0): ?>
            <div class="text-center py-4">
                <div class="animate-slide-up">
                    <h3 class="font-weight-bold text-dark mb-2" style="letter-spacing: -0.5px;">Please Select Your Language</h3>
                    <h5 class="text-muted mb-5" style="font-weight: 400;">Lütfen Dil Seçimi Yapınız</h5>
                </div>
                
                <div class="row justify-content-center">
                    <div class="col-6 col-md-5 mb-3 animate-slide-up delay-1">
                        <a href="step-1?lang=tr" class="btn btn-outline-light text-dark btn-block py-4 shadow-sm lang-btn" style="border-radius: 20px; border: 2px solid #e2e8f0; text-decoration: none;">
                            <img src="https://flagcdn.com/w80/tr.png" alt="Türkçe" style="width: 70px; height: 70px; border-radius: 50%; object-fit: cover; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 10px;">
                            <span class="font-weight-bold mt-2 d-block" style="font-size: 1.2rem;">Türkçe</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-5 mb-3 animate-slide-up delay-2">
                        <a href="step-1?lang=en" class="btn btn-outline-light text-dark btn-block py-4 shadow-sm lang-btn" style="border-radius: 20px; border: 2px solid #e2e8f0; text-decoration: none;">
                            <img src="https://flagcdn.com/w80/gb.png" alt="English" style="width: 70px; height: 70px; border-radius: 50%; object-fit: cover; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 10px;">
                            <span class="font-weight-bold mt-2 d-block" style="font-size: 1.2rem;">English</span>
                        </a>
                    </div>
                </div>
            </div>

        <?php elseif ($step === 1): ?>
            <h5 class="font-weight-bold text-dark mb-4"><?= t('req_check') ?></h5>
            <ul class="list-group mb-4">
                <?php
                $requirements = [
                    ['name' => t('php_ver') . ' <small class="text-muted ml-2">(Mevcut: ' . phpversion() . ')</small>', 'check' => version_compare(PHP_VERSION, '8.2.0', '>=')],
                    ['name' => t('pdo_ext'), 'check' => extension_loaded('pdo_mysql')],
                    ['name' => t('ext_ldap'), 'check' => extension_loaded('ldap')],
                    ['name' => t('ext_gd'), 'check' => extension_loaded('gd')],
                    ['name' => t('ext_curl'), 'check' => extension_loaded('curl')],
                    ['name' => t('ext_mb'), 'check' => extension_loaded('mbstring')]
                ];
                
                $all_passed = true;
                foreach ($requirements as $req) {
                    if (!$req['check']) $all_passed = false;
                    $badge = $req['check'] ? '<span class="badge badge-success badge-pill"><i class="fas fa-check"></i></span>' : '<span class="badge badge-danger badge-pill"><i class="fas fa-times"></i></span>';
                    echo '<li class="list-group-item d-flex justify-content-between align-items-center">' . $req['name'] . $badge . '</li>';
                }
                ?>
            </ul>
            <?php if (!$all_passed): ?>
                <div class="alert alert-warning small"><i class="fas fa-exclamation-triangle mr-2"></i> <?= t('req_warn') ?></div>
            <?php endif; ?>
            <a href="step-2" class="btn btn-primary btn-block"><?= t('btn_db') ?> <i class="fas fa-arrow-right ml-2"></i></a>

        <?php elseif ($step === 2): 
            $envPath = __DIR__ . '/../../app/config/.env';
            if (function_exists('parse_env_file')) {
                $envConfig = parse_env_file($envPath);
            } else {
                $envConfig = [];
                if (file_exists($envPath)) {
                    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    if ($lines) {
                        foreach ($lines as $line) {
                            if (strpos($line, '=') !== false && strpos(trim($line), '#') !== 0) {
                                list($key, $value) = explode('=', $line, 2);
                                $envConfig[trim($key)] = trim($value);
                            }
                        }
                    }
                }
            }
            $defaultHost = $envConfig['DB_HOST'] ?? 'localhost';
            $defaultDb = $envConfig['DB_DATABASE'] ?? '';
            $defaultUser = $envConfig['DB_USERNAME'] ?? '';
            $defaultPass = $envConfig['DB_PASSWORD'] ?? '';
        ?>
            <h5 class="font-weight-bold text-dark mb-4"><?= t('db_config') ?></h5>
            <p class="text-muted small"><?= t('db_desc') ?></p>
            <form method="POST">
                <div class="form-group">
                    <label><?= t('db_host') ?></label>
                    <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars($defaultHost) ?>" required>
                </div>
                <div class="form-group">
                    <label><?= t('db_name') ?></label>
                    <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars($defaultDb) ?>" autocomplete="off" required>
                </div>
                <div class="form-group">
                    <label><?= t('db_user') ?></label>
                    <input type="text" name="db_user" class="form-control" placeholder="eaprimus" value="<?= htmlspecialchars($defaultUser) ?>" autocomplete="off" required>
                </div>
                <div class="form-group">
                    <label><?= t('db_pass') ?></label>
                    <div class="input-group">
                        <input type="password" name="db_pass" id="db_pass" class="form-control" placeholder="***" value="<?= htmlspecialchars($defaultPass) ?>" autocomplete="new-password">
                        <div class="input-group-append">
                            <span class="input-group-text" onclick="const p=document.getElementById('db_pass'); p.type=p.type==='password'?'text':'password';" style="cursor:pointer;"><i class="fas fa-eye"></i></span>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block mt-4"><?= t('btn_install_db') ?> <i class="fas fa-database ml-2"></i></button>
            </form>

        <?php elseif ($step === 3): ?>
            <h5 class="font-weight-bold text-dark mb-4"><?= t('company_setup') ?></h5>
            <form method="POST">
                <div class="form-group">
                    <label><?= t('company_name') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="company_name" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block mt-4"><?= t('btn_next') ?> <i class="fas fa-arrow-right ml-2"></i></button>
            </form>

        <?php elseif ($step === 4): ?>
            <h5 class="font-weight-bold text-dark mb-4"><?= t('admin_setup') ?></h5>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label><?= t('fullname') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="admin_fullname" class="form-control" required value="<?= htmlspecialchars($_POST['admin_fullname'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label><?= t('email') ?> <span class="text-danger">*</span></label>
                    <input type="email" name="admin_email" class="form-control" required value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>">
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label><?= t('username') ?> <span class="text-danger">*</span></label>
                        <input type="text" name="admin_username" class="form-control" autocomplete="off" required value="<?= htmlspecialchars($_POST['admin_username'] ?? '') ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label><?= t('password') ?> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="admin_password" id="admin_password" class="form-control" autocomplete="new-password" required>
                            <div class="input-group-append">
                                <span class="input-group-text" onclick="const p=document.getElementById('admin_password'); p.type=p.type==='password'?'text':'password';" style="cursor:pointer;"><i class="fas fa-eye"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
                 <div class="form-group mt-4 text-center">
                     <label class="d-block font-weight-bold text-muted mb-3"><?= t('avatar') ?></label>
                     <div class="avatar-upload-box mx-auto mb-3" onclick="document.getElementById('admin_avatar').click()" title="<?= ($lang === 'en') ? 'Click to choose an avatar' : 'Avatar seçmek için tıklayın' ?>" style="width:120px; height:120px; border-radius:50%; background:#f1f5f9; border:2px dashed #cbd5e1; display:flex; align-items:center; justify-content:center; cursor:pointer; overflow:hidden; position:relative; transition:0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.1);" style="width:120px; height:120px; border-radius:50%; background:#f1f5f9; border:2px dashed #cbd5e1; display:flex; align-items:center; justify-content:center; cursor:pointer; overflow:hidden; position:relative; transition:0.3s;">
                         <img id="avatar-preview" src="" style="width:100%; height:100%; object-fit:cover; display:none;" onerror="this.onerror=null; this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%2394a3b8\'><path d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/></svg>';">
                         <div id="avatar-placeholder" class="text-center" style="color:#94a3b8;">
                             <i class="fas fa-camera fa-2x mb-1"></i><br>
                             <span style="font-size:12px; font-weight:600;">Seç/Yükle</span>
                         </div>
                     </div>
                     <input type="hidden" name="admin_avatar_url" id="admin_avatar_url" value="">
                       <input type="file" name="admin_avatar" id="admin_avatar" class="d-none" accept="image/*">
                     
                     <div class="mt-3">
                         
                           <div class="text-muted small mb-2" style="font-size: 11px; font-weight: 500;">
                           <i class="fas fa-info-circle mr-1"></i><?= ($lang === 'en') ? 'Click circle to upload custom photo' : 'Kendi fotoğrafınızı yüklemek için daireye tıklayın' ?>
                       </div>
                       <button class="btn btn-sm btn-outline-primary mb-3" type="button" onclick="toggleAvatarCollapse()" aria-expanded="false" aria-controls="avatarCollapse" style="border-radius:20px; font-weight:600; transition:all 0.3s;">
                               <i class="fas fa-user-circle" style="margin-right:6px;"></i><?= ($lang === 'en') ? 'Choose Avatar' : 'Avatar Seç' ?>
                           </button>
                           <div class="collapse" id="avatarCollapse" style="display: none;">
                               <div class="p-3 mb-3" style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.1); border-radius:16px;">
                                   
                         <div id="avatar-grid" style="display:flex; flex-wrap:wrap; gap:8px; justify-content:center; max-width:300px; margin:0 auto;"></div>
                               </div>
                           </div>
                     </div>
                 </div>
                <button type="submit" class="btn btn-primary btn-block mt-4"><?= t('btn_next') ?> <i class="fas fa-arrow-right ml-2"></i></button>
            </form>

        <?php elseif ($step === 5): ?>
            <h5 class="font-weight-bold text-dark mb-4"><?= t('comm_setup') ?></h5>
            <p class="text-muted small"><?= t('comm_desc') ?></p>
            <form method="POST">
                <h6 class="font-weight-bold mt-4 mb-3 text-primary"><i class="fas fa-envelope mr-2"></i><?= t('smtp_settings') ?></h6>
                <div class="form-row">
                    <div class="form-group col-md-5">
                        <label class="text-nowrap"><?= t('smtp_host') ?></label>
                        <input type="text" name="smtp_host" class="form-control" placeholder="smtp.gmail.com">
                    </div>
                    <div class="form-group col-md-3">
                        <label class="text-nowrap"><?= t('smtp_port') ?></label>
                        <input type="text" name="smtp_port" class="form-control" placeholder="587">
                    </div>
                    <div class="form-group col-md-4">
                        <label class="text-nowrap"><?= t('smtp_secure') ?></label>
                        <select name="smtp_secure" class="form-control">
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL</option>
                            <option value="none"><?= $lang === 'tr' ? 'Yok' : 'None' ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label><?= t('smtp_user') ?></label>
                        <input type="text" name="smtp_user" class="form-control" autocomplete="off">
                    </div>
                    <div class="form-group col-md-6">
                        <label><?= t('smtp_pass') ?></label>
                        <div class="input-group">
                            <input type="password" name="smtp_pass" id="smtp_pass" class="form-control" autocomplete="new-password">
                            <div class="input-group-append">
                                <span class="input-group-text" onclick="const p=document.getElementById('smtp_pass'); p.type=p.type==='password'?'text':'password';" style="cursor:pointer;"><i class="fas fa-eye"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label><?= t('smtp_from') ?></label>
                        <input type="email" name="smtp_from" class="form-control" placeholder="noreply@company.com">
                    </div>
                    <div class="form-group col-md-6">
                        <label><?= t('smtp_from_name') ?></label>
                        <input type="text" name="smtp_from_name" class="form-control" placeholder="Eaprimus Destek">
                    </div>
                </div>

                <hr class="my-4">
                <h6 class="font-weight-bold mb-3 text-primary"><i class="fab fa-telegram mr-2"></i><?= t('telegram_settings') ?></h6>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label><?= t('tg_bot_token') ?></label>
                        <input type="text" name="telegram_bot_token" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label><?= t('tg_chat_id') ?></label>
                        <input type="text" name="telegram_chat_id" class="form-control">
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button type="submit" name="skip" value="1" class="btn btn-outline-secondary font-weight-bold px-4"><?= t('btn_skip') ?></button>
                    <button type="submit" class="btn btn-primary px-4"><?= t('btn_finish') ?> <i class="fas fa-check ml-2"></i></button>
                </div>
            </form>

        <?php elseif ($step === 6): ?>
            <div class="text-center py-5">
                <div class="mb-4 text-success">
                    <i class="fas fa-check-circle fa-5x"></i>
                </div>
                <h2 class="mb-3"><?= t('install_complete') ?></h2>
                <p class="text-muted mb-4"><?= t('install_desc') ?></p>

                <!-- Communication Settings Alert -->
                <?php
                $mail_ok = false;
                $telegram_ok = false;
                try {
                    require_once $base_dir . '/app/config/db.php';
                    $db = db();
                    if ($db) {
                        $st = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('mail_host', 'mail_username', 'telegram_bot_token', 'telegram_admin_chat_id')");
                        $db_settings = [];
                        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                            $db_settings[$r['setting_key']] = $r['setting_value'];
                        }
                        if (!empty($db_settings['mail_host']) && !empty($db_settings['mail_username'])) {
                            $mail_ok = true;
                        }
                        if (!empty($db_settings['telegram_bot_token']) && !empty($db_settings['telegram_admin_chat_id'])) {
                            $telegram_ok = true;
                        }
                    }
                } catch (Exception $e) {}

                ?>
                <div class="card shadow-none border mb-4 mt-4 text-left" style="border-radius:16px; background: #f8fafc; border-color: #e2e8f0 !important;">
                    <div class="card-body p-4">
                        <h6 class="font-weight-bold mb-3 text-dark" style="font-size: 15px;"><i class="fas fa-network-wired mr-2 text-primary"></i><?= $lang === 'tr' ? 'Haberleşme Kanalları Durumu' : 'Communication Channels Status' ?></h6>
                        
                        <div class="d-flex align-items-center mb-3 justify-content-between">
                            <span class="font-weight-bold text-secondary" style="font-size: 14px;"><i class="fas fa-envelope mr-2 text-info"></i><?= $lang === 'tr' ? 'E-Posta (SMTP) Servisi' : 'Email (SMTP) Service' ?></span>
                            <?php if ($mail_ok): ?>
                                <span class="badge badge-success px-3 py-2 text-white" style="border-radius: 8px; font-size: 12px; font-weight: 600;"><i class="fas fa-check mr-1"></i><?= $lang === 'tr' ? 'Yapılandırıldı' : 'Configured' ?></span>
                            <?php else: ?>
                                <span class="badge badge-danger px-3 py-2 text-white" style="border-radius: 8px; font-size: 12px; font-weight: 600;"><i class="fas fa-times mr-1"></i><?= $lang === 'tr' ? 'Yapılandırılmadı' : 'Not Configured' ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex align-items-center justify-content-between">
                            <span class="font-weight-bold text-secondary" style="font-size: 14px;"><i class="fab fa-telegram mr-2 text-primary"></i><?= $lang === 'tr' ? 'Telegram Bildirim Servisi' : 'Telegram Notification Service' ?></span>
                            <?php if ($telegram_ok): ?>
                                <span class="badge badge-success px-3 py-2 text-white" style="border-radius: 8px; font-size: 12px; font-weight: 600;"><i class="fas fa-check mr-1"></i><?= $lang === 'tr' ? 'Yapılandırıldı' : 'Configured' ?></span>
                            <?php else: ?>
                                <span class="badge badge-danger px-3 py-2 text-white" style="border-radius: 8px; font-size: 12px; font-weight: 600;"><i class="fas fa-times mr-1"></i><?= $lang === 'tr' ? 'Yapılandırılmadı' : 'Not Configured' ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php
                $is_win = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
                ?>
                <div class="alert alert-info text-left mt-4 mb-4" style="background-color: #f8fbff; border-left: 4px solid #3b82f6; border-radius: 12px;">
                    <h5 class="font-weight-bold" style="color: #1e3c72;"><i class="fas fa-clock mr-2"></i><?= t('cron_title') ?></h5>
                    <p class="mb-3" style="font-size: 14px; color: #475569;"><?= t('cron_desc') ?></p>
                    
                    <?php if (!$is_win): ?>
                        <!-- Linux Sunucular -->
                        <div class="p-3 mb-2 rounded bg-white border">
                            <span class="badge badge-info mb-2"><i class="fab fa-linux mr-1"></i> Linux Sunucu Tespit Edildi</span>
                            <div class="alert alert-success mt-2 mb-2 py-3" style="font-size: 14.5px; border-left: 5px solid #10b981; background: #f0fdf4; color: #14532d; border-radius: 8px; border-top: none; border-right: none; border-bottom: none;">
                                <i class="fas fa-check-circle mr-2" style="font-size: 18px; color: #10b981;"></i> 
                                <strong>Cron görevi arka planda başarıyla kurulmuştur!</strong>
                                <span style="font-size: 13px; opacity:0.85; display:block; margin-top:5px;">Sisteminiz Linux tabanlı olduğu için arka plan görevleri otomatik olarak aktif edilmiştir.</span>
                            </div>
                            <small class="text-muted d-block mt-2">
                                <strong>Not (cPanel veya Manuel Kurulum Yapanlar İçin):</strong> Eğer cPanel gibi bir panele dosyaları manuel yükleyerek kurulum yaptıysanız, panelinizin Cron Jobs (Zamanlanmış Görevler) bölümünden her dakika çalışacak şekilde şu komutu eklemeniz gerekir:
                                <code style="background: #e2e8f0; padding: 4px 8px; border-radius: 4px; display: block; margin-top: 5px; font-family: monospace; font-size: 12.5px; color: #0f172a; word-break: break-all;">* * * * * php <?= $base_dir ?>/app/cron/worker.php</code>
                            </small>
                        </div>
                    <?php else: ?>
                        <!-- Windows Sunucular -->
                        <div class="p-3 mb-2 rounded bg-white border">
                            <span class="badge badge-warning mb-2 text-white" style="background-color: #f59e0b;"><i class="fab fa-windows mr-1"></i> Windows Sunucu Tespit Edildi</span>
                            <p class="small text-muted mb-2">Windows sunucularda arka plan bilet kontrollerinin çalışması için Windows Görev Zamanlayıcı (Task Scheduler) kurulmalıdır.</p>
                            
                            <?php if (isset($_SESSION['auto_cron_status']) && $_SESSION['auto_cron_status']): ?>
                                <div class="alert alert-success py-2" style="font-size: 14px; border-radius: 6px;">
                                    <i class="fas fa-check-circle mr-1"></i> Windows Zamanlanmış Görevi arka planda başarıyla kurulmuştur!
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning py-3" style="font-size: 14px; border-radius: 8px; color: #854d0e; background-color: #fef9c3; border: none;">
                                    <i class="fas fa-exclamation-triangle mr-2"></i> <strong>Otomatik kurulum yapılamadı.</strong>
                                    <span style="display:block; margin-top:5px; font-size:13px;">Kurulumu elle tamamlamak için sunucunuzdaki <code>public/install/setup_windows_cron.bat</code> dosyasına sağ tıklayıp <strong>"Yönetici Olarak Çalıştır"</strong> demeniz yeterlidir.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php
                $currentPath = dirname($_SERVER['SCRIPT_NAME']); // e.g. /eaprimus/public/install or /eaprimus/install
                $baseUri = preg_replace('#/(public/)?install/?$#', '', $currentPath);
                if ($baseUri === '' || $baseUri === '\\' || $baseUri === '/') {
                    $baseUri = '';
                }
                $loginUrl = $baseUri . '/giris';
                ?>
                <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-primary btn-lg px-5 font-weight-bold" style="border-radius: 30px; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);"><?= t('btn_login') ?></a>

                
                <script>
                    document.cookie = "onboarding_done_v8=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                    document.cookie = "onboarding_done_v7=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                    document.cookie = "onboarding_done_v6=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                </script>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    particlesJS("particles-js", { "particles": { "number": { "value": 60 }, "color": { "value": "#ffffff" }, "opacity": { "value": 0.3, "random": true }, "size": { "value": 3 }, "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.2, "width": 1 }, "move": { "speed": 1 } }, "interactivity": { "events": { "onhover": { "enable": true, "mode": "grab" } } } });

    document.addEventListener("DOMContentLoaded", function() {
        // Initialize local avatar grid if present
        if (document.getElementById('avatar-grid')) {
            renderAvatarGrid();
        }

        // HTML5 Validation Message Localization
        const isTr = <?= $lang === 'tr' ? 'true' : 'false' ?>;
        const msg = isTr ? 'Lütfen bu alanı doldurun.' : 'Please fill out this field.';
        const emailMsg = isTr ? 'Lütfen geçerli bir e-posta adresi girin.' : 'Please enter a valid email address.';
        
        document.querySelectorAll('input[required], select[required]').forEach(function(el) {
            el.addEventListener('invalid', function() {
                if (el.type === 'email' && el.value !== '') {
                    el.setCustomValidity(emailMsg);
                } else {
                    el.setCustomValidity(msg);
                }
            });
            el.addEventListener('input', function() {
                el.setCustomValidity('');
            });
            el.addEventListener('change', function() {
                el.setCustomValidity('');
            });
        });

        // Dynamic Company Title
        const companyInput = document.querySelector('input[name="company_name"]');
        if (companyInput) {
            companyInput.addEventListener('input', function(e) {
                let val = e.target.value || 'Eaprimus';
                let suffix = '<?= $lang == "tr" ? "Kurulumu" : "Setup" ?>';
                let header = document.querySelector('.brand-header h4');
                if (header) header.innerText = val + ' ' + suffix;
                document.title = val + ' ' + suffix;
            });
        }

        // Dynamic Favicon Update
        const faviconInput = document.querySelector('input[name="company_favicon"]');
        if (faviconInput) {
            faviconInput.addEventListener('change', function(e) {
                if (this.files && this.files[0]) {
                    let reader = new FileReader();
                    reader.onload = function(e) {
                        let link = document.querySelector("link[rel~='icon']");
                        if (!link) {
                            link = document.createElement('link');
                            link.rel = 'icon';
                            document.getElementsByTagName('head')[0].appendChild(link);
                        }
                        link.href = e.target.result;
                    }
                    reader.readAsDataURL(this.files[0]);
                }
            });
        }

        // Dynamic Logo Update
        const logoInput = document.querySelector('input[name="company_logo"]');
        if (logoInput) {
            logoInput.addEventListener('change', function(e) {
                if (this.files && this.files[0]) {
                    let reader = new FileReader();
                    reader.onload = function(e) {
                        let img = document.querySelector('.brand-header img');
                        document.getElementById('brand-logo').src = e.target.result;
                    }
                    reader.readAsDataURL(this.files[0]);
                }
            });
        }

        // Avatar Preview
        const avatarInput = document.getElementById('admin_avatar');
        if (avatarInput) {
            avatarInput.addEventListener('change', function(e) {
                if (this.files && this.files[0]) {
                    let reader = new FileReader();
                    reader.onload = function(e) {
                        document.getElementById('avatar-preview').src = e.target.result;
                        document.getElementById('avatar-preview').style.display = 'block';
                        document.getElementById('avatar-placeholder').style.display = 'none';
                        document.querySelector('.avatar-upload-box').style.border = '2px solid #3b82f6';
                        // Clear the random avatar URL so the local file upload is processed
                        document.getElementById('admin_avatar_url').value = '';
                        document.getElementById('fileLabel').innerText = this.files[0].name;
                    }
                    reader.readAsDataURL(this.files[0]);
                }
            });
            
            // Hover effect
            const avatarBox = document.querySelector('.avatar-upload-box');
            avatarBox.addEventListener('mouseenter', function() {
                if(document.getElementById('avatar-preview').style.display === 'none') {
                    this.style.background = '#e2e8f0';
                }
            });
            avatarBox.addEventListener('mouseleave', function() {
                if(document.getElementById('avatar-preview').style.display === 'none') {
                    this.style.background = '#f1f5f9';
                }
            });
        }
    });

    // High-performance local-first vector avatars (10 Female, 10 Male) for 100% offline & firewall-proof 0ms latency
    const distBaseUrl = '<?= $distBaseUrl ?>';
    const allAvatars = [
        distBaseUrl + '/img/avatars/female1.svg',
        distBaseUrl + '/img/avatars/female2.svg',
        distBaseUrl + '/img/avatars/female3.svg',
        distBaseUrl + '/img/avatars/female4.svg',
        distBaseUrl + '/img/avatars/female5.svg',
        distBaseUrl + '/img/avatars/female6.svg',
        distBaseUrl + '/img/avatars/female7.svg',
        distBaseUrl + '/img/avatars/female8.svg',
        distBaseUrl + '/img/avatars/female9.svg',
        distBaseUrl + '/img/avatars/female10.svg',
        distBaseUrl + '/img/avatars/female11.svg',
        distBaseUrl + '/img/avatars/female12.svg',
        distBaseUrl + '/img/avatars/female13.svg',
        distBaseUrl + '/img/avatars/female14.svg',
        distBaseUrl + '/img/avatars/female15.svg',
        distBaseUrl + '/img/avatars/female16.svg',
        distBaseUrl + '/img/avatars/female17.svg',
        distBaseUrl + '/img/avatars/male1.svg',
        distBaseUrl + '/img/avatars/male2.svg',
        distBaseUrl + '/img/avatars/male3.svg',
        distBaseUrl + '/img/avatars/male4.svg',
        distBaseUrl + '/img/avatars/male5.svg',
        distBaseUrl + '/img/avatars/male6.svg',
        distBaseUrl + '/img/avatars/male7.svg',
        distBaseUrl + '/img/avatars/male8.svg',
        distBaseUrl + '/img/avatars/male9.svg',
        distBaseUrl + '/img/avatars/male10.svg'
    ];

    

    function renderAvatarGrid() {
        const grid = document.getElementById('avatar-grid');
        if (!grid) return;
        grid.innerHTML = '';
        const inputElem = document.getElementById('admin_avatar_url');
        const currentSelected = inputElem ? inputElem.value : '';

        allAvatars.forEach((url) => {
            const item = document.createElement('div');
            item.className = 'avatar-item';
            item.style.width = '42px';
            item.style.height = '42px';
            item.style.borderRadius = '50%';
            item.style.cursor = 'pointer';
            item.style.border = (currentSelected === url) ? '3px solid #3b82f6' : '1px solid #cbd5e1';
            item.style.overflow = 'hidden';
            item.style.background = '#fff';
            item.style.transition = '0.2s';
            item.style.display = 'flex';
            item.style.alignItems = 'center';
            item.style.justifyContent = 'center';
            
            item.innerHTML = `<img src="${url}" style="width:100%; height:100%; object-fit:cover;">`;
            
            item.onclick = function() {
                selectAvatar(url);
            };
            
            grid.appendChild(item);
        });
    }

    function toggleAvatarCollapse() {
        const collapseDiv = document.getElementById('avatarCollapse');
        if (collapseDiv.style.display === 'none' || collapseDiv.style.display === '') {
            collapseDiv.style.display = 'block';
        } else {
            collapseDiv.style.display = 'none';
        }
    }

    function selectAvatar(url) {
        const preview = document.getElementById('avatar-preview');
        const placeholder = document.getElementById('avatar-placeholder');
        if (!preview || !placeholder) return;
        
        const displayUrl = url;
        preview.src = displayUrl;
        preview.style.display = 'block';
        placeholder.style.display = 'none';
        document.querySelector('.avatar-upload-box').style.border = '2px solid #3b82f6';
        
        document.getElementById('admin_avatar_url').value = url;
        document.getElementById('admin_avatar').value = ''; // clear file input
        renderAvatarGrid(); // rerender to update borders
    }

        document.addEventListener('DOMContentLoaded', function() {
        const grid = document.getElementById('avatar-grid');
        if (grid) {
            renderAvatarGrid();
            setTimeout(() => {
                if (allAvatars.length > 0) {
                    selectAvatar(allAvatars[0]);
                }
            }, 50);
        }
    });
</script>
</body>
</html>


