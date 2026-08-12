<?php
// reset-password.php

// Oturum yÃ¶netimini merkezi 'session.php' Ã¼zerinden baÅŸlat
require_once __DIR__ . "/../app/includes/session.php";
if (isLoggedIn()) {
    header("Location: anasayfa");
    exit;
}
require_once __DIR__ . '/../app/config/db.php';

if (!isset($pdo)) {
    $pdo = db();
}

$message = "";
$messageType = "";
// AdÄ±mÄ± session'dan al
$step = $_SESSION['reset_step'] ?? 1;
$current_email = $_SESSION['reset_email'] ?? '';

// Auto-invalidate code if expired
if ($step == 2 && isset($_SESSION['reset_expires']) && time() > $_SESSION['reset_expires']) {
    if (!empty($current_email)) {
        $pdo->prepare("UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE mail = ?")->execute([$current_email]);
    }
    unset($_SESSION['reset_step'], $_SESSION['reset_email'], $_SESSION['reset_expires'], $_SESSION['last_code_sent'], $_SESSION['local_reset_code']);
    $msg = ($current_lang === 'tr') ? "Doğrulama kodunun süresi doldu. Lütfen yeni bir kod talep edin." : "Verification code has expired. Please request a new code.";
    $_SESSION['message'] = $msg;
    $_SESSION['messageType'] = "error";
    session_write_close();
    header("Location: sifre-sifirla");
    exit;
}

// =======================================================================
// YENÄ° AKIÅž: TEKRAR GÃ–NDER & URL Ä°ÅžLEMLERÄ°
// =======================================================================
if (isset($_GET['step']) && $_GET['step'] == 2 && isset($_GET['email'])) {
    $_SESSION['reset_step'] = 2;
    $_SESSION['reset_email'] = $_GET['email'];
    $_SESSION['reset_expires'] = time() + (2 * 60);
    header("Location: sifre-sifirla");
    exit;
}

if (isset($_GET['cancel']) && $_GET['cancel'] == 1) {
    if (!empty($_SESSION['reset_email'])) {
        $pdo->prepare("UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE mail = ?")->execute([$_SESSION['reset_email']]);
    }
    unset($_SESSION['reset_step'], $_SESSION['reset_email'], $_SESSION['reset_expires'], $_SESSION['last_code_sent'], $_SESSION['local_reset_code']);
    session_write_close();
    header("Location: giris");
    exit;
}

if (isset($_GET['reset']) && $_GET['reset'] == 1) {
    if (isset($_SESSION['reset_email'])) {
        $_POST['action'] = 'send_code';
        $_POST['email'] = $_SESSION['reset_email'];
    } else {
        session_destroy();
        session_write_close();
        header("Location: sifre-sifirla");
        exit;
    }
}

// =======================================================================
// ADIM 1: KOD GÃ–NDERME
// =================================================================================================
if (isset($_POST['action']) && $_POST['action'] == 'send_code') {
    require_csrf_token();
    $email = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = __("invalid_email_error");
        $messageType = "error";
        $_SESSION['reset_step'] = 1;
    } else {
        $stmt = $pdo->prepare("SELECT id, fullname FROM users WHERE mail = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // ÅÄ°FRE SIFIRLAMA SPAM ENGELÄ° (2 Dakika)
            if (isset($_SESSION['last_code_sent']) && (time() - $_SESSION['last_code_sent']) < 120) {
                $wait = 120 - (time() - $_SESSION['last_code_sent']);
                $message = sprintf(__("spam_wait_error"), $wait);
                $messageType = "error";
                $_SESSION['reset_step'] = 1;
                if (isset($_SESSION['reset_email']) && $_SESSION['reset_email'] == $email && isset($_SESSION['reset_expires']) && $_SESSION['reset_expires'] > time()) {
                    $_SESSION['reset_step'] = 2;
                    header("Location: sifre-sifirla");
                    exit;
                }
            } else {
                $code = rand(100000, 999999);
                $expires_time = time() + (2 * 60);
                $expires_date = date("Y-m-d H:i:s", $expires_time);

                $update = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                $update->execute([$code, $expires_date, $user['id']]);

                // MAİL GÖNDER
                $mailHelper = __DIR__ . '/../app/includes/mailer.php';
                if (file_exists($mailHelper))
                    require_once $mailHelper;

                try {
                    $defL = s('mail_default_lang', 'tr');
                    $dbBody = s('mail_password_reset_' . $defL . '_body') ?: s('mail_password_reset_body') ?: s('mail_password_reset_tr_body');
                    
                    $site_url = s('site_url') ?: 'http://localhost';
                    $logo_path = s('logo_path') ?: 'logo.png';
                    $logo_url = rtrim($site_url, '/') . '/public/' . $logo_path;
                    $site_name = s('site_title') ?: 'Destek Sistemi';
                    $reset_link = rtrim($site_url, '/') . "/sifre-sifirla?step=2&email=" . urlencode($email);
                    
                    if ($dbBody) {
                        $dbBody = html_entity_decode($dbBody, ENT_QUOTES, 'UTF-8');
                        $finalBody = str_replace(['{{code}}', '{{reset_link}}', '{{LOGO_SRC}}', '{{SITE_TITLE}}'], [$code, $reset_link, $logo_url, $site_name], $dbBody);
                        $subject = s('mail_password_reset_subject') ?: s('mail_password_reset_tr_subject') ?: sprintf(__("reset_mail_subject"), $code);
                    } else {
                        $contentHtml = "<p>" . sprintf(__("reset_mail_greeting"), "<strong>{$user['fullname']}</strong>") . "</p>";
                        $contentHtml .= "<p>" . __("reset_mail_instruction") . "</p>";
                        $contentHtml .= "<div style='background-color:#eef2ff; color:#0043c9; font-size:32px; font-weight:bold; letter-spacing:8px; padding:15px; border-radius:6px; text-align:center; margin:20px 0; border:2px dashed #0043c9;'>{$code}</div>";
                        $contentHtml .= "<p style='color:#d9534f; font-weight:bold; font-size:14px; text-align:center;'>" . __("reset_mail_warning") . "</p>";
                        $contentHtml .= "<p style='font-size:13px; color:#555; text-align:center;'>" . __("reset_mail_footer") . "</p>";
    
                        $finalBody = function_exists('buildMailTemplate') ? buildMailTemplate($contentHtml) : $contentHtml;
                        $subject = sprintf(__("reset_mail_subject"), $code);
                    }

                    $sent = false;
                    if (function_exists('sendEaprimusMail')) {
                        $sent = sendEaprimusMail($email, $user['fullname'], $subject, $finalBody);
                    }

                    if (!$sent) {
                        throw new Exception("Mail sunucusu yanıt vermedi veya ayarlar hatalı.");
                    }

                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_step'] = 2;
                    $_SESSION['reset_expires'] = $expires_time;
                    $_SESSION['last_code_sent'] = time();

                    header("Location: " . rtrim($site_url, '/') . "/sifre-sifirla");
                    exit;

                } catch (Exception $e) {
                    if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1') {
                        $_SESSION['reset_email'] = $email;
                        $_SESSION['reset_step'] = 2;
                        $_SESSION['reset_expires'] = $expires_time;
                        $_SESSION['last_code_sent'] = time();
                        $_SESSION['local_reset_code'] = $code;
                        $_SESSION['message'] = "Localhost uyarısı: E-posta gönderilemedi, ancak doğrulama kodunuz: " . $code;
                        $_SESSION['messageType'] = "error";
                        header("Location: " . $page_url);
                        exit;
                    } else {
                        $message = "Mail hatası: " . $e->getMessage();
                        $messageType = "error";
                        $_SESSION['reset_step'] = 1;
                    }
                }
            }
        } else {
            $message = __("email_not_registered");
            $messageType = "error";
            $_SESSION['reset_step'] = 1;
        }
    }
}
// =======================================================================
// ADIM 2: KOD DOĞRULAMA
// =======================================================================
if (isset($_POST['action']) && $_POST['action'] == 'verify_code') {
    require_csrf_token();
    $code_input = trim($_POST['code']);
    $email = $_SESSION['reset_email'] ?? '';

    if (empty($email)) {
        header("Location: " . $page_url . "?reset=1");
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE mail = ? AND reset_token = ?");
    $stmt->execute([$email, $code_input]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        if (time() > strtotime($user['reset_expires'])) {
            $message = __("code_expired");
            $messageType = "error";
            $_SESSION['reset_step'] = 2;
        } else {
            $_SESSION['reset_step'] = 3;
            $_SESSION['message'] = __("code_verified_new_pass");
            $_SESSION['messageType'] = "success";
            header("Location: " . $page_url);
            exit;
        }
    } else {
        $message = __("invalid_code");
        $messageType = "error";
        $_SESSION['reset_step'] = 2;
    }
}

// =======================================================================
// ADIM 3: ŞİFRE DEĞİŞTİRME
// =======================================================================
if (isset($_POST['action']) && $_POST['action'] == 'new_password') {
    require_csrf_token();
    $pass1 = $_POST['password'];
    $pass2 = $_POST['password_confirm'];
    $email = $_SESSION['reset_email'] ?? '';

    $is_long_enough = strlen($pass1) >= 8;
    $has_uppercase = preg_match('/[A-Z]/', $pass1);
    $has_lowercase = preg_match('/[a-z]/', $pass1);

    $stmtCheck = $pdo->prepare("SELECT password FROM users WHERE mail = ?");
    $stmtCheck->execute([$email]);
    $userCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    $newHash = hash('sha256', $pass1);

    if ($pass1 !== $pass2) {
        $message = __("password_mismatch");
        $messageType = "error";
        $_SESSION['reset_step'] = 3;
    } elseif (!$is_long_enough || !$has_uppercase || !$has_lowercase) {
        $message = __("password_complexity_error");
        $messageType = "error";
        $_SESSION['reset_step'] = 3;
    } elseif ($userCheck && $userCheck['password'] === $newHash) {
        $message = __("password_same_as_old");
        $messageType = "error";
        $_SESSION['reset_step'] = 3;
    } else {
        $update = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE mail = ?");
        if ($update->execute([$newHash, $email])) {
            session_destroy();
            ?>
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f4f6f9;
                }
            </style>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    Swal.fire({
                        title: '<?= __("Başarılı!") ?>',
                        text: '<?= __("password_reset_success") ?>',
                        icon: 'success',
                        confirmButtonText: '<?= __("login_button") ?>',
                        confirmButtonColor: '#28a745',
                        allowOutsideClick: false,
                        timer: 5000,
                        timerProgressBar: true
                    }).then(() => { window.location.href = '<?= ($_SESSION['lang'] ?? 'tr') === 'en' ? 'login' : 'giris' ?>'; });
                });
            </script>
            <?php
            exit;
        } else {
            $message = __("generic_error_start_over");
            $messageType = "error";
            $_SESSION['reset_step'] = 1;
            header("Location: " . $page_url);
            exit;
        }
    }
}

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'];
    unset($_SESSION['message']);
    unset($_SESSION['messageType']);
}

$remaining_seconds = 0;
if ($step == 2 && isset($_SESSION['reset_expires'])) {
    $remaining_seconds = $_SESSION['reset_expires'] - time();
    if ($remaining_seconds < 0)
        $remaining_seconds = 0;
}

// BASE URL HESAPLAMA
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
    <title><?= $current_lang === 'tr' ? 'Şifre Sıfırlama' : __("reset_password_title") ?></title>
    <base href="<?php echo $base_href; ?>">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700&display=fallback" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    <style>
        body { margin: 0; height: 100vh; display: flex; align-items: center; justify-content: center; background: #0f1b3d; overflow: hidden; font-family: 'Source Sans Pro', sans-serif; }
        .login-box { width: 400px; padding: 40px; background: rgba(255, 255, 255, 0.96); border-radius: 12px; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3); text-align: center; position: relative; z-index: 10; }
        .btn-primary { background: #0043c9; border: none; height: 45px; font-weight: bold; width: 100%; margin-top: 15px; }
        .btn-primary:hover { background: #003399; }
        .steps-indicator { display: flex; justify-content: center; margin-bottom: 20px; }
        .step-dot { height: 10px; width: 10px; background-color: #ddd; border-radius: 50%; margin: 0 5px; transition: all 0.3s; }
        .step-dot.active { background-color: #0043c9; transform: scale(1.3); }
        .timer-box { font-size: 18px; font-weight: bold; color: #0043c9; margin: 15px 0; border: 1px dashed #0043c9; padding: 10px; border-radius: 5px; }
        .error-message { background-color: #f8d7da; color: #721c24; border-radius: 6px; padding: 12px; margin-bottom: 20px; font-size: 14px; text-align: left; }
        .success-message { background-color: #d4edda; color: #155724; border-radius: 6px; padding: 12px; margin-bottom: 20px; font-size: 14px; text-align: left; }
        .lang-switch { position: absolute; top: 15px; right: 20px; font-size: 12px; font-weight: bold; }
        .lang-switch a { color: #6c757d; text-decoration: none; padding: 2px 5px; }
        .lang-switch a.active { color: #0043c9; }
    </style>
</head>
<body>
    <div id="particles-js" style="position:fixed;width:100%;height:100%;background:radial-gradient(circle at center, #1a2b5e 0%, #080f26 100%);z-index:-1;"></div>
    
    <div class="login-box">
        <div class="lang-switch">
            <a href="sifre-sifirla?lang=tr" class="<?= $current_lang === 'tr' ? 'active' : '' ?>">TR</a> | 
            <a href="sifre-sifirla?lang=en" class="<?= $current_lang === 'en' ? 'active' : '' ?>">EN</a>
        </div>

        <h3 style="font-weight:700; color:#1a2b5e; margin-bottom:20px;"><?= __("reset_password_title") ?></h3>

        <div class="steps-indicator">
            <div class="step-dot <?= $step >= 1 ? 'active' : '' ?>"></div>
            <div class="step-dot <?= $step >= 2 ? 'active' : '' ?>"></div>
            <div class="step-dot <?= $step >= 3 ? 'active' : '' ?>"></div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="<?= $messageType === 'error' ? 'error-message' : 'success-message' ?>">
                <i class="fas <?= $messageType === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?> mr-1"></i>
                <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if ($step == 1): ?>
            <p class="text-muted"><?= __("reset_step1_instruction") ?></p>
            <form method="POST" action="<?= $page_url ?>" onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').innerHTML = '<i class=\'fas fa-spinner fa-spin\'></i>';">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_code">
                <div class="input-group mb-3">
                    <input type="email" name="email" class="form-control" placeholder="<?= __("mail_address") ?>" required autofocus value="<?= htmlspecialchars($current_email) ?>">
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-envelope text-muted"></span></div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><?= __("send_reset_code") ?></button>
                <div class="mt-3 text-left">
                    <a href="<?= ($_SESSION['lang'] ?? 'tr') === 'en' ? 'login' : 'giris' ?>" class="text-muted" style="font-size:14px;"><i class="fas fa-arrow-left mr-1"></i> <?= __("login_button") ?></a>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($step == 2): ?>
            <?php
            $instruction = __("reset_step2_instruction");
            $instruction = str_replace('{{email}}', htmlspecialchars($current_email), $instruction);
            if (strpos($instruction, '%s') !== false) {
                $instruction = sprintf($instruction, htmlspecialchars($current_email));
            }
            ?>
            <p class="text-muted"><?= $instruction ?></p>
            <form method="POST" action="<?= $page_url ?>" onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').innerHTML = '<i class=\'fas fa-spinner fa-spin\'></i>';">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_code">
                <div class="input-group mb-3">
                    <input type="text" name="code" class="form-control" placeholder="<?= __("verification_code") ?>" required autocomplete="off" style="text-align:center; font-size:24px; letter-spacing:8px; font-weight:bold;">
                </div>
                <div class="timer-box">
                    <i class="far fa-clock"></i> <?= __("remaining_time") ?> <span id="countdown">00:00</span>
                </div>
                <button type="submit" class="btn btn-primary"><?= __("verify_button") ?> <i class="fas fa-check"></i></button>
                <div class="mt-3 d-flex justify-content-between" style="font-size:13px;">
                    <a href="<?= $page_url ?>?cancel=1" class="text-muted"><?= __("cancel_reset") ?></a>
                    <a href="<?= $page_url ?>?reset=1" class="text-danger"><?= __("resend_code") ?></a>
                </div>
            </form>
            <script>
                var timeLeft = <?= $remaining_seconds ?>;
                var countdownEl = document.getElementById('countdown');
                var timerId = setInterval(function () {
                    if (timeLeft <= 0) {
                        clearInterval(timerId);
                        countdownEl.innerHTML = "00:00";
                        countdownEl.style.color = "red";
                        window.location.reload();
                    } else {
                        var m = Math.floor(timeLeft / 60);
                        var s = timeLeft % 60;
                        countdownEl.innerHTML = (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
                        timeLeft--;
                    }
                }, 1000);
            </script>
        <?php endif; ?>

        <?php if ($step == 3): ?>
            <p class="text-muted"><?= __("reset_step3_instruction") ?></p>
            <form method="POST" action="<?= $page_url ?>" onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').innerHTML = '<i class=\'fas fa-spinner fa-spin\'></i>';">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="new_password">
                <div class="input-group mb-3">
                    <input type="password" name="password" class="form-control" placeholder="<?= __("new_password") ?>" required minlength="8">
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-lock text-muted"></span></div>
                    </div>
                </div>
                <div class="input-group mb-3">
                    <input type="password" name="password_confirm" class="form-control" placeholder="<?= __("confirm_new_password") ?>" required minlength="8">
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-lock text-muted"></span></div>
                    </div>
                </div>
                <ul class="text-left text-muted mb-3" style="font-size:12px; padding-left:20px;">
                    <li><?= __("password_min_length") ?></li>
                    <li><?= __("password_require_upper") ?></li>
                    <li><?= __("password_require_lower") ?></li>
                </ul>
                <button type="submit" class="btn btn-primary"><?= __("save_password") ?> <i class="fas fa-save"></i></button>
            </form>
        <?php endif; ?>
    </div>
    
    <!-- Scripts -->
    <script src="plugins/jquery/jquery.min.js"></script>
    <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="dist/js/adminlte.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
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

            if (typeof particlesJS !== 'undefined') {
                particlesJS('particles-js', {
                    "particles": {
                        "number": { "value": 80, "density": { "enable": true, "value_area": 800 } },
                        "color": { "value": "#ffffff" },
                        "shape": { "type": "circle" },
                        "opacity": { "value": 0.3, "random": false },
                        "size": { "value": 3, "random": true },
                        "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.2, "width": 1 },
                        "move": { "enable": true, "speed": 1.5, "direction": "none", "random": false, "straight": false, "out_mode": "out", "bounce": false }
                    },
                    "interactivity": {
                        "detect_on": "canvas",
                        "events": { "onhover": { "enable": true, "mode": "grab" }, "onclick": { "enable": true, "mode": "push" }, "resize": true },
                        "modes": { "grab": { "distance": 140, "line_linked": { "opacity": 0.5 } }, "push": { "particles_nb": 4 } }
                    },
                    "retina_detect": true
                });
            }
        });
    </script>
</body>
</html>