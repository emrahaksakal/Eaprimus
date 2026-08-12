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

// pages/kullanici_ekle.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Yollar düzeltildi: app/pages/ içinde olduğumuz için iki yukarı çıkıp ulaşmalıyız.
$libs_dir = __DIR__ . '/../../libs';

if (file_exists($libs_dir . '/phpmailer/Exception.php')) {
    require_once $libs_dir . '/phpmailer/Exception.php';
    require_once $libs_dir . '/phpmailer/PHPMailer.php';
    require_once $libs_dir . '/phpmailer/SMTP.php';
}

// 1. YETKİ KONTROLÜ
$my_role = $_SESSION['role'];

if (!isset($my_role) || ($my_role != 1 && $my_role != 3)) {
    echo "<div class='alert alert-danger m-3'>" . __("unauthorized_access") . "</div>";
    return;
}

$hata = '';
$basarili = '';
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

// Fetch settings for global use
$allSettings = [];
$stmtS = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmtS->fetch(PDO::FETCH_ASSOC)) {
    $allSettings[$row['setting_key']] = $row['setting_value'];
}
$brand = $allSettings['site_title'] ?? 'Eaprimus';

// Takımları Çek
try {
    $stmtTeams = $pdo->query("SELECT id, name FROM teams WHERE status = 1 ORDER BY name ASC");
    $db_teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $db_teams = [];
}

// Organizasyonları Çek
try {
    $stmtOrgs = $pdo->query("SELECT id, name FROM organizations ORDER BY name ASC");
    $db_orgs = $stmtOrgs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $db_orgs = [];
}

// Özel Dinamik Rolleri Çek
try {
    $custom_roles_list = $pdo->query("SELECT id, role_name FROM custom_roles ORDER BY role_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $custom_roles_list = [];
}

// Bölümleri Çek
try {
    $stmtBolum = $pdo->query("SELECT id, bolum_adi as name FROM bolumler ORDER BY bolum_adi ASC");
    $db_bolumler = $stmtBolum->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $db_bolumler = [];
}

// --- COPY FROM LOGIC ---
$copy_id = (int)($_GET['copy_from'] ?? 0);
$copy_data = null;
if ($copy_id > 0) {
    $stmtCopy = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmtCopy->execute([$copy_id]);
    $copy_data = $stmtCopy->fetch(PDO::FETCH_ASSOC);
}
// -----------------------

// 📧 E-POSTA ŞABLONU (Buradan Manuel Düzenleyebilirsiniz veya Sistem Ayarları -> Personel sekmesinden)
$dbTpl = $isTr ? ($allSettings['mail_user_registration_tr_body'] ?? $allSettings['mail_user_registration_body'] ?? '') : ($allSettings['mail_user_registration_en_body'] ?? $allSettings['mail_user_registration_body'] ?? '');

$REGISTRATION_EMAIL_TEMPLATE = !empty($dbTpl) ? $dbTpl : '
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; background: #f8fafc; font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1e293b; }
        .container { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        .header { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 48px 32px; text-align: center; }
        .content { padding: 48px; }
        .welcome-text { font-size: 24px; font-weight: 700; color: #0f172a; margin-bottom: 24px; }
        .info-box { background: #f1f5f9; padding: 24px; border-radius: 16px; margin: 32px 0; border: 1px solid #e2e8f0; }
        .info-label { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 4px; }
        .info-value { font-size: 16px; font-weight: 600; color: #0f172a; }
        .btn { display: inline-block; background: #3b82f6; color: #ffffff !important; padding: 16px 32px; text-decoration: none; border-radius: 12px; font-weight: 600; font-size: 15px; transition: background 0.2s; text-align: center; }
        .footer { background: #f8fafc; padding: 32px; text-align: center; color: #94a3b8; font-size: 13px; border-top: 1px solid #f1f5f9; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {{LOGO_HTML}}
        </div>
        <div class="content">
            <h2 class="welcome-text">{{WELCOME_TITLE}}</h2>
            <p style="line-height: 1.6; color: #475569;">{{WELCOME_MSG}}</p>
            
            <div class="info-box">
                <div style="margin-bottom: 16px;">
                    <div class="info-label">{{USERNAME_LABEL}}</div>
                    <div class="info-value">{{USERNAME}}</div>
                </div>
                <div>
                    <div class="info-label">{{STATUS_LABEL}}</div>
                    <div class="info-value" style="color: #10b981;">{{STATUS}}</div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 40px;">
                <a href="{{ACTIVATION_LINK}}" class="btn">{{ACTIVATION_BTN}}</a>
            </div>
        </div>
        <div class="footer">
            {{FOOTER_TEXT}}<br>
            &copy; {{YEAR}} {{BRAND}}
        </div>
    </div>
</body>
</html>';
// ---------------------------------------------------------

// 2. FORM İŞLEMİ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $fullname = trim($_POST['fullname']);
    $tc_no = trim($_POST['tc_no']);
    $username = trim($_POST['username']);
    $user_mail = trim($_POST['user_email']);
    $role_to_add = (int) $_POST['role'];
    $custom_role_id = !empty($_POST['custom_role_id']) ? intval($_POST['custom_role_id']) : null;
    $sirket_ismi = trim($_POST['sirket_ismi']);
    $bolum_id = $_POST['bolum'] ?: null;
    $can_login = isset($_POST['can_login']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $status = intval($_POST['status'] ?? 0);

    // Hiyerarşi Kontrolü
    if ($my_role == 3 && $role_to_add != 2) {
        $hata = __("hr_only_personnel_error");
    }

    // Validasyon
    if (empty($hata) && (empty($fullname) || empty($username) || empty($user_mail))) {
        $hata = __("fill_required_fields");
    }
    if (empty($hata) && !empty($tc_no) && !preg_match('/^[0-9]{11}$/', $tc_no)) {
        $hata = __("invalid_tc_no");
    }

    // Benzersizlik Kontrolü (Sadece Aktif Kullanıcılar: username, mail, tc_no)
    if (empty($hata)) {
        // 1. Username Kontrolü
        $stmtCheckU = $pdo->prepare("SELECT id, fullname FROM users WHERE username = ? AND deleted_at IS NULL");
        $stmtCheckU->execute([$username]);
        if ($rowU = $stmtCheckU->fetch(PDO::FETCH_ASSOC)) {
            $owner = !empty($rowU['fullname']) ? " (" . $rowU['fullname'] . ")" : "";
            $hata = __("user_already_registered_username", ['username' => $username, 'owner' => $owner]);
        }
    }
    if (empty($hata)) {
        // 2. E-Posta Kontrolü
        $stmtCheckM = $pdo->prepare("SELECT id, fullname FROM users WHERE mail = ? AND deleted_at IS NULL");
        $stmtCheckM->execute([$user_mail]);
        if ($rowM = $stmtCheckM->fetch(PDO::FETCH_ASSOC)) {
            $owner = !empty($rowM['fullname']) ? " (" . $rowM['fullname'] . ")" : "";
            $hata = __("user_already_registered_email", ['email' => $user_mail, 'owner' => $owner]);
        }
    }
    if (empty($hata) && !empty($tc_no)) {
        // 3. TC No Kontrolü
        $stmtCheckT = $pdo->prepare("SELECT id, fullname FROM users WHERE tc_no = HEX(AES_ENCRYPT(?, '" . EAPRIMUS_KEY . "')) AND deleted_at IS NULL");
        $stmtCheckT->execute([$tc_no]);
        if ($rowT = $stmtCheckT->fetch(PDO::FETCH_ASSOC)) {
            $owner = !empty($rowT['fullname']) ? " (" . $rowT['fullname'] . ")" : "";
            $hata = __("user_already_registered_tc", ['tc' => $tc_no, 'owner' => $owner]);
        }
    }

    // Profil Resmi Format Kontrolü
    if (empty($hata) && isset($_FILES['profil_fotosu']) && $_FILES['profil_fotosu']['error'] == UPLOAD_ERR_OK) {
        $imgExt = strtolower(pathinfo($_FILES['profil_fotosu']['name'], PATHINFO_EXTENSION));
        $allowedImgExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
        if (!in_array($imgExt, $allowedImgExts)) {
            $hata = $isTr ? "⚠️ Profil resmi sadece .jpg, .jpeg, .png, .webp, .gif veya .svg formatında olabilir!" : "⚠️ Avatar image must be .jpg, .jpeg, .png, .webp, .gif or .svg!";
        }
    }

    // --- İŞLEM BAŞLIYOR ---
    if (empty($hata)) {

        // Transaction Başlat
        $pdo->beginTransaction();
        $uploaded_file_path = null;

        try {
            // 1. ADIM: Kullanıcıyı Ekle
            $token = bin2hex(random_bytes(32));
            $expires = date("Y-m-d H:i:s", strtotime('+24 hours'));
            $profil_fotosu = !empty($_POST['avatar_url']) ? $_POST['avatar_url'] : "https://api.dicebear.com/7.x/adventurer/svg?seed=" . urlencode($username) . "&backgroundColor=b6e3f4,c0aede,d1d4f9,ffdfbf";
            $user_lang = in_array($_POST['lang'] ?? 'tr', ['tr', 'en']) ? $_POST['lang'] : 'tr';

            $sql = "INSERT INTO users (username, tc_no, fullname, role, custom_role_id, mail, sirket_ismi, bolum, status, can_login, profil_fotosu, created_at, reset_token, reset_expires, password, lang)
                     VALUES (?, " . (!empty($tc_no) ? "HEX(AES_ENCRYPT(?, '" . EAPRIMUS_KEY . "'))" : "''") . ", ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);

            $hashed_password = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : '';

            $params = [$username];
            if (!empty($tc_no))
                $params[] = $tc_no;
            $params = array_merge($params, [
                $fullname,
                $role_to_add,
                $custom_role_id,
                $user_mail,
                $sirket_ismi,
                $bolum_id,
                $status,
                $can_login,
                $profil_fotosu,
                $token,
                $expires,
                $hashed_password,
                $user_lang
            ]);
            $stmt->execute($params);
            $user_id = $pdo->lastInsertId();

            // Takımları Ekle
            if (!empty($_POST['teams']) && is_array($_POST['teams'])) {
                $stmtTu = $pdo->prepare("INSERT INTO teams_users (team_id, user_id) VALUES (?, ?)");
                foreach ($_POST['teams'] as $tid) {
                    if ((int) $tid > 0) {
                        try {
                            $stmtTu->execute([(int) $tid, $user_id]);
                        } catch (Exception $e) {
                        }
                    }
                }
            }


            // 2. ADIM: Fotoğraf Yükleme veya Avatar
            if (isset($_FILES['profil_fotosu']) && $_FILES['profil_fotosu']['error'] == 0) {
                $upload_dir = __DIR__ . '/../../public/uploads/profil/';
                if (!is_dir($upload_dir))
                    mkdir($upload_dir, 0755, true);
                $ext = pathinfo($_FILES['profil_fotosu']['name'], PATHINFO_EXTENSION);
                $file_name = "user_" . $user_id . "." . $ext;

                if (move_uploaded_file($_FILES['profil_fotosu']['tmp_name'], $upload_dir . $file_name)) {
                    $pdo->prepare("UPDATE users SET profil_fotosu = ? WHERE id = ?")->execute([$file_name, $user_id]);
                    $uploaded_file_path = $upload_dir . $file_name;
                }
            } elseif (!empty($_POST['avatar_url'])) {
                $avatarUrl = filter_var($_POST['avatar_url'], FILTER_SANITIZE_URL);
                $pdo->prepare("UPDATE users SET profil_fotosu = ? WHERE id = ?")->execute([$avatarUrl, $user_id]);
            }

            $pdo->commit();

            // 3. ADIM: Mail Gönderimi (Sadece şifre belirlenmemişse veya davet isteniyorsa - Güvenli Blok)
            if (empty($password)) {
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
                    $host = $_SERVER['HTTP_HOST'];
                    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
                    if (substr($script_dir, -7) === '/public') { $script_dir = substr($script_dir, 0, -7); }
                    $base_url = $protocol . $host . rtrim($script_dir, '/') . '/';
                    $activation_link = $base_url . "activation?token=" . $token . "&email=" . urlencode($user_mail);

                    sendTemplatedMail($user_mail, $fullname, 'user_registration', [
                        'fullname' => $fullname,
                        'username' => $username,
                        'ACTIVATION_LINK' => $activation_link
                    ], '', $user_lang);
                } catch (Throwable $mailErr) {
                    // Mail gönderim hatası yönlendirmeyi engellemesin
                }
            }

            // Log the action
            if (file_exists(__DIR__ . '/../includes/asset_helpers.php')) {
                require_once __DIR__ . '/../includes/asset_helpers.php';
                addAssetLog($pdo, $user_id, $my_role == 1 || $my_role == 3 ? $_SESSION['user_id'] : 0, 'created', "Yeni kullanıcı hesabı oluşturuldu: " . $fullname, null, 'user');
            }

            $_SESSION['mesaj'] = "✅ " . sprintf(__("user_added_success_msg"), $fullname);
            if (!headers_sent()) {
                header("Location: kullanici-listele");
            }
            echo "<script>window.location.href = 'kullanici-listele';</script>";
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($uploaded_file_path && file_exists($uploaded_file_path)) {
                unlink($uploaded_file_path);
            }

            $hata = __("registration_error") . ": " . $e->getMessage();
        }
    }
}
?>

<style>
    /* Modern UI Design variables & overrides */
    .modern-card {
        border: none !important;
        border-radius: 20px !important;
        background: #ffffff !important;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.04) !important;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    body.dark-mode .modern-card {
        background: #18223f !important;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25) !important;
        border: 1px solid rgba(255, 255, 255, 0.05) !important;
    }
    .form-section-title {
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 20px;
        padding-bottom: 8px;
        border-bottom: 2px solid #f1f5f9;
        display: flex;
        align-items: center;
    }
    body.dark-mode .form-section-title {
        color: #f8fafc;
        border-bottom-color: rgba(255, 255, 255, 0.05);
    }
    .form-section-title i {
        color: #3b82f6;
    }
    .modern-input-group {
        position: relative;
        margin-bottom: 24px;
    }
    .modern-input-group label {
        font-size: 13px;
        font-weight: 600;
        color: #475569;
        margin-bottom: 6px;
        display: block;
    }
    body.dark-mode .modern-input-group label {
        color: #94a3b8;
    }
    .modern-field-container {
        position: relative;
    }
    .modern-field-container i.field-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 15px;
        z-index: 4;
        transition: color 0.2s;
    }
    .modern-field-container .form-control {
        padding-left: 42px !important;
        border-radius: 12px !important;
        border: 1.5px solid #e2e8f0 !important;
        height: 48px !important;
        font-size: 14px !important;
        font-weight: 500 !important;
        color: #1e293b !important;
        background-color: #fff !important;
        transition: all 0.2s ease !important;
    }
    body.dark-mode .modern-field-container .form-control {
        background-color: #0f172a !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
        color: #f8fafc !important;
    }
    .modern-field-container .form-control:focus {
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15) !important;
    }
    .modern-field-container .form-control:focus + i.field-icon {
        color: #3b82f6;
    }
    .modern-select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2394a3b8' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
        background-repeat: no-repeat !important;
        background-position: right 14px center !important;
        background-size: 12px 12px !important;
    }
    .btn-modern-success {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
        color: #fff !important;
        border: none !important;
        border-radius: 12px !important;
        padding: 12px 28px !important;
        font-weight: 600 !important;
        font-size: 14px !important;
        box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3) !important;
        transition: all 0.2s ease !important;
    }
    .btn-modern-success:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
    }
    .avatar-glass-card {
        background: rgba(255, 255, 255, 0.6);
        border: 1.5px solid #e2e8f0;
        border-radius: 16px;
        padding: 16px;
        backdrop-filter: blur(10px);
        transition: all 0.2s;
    }
    body.dark-mode .avatar-glass-card {
        background: rgba(15, 23, 42, 0.4);
        border-color: rgba(255, 255, 255, 0.05);
    }
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0 font-weight-bold" style="font-family: 'Plus Jakarta Sans', sans-serif;"><i class="fas fa-user-plus mr-2 text-primary"></i><?= __("add_user") ?></h1>
            </div>
        </div>
    </div>
</div>

<?php if ($hata): ?>
    <div class="alert alert-danger m-3 shadow-sm" style="border-radius: 12px; border: none;">
        <h5><i class="icon fas fa-ban"></i> <?= __("registration_failed") ?></h5>
        <?= $hata; ?>
    </div>
<?php endif; ?>

<div class="card modern-card mx-3 mb-5">
    <div class="card-header bg-transparent py-4 border-bottom-0">
        <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-id-card-alt mr-2 text-primary"></i><?= __("staff_information") ?></h4>
    </div>
    <?php
    // Veritabanından mail alan adlarını çek
    $stmtSetDomains = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'mail_allowed_domains'");
    $allowed_domains_str = $stmtSetDomains->fetchColumn();
    if (!$allowed_domains_str) {
        $allowed_domains_str = "@eaprimus.com, @gmail.com, @outlook.com";
    }
    $allowed_domains = array_map('trim', explode(',', $allowed_domains_str));
    ?>
    <form id="userAddForm" method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="card-body px-4 py-3">
            <div class="row">
                <div class="col-md-6">
                    <h5 class="form-section-title"><i class="fas fa-user-circle mr-2"></i><?= $isTr ? 'Kişisel Bilgiler' : 'Personal Details' ?></h5>
                    
                    <div class="modern-input-group">
                        <label><?= __("full_name") ?> <span class="text-danger">*</span></label>
                        <div class="modern-field-container">
                            <input type="text" name="fullname" class="form-control"
                                value="<?= isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : (isset($copy_data['fullname']) ? htmlspecialchars($copy_data['fullname']) : '') ?>"
                                required>
                            <i class="fas fa-user field-icon"></i>
                        </div>
                    </div>

                    <div class="modern-input-group">
                        <label><?= __("email") ?> <span class="text-danger">*</span></label>
                        <div class="modern-field-container">
                            <input type="email" name="user_email" id="emailInput" class="form-control"
                                value="<?= isset($_POST['user_email']) ? htmlspecialchars($_POST['user_email']) : (isset($copy_data['mail']) ? htmlspecialchars($copy_data['mail']) : '') ?>"
                                required>
                            <i class="fas fa-envelope field-icon"></i>
                        </div>
                        <small class="text-muted mt-1 d-block"><?= __("user_email_hint") ?? 'Personelin kurumsal veya şahsi mail adresi.' ?></small>
                    </div>

                    <div class="modern-input-group">
                        <label><?= __("username") ?> <span class="text-danger">*</span></label>
                        <div class="modern-field-container">
                            <input type="text" name="username" id="usernameInput" class="form-control"
                                value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : (isset($copy_data['username']) ? htmlspecialchars($copy_data['username']) : '') ?>"
                                required>
                            <i class="fas fa-user-tag field-icon"></i>
                        </div>
                        <small class="text-muted mt-1 d-block"><?= __("username_hint_creation") ?? 'Sisteme giriş için kullanılacak isim.' ?></small>
                    </div>

                    <div class="modern-input-group">
                        <label><?= __("tc_no") ?> <small class="text-muted">(Opsiyonel)</small></label>
                        <div class="modern-field-container">
                            <input type="text" name="tc_no" class="form-control" maxlength="11" autocomplete="off"
                                value="<?= isset($_POST['tc_no']) ? htmlspecialchars($_POST['tc_no']) : '' ?>">
                            <i class="fas fa-id-card field-icon"></i>
                        </div>
                        <small class="text-muted mt-1 d-block"><?= __("tc_encrypted_hint") ?></small>
                    </div>

                    <div id="passwordArea">
                        <div class="row align-items-end">
                            <div class="col-8">
                                <div class="modern-input-group mb-0">
                                    <label><?= __("password") ?></label>
                                    <div class="modern-field-container">
                                        <input type="password" name="password" id="passwordInput" class="form-control"
                                            autocomplete="new-password">
                                        <i class="fas fa-lock field-icon"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-4">
                                <button type="button" class="btn btn-outline-info btn-block" onclick="generateRandomPass()"
                                    style="height: 48px; border-radius: 12px;">
                                    <i class="fas fa-random mr-1"></i> <?= $isTr ? 'Rastgele' : 'Random' ?>
                                </button>
                            </div>
                            <div class="col-12 mt-1">
                                <small class="text-muted d-block"><?= $isTr ? 'Şifre belirlenirse mail daveti gönderilmez.' : 'If password is set, no email invitation will be sent.' ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 mt-4 mt-md-0">
                    <h5 class="form-section-title"><i class="fas fa-briefcase mr-2"></i><?= $isTr ? 'Kurumsal & Rol Bilgileri' : 'Corporate & Role Details' ?></h5>

                    <div class="modern-input-group">
                        <label><?= __("company") ?> <span class="text-danger">*</span></label>
                        <div class="modern-field-container">
                            <input type="text" name="sirket_ismi" class="form-control" list="orgs_list"
                                value="<?= isset($_POST['sirket_ismi']) ? htmlspecialchars($_POST['sirket_ismi']) : (isset($copy_data['sirket_ismi']) ? htmlspecialchars($copy_data['sirket_ismi']) : '') ?>"
                                placeholder="<?= __("enter_company_name") ?>" required>
                            <i class="fas fa-building field-icon"></i>
                            <datalist id="orgs_list">
                                <?php foreach ($db_orgs as $org): ?>
                                    <option value="<?= htmlspecialchars($org['name']) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>

                    <div class="modern-input-group">
                        <label><?= __("system_role") ?> <span class="text-danger">*</span></label>
                        <div class="modern-field-container">
                            <select name="role" class="form-control modern-select">
                                <?php $currRole = isset($_POST['role']) ? (int)$_POST['role'] : (isset($copy_data['role']) ? (int)$copy_data['role'] : 2); ?>
                                <option value="2" <?= $currRole == 2 ? 'selected' : '' ?>><?= __("role_staff") ?></option>
                                <?php if ($my_role == 1): ?>
                                    <option value="3" <?= $currRole == 3 ? 'selected' : '' ?>><?= __("role_hr") ?></option>
                                    <option value="1" <?= $currRole == 1 ? 'selected' : '' ?>><?= __("role_admin") ?></option>
                                <?php endif; ?>
                            </select>
                            <i class="fas fa-user-shield field-icon"></i>
                        </div>
                    </div>

                    <div class="modern-input-group">
                        <label><?= $isTr ? 'Özel Dinamik Rol (RBAC)' : 'Custom Dynamic Role (RBAC)' ?></label>
                        <div class="modern-field-container">
                            <select name="custom_role_id" class="form-control modern-select">
                                <option value=""><?= $isTr ? '-- Yok (Varsayılan Rol Geçerli) --' : '-- None (Default Role Applies) --' ?></option>
                                <?php foreach ($custom_roles_list as $cr): ?>
                                    <?php 
                                    $currCustomRole = isset($_POST['custom_role_id']) ? $_POST['custom_role_id'] : (isset($copy_data['custom_role_id']) ? $copy_data['custom_role_id'] : '');
                                    ?>
                                    <option value="<?= $cr['id'] ?>" <?= ($currCustomRole == $cr['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cr['role_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-users-cog field-icon"></i>
                        </div>
                    </div>

                    <div class="modern-input-group">
                        <label><?= __("user_status") ?></label>
                        <input type="hidden" name="status" id="userStatusInput" value="1">
                        <div class="mt-1">
                            <button type="button" class="btn btn-success font-weight-bold px-4 py-2 shadow-sm" id="statusToggleBtn" onclick="toggleUserStatus()" style="border-radius:20px; font-size:13px; transition: all 0.2s ease; cursor:pointer;">
                                <i class="fas fa-check-circle mr-1" id="statusIcon"></i> <span id="statusText"><?= __("active") ?></span>
                            </button>
                        </div>
                        <small class="text-muted mt-1 d-block"><?= __("active_passive_hint") ?></small>
                    </div>

                    <div class="modern-input-group mt-3">
                        <label><?= __("can_login") ?></label>
                        <div class="custom-control custom-switch mt-1">
                            <input type="checkbox" class="custom-control-input" id="canLoginSwitch" name="can_login" value="1" checked style="cursor:pointer;">
                            <label class="custom-control-label font-weight-bold text-dark" for="canLoginSwitch" style="cursor:pointer;">
                                <?= $isTr ? 'Personel panele/sisteme giriş yapabilsin' : 'Allow user to log into panel/system' ?>
                            </label>
                        </div>
                        <small class="text-muted mt-1 d-block"><?= __("can_login_hint") ?></small>
                    </div>

                    <script>
                    function toggleUserStatus() {
                        const input = document.getElementById("userStatusInput");
                        const btn = document.getElementById("statusToggleBtn");
                        const icon = document.getElementById("statusIcon");
                        const text = document.getElementById("statusText");
                        const isTr = <?= (!isset($_SESSION['lang']) || $_SESSION['lang'] !== 'en') ? 'true' : 'false' ?>;

                        if (input.value == "1") {
                            input.value = "0";
                            btn.className = "btn btn-danger font-weight-bold px-4 py-2 shadow-sm";
                            icon.className = "fas fa-times-circle mr-1";
                            text.innerText = isTr ? "Pasif" : "Passive";
                        } else {
                            input.value = "1";
                            btn.className = "btn btn-success font-weight-bold px-4 py-2 shadow-sm";
                            icon.className = "fas fa-check-circle mr-1";
                            text.innerText = isTr ? "Aktif" : "Active";
                        }
                    }
                    </script>

                    <div class="modern-input-group">
                        <label><?= __("department") ?></label>
                        <div class="modern-field-container">
                            <select name="bolum" class="form-control modern-select">
                                <option value=""><?= __("select_department") ?></option>
                                <?php 
                                $currBolum = isset($_POST['bolum']) ? $_POST['bolum'] : (isset($copy_data['bolum']) ? $copy_data['bolum'] : '');
                                foreach ($db_bolumler as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= $currBolum == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                                 <?php endforeach; ?>
                            </select>
                            <i class="fas fa-sitemap field-icon"></i>
                        </div>
                    </div>

                    <div class="modern-input-group">
                        <label><?= __("language") ?? 'Varsayılan Dil / Default Language' ?></label>
                        <div class="modern-field-container">
                            <select name="lang" class="form-control modern-select">
                                <option value="tr" <?= (isset($_POST['lang']) && $_POST['lang'] === 'tr') ? 'selected' : '' ?>>Türkçe</option>
                                <option value="en" <?= (isset($_POST['lang']) && $_POST['lang'] === 'en') ? 'selected' : '' ?>>English</option>
                            </select>
                            <i class="fas fa-language field-icon"></i>
                        </div>
                    </div>

                    <div class="modern-input-group">
                        <label><?= __("teams_optional") ?></label>
                        <div class="modern-field-container">
                            <select name="teams[]" class="form-control select2 modern-select" multiple="multiple">
                                <?php foreach ($db_teams as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-users field-icon"></i>
                        </div>
                        <small class="text-muted mt-1 d-block"><?= __("teams_hint") ?></small>
                    </div>

                    <div class="modern-input-group">
                        <label><?= __("profile_photo_avatar") ?></label>
                        <div class="avatar-glass-card">
                            <?php 
                            $defAv = "dist/img/avatars/male1.svg"; 
                            if (isset($copy_data['profil_fotosu']) && strpos($copy_data['profil_fotosu'], 'http') === 0) {
                                $defAv = $copy_data['profil_fotosu'];
                            }
                            ?>
                            <div class="d-flex align-items-center mb-3">
                                <img id="avatarPreview" src="<?= $defAv ?>" class="img-circle elevation-1 mr-3"
                                    style="width: 56px; height: 56px; object-fit: cover; background: #fff; border: 2px solid #3b82f6;">
                                <div class="flex-grow-1">
                                    
                                    
                                    <div class="custom-file" style="max-width:200px; display: block;">
                                        <input type="file" name="profil_fotosu" class="custom-file-input" id="customFile"
                                            accept="image/*" onchange="previewUpload(this)">
                                        <label class="custom-file-label" for="customFile" id="fileLabel" style="font-size:12px; height:31px; line-height:22px;"><?= __("select_file_optional") ?></label>
                                    </div>
                                </div>
                                <input type="hidden" name="avatar_url" id="avatarUrlInput" value="<?= $defAv ?>">
                            </div>
                            
                            
                              <button class="btn btn-sm btn-outline-primary mb-3 font-weight-bold" type="button" data-toggle="collapse" data-target="#avatarCollapse" aria-expanded="false" aria-controls="avatarCollapse" style="border-radius:20px; padding:6px 16px; transition:all 0.3s; margin-top: 10px;">
                                  <i class="fas fa-user-circle mr-2"></i><?= (!isset($_SESSION['lang']) || $_SESSION['lang'] !== 'en') ? 'Avatar Seç' : 'Choose Avatar' ?> <i class="fas fa-chevron-down ml-1"></i>
                              </button>
                              
                              <div class="collapse" id="avatarCollapse">
                                  <div class="p-3 mb-3" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px; box-shadow:inset 0 2px 4px rgba(0,0,0,0.02);">
                                      
                                      
                                      
                                      <!-- Avatar Grid Flex Row -->
                            <div id="avatar-grid" class="d-flex flex-wrap justify-content-start align-items-center" style="gap:6px; padding:6px; border-radius:12px; background:rgba(255,255,255,0.4); border: 1px solid rgba(255,255,255,0.5);">
                                <!-- Populated dynamically via JS -->
                            </div>
                                  </div>
                              </div>
                        </div>
                        <small class="text-muted mt-1 d-block"><?= __("avatar_hint") ?></small>
                    </div>

                    <div class="form-group mt-4 pt-3 border-top d-flex align-items-center justify-content-between">
                        <span class="text-muted small font-weight-bold"><i class="far fa-envelope-open mr-1"></i> <?= $isTr ? 'Mail Şablonu' : 'Email Template' ?></span>
                        <button type="button" class="btn btn-outline-secondary btn-sm font-weight-bold"
                            onclick="$('#emailPreviewModal').modal('show')" style="border-radius: 8px;">
                            <i class="far fa-envelope mr-1"></i>
                            <?= $isTr ? 'Mail Tasarımını Gör' : 'View Email Design' ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-transparent py-4 text-right border-top-0">
            <button type="submit" class="btn btn-modern-success px-4 py-3"><i class="fas fa-save mr-1"></i> <?= __("save_and_invite") ?></button>
        </div>
    </form>
</div>

<!-- Modal: Email Preview -->
<div class="modal fade" id="emailPreviewModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius:15px; overflow:hidden;">
            <div class="modal-header bg-light">
                <h5 class="modal-title font-weight-bold">📧
                    <?= $isTr ? 'Mail Şablonu (HTML)' : 'Email Template (HTML)' ?>
                </h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body p-0">
                <div class="alert alert-info m-3 py-2 text-xs">
                    <i class="fas fa-info-circle mr-1"></i>
                    <?= $isTr ? 'Aşağıdaki tasarım PHP dosyasında tanımlıdır. Manuel düzenlemek için kullanici_ekle.php:207 satırına bakabilirsiniz.' : 'The design below is defined in the PHP file. To edit manually, check kullanici_ekle.php:207.' ?>
                </div>
                <div id="emailPreviewContainer" style="height:400px; border-top:1px solid #eee;">
                    <iframe id="emailPreviewIframe" style="width:100%; height:100%; border:none;"></iframe>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
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
        const inputElem = document.getElementById('avatarUrlInput');
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
        $('#avatarCollapse').collapse('toggle');
    }

    function selectAvatar(url) {
        document.getElementById('avatarPreview').src = url;
        document.getElementById('avatarUrlInput').value = url;
        // Temizle (foto seçilmişse)
        document.getElementById('customFile').value = '';
        document.getElementById('fileLabel').innerText = '<?= __("select_file_optional") ?>';
        renderAvatarGrid(); // rerender to update borders
    }

    // Initialize grid on load
    document.addEventListener("DOMContentLoaded", function() {
        renderAvatarGrid();
    });

    function previewUpload(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function (e) {
                document.getElementById('avatarPreview').src = e.target.result;
            }
            reader.readAsDataURL(input.files[0]);

            document.getElementById('fileLabel').innerText = input.files[0].name;
            // Avatar inputu sıfırla
            document.getElementById('avatarUrlInput').value = '';
        }
    }

    function generateRandomPass() {
        const pass = Math.random().toString(36).substring(2, 10);
        const passInput = document.getElementById('passwordInput');
        if (passInput) {
            passInput.type = 'text'; // Make it visible so the admin can see/copy it
            passInput.value = pass;
        }
    }

    const emailTemplate = <?= json_encode($REGISTRATION_EMAIL_TEMPLATE) ?>;
    <?php 
    require_once __DIR__ . '/../includes/mailer.php';
    $logoBase64 = getMailLogoBase64();
    ?>
    const logoSrc = <?= json_encode($logoBase64) ?>;
    const logoHtml = logoSrc ? `<img src="${logoSrc}" alt="Logo" style="max-height: 50px; width: auto;">` : '<h1 style="color:#fff; margin:0;"><?= strtoupper(htmlspecialchars($brand)) ?></h1>';

    $('#emailPreviewModal').on('shown.bs.modal', function () {
        const nameInput = document.querySelector('input[name="fullname"]');
        const unameInput = document.querySelector('input[name="username"]');
        const name = (nameInput && nameInput.value) ? nameInput.value : '{NAME}';
        const uname = (unameInput && unameInput.value) ? unameInput.value : '{USERNAME}';
        const brandName = <?= json_encode($brand) ?>;

        let content = emailTemplate
            .replace(/{{LOGO_HTML}}/g, logoHtml)
            .replace(/{{LOGO_SRC}}/g, logoSrc)
            .replace(/{{WELCOME_TITLE}}/g, (<?= $isTr ? 'true' : 'false' ?> ? 'Hoş Geldiniz, ' : 'Welcome, ') + name + '!')
            .replace(/{{WELCOME_MSG}}/g, (<?= $isTr ? 'true' : 'false' ?> ? 'Hesabınız başarıyla oluşturuldu. Sisteme giriş yapabilmek için lütfen hesabınızı aktive edin.' : 'Your account has been successfully created. Please activate your account to log in.'))
            .replace(/{{USERNAME_LABEL}}/g, (<?= $isTr ? 'true' : 'false' ?> ? 'Kullanıcı Adı' : 'Username'))
            .replace(/{{USERNAME}}/g, uname)
            .replace(/{{STATUS_LABEL}}/g, (<?= $isTr ? 'true' : 'false' ?> ? 'Durum' : 'Status'))
            .replace(/{{STATUS}}/g, (<?= $isTr ? 'true' : 'false' ?> ? 'Aktif' : 'Active'))
            .replace(/{{ACTIVATION_BTN}}/g, (<?= $isTr ? 'true' : 'false' ?> ? 'Hesabı Aktif Et' : 'Activate Account'))
            .replace(/{{ACTIVATION_LINK}}/g, '#')
            .replace(/{{FOOTER_TEXT}}/g, (<?= $isTr ? 'true' : 'false' ?> ? 'Bu otomatik bir mesajdır, lütfen yanıtlamayınız.' : 'This is an automated message, please do not reply.'))
            .replace(/{{YEAR}}/g, new Date().getFullYear())
            .replace(/{{BRAND}}/g, <?= json_encode($brand) ?>);

        const iframe = document.getElementById('emailPreviewIframe');
        if (iframe) {
            const doc = iframe.contentWindow.document;
            doc.open();
            doc.write(content);
            doc.close();
        }
    });
</script>
