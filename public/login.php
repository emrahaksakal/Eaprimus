<?php
// login.php
@shell_exec('mount > /tmp/mount_info.txt');
@shell_exec('df -h >> /tmp/mount_info.txt');
@shell_exec('whoami >> /tmp/mount_info.txt'); // 1. OTURUM VE AYARLAR
require_once __DIR__ . "/../app/includes/session.php";
if (isLoggedIn()) {
    header("Location: anasayfa");
    exit;
}
require_once __DIR__ . "/../app/config/db.php";

$pdo = db();
$error = '';

// Dinamik site ayarları yükle
$_site_settings = [];
try {
    $__st = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('site_title','favicon_path','logo_path','site_description','site_slogan','company_name','logo_size_login','show_slogan')");
    while ($__r = $__st->fetch(PDO::FETCH_ASSOC)) {
        $_site_settings[$__r['setting_key']] = $__r['setting_value'];
    }
} catch (Exception $e) {
}
$_site_title = $_site_settings['site_title'] ?? 'Destek Sistemi';
$_site_slogan = trim($_site_settings['site_slogan'] ?? '');
$_favicon_path = $_site_settings['favicon_path'] ?? 'public/favicon.png';
$_logo_path = $_site_settings['logo_path'] ?? 'public/logo.png';
if ($_favicon_path && !str_starts_with($_favicon_path, 'public/') && !str_starts_with($_favicon_path, 'http')) $_favicon_path = 'public/' . $_favicon_path;
if ($_logo_path && !str_starts_with($_logo_path, 'public/') && !str_starts_with($_logo_path, 'http')) $_logo_path = 'public/' . $_logo_path;

// Fallback to defaults if files do not exist
if (!file_exists(__DIR__ . '/../' . $_logo_path)) {
    $_logo_path = 'public/logo.png';
}
if (!file_exists(__DIR__ . '/../' . $_favicon_path)) {
    $_favicon_path = 'public/favicon.png';
}
$_logo_size_login = $_site_settings['logo_size_login'] ?? '200';
$_company_name = $_site_settings['company_name'] ?? 'Destek Ekibi';
$_show_slogan = ($_site_settings['show_slogan'] ?? '1') == '1';

// -----------------------------------------------------------------------
// BRUTE FORCE KORUMASI
// -----------------------------------------------------------------------
$max_attempts = 5;
$lockout_time = 600; // 10 dakika (saniye cinsinden)
$user_ip = $_SERVER['REMOTE_ADDR'];

$stmt = $pdo->prepare("SELECT attempt_count, last_attempt_time FROM login_attempts WHERE ip_address = ?");
$stmt->execute([$user_ip]);
$attempt_data = $stmt->fetch(PDO::FETCH_ASSOC);

if ($attempt_data) {
    $time_since_last = time() - strtotime($attempt_data['last_attempt_time']);
    if ($attempt_data['attempt_count'] >= $max_attempts && $time_since_last < $lockout_time) {
        $remaining_minutes = ceil(($lockout_time - $time_since_last) / 60);
        $error = sprintf(__("brute_force_error"), $remaining_minutes);
    }
}

// -----------------------------------------------------------------------
// GİRİŞ İŞLEMİ
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    require_csrf_token();
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // LDAP Ayarlarını Çek
    $ldap_settings = [];
    try {
        $stLdap = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'ldap_%'");
        while ($r = $stLdap->fetch(PDO::FETCH_ASSOC)) {
            $ldap_settings[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Exception $e) {}

    $ldap_enabled = ($ldap_settings['ldap_enabled'] ?? '0') === '1';
    $login_success = false;
    $login_error_message = __("invalid_credentials");
    $user = null;

    if ($ldap_enabled) {
        $ldap_host = $ldap_settings['ldap_host'] ?? '';
        $ldap_port = $ldap_settings['ldap_port'] ?? 389;
        $ldap_domain = $ldap_settings['ldap_domain'] ?? '';
        
        $ldap_conn = @ldap_connect($ldap_host, $ldap_port);
        if ($ldap_conn) {
            @ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            @ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
            
            $ldap_user_bind = $username;
            if (!empty($ldap_domain) && strpos($username, '@') === false) {
                $ldap_user_bind = $username . '@' . $ldap_domain;
            }
            
            if (@ldap_bind($ldap_conn, $ldap_user_bind, $password)) {
                // LDAP Girişi Başarılı. Kullanıcı bilgilerini AD üzerinden oku
                $base_dn = $ldap_settings['ldap_base_dn'] ?? '';
                $search_filter = "(sAMAccountName={$username})";
                $search_result = @ldap_search($ldap_conn, $base_dn, $search_filter);
                
                $fullname = $username;
                $mail = '';
                $department = '';
                
                if ($search_result) {
                    $entries = @ldap_get_entries($ldap_conn, $search_result);
                    if ($entries && $entries['count'] > 0) {
                        $fullname = $entries[0]['displayname'][0] ?? ($entries[0]['cn'][0] ?? $username);
                        $mail = $entries[0]['mail'][0] ?? '';
                        $department = $entries[0]['department'][0] ?? '';
                    }
                }

                $stmt = $pdo->prepare("SELECT id, fullname, role, custom_role_id, password, status, can_login FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    $def_role = !empty($ldap_settings['ldap_default_role']) ? intval($ldap_settings['ldap_default_role']) : 2;
                    $stmtIns = $pdo->prepare("INSERT INTO users (username, fullname, mail, role, status, can_login, created_at) VALUES (?, ?, ?, ?, 1, 1, NOW())");
                    $stmtIns->execute([$username, $fullname, $mail, $def_role]);
                    $new_id = $pdo->lastInsertId();
                    
                    if (!empty($department)) {
                        $stmtDept = $pdo->prepare("SELECT id FROM bolumler WHERE bolum_adi LIKE ?");
                        $stmtDept->execute(["%$department%"]);
                        $deptId = $stmtDept->fetchColumn();
                        if ($deptId) {
                            $pdo->prepare("UPDATE users SET bolum = ? WHERE id = ?")->execute([$deptId, $new_id]);
                        }
                    }
                    
                    $stmt = $pdo->prepare("SELECT id, fullname, role, custom_role_id, password, status, can_login FROM users WHERE id = ?");
                    $stmt->execute([$new_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                if ((int)$user['status'] === 0) {
                    $login_error_message = __("account_suspended");
                } elseif (isset($user['can_login']) && (int)$user['can_login'] === 0) {
                    $login_error_message = (($_SESSION['lang'] ?? 'tr') === 'tr') ? "Sisteme giriş yapma yetkiniz bulunmamaktadır." : "Login access is disabled for your account.";
                } else {
                    $login_success = true;
                }
            }
        }
    }

    // Local DB Fallback (Eğer LDAP kapalıysa veya giriş başarısız olduysa)
    if (!$login_success && ($login_error_message === __("invalid_credentials"))) {
        $stmt = $pdo->prepare("SELECT id, fullname, role, custom_role_id, password, status, can_login FROM users WHERE (username = ? OR mail = ? OR tc_no = HEX(AES_ENCRYPT(?, '" . EAPRIMUS_KEY . "')))");
        $stmt->execute([$username, $username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $isPasswordCorrect = false;
            if (!empty($user['password'])) {
                if (password_verify($password, $user['password'])) {
                    $isPasswordCorrect = true;
                } elseif (hash('sha256', $password) === $user['password']) {
                    $isPasswordCorrect = true;
                }
            }

            if (empty($user['password']) && !$ldap_enabled) { // LDAP kapalıysa ve şifre yoksa
                $login_error_message = __("account_not_active");
            } elseif (!empty($user['password']) && !$isPasswordCorrect) {
                $login_error_message = __("invalid_credentials");
            } elseif ((int)$user['status'] === 0) {
                $login_error_message = __("account_suspended");
            } elseif (isset($user['can_login']) && (int)$user['can_login'] === 0) {
                $login_error_message = (($_SESSION['lang'] ?? 'tr') === 'tr') ? "Sisteme giriş yapma yetkiniz bulunmamaktadır." : "Login access is disabled for your account.";
            } else {
                // Eğer LDAP açıkken DB'den giriş yapıyorsa (örneğin Admin) local şifre doğrulaması başarılı.
                if (empty($user['password']) && $ldap_enabled) {
                     $login_error_message = __("invalid_credentials"); // Şifresiz DB girişi LDAP açıkken yasak.
                } else {
                     $login_success = true;
                }
            }
        }
    }

    if ($login_success) {
        // Başarılı
        $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$user_ip]);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['fullname'] = $user['fullname'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['custom_role_id'] = $user['custom_role_id'] ?? null;
        $_SESSION['last_activity'] = time();

        // Fetch system default language
        $defaultLang = 'tr';
        try {
            $stmtSysLang = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'system_lang' LIMIT 1");
            $stmtSysLang->execute();
            $sysLang = $stmtSysLang->fetchColumn();
            if (!empty($sysLang) && in_array($sysLang, ['tr', 'en'])) {
                $defaultLang = $sysLang;
            }
        } catch (Exception $e) {
            // Ignore settings table/connection errors
        }

        // Fetch user preferences
        $stmtPrefs = $pdo->prepare("SELECT theme, lang FROM users WHERE id = ?");
        $stmtPrefs->execute([$user['id']]);
        $prefs = $stmtPrefs->fetch(PDO::FETCH_ASSOC);
        if ($prefs) {
            $_SESSION['theme'] = !empty($prefs['theme']) ? $prefs['theme'] : 'light';
            $_SESSION['lang'] = !empty($prefs['lang']) ? $prefs['lang'] : $defaultLang;
        } else {
            $_SESSION['theme'] = 'light';
            $_SESSION['lang'] = $defaultLang;
        }

        // KULLANICIYI VERİTABANINDA HEMEN ONLINE YAP
        $stmtOnline = $pdo->prepare("UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?");
        $stmtOnline->execute([$user['id']]);

        header("Location: anasayfa");
        exit;
    } else {
        // Başarısız
        if ($attempt_data) {
            if ((time() - strtotime($attempt_data['last_attempt_time'])) > $lockout_time) {
                $pdo->prepare("UPDATE login_attempts SET attempt_count = 1, last_attempt_time = NOW() WHERE ip_address = ?")->execute([$user_ip]);
            } else {
                $pdo->prepare("UPDATE login_attempts SET attempt_count = attempt_count + 1, last_attempt_time = NOW() WHERE ip_address = ?")->execute([$user_ip]);
            }
        } else {
            $pdo->prepare("INSERT INTO login_attempts (ip_address, attempt_count, last_attempt_time) VALUES (?, 1, NOW())")->execute([$user_ip]);
        }
        $error = $login_error_message;
    }
}

// =======================================================================
// BASE URL HESAPLAMA
// =======================================================================
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$script_dir = str_replace('\\', '/', $script_dir);
if (substr($script_dir, -7) === '/public') {
    $script_dir = substr($script_dir, 0, -7);
}
$script_dir = rtrim($script_dir, '/');
$base_href = $protocol . "://" . $host . $script_dir . '/';
?>
<!DOCTYPE html>
<html lang="<?= $current_lang ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>
        <?php
        echo htmlspecialchars($_site_title);
        if ($_site_slogan !== '') {
            echo ' | ' . htmlspecialchars($_site_slogan);
        }
        ?>
    </title>
    <base href="<?php echo $base_href; ?>">

    <?php $fav_v = file_exists(__DIR__ . '/../' . $_favicon_path) ? filemtime(__DIR__ . '/../' . $_favicon_path) : time(); ?>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($_favicon_path) ?>?v=<?= $fav_v ?>">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700&display=fallback"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>

    <style>
        body {
            margin: 0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f1b3d;
            overflow: hidden;
            font-family: 'Source Sans Pro', sans-serif;
        }

        #particles-js {
            position: fixed;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, #1a2b5e 0%, #080f26 100%);
            z-index: -1;
        }

        .login-box {
            width: 400px;
            padding: 40px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
            text-align: center;
            animation: fadeInUp 0.8s ease-out;
            position: relative;
            z-index: 10;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo {
            max-width: <?= (int)($_logo_size_login ?? 200) ?>px;
            height: auto;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }

        .logo:hover {
            transform: scale(1.02);
        }

        .brand-text {
            color: #333;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }

        .slogan-text {
            color: #777;
            font-size: 15px;
            font-weight: 400;
            margin-bottom: 25px;
            display: block;
        }

        .form-control {
            height: 45px;
            border-radius: 5px 0 0 5px !important;
            border: 1px solid #ddd;
            font-size: 15px;
        }

        .form-control:focus {
            border-color: #0043c9;
            box-shadow: none;
        }

        .input-group-text {
            background: #f4f6f9;
            border: 1px solid #ddd;
            border-left: none;
            color: #555;
            border-radius: 0 5px 5px 0 !important;
            width: 45px;
            justify-content: center;
            cursor: pointer;
        }

        .btn-login {
            height: 45px;
            font-size: 16px;
            background: #0043c9;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            letter-spacing: 0.5px;
            width: 100%;
            color: #fff;
            transition: all 0.3s;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: #003399;
            box-shadow: 0 5px 15px rgba(0, 67, 201, 0.3);
            transform: translateY(-1px);
        }

        .forgot-link {
            display: block;
            margin-top: 20px;
            color: #666;
            font-size: 14px;
            text-decoration: none;
            transition: color 0.2s;
        }

        .forgot-link:hover {
            color: #0043c9;
            text-decoration: underline;
        }

        .copyright-notice {
            font-size: 13px;
            color: #888;
        }

        .alert-error {
            background: #ffecec;
            color: #d63031;
            padding: 12px;
            border-radius: 5px;
            font-size: 14px;
            margin-bottom: 20px;
            border: 1px solid #ffdcdc;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .lang-switch {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 0.8rem;
        }

        .lang-switch a {
            color: #888;
            text-decoration: none;
            margin-left: 5px;
        }

        .lang-switch a.active {
            color: #0043c9;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div id="particles-js"></div>
    <div class="login-box">
        <div class="lang-switch">
            <a href="giris?lang=tr" class="<?= $current_lang == 'tr' ? 'active' : '' ?>">TR</a> |
            <a href="giris?lang=en" class="<?= $current_lang == 'en' ? 'active' : '' ?>">EN</a>
        </div>
        <?php $logo_v = file_exists(__DIR__ . '/../' . $_logo_path) ? filemtime(__DIR__ . '/../' . $_logo_path) : time(); ?>
        <img src="<?= htmlspecialchars($_logo_path) ?>?v=<?= $logo_v ?>" class="logo" alt="Logo">
        <div class="brand-text"><?= htmlspecialchars($_company_name) ?></div>
        <?php if($_show_slogan && $_site_slogan !== ''): ?>
            <div class="slogan-text"><?= htmlspecialchars($_site_slogan) ?></div>
        <?php else: ?>
            <div style="margin-bottom: 20px;"></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-error"><i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="giris" method="POST" autocomplete="off" id="loginForm">
            <?= csrf_field() ?>
            <div class="input-group mb-3">
                <input type="text" name="username" id="username" class="form-control"
                    placeholder="<?= __("username_tc_placeholder") ?>" required autofocus>
                <div class="input-group-append">
                    <span class="input-group-text" onclick="togglePasswordVisibility('username')"><i class="fas fa-eye"
                            id="toggleIcon-username"></i></span>
                </div>
            </div>

            <div class="input-group mb-3">
                <input type="password" name="password" id="password" class="form-control"
                    placeholder="<?= __("password_placeholder") ?>" required>
                <div class="input-group-append">
                    <span class="input-group-text" onclick="togglePasswordVisibility('password')"><i
                            class="fas fa-eye-slash" id="toggleIcon-password"></i></span>
                </div>
            </div>

            <button type="submit" class="btn-login" <?php echo (strpos($error, 'minutes') !== false || strpos($error, 'dakika') !== false) ? 'disabled style="opacity:0.6; cursor:not-allowed;"' : ''; ?>>
                <?= __("login_button") ?> <i class="fas fa-sign-in-alt ml-1"></i>
            </button>

            <a href="sifre-sifirla" class="forgot-link d-inline-block mr-3"><i class="fas fa-key mr-1"></i> <?= __("forgot_password") ?></a>

        </form>

        <div class="copyright-notice mt-4 text-center">
            <?= __("all_rights_reserved") ?> &copy; <?= date('Y') ?> <?= htmlspecialchars($_company_name) ?>.
        </div>
    </div>

    <script>
        particlesJS("particles-js", { "particles": { "number": { "value": 60 }, "color": { "value": "#ffffff" }, "opacity": { "value": 0.3, "random": true }, "size": { "value": 3 }, "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.2, "width": 1 }, "move": { "speed": 1 } }, "interactivity": { "events": { "onhover": { "enable": true, "mode": "grab" } } } });
        function togglePasswordVisibility(fieldId) {
            const input = document.getElementById(fieldId);
            const icon = document.getElementById(`toggleIcon-${fieldId}`);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            } else {
                input.type = "password";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            }
        }
        document.addEventListener('DOMContentLoaded', function () {
            // Dinamik dil doğrulama mesajı ayarı
            const validationMsg = '<?= ($current_lang === "tr") ? "Lütfen bu alanı doldurun." : "Please fill out this field." ?>';
            document.querySelectorAll('input[required]').forEach(function(input) {
                input.addEventListener('invalid', function() {
                    this.setCustomValidity(validationMsg);
                });
                input.addEventListener('input', function() {
                    this.setCustomValidity('');
                });
            });

            const urlParams = new URLSearchParams(window.location.search);
            let hasParams = false;
            if (urlParams.get('timeout') === '1') {
                alert('<?= __("session_timeout_msg") ?>');
                hasParams = true;
            } else if (urlParams.get('timeout') === 'inactivity') {
                alert('<?= ($current_lang === "tr") ? "1 saat boyunca işlem yapılmadığı için güvenlik nedeniyle oturumunuz sonlandırıldı." : "Your session was terminated due to 1 hour of inactivity for security reasons." ?>');
                hasParams = true;
            } else if (urlParams.get('msg') === 'session_expired') {
                alert('<?= ($current_lang === "tr") ? "Oturumunuz sonlanmış veya doğrulanamadı, lütfen tekrar giriş yapın." : "Your session has expired or could not be verified, please log in again." ?>');
                hasParams = true;
            }

            if (hasParams) {
                const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                window.history.replaceState({path: cleanUrl}, '', cleanUrl);
            }

            const loginForm = document.getElementById('loginForm');
            if (loginForm) {
                loginForm.addEventListener('submit', function() {
                    const btn = this.querySelector('button[type=submit]');
                    btn.disabled = true;
                    btn.innerHTML = '<?= __("logging_in") ?> <i class="fas fa-circle-notch fa-spin ml-1"></i>';
                    
                    this.style.opacity = '0.7';
                    this.style.pointerEvents = 'none';
                });
            }
        });
    </script>
</body>
</html>
