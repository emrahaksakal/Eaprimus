<?php
// activate.php
// Kullanıcı maildeki linke tıkladığında burası çalışır.

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/includes/session.php';
$pdo = db();

$message = "";
$messageType = ""; // success veya error
$showForm = false;

// Base URL hesaplama (linklerin doğru çalışması için)
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$script_dir = str_replace('\\', '/', $script_dir);
if (substr($script_dir, -7) === '/public') {
    $script_dir = substr($script_dir, 0, -7);
}
$script_dir = rtrim($script_dir, '/');
$base_url = $protocol . "://" . $host . $script_dir . '/';

// Dinamik site ayarlarını çek
$_site_title = 'Eaprimus';
$_site_description = 'Eaprimus Ticket Sistemi';
$_logo_path = 'logo.png';
$_site_slogan = '';
$_show_slogan = '0';
try {
    $stSettings = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('site_title', 'logo_path', 'site_description', 'site_slogan', 'show_slogan')");
    while($row = $stSettings->fetch(PDO::FETCH_ASSOC)) {
        if($row['setting_key'] === 'site_title') $_site_title = $row['setting_value'];
        if($row['setting_key'] === 'logo_path') $_logo_path = $row['setting_value'];
        if($row['setting_key'] === 'site_description') $_site_description = $row['setting_value'];
        if($row['setting_key'] === 'site_slogan') $_site_slogan = $row['setting_value'];
        if($row['setting_key'] === 'show_slogan') $_show_slogan = $row['setting_value'];
    }
} catch (Exception $e) {}

// Slogan/Açıklama seçimi
$display_subtitle = ($_show_slogan === '1' && !empty($_site_slogan)) ? $_site_slogan : $_site_description;

// Linkten gelen verileri al
$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

// 1. KONTROL: Linkteki veriler dolu mu?
if (empty($token) || empty($email)) {
    $message = "Geçersiz bağlantı! Link hatalı veya eksik.";
    $messageType = "error";
} else {
    // 2. KONTROL: Token veritabanında var mı ve süresi dolmuş mu?
    $stmt = $pdo->prepare("SELECT * FROM users WHERE mail = ? AND reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$email, $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Kullanıcı bulunamadıysa ya link yanlıştır ya da süresi dolmuştur.
        $message = "Bu aktivasyon bağlantısının süresi dolmuş veya daha önce kullanılmış. Lütfen yöneticinizden yeni bir davet isteyiniz.";
        $messageType = "error";
    } else {
        // Her şey yolunda, şifre belirleme formunu göster
        $showForm = true;
    }
}

// 3. İŞLEM: Form Gönderildiğinde (Şifre Belirleme)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $showForm) {
    require_csrf_token();
    $pass1 = $_POST['password'];
    $pass2 = $_POST['password_confirm'];

    // Yeni Şifre Kuralları Kontrolü (GÜNCELLENDİ)
    $is_long_enough = strlen($pass1) >= 8;
    $has_uppercase = preg_match('/[A-Z]/', $pass1);
    $has_lowercase = preg_match('/[a-z]/', $pass1);

    if ($pass1 !== $pass2) {
        $message = "Şifreler birbiriyle uyuşmuyor!";
        $messageType = "error";
    } elseif (!$is_long_enough) {
        $message = "Şifre en az 8 karakter olmalıdır!"; // GÜNCELLENDİ: 6'dan 8'e
        $messageType = "error";
    } elseif (!$has_uppercase || !$has_lowercase) { // YENİ KONTROL
        $message = "Şifre en az bir büyük harf ve bir küçük harf içermelidir!";
        $messageType = "error";
    } else {
        // Şifreleme (Login.php ile uyumlu SHA256)
        $hashedPassword = hash('sha256', $pass1);

        try {
            // Veritabanını Güncelle:
            // 1. Şifreyi yaz
            // 2. Hesabı Aktif Yap (status = 1)
            // 3. Token'ı sil (Link bir daha kullanılamasın)
            $sql = "UPDATE users SET password = ?, status = 1, reset_token = NULL, reset_expires = NULL WHERE id = ?";
            $updateStmt = $pdo->prepare($sql);
            $result = $updateStmt->execute([$hashedPassword, $user['id']]);

            if ($result) {
                $message = "Tebrikler! Hesabınız başarıyla aktif edildi. Şimdi giriş yapabilirsiniz.";
                $messageType = "success";
                $showForm = false; // Formu gizle, sadece başarı mesajı kalsın
            } else {
                $message = "Bir hata oluştu, lütfen tekrar deneyin.";
                $messageType = "error";
            }
        } catch (PDOException $e) {
            $message = "Veritabanı hatası: " . $e->getMessage();
            $messageType = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hesap Aktivasyonu | <?= htmlspecialchars($_site_title ?? 'Eaprimus') ?></title>
    <base href="<?= $base_url ?>">
    <link rel="icon" type="image/png" href="favicon.png">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700&display=fallback" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>

    <style>
        body {
            margin: 0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f1b3d;
            /* Login sayfasıyla aynı koyu lacivert */
            overflow: hidden;
            font-family: 'Source Sans Pro', sans-serif;
        }

        /* Arka Plan Efekti */
        #particles-js {
            position: fixed;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, #1a2b5e 0%, #080f26 100%);
            z-index: -1;
        }

        /* Ana Kutu */
        .login-box {
            width: 420px;
            padding: 40px;
            background: rgba(255, 255, 255, 0.96);
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            text-align: center;
            animation: zoomIn 0.6s ease-out;
            position: relative;
            z-index: 10;
        }

        @keyframes zoomIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .logo {
            max-width: 150px;
            margin-bottom: -20px;
        }

        .title {
            color: #333;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 25px;
        }

        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 25px;
        }

        /* Form Elemanları */
        .form-control {
            height: 50px;
            border-radius: 8px !important;
            border: 1px solid #ddd;
            padding-left: 15px;
            font-size: 15px;
            background-color: #f9f9f9;
        }

        .form-control:focus {
            border-color: #0043c9;
            background-color: #fff;
            box-shadow: 0 0 0 3px rgba(0, 67, 201, 0.1);
        }

        .btn-activate {
            height: 50px;
            font-size: 16px;
            background: linear-gradient(135deg, #0043c9 0%, #003399 100%);
            border: none;
            border-radius: 8px;
            font-weight: 600;
            width: 100%;
            color: #fff;
            transition: all 0.3s;
            margin-top: 15px;
            box-shadow: 0 4px 15px rgba(0, 67, 201, 0.3);
        }

        .btn-activate:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 67, 201, 0.4);
        }

        /* Mesaj Kutuları */
        .alert-custom {
            padding: 15px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: left;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .user-badge {
            background: #e0e7ff;
            color: #3730a3;
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9em;
        }

        @media (max-width: 480px) {
            .login-box {
                width: 90%;
                padding: 25px;
            }
        }
    </style>
</head>

<body>

    <div id="particles-js"></div>

    <div class="login-box">

        <img src="<?= htmlspecialchars($_logo_path) ?>" class="logo" alt="Logo">

        <div class="title">Hesap Aktivasyonu</div>
        <div class="subtitle"><?= htmlspecialchars($display_subtitle) ?></div>

        <?php if ($message): ?>
            <div class="alert-custom <?= $messageType == 'success' ? 'alert-success' : 'alert-error' ?>">
                <i class="fas <?= $messageType == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"
                    style="font-size: 1.2em;"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($showForm): ?>
            <div style="margin-bottom: 20px;">
                Merhaba <span class="user-badge"><?= htmlspecialchars($user['fullname']) ?></span>,<br>
                <small style="color:#666;">Güvenliğiniz için lütfen güçlü bir şifre belirleyiniz.</small>
            </div>

            <div class="alert alert-info py-2" style="font-size: 13px; text-align: left; margin-bottom: 25px;">
                <i class="fas fa-lock mr-1"></i> **Güçlü Şifre Kuralları:**
                <ul style="margin-bottom: 0; padding-left: 20px;">
                    <li>En az **8 karakter** olmalıdır.</li>
                    <li>En az **bir büyük harf** (A-Z) içermelidir.</li>
                    <li>En az **bir küçük harf** (a-z) içermelidir.</li>
                </ul>
            </div>
            <form action="" method="post" autocomplete="off">
                <?= csrf_field() ?>
                <div class="form-group mb-3">
                    <div class="input-group">
                        <input type="password" name="password" class="form-control" placeholder="Yeni Şifreniz" required
                            autofocus minlength="8">
                    </div>
                </div>

                <div class="form-group mb-3">
                    <div class="input-group">
                        <input type="password" name="password_confirm" class="form-control"
                            placeholder="Yeni Şifreniz (Tekrar)" required minlength="8">
                    </div>
                </div>

                <button type="submit" class="btn-activate">
                    Hesabı Aktifleştir <i class="fas fa-arrow-right ml-2"></i>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($messageType == 'success'): ?>
            <a href="giris" class="btn btn-block btn-outline-primary mt-3"
                style="border-radius:8px; height:45px; line-height:30px;">
                <i class="fas fa-sign-in-alt mr-2"></i> Giriş Yap
            </a>
        <?php endif; ?>

        <?php if (!$showForm && $messageType == 'error'): ?>
            <div class="mt-4">
                <a href="giris" class="text-muted"><i class="fas fa-arrow-left mr-1"></i> Ana Sayfaya Dön</a>
            </div>
        <?php endif; ?>

    </div>

    <script>
        particlesJS("particles-js", {
            "particles": {
                "number": { "value": 60 },
                "color": { "value": "#ffffff" },
                "shape": { "type": "circle" },
                "opacity": { "value": 0.2, "random": true },
                "size": { "value": 3, "random": true },
                "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.15, "width": 1 },
                "move": { "enable": true, "speed": 1 }
            },
            "interactivity": {
                "detect_on": "canvas",
                "events": { "onhover": { "enable": true, "mode": "grab" } },
                "modes": { "grab": { "distance": 140, "line_linked": { "opacity": 0.4 } } }
            },
            "retina_detect": true
        });
    </script>

</body>

</html>