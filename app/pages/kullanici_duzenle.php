<?php
// pages/kullanici_duzenle.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../../libs/phpmailer/Exception.php';
require_once __DIR__ . '/../../libs/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../../libs/phpmailer/SMTP.php';

$current_user_id = $_SESSION['user_id'] ?? 0;
$user_id_to_edit = (int) ($_GET['id'] ?? $current_user_id);
if ($user_id_to_edit === 0) {
    $user_id_to_edit = $current_user_id;
}

// 1. YETKİ KONTROLÜ (Kendi profilini herkes düzenleyebilir; başkasının profilini sadece Admin veya İK düzenleyebilir)
if ($user_id_to_edit !== $current_user_id && (!isset($_SESSION['role']) || ($_SESSION['role'] != 1 && $_SESSION['role'] != 3))) {
    echo "<div class='alert alert-danger'>" . __("no_access") . "</div>";
    return;
}

$my_role = $_SESSION['role'] ?? 2;
$can_edit_all = ($my_role == 1 || $my_role == 3);
$mesaj = '';
$hata = '';
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

// AJAX handlers for API keys
if (isset($_POST['action']) && $_POST['action'] === 'generate_user_api_key_admin') {
    require_csrf_token();
    if ((int)$_SESSION['role'] !== 1) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    try {
        // Revoke old
        $stmtRevoke = $pdo->prepare("UPDATE api_keys SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        $stmtRevoke->execute([$user_id_to_edit]);

        // New keys
        $clientId = 'ea_u_key_' . bin2hex(random_bytes(12));
        $clientSecret = 'ea_u_sec_' . bin2hex(random_bytes(16));
        $secretHash = password_hash($clientSecret, PASSWORD_DEFAULT);

        $stmtInsert = $pdo->prepare("INSERT INTO api_keys (user_id, client_id, client_secret_hash, client_secret_plain) VALUES (?, ?, ?, ?)");
        $stmtInsert->execute([$user_id_to_edit, $clientId, $secretHash, $clientSecret]);

        echo json_encode([
            'status' => 'success',
            'message' => $isTr ? 'API anahtarı başarıyla oluşturuldu.' : 'API key successfully generated.'
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
} elseif (isset($_POST['action']) && $_POST['action'] === 'revoke_user_api_key_admin') {
    require_csrf_token();
    if ((int)$_SESSION['role'] !== 1) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    try {
        $stmtRevoke = $pdo->prepare("UPDATE api_keys SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        $stmtRevoke->execute([$user_id_to_edit]);
        echo json_encode([
            'status' => 'success',
            'message' => $isTr ? 'API anahtarı iptal edildi.' : 'API key successfully revoked.'
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =======================================================================
// 2. KULLANICI BİLGİSİNİ ÇEK (ŞİFRE ÇÖZME İŞLEMİ BURADA)
// =======================================================================
try {
    // TC'yi çözerek alıyoruz: aes_tc
    $sql = "SELECT *, CAST(AES_DECRYPT(UNHEX(tc_no), '" . EAPRIMUS_KEY . "') AS CHAR) as aes_tc FROM users WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id_to_edit]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        echo "<div class='alert alert-danger'>" . __("user_not_found") . "</div>";
        return;
    }

    if ($u && !empty($u['deleted_at'])) {
        $deletedLink = ($base_url ?? '') . 'kullanici-listele/deleted?highlight_id=' . $u['id'];
        echo "<div class='alert alert-warning'><i class='fas fa-user-slash mr-2'></i>"
            . ($isTr ? "Bu kullanıcı silinmiş." : "This user has been deleted.")
            . " <a href='" . htmlspecialchars($deletedLink) . "' class='alert-link'>"
            . ($isTr ? "Silinmiş kullanıcıları görmek için tıklayın." : "Click to view deleted users.")
            . "</a></div>";
        return;
    }
    
    // Fetch active API key if exists
    $stmtKey = $pdo->prepare("SELECT client_id, client_secret_plain FROM api_keys WHERE user_id = ? AND revoked_at IS NULL LIMIT 1");
    $stmtKey->execute([$user_id_to_edit]);
    $userKey = $stmtKey->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>" . __("db_error") . ": " . $e->getMessage() . "</div>";
    return;
}

// BÖLÜMLERİ ÇEK (Departmanlar)
try {
    $stmtBolum = $pdo->query("SELECT id, bolum_adi FROM bolumler ORDER BY bolum_adi ASC");
    $bolumler = $stmtBolum->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bolumler = [];
}

// TAKIMLARI ÇEK
try {
    $stmtTeams = $pdo->query("SELECT id, name FROM teams ORDER BY name ASC");
    $all_teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtUserTeams = $pdo->prepare("SELECT team_id FROM teams_users WHERE user_id = ?");
    $stmtUserTeams->execute([$user_id_to_edit]);
    $user_teams = $stmtUserTeams->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $all_teams = [];
    $user_teams = [];
}

// ÖZEL ROLLERİ ÇEK
try {
    $custom_roles_list = $pdo->query("SELECT id, role_name FROM custom_roles ORDER BY role_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $custom_roles_list = [];
}

// ORGANİZASYONLARI ÇEK
try {
    $stmtOrgs = $pdo->query("SELECT id, name FROM organizations ORDER BY name ASC");
    $db_orgs = $stmtOrgs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $db_orgs = [];
}

// =======================================================================
// 3. ŞİFRE SIFIRLAMA LİNKİ GÖNDERME
// =======================================================================
if (isset($_POST['action']) && $_POST['action'] == 'send_reset_link') {
    require_csrf_token();
    $code = rand(100000, 999999);
    $expires = date("Y-m-d H:i:s", strtotime('+2 minutes'));

    $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?")->execute([$code, $expires, $user_id_to_edit]);
    $reset_link = rtrim(s('site_url'), '/') . ((($_SESSION['lang'] ?? 'tr') === 'en') ? "/reset-password?step=2" : "/sifre-sifirla?step=2") . "&email=" . urlencode($u['mail']);

    require_once __DIR__ . '/../includes/mailer.php';
    $mail = new PHPMailer(true);
    try {
        // Veritabanından mail ayarlarını çek
        $mailSettings = [];
        try {
            $stmtSet = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'mail_%'");
            while ($row = $stmtSet->fetch(PDO::FETCH_ASSOC)) {
                $mailSettings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
        }

        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->SMTPAuth = true;
        $mail->Host = $mailSettings['mail_host'] ?? 'mail.eaprimus.com';
        $mail->Port = $mailSettings['mail_port'] ?? 587;

        $secure = $mailSettings['mail_secure'] ?? 'tls';
        if ($secure == 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure == 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPAutoTLS = false;
            $mail->SMTPSecure = '';
        }

        $mail->Username = $mailSettings['mail_username'] ?? 'bildirim@eaprimus.com';
        $mail->Password = $mailSettings['mail_password'] ?? '***************';
        $fromEmail = !empty($mailSettings['mail_from_address']) ? $mailSettings['mail_from_address'] : ($mailSettings['mail_username'] ?? '');
        $fromName = !empty($mailSettings['mail_from_name']) ? $mailSettings['mail_from_name'] : 'Destek Güvenlik';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($u['mail'], $u['fullname']);
        $mail->isHTML(true);
        $mail->Subject = s('mail_password_reset_subject') ?: 'Şifre Sıfırlama Talebi / Password Reset Request';
        
        $site_url = s('site_url') ?: 'http://localhost';
        $logo_path = s('logo_path') ?: 'logo.png';
        $logo_url = function_exists('getMailLogoBase64') ? getMailLogoBase64() : rtrim($site_url, '/') . '/public/' . $logo_path;
        $site_name = s('site_title') ?: 'Destek Sistemi';
        
        // Fetch from DB if user edited it
        $defL = s('mail_default_lang', 'tr');
        $dbBody = s('mail_password_reset_' . $defL . '_body') ?: s('mail_password_reset_body') ?: s('mail_password_reset_tr_body');
        
        if ($dbBody) {
            $dbBody = html_entity_decode($dbBody, ENT_QUOTES, 'UTF-8');
            // If it's a full HTML template that got wrapped in <p> tags by the editor, clean it up
            if (preg_match('/^<p>\s*<!DOCTYPE|<p>\s*<html/i', trim($dbBody))) {
                $dbBody = preg_replace('/^<p>|<\/p>$/i', '', trim($dbBody));
                $dbBody = preg_replace('/<\/p>\s*<p>/i', "\n", $dbBody);
                $dbBody = str_replace(['<p>', '</p>', '<br>', '<br />'], '', $dbBody);
                $dbBody = html_entity_decode($dbBody, ENT_QUOTES, 'UTF-8');
            }
            $dbBody = str_replace(['{{code}}', '{{reset_link}}', '{{LOGO_SRC}}', '{{SITE_TITLE}}'], [$code, $reset_link, $logo_url, $site_name], $dbBody);
            $mail->Body = $dbBody;
        } else {
            // Modern Envato-style email template fallback
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f7fa; margin: 0; padding: 0; }
                .email-container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
                .email-header { background-color: #1e3c72; padding: 30px 20px; text-align: center; }
                .email-body { padding: 40px 30px; color: #333333; line-height: 1.6; }
                .email-body h2 { color: #1e3c72; font-size: 24px; margin-top: 0; margin-bottom: 20px; font-weight: 600; }
                .email-body p { font-size: 16px; margin-bottom: 24px; color: #555555; }
                .code-box { background-color: #f8f9fa; border: 2px dashed #1e3c72; border-radius: 6px; padding: 20px; text-align: center; margin: 30px 0; }
                .code-text { font-size: 32px; font-weight: bold; color: #1e3c72; letter-spacing: 6px; margin: 0; }
                .action-button { display: inline-block; background-color: #1e3c72; color: #ffffff !important; text-decoration: none; padding: 14px 32px; border-radius: 5px; font-weight: bold; font-size: 16px; margin-top: 10px; }
                .email-footer { background-color: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #eeeeee; }
                .email-footer p { margin: 0; font-size: 13px; color: #999999; }
            </style>
            </head>
            <body>
                <div class='email-container'>
                    <div class='email-header'>
                        <img src='{$logo_url}' alt='{$site_name}' style='max-height: 45px; width: auto; display: block; margin: 0 auto;'>
                    </div>
                    <div class='email-body'>
                        <h2>" . __("password_reset_mail") . "</h2>
                        <p>" . __("password_reset_mail_desc") . "</p>
                        <div class='code-box'>
                            <p class='code-text'>{$code}</p>
                        </div>
                        <p style='text-align: center;'>
                            <a href='{$reset_link}' class='action-button'>Linke Git / Go to Link</a>
                        </p>
                    </div>
                    <div class='email-footer'>
                        <p>&copy; " . date('Y') . " {$site_name}. " . __("all_rights_reserved", "Tüm Hakları Saklıdır") . ".</p>
                    </div>
                </div>
            </body>
            </html>
            ";
        }

        $mail->send(); $_SESSION['mesaj'] = __("reset_link_sent"); header("Location: " . $_SERVER['REQUEST_URI']); exit;
    } catch (Exception $e) {
        if (strpos($mail->ErrorInfo, 'Invalid address:  (From):') !== false || strpos($mail->ErrorInfo, 'Invalid address: (From):') !== false) {
            $hata = __("smtp_settings_error");
        } else {
            $hata = __("mail_send_error") . $mail->ErrorInfo;
        }
    }
}

// =======================================================================
// 4. GÜNCELLEME İŞLEMİ (KAYDET BUTONU)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    require_csrf_token();
    
    $is_own_profile = ($user_id_to_edit == $_SESSION['user_id']);
    // Yalnızca admin (1) ve İK/Teknik Destek (3) bu alanları değiştirebilir
    $can_edit_all = ($my_role == 1 || $my_role == 3);
    
    $fullname = trim($_POST['fullname'] ?? $u['fullname']);
    $mail = trim($_POST['mail'] ?? $u['mail']);
    $signature = trim($_POST['signature'] ?? $u['signature']);
    
    if ($can_edit_all || !$is_own_profile) {
        $username = trim($_POST['username'] ?? '');
        $role = (int) ($_POST['role'] ?? $u['role']);
        $custom_role_id = !empty($_POST['custom_role_id']) ? intval($_POST['custom_role_id']) : null;
        $bolum_id = (int) ($_POST['bolum'] ?? 0);
        $status = isset($_POST['status']) ? (int) $_POST['status'] : $u['status'];
        $can_login = isset($_POST['can_login']) ? 1 : (isset($_POST['status']) ? 0 : (isset($u['can_login']) ? (int)$u['can_login'] : 1));
        $sirket_ismi = trim($_POST['sirket_ismi'] ?? '');
    } else {
        $username = $u['username'];
        $role = $u['role'];
        $custom_role_id = $u['custom_role_id'];
        $bolum_id = $u['bolum'];
        $status = $u['status'];
        $can_login = isset($u['can_login']) ? (int)$u['can_login'] : 1;
        $sirket_ismi = $u['sirket_ismi'];
    }
    
    $tc_no_input = '';

    if (empty($fullname) || empty($username) || empty($mail)) {
        $hata = __("fill_required_fields"); // Prevent DB constraints crash
    }

    // TC Kontrolü - Tüm roller kendi profilinde TC girebilir
    $tc_no_input = trim($_POST['tc_no'] ?? '');
    if (!empty($tc_no_input)) {
        if (strlen($tc_no_input) != 11 || !ctype_digit($tc_no_input)) {
            $hata = $isTr ? "TC Kimlik No 11 haneli ve rakam olmalidir." : "TC Identity Number must be 11 digits and numeric.";
        }
    }

    $is_own_profile = ($user_id_to_edit == $_SESSION['user_id']);
    $old_password = trim($_POST['old_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = $_POST['confirm_password'] ?? '';
    $change_pass_attempted = !empty($new_password);

    try {
        // Benzersizlik Kontrolü
        if (empty($hata)) {
            // 1. Username Kontrolü
            $stmtCheckU = $pdo->prepare("SELECT id, fullname FROM users WHERE username = ? AND id != ? AND deleted_at IS NULL");
            $stmtCheckU->execute([$username, $user_id_to_edit]);
            if ($rowU = $stmtCheckU->fetch(PDO::FETCH_ASSOC)) {
                $owner = !empty($rowU['fullname']) ? " (" . $rowU['fullname'] . ")" : "";
                $hata = __("user_already_registered_username", ['username' => $username, 'owner' => $owner]);
            }

            // 2. E-Posta Kontrolü
            if (empty($hata)) {
                $stmtCheckM = $pdo->prepare("SELECT id, fullname FROM users WHERE mail = ? AND id != ? AND deleted_at IS NULL");
                $stmtCheckM->execute([$mail, $user_id_to_edit]);
                if ($rowM = $stmtCheckM->fetch(PDO::FETCH_ASSOC)) {
                    $owner = !empty($rowM['fullname']) ? " (" . $rowM['fullname'] . ")" : "";
                    $hata = __("user_already_registered_email", ['email' => $mail, 'owner' => $owner]);
                }
            }

            // 3. TC No Kontrolü (İK rolü veya TC girilmişse)
            if (empty($hata) && $my_role != 1 && !empty($tc_no_input)) {
                $stmtCheckT = $pdo->prepare("SELECT id, fullname FROM users WHERE tc_no = HEX(AES_ENCRYPT(?, '" . EAPRIMUS_KEY . "')) AND id != ? AND deleted_at IS NULL");
                $stmtCheckT->execute([$tc_no_input, $user_id_to_edit]);
                if ($rowT = $stmtCheckT->fetch(PDO::FETCH_ASSOC)) {
                    $owner = !empty($rowT['fullname']) ? " (" . $rowT['fullname'] . ")" : "";
                    $hata = __("user_already_registered_tc", ['tc' => $tc_no_input, 'owner' => $owner]);
                }
            }
        }

        if (empty($hata) && $change_pass_attempted) {
            $isTr = (($_SESSION['lang'] ?? 'tr') == 'tr');
            if ($is_own_profile || $my_role != 1) {
                if (empty($old_password)) {
                    $hata = $isTr ? "Mevcut (eski) şifrenizi girmelisiniz." : "You must enter your current (old) password.";
                } else {
                    $is_old_valid = false;
                    if (!empty($u['password'])) {
                        if (password_verify($old_password, $u['password'])) {
                            $is_old_valid = true;
                        } elseif (hash('sha256', $old_password) === $u['password']) {
                            $is_old_valid = true;
                        }
                    }
                    if (!$is_old_valid) {
                        $hata = $isTr ? "Mevcut (eski) şifreniz hatalı!" : "Current (old) password is incorrect!";
                    }
                }
            }

            if (empty($hata)) {
                if ($new_password !== $confirm_password) {
                    $hata = __("password_mismatch");
                } elseif (strlen($new_password) < 6) {
                    $hata = __("password_min_length");
                }
            }
        }

        // Profil Resmi Format Kontrolü
        $isTr = (($_SESSION['lang'] ?? 'tr') == 'tr');
        if (empty($hata) && isset($_FILES['profil_fotosu']) && $_FILES['profil_fotosu']['error'] == UPLOAD_ERR_OK) {
            $imgExt = strtolower(pathinfo($_FILES['profil_fotosu']['name'], PATHINFO_EXTENSION));
            $allowedImgExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
            if (!in_array($imgExt, $allowedImgExts)) {
                $hata = $isTr ? "⚠️ Profil resmi sadece .jpg, .jpeg, .png, .webp, .gif veya .svg formatında olabilir!" : "⚠️ Avatar image must be .jpg, .jpeg, .png, .webp, .gif or .svg!";
            }
        }

        // KAYIT İŞLEMİ
        if (empty($hata)) {

            $user_lang = in_array($_POST['lang'] ?? 'tr', ['tr', 'en']) ? $_POST['lang'] : 'tr';

            $sql = "UPDATE users SET fullname=?, username=?, mail=?, role=?, custom_role_id=?, bolum=?, status=?, can_login=?, signature=?, sirket_ismi=?, lang=?";
            $params = [$fullname, $username, $mail, $role, $custom_role_id, $bolum_id, $status, $can_login, $signature, $sirket_ismi, $user_lang];

            // TC kaydet: kendi profili veya admin başkasının profilini düzenliyorsa
            if ($is_own_profile || $can_edit_all) {
                if (!empty($tc_no_input)) {
                    $sql .= ", tc_no=HEX(AES_ENCRYPT(?, '" . EAPRIMUS_KEY . "'))";
                    $params[] = $tc_no_input;
                } else {
                    $sql .= ", tc_no=''";
                }
            }

            if ($change_pass_attempted && empty($hata) && !empty($new_password)) {
                $sql .= ", password=?";
                $params[] = hash('sha256', $new_password);
            }

            // Track user changes for the timeline
            $changesTr = [];
            $changesEn = [];

            if ($u['fullname'] !== $fullname) {
                $changesTr[] = "Ad Soyad: {$u['fullname']} -> {$fullname}";
                $changesEn[] = "Full Name: {$u['fullname']} -> {$fullname}";
            }
            if ($u['username'] !== $username) {
                $changesTr[] = "Kullanıcı Adı: {$u['username']} -> {$username}";
                $changesEn[] = "Username: {$u['username']} -> {$username}";
            }
            if ($u['mail'] !== $mail) {
                $changesTr[] = "E-posta: {$u['mail']} -> {$mail}";
                $changesEn[] = "Email: {$u['mail']} -> {$mail}";
            }
            if ((int)$u['role'] !== (int)$role) {
                $roleNamesTr = [1 => 'Yönetici', 2 => 'Kullanıcı', 3 => 'İK / Destek'];
                $roleNamesEn = [1 => 'Admin', 2 => 'User', 3 => 'HR / Support'];
                $oldRoleNameTr = $roleNamesTr[$u['role']] ?? 'Bilinmeyen';
                $newRoleNameTr = $roleNamesTr[$role] ?? 'Bilinmeyen';
                $oldRoleNameEn = $roleNamesEn[$u['role']] ?? 'Unknown';
                $newRoleNameEn = $roleNamesEn[$role] ?? 'Unknown';
                $changesTr[] = "Rol: {$oldRoleNameTr} -> {$newRoleNameTr}";
                $changesEn[] = "Role: {$oldRoleNameEn} -> {$newRoleNameEn}";
            }
            if ((int)$u['bolum'] !== (int)$bolum_id) {
                $oldBolum = $u['bolum'] > 0 ? $pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . intval($u['bolum']))->fetchColumn() : 'Yok';
                $newBolum = $bolum_id > 0 ? $pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . intval($bolum_id))->fetchColumn() : 'Yok';
                $changesTr[] = "Bölüm: {$oldBolum} -> {$newBolum}";
                $changesEn[] = "Department: " . ($oldBolum === 'Yok' ? 'None' : $oldBolum) . " -> " . ($newBolum === 'Yok' ? 'None' : $newBolum);
            }
            if ((int)$u['status'] !== (int)$status) {
                $statusTr = [1 => 'Aktif', 0 => 'Pasif'];
                $statusEn = [1 => 'Active', 0 => 'Inactive'];
                $oldStatusTr = $statusTr[$u['status']] ?? 'Bilinmeyen';
                $newStatusTr = $statusTr[$status] ?? 'Bilinmeyen';
                $oldStatusEn = $statusEn[$u['status']] ?? 'Unknown';
                $newStatusEn = $statusEn[$status] ?? 'Unknown';
                $changesTr[] = "Durum: {$oldStatusTr} -> {$newStatusTr}";
                $changesEn[] = "Status: {$oldStatusEn} -> {$newStatusEn}";
            }
            if (trim((string)($u['sirket_ismi'] ?? '')) !== trim((string)$sirket_ismi)) {
                $oldSirket = trim((string)($u['sirket_ismi'] ?? '')) ?: 'Yok';
                $newSirket = trim((string)$sirket_ismi) ?: 'Yok';
                $changesTr[] = "Şirket: {$oldSirket} -> {$newSirket}";
                $changesEn[] = "Company: " . ($oldSirket === 'Yok' ? 'None' : $oldSirket) . " -> " . ($newSirket === 'Yok' ? 'None' : $newSirket);
            }
            if (isset($tc_no_input) && isset($u['aes_tc'])) {
                if (trim((string)($u['aes_tc'] ?? '')) !== trim((string)$tc_no_input) && (!empty($u['aes_tc']) || !empty($tc_no_input))) {
                    $oldTc = trim((string)($u['aes_tc'] ?? '')) ? 'Gizli' : 'Yok';
                    $newTc = trim((string)$tc_no_input) ? 'Gizli (Güncellendi)' : 'Kaldırıldı';
                    $changesTr[] = "TC Kimlik: {$oldTc} -> {$newTc}";
                    $changesEn[] = "National ID: {$oldTc} -> {$newTc}";
                }
            }

            if (!empty($changesTr)) {
                $logMsg = "Bilgi güncellendi: Kullanıcı bilgileri güncellendi. Güncellenenler: " . implode(", ", $changesTr);
                if (file_exists(__DIR__ . '/../includes/asset_helpers.php')) {
                    require_once __DIR__ . '/../includes/asset_helpers.php';
                }
                if (function_exists('addAssetLog')) {
                    addAssetLog($pdo, $user_id_to_edit, $current_user_id, 'updated', $logMsg, null, 'user');
                }
            }

            $sql .= " WHERE id=?";
            $params[] = $user_id_to_edit;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Takımları güncelle
            if (!$is_own_profile || $my_role == 1) { // Kilitli değilse
                $pdo->prepare("DELETE FROM teams_users WHERE user_id = ?")->execute([$user_id_to_edit]);
                if (isset($_POST['teams']) && is_array($_POST['teams'])) {
                    $stmtTeams = $pdo->prepare("INSERT INTO teams_users (team_id, user_id) VALUES (?, ?)");
                    foreach ($_POST['teams'] as $tid) {
                        $tid = (int)$tid;
                        if ($tid > 0) {
                            $stmtTeams->execute([$tid, $user_id_to_edit]);
                        }
                    }
                }
            }

            // Profil Fotoğrafı Kaydı
            if (isset($_FILES['profil_fotosu']) && $_FILES['profil_fotosu']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../../public/uploads/profil/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $ext = strtolower(pathinfo($_FILES['profil_fotosu']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
                    $file_name = "user_" . $user_id_to_edit . "." . $ext;
                    if (move_uploaded_file($_FILES['profil_fotosu']['tmp_name'], $upload_dir . $file_name)) {
                        $pdo->prepare("UPDATE users SET profil_fotosu = ? WHERE id = ?")->execute([$file_name, $user_id_to_edit]);
                        if ($is_own_profile) {
                            $_SESSION['profil_fotosu'] = $file_name;
                            unset($_SESSION['user_avatar']);
                        }
                    }
                }
            } elseif (!empty($_POST['avatar_url'])) {
                $avatarUrl = filter_var($_POST['avatar_url'], FILTER_SANITIZE_URL);
                if (strpos($avatarUrl, 'dist/img/avatars/') !== false) {
                    $avatarUrl = preg_replace('/^.*(dist\/img\/avatars\/[a-zA-Z0-9_\-\.]+)/', '$1', $avatarUrl);
                }
                $pdo->prepare("UPDATE users SET profil_fotosu = ? WHERE id = ?")->execute([$avatarUrl, $user_id_to_edit]);
                if ($is_own_profile) {
                    $_SESSION['profil_fotosu'] = $avatarUrl;
                    unset($_SESSION['user_avatar']);
                }
            }

            $_SESSION['mesaj'] = __("user_updated");
            if ($is_own_profile) {
                $_SESSION['fullname'] = $fullname;
                $_SESSION['mail'] = $mail;
                $_SESSION['email'] = $mail;
                $_SESSION['lang'] = $user_lang;
                echo "<script>window.location.href = 'profilim';</script>";
            } else {
                echo "<script>window.location.href = 'kullanici-listele';</script>";
            }
            exit;
        }

    } catch (PDOException $e) {
        $hata = __("db_error") . ": " . $e->getMessage();
    }
}
?>

<style>
    /* Modern UI Design variables & overrides */
    .profile-cover-card {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%) !important;
        border: none !important;
        border-radius: 20px !important;
        position: relative;
        overflow: hidden;
        margin-bottom: 30px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15) !important;
    }
    .profile-cover-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: radial-gradient(circle at 80% 20%, rgba(59, 130, 246, 0.15) 0%, transparent 50%);
    }
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
        background: rgba(248, 250, 252, 0.8);
        border: 1.5px solid #e2e8f0;
        border-radius: 16px;
        padding: 16px;
        transition: all 0.3s ease;
    }
    body.dark-mode .avatar-glass-card {
        background: rgba(15, 23, 42, 0.6) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }
    .team-select-box {
        background: #f8fafc;
        border-color: #e2e8f0;
    }
    body.dark-mode .team-select-box,
    .dark-mode .team-select-box {
        background: rgba(15, 23, 42, 0.6) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
        color: #f1f5f9 !important;
    }
    body.dark-mode .team-select-box label,
    .dark-mode .team-select-box label {
        color: #f1f5f9 !important;
    }
</style>

<form method="POST" autocomplete="off" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="row">
        <!-- Profil Cover Header -->
        <div class="col-12">
            <?php
            $defPhotoRaw = $u['profil_fotosu'] ?? '';
            $_logoRaw = s('logo_path') ?: 'logo.png';
            $defFallback = $base_url . (str_starts_with($_logoRaw, 'public/') ? $_logoRaw : 'public/' . $_logoRaw);
            if (empty($defPhotoRaw) || $defPhotoRaw === 'default.png') {
                $defPhoto = $defFallback;
            } elseif (filter_var($defPhotoRaw, FILTER_VALIDATE_URL)) {
                $defPhoto = $defPhotoRaw;
            } elseif (strpos($defPhotoRaw, 'dist/img/avatars/') !== false) {
                $defPhoto = $base_url . preg_replace('/^.*(dist\/img\/avatars\/[a-zA-Z0-9_\-\.]+)/', '$1', $defPhotoRaw);
            } else {
                $defPhoto = $base_url . 'uploads/profil/' . $defPhotoRaw . '?v=' . time();
            }
            ?>
            <div class="card profile-cover-card py-5 px-4 mb-4">
                <div class="profile-cover-overlay"></div>
                <div class="row align-items-center position-relative" style="z-index: 2;">
                    <div class="col-auto text-center text-md-left">
                        <div class="position-relative d-inline-block">
                            <img id="avatarHeaderPreview" src="<?= $defPhoto ?>" alt="Avatar" class="img-circle border border-white" style="width: 100px; height: 100px; object-fit: cover; border-width: 4px !important; box-shadow: 0 8px 24px rgba(0,0,0,0.3);"
                                onerror="this.onerror=null; this.src='<?= $defFallback ?>';">
                            <label for="customFile" class="btn btn-sm btn-primary rounded-circle position-absolute" style="bottom: 0; right: 0; width: 34px; height: 34px; padding: 0; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.3);" title="<?= $isTr ? 'Fotoğraf Yükle' : 'Upload Photo' ?>">
                                <i class="fas fa-camera" style="font-size: 14px;"></i>
                            </label>
                        </div>
                    </div>
                    <div class="col text-center text-md-left mt-3 mt-md-0 text-white">
                        <h2 class="font-weight-bold mb-1" style="font-family: 'Plus Jakarta Sans', sans-serif;"><i class="fas fa-user-edit mr-2"></i><?= htmlspecialchars($u['fullname']) ?></h2>
                        <p class="mb-2 opacity-75 font-weight-medium">@<?= htmlspecialchars($u['username']) ?> &bull; <?= htmlspecialchars($u['sirket_ismi'] ?? '') ?></p>
                        <div>
                            <?php if ($u['role'] == 1): ?>
                                <span class="badge badge-primary px-3 py-1 font-weight-bold" style="border-radius: 20px; background: rgba(59, 130, 246, 0.2); border: 1.5px solid #3b82f6;"><?= __("role_admin") ?></span>
                            <?php elseif ($u['role'] == 3): ?>
                                <span class="badge badge-info px-3 py-1 font-weight-bold" style="border-radius: 20px; background: rgba(0, 188, 212, 0.2); border: 1.5px solid #00bcd4;"><?= __("role_hr") ?></span>
                            <?php else: ?>
                                <span class="badge badge-light px-3 py-1 font-weight-bold text-muted" style="border-radius: 20px;"><?= __("role_staff") ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($mesaj): ?>
            <div class="col-12">
                <div class="alert alert-success alert-dismissible" style="border-radius:12px; border:none;"><button type="button" class="close"
                        data-dismiss="alert">&times;</button><i class="fas fa-check-circle mr-1"></i>
                    <?= htmlspecialchars($mesaj) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($hata): ?>
            <div class="col-12">
                <div class="alert alert-danger alert-dismissible" style="border-radius:12px; border:none;"><button type="button" class="close"
                        data-dismiss="alert">&times;</button><i class="fas fa-exclamation-circle mr-1"></i>
                    <?= htmlspecialchars($hata) ?></div>
            </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="card modern-card mb-4">
                <div class="card-header bg-transparent border-bottom-0 pt-4 px-4">
                    <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-id-card-alt mr-2 text-primary"></i><?= __("staff_information") ?></h4>
                </div>
                <div class="card-body px-4 py-3">
                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="form-section-title"><i class="fas fa-user-circle mr-2"></i><?= $isTr ? 'Kişisel Bilgiler' : 'Personal Details' ?></h5>
                            
                            <div class="modern-input-group">
                                <label><?= __("profile_photo_avatar") ?></label>
                                <div class="avatar-glass-card">
                                    <div class="d-flex align-items-center mb-3">
                                        <img id="avatarFormPreview" src="<?= $defPhoto ?>" class="img-circle border mr-3"
                                            style="width: 56px; height: 56px; object-fit: cover; background: #fff; border: 2px solid #3b82f6 !important; box-shadow: 0 4px 12px rgba(0,0,0,0.08);"
                                            onerror="this.onerror=null; this.src='<?= $defFallback ?>';">
                                        <div class="flex-grow-1">
                                            <div class="custom-file" style="max-width: 100%;">
                                                <input type="file" name="profil_fotosu" class="custom-file-input" id="customFile"
                                                    accept="image/*" onchange="previewUpload(this)">
                                                <label class="custom-file-label" for="customFile" id="fileLabel" style="font-size:12px; height:38px; line-height:24px; border-radius:10px; border:1.5px solid #e2e8f0;"><?= $isTr ? 'Fotoğraf Yükle (Dosya Seç)' : 'Upload Photo (Choose File)' ?></label>
                                            </div>
                                        </div>
                                        <input type="hidden" name="avatar_url" id="avatarUrlInput" value="<?= htmlspecialchars($u['profil_fotosu'] ?? '') ?>">
                                    </div>
                                    
                                    <div class="d-flex align-items-center justify-content-between">
                                        <button class="btn btn-sm btn-outline-primary font-weight-bold" type="button" data-toggle="collapse" data-target="#avatarCollapse" aria-expanded="false" aria-controls="avatarCollapse" style="border-radius:20px; padding:5px 14px; transition:all 0.3s;">
                                            <i class="fas fa-user-circle mr-2"></i><?= (!isset($_SESSION['lang']) || $_SESSION['lang'] !== 'en') ? 'Hazır Avatar Seç' : 'Choose Avatar' ?> <i class="fas fa-chevron-down ml-1"></i>
                                        </button>
                                        <small class="text-muted"><i class="fas fa-info-circle mr-1"></i> JPG, PNG, WEBP, GIF, SVG</small>
                                    </div>

                                    <div class="collapse mt-3" id="avatarCollapse">
                                        <div class="p-3" style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:inset 0 2px 4px rgba(0,0,0,0.02);">
                                            <div id="avatar-grid" class="d-flex flex-wrap justify-content-start align-items-center" style="gap:8px; padding:4px;">
                                                <!-- Populated dynamically via JS -->
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <small class="text-muted mt-1 d-block"><?= __("avatar_hint") ?></small>
                            </div>

                            <div class="modern-input-group">
                                <label><?= __("full_name") ?> <span class="text-danger">*</span></label>
                                <div class="modern-field-container">
                                    <input type="text" name="fullname" class="form-control"
                                        value="<?= htmlspecialchars($u['fullname']) ?>" required autocomplete="off">
                                    <i class="fas fa-user field-icon"></i>
                                </div>
                            </div>

                            <div class="modern-input-group">
                                <label><?= __("tc_no") ?> <small class="text-muted">(Opsiyonel)</small></label>
                                <div class="modern-field-container">
                                    <?php 
                                    $is_own_profile = ($user_id_to_edit == $_SESSION['user_id']);
                                    if (!$is_own_profile): 
                                    ?>
                                        <input type="text" class="form-control" value="***********" disabled>
                                        <i class="fas fa-lock field-icon"></i>
                                    <?php else: ?>
                                        <input type="text" name="tc_no" class="form-control"
                                            value="<?= htmlspecialchars($u['aes_tc'] ?? '') ?>" maxlength="11">
                                        <i class="fas fa-id-card field-icon"></i>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$is_own_profile): ?>
                                    <small class="form-text text-muted mt-1 d-block"><i class="fas fa-info-circle mr-1"></i> KVKK gereği başkalarının TC Kimlik numarası görüntülenemez ve değiştirilemez.</small>
                                <?php endif; ?>
                            </div>

                            <?php $attr_disabled = ($is_own_profile && !$can_edit_all) ? 'disabled' : ''; ?>
                            <div class="modern-input-group">
                                 <label><?= __("username") ?> <span class="text-danger">*</span></label>
                                 <div class="modern-field-container">
                                     <input type="text" name="username" class="form-control"
                                         value="<?= htmlspecialchars($u['username']) ?>" required <?= $attr_disabled ?>>
                                     <i class="fas fa-user-tag field-icon"></i>
                                 </div>
                             </div>

                             <div class="modern-input-group">
                                 <label><?= __("email") ?> <span class="text-danger">*</span></label>
                                 <div class="modern-field-container">
                                     <input type="email" name="mail" class="form-control" value="<?= htmlspecialchars($u['mail']) ?>"
                                         required>
                                     <i class="fas fa-envelope field-icon"></i>
                                 </div>
                             </div>

                             <div class="modern-input-group">
                                 <label><?= __("account_status") ?></label>
                                 <div class="modern-field-container">
                                     <select name="status" class="form-control modern-select" <?= $attr_disabled ?> id="statusSelect">
                                         <option value="1" <?= $u['status'] == 1 ? 'selected' : '' ?>><?= __("active") ?></option>
                                         <option value="0" <?= $u['status'] == 0 ? 'selected' : '' ?>><?= __("passive") ?></option>
                                     </select>
                                     <i class="fas <?= $u['status'] == 1 ? 'fa-toggle-on text-success' : 'fa-toggle-off text-muted' ?> field-icon" id="statusIcon" style="<?= $u['status'] == 1 ? 'color:#10b981 !important;' : '' ?>"></i>
                                 </div>
                                 <small class="text-muted mt-1 d-block"><?= __("active_passive_hint") ?></small>
                             </div>

                             <div class="modern-input-group mt-3">
                                 <label><?= __("can_login") ?></label>
                                 <div class="custom-control custom-switch mt-1">
                                     <input type="checkbox" class="custom-control-input" id="canLoginSwitch" name="can_login" value="1" <?= (isset($u['can_login']) && (int)$u['can_login'] === 0) ? '' : 'checked' ?> <?= $attr_disabled ?> style="cursor:pointer;">
                                     <label class="custom-control-label font-weight-bold text-dark" for="canLoginSwitch" style="cursor:pointer;">
                                         <?= $isTr ? 'Personel panele/sisteme giriş yapabilsin' : 'Allow user to log into panel/system' ?>
                                     </label>
                                 </div>
                                 <small class="text-muted mt-1 d-block"><?= __("can_login_hint") ?></small>
                             </div>

                            <div class="modern-input-group">
                                <label><?= __("language") ?? 'Varsayılan Dil / Default Language' ?></label>
                                <div class="modern-field-container">
                                    <select name="lang" class="form-control modern-select">
                                        <option value="tr" <?= ($u['lang'] ?? 'tr') == 'tr' ? 'selected' : '' ?>>Türkçe</option>
                                        <option value="en" <?= ($u['lang'] ?? 'tr') == 'en' ? 'selected' : '' ?>>English</option>
                                    </select>
                                    <i class="fas fa-language field-icon"></i>
                                </div>
                            </div>

                            <div class="modern-input-group">
                                <label><?= __("company") ?></label>
                                <div class="modern-field-container">
                                    <input type="text" name="sirket_ismi" class="form-control" list="orgs_list" value="<?= htmlspecialchars($u['sirket_ismi'] ?? '') ?>" placeholder="Şirket ismini yazın veya seçin" <?= $attr_disabled ?>>
                                    <i class="fas fa-building field-icon"></i>
                                    <datalist id="orgs_list">
                                        <?php foreach($db_orgs as $org): ?>
                                            <option value="<?= htmlspecialchars($org['name']) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <h5 class="form-section-title"><i class="fas fa-briefcase mr-2"></i><?= $isTr ? 'Kurumsal & Rol Bilgileri' : 'Corporate & Role Details' ?></h5>
                            
                            <div class="modern-input-group">
                                <label><?= __("role") ?> <span class="text-danger">*</span></label>
                                <div class="modern-field-container">
                                    <select name="role" class="form-control modern-select" required <?= $attr_disabled ?>>
                                        <option value="2" <?= $u['role'] == 2 ? 'selected' : '' ?>><?= __("personnel") ?></option>
                                        <?php if ($my_role == 1 || $u['role'] == 3 || $u['role'] == 1): ?>
                                            <option value="3" <?= $u['role'] == 3 ? 'selected' : '' ?>><?= __("tech_support") ?></option>
                                            <option value="1" <?= $u['role'] == 1 ? 'selected' : '' ?>><?= __("super_admin") ?></option>
                                        <?php endif; ?>
                                    </select>
                                    <i class="fas fa-user-shield field-icon"></i>
                                </div>
                            </div>

                            <div class="modern-input-group">
                                <label><?= __("custom_dynamic_role") ?></label>
                                <div class="modern-field-container">
                                    <select name="custom_role_id" class="form-control modern-select" <?= $attr_disabled ?>>
                                        <option value=""><?= __("no_default_role") ?></option>
                                        <?php foreach ($custom_roles_list as $cr): ?>
                                            <option value="<?= $cr['id'] ?>" <?= ($u['custom_role_id'] == $cr['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cr['role_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="fas fa-users-cog field-icon"></i>
                                </div>
                            </div>

                            <div class="modern-input-group">
                                <label><?= $isTr ? 'Bölüm / Departman' : 'Department' ?></label>
                                <div class="modern-field-container">
                                    <select name="bolum" class="form-control modern-select" <?= $attr_disabled ?>>
                                        <option value="0"><?= $isTr ? 'Hiçbiri' : 'None' ?></option>
                                        <?php foreach ($bolumler as $b): ?>
                                            <option value="<?= $b['id'] ?>" <?= ($u['bolum'] == $b['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($b['bolum_adi']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="fas fa-sitemap field-icon"></i>
                                </div>
                            </div>

                            <div class="modern-input-group">
                                <label><?= $isTr ? 'Takımlar (Birden fazla seçilebilir)' : 'Teams (Multiple selection)' ?></label>
                                <div class="modern-field-container team-select-box" style="border: 1px solid rgba(128,128,128,0.2); border-radius: 12px; padding: 15px; max-height: 200px; overflow-y: auto;">
                                    <?php foreach ($all_teams as $t): ?>
                                        <div class="custom-control custom-checkbox mb-2">
                                            <input type="checkbox" name="teams[]" class="custom-control-input" id="team_<?= $t['id'] ?>" value="<?= $t['id'] ?>" <?= in_array($t['id'], $user_teams) ? 'checked' : '' ?> <?= $attr_disabled ?>>
                                            <label class="custom-control-label" for="team_<?= $t['id'] ?>" style="cursor: pointer;"><?= htmlspecialchars($t['name']) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if(empty($all_teams)): ?>
                                        <small class="text-muted"><?= $isTr ? 'Hiç takım bulunamadı.' : 'No teams found.' ?></small>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted mt-2 d-block"><i class="fas fa-info-circle"></i> <?= $isTr ? 'Personeli dahil etmek istediğiniz takımları işaretleyin. Takımdan çıkarmak için tiki kaldırın.' : 'Check the teams you want to include the user in. Uncheck to remove.' ?></small>
                            </div>

                            <div class="form-group mt-2 mb-3">
                                <label class="text-info font-weight-bold mb-2"><i class="fas fa-file-signature mr-1"></i> <?= __("email_signature_label") ?></label>
                                <textarea name="signature" id="signatureEditor" class="form-control"
                                    rows="4"><?= htmlspecialchars($u['signature'] ?? '') ?></textarea>
                            </div>

                            <hr class="my-4">

                            <?php if ($is_own_profile || $my_role == 1): ?>
                                <div class="p-3 rounded mb-4" style="background: rgba(220, 53, 69, 0.05); border: 1.5px solid rgba(220, 53, 69, 0.15); border-radius: 12px !important;">
                                    <label class="text-danger font-weight-bold mb-3"><i class="fas fa-key mr-1"></i> <?= __("manual_password_change") ?></label>
                                    
                                    <div class="modern-input-group mb-2">
                                        <div class="modern-field-container">
                                            <input type="password" name="old_password" class="form-control" placeholder="<?= $isTr ? 'Mevcut (Eski) Şifre' : 'Current (Old) Password' ?>" autocomplete="current-password">
                                            <i class="fas fa-key field-icon"></i>
                                        </div>
                                    </div>
                                    <div class="modern-input-group mb-2">
                                        <div class="modern-field-container">
                                            <input type="password" name="new_password" class="form-control" placeholder="<?= __("new_password") ?>" autocomplete="new-password">
                                            <i class="fas fa-lock field-icon"></i>
                                        </div>
                                    </div>
                                    <div class="modern-input-group mb-2">
                                        <div class="modern-field-container">
                                            <input type="password" name="confirm_password" class="form-control" placeholder="<?= __("new_password_again") ?>" autocomplete="new-password">
                                            <i class="fas fa-redo field-icon"></i>
                                        </div>
                                    </div>
                                    
                                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> <?= __("manual_password_hint") ?></small>
                                </div>
                            <?php endif; ?>

                            <div class="form-group mt-3">
                                <label class="small text-muted font-weight-bold"><i class="fas fa-magic mr-1"></i> <?= __("password_operations") ?></label>
                                <button type="submit" name="action" value="send_reset_link"
                                    class="btn btn-outline-primary btn-block font-weight-bold" style="border-radius: 10px; padding: 10px;">
                                    <i class="fas fa-paper-plane mr-2"></i> <?= __("send_reset_link") ?>
                                </button>
                                <small class="text-muted d-block mt-1"><?= __("send_reset_link_hint") ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0 pb-4 px-4 text-right">
                    <a href="dashboard.php?route=kullanici_listele" class="btn btn-secondary mr-2" style="border-radius: 12px; padding: 12px 24px; font-weight: 600;"><?= __("cancel") ?></a>
                    <button type="submit" class="btn btn-modern-success"><i class="fas fa-save mr-1"></i> <?= __("save") ?></button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- API ve Ajan Bağlantısı Kartı (Sadece Yöneticiler İçin) -->
<?php if ((int)$my_role === 1): ?>
<div class="card modern-card mb-5">
    <div class="card-header bg-transparent border-bottom-0 pt-4 px-4">
        <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-terminal mr-2 text-info"></i><?= $isTr ? 'API ve Ajan Bağlantısı' : 'API & Agent Connection' ?></h4>
    </div>
    <div class="card-body px-4 py-3">
        <p class="small text-muted mb-4">
            <?= $isTr 
                ? 'Bu kullanıcıya özel API anahtarı üreterek PowerShell ajanının bu kullanıcıyla eşleşmiş şekilde çalışmasını sağlayabilirsiniz.' 
                : 'You can generate a user-specific API key to ensure the PowerShell agent runs associated with this user.' ?>
        </p>
        
        <div class="row align-items-center">
            <div class="col-md-8">
                <?php if ($userKey): ?>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold small text-muted">Client ID (API Key)</label>
                            <div class="input-group">
                                <input type="text" class="form-control font-mono" value="<?= htmlspecialchars($userKey['client_id']) ?>" readonly style="background-color: #0f172a !important; color: #38bdf8 !important; font-family: monospace; border: 1.5px solid rgba(255,255,255,0.05); border-radius: 8px;">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('<?= htmlspecialchars($userKey['client_id']) ?>');" style="border-top-right-radius: 8px; border-bottom-right-radius: 8px;"><i class="fas fa-copy"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold small text-muted">Client Secret (API Secret)</label>
                            <div class="input-group">
                                <input type="password" id="user_secret_field" class="form-control font-mono" value="<?= htmlspecialchars($userKey['client_secret_plain']) ?>" readonly style="background-color: #0f172a !important; color: #38bdf8 !important; font-family: monospace; border: 1.5px solid rgba(255,255,255,0.05); border-radius: 8px;">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="const f=document.getElementById('user_secret_field'); f.type = f.type==='password'?'text':'password';"><i class="fas fa-eye"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('<?= htmlspecialchars($userKey['client_secret_plain']) ?>');" style="border-top-right-radius: 8px; border-bottom-right-radius: 8px;"><i class="fas fa-copy"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-custom-info py-3 mb-0" style="border-radius: 12px;">
                        <i class="fas fa-info-circle mr-2"></i> <?= $isTr ? 'Bu kullanıcı için tanımlanmış aktif bir API anahtarı bulunmuyor.' : 'There is no active API key defined for this user.' ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-right">
                <?php if ($userKey): ?>
                    <a href="<?= rtrim($base_url, '/') ?>/ajax/download_agent.php?personal=1&user_id=<?= $user_id_to_edit ?>" class="btn btn-success font-weight-bold mr-1" style="border-radius: 8px;">
                        <i class="fab fa-windows mr-1"></i> <?= $isTr ? 'Ajanı İndir (BAT)' : 'Download Agent (BAT)' ?>
                    </a>
                    <a href="<?= rtrim($base_url, '/') ?>/ajax/download_agent_linux.php?personal=1&user_id=<?= $user_id_to_edit ?>" class="btn btn-info font-weight-bold mr-1" style="border-radius: 8px;">
                        <i class="fab fa-linux mr-1"></i> <?= $isTr ? 'Ajanı İndir (SH)' : 'Download Agent (SH)' ?>
                    </a>
                    <button type="button" onclick="revokeUserKey()" class="btn btn-outline-danger font-weight-bold" style="border-radius: 8px;">
                        <i class="fas fa-ban mr-1"></i> <?= $isTr ? 'Anahtarı İptal Et' : 'Revoke Key' ?>
                    </button>
                <?php else: ?>
                    <button type="button" onclick="generateUserKey()" class="btn btn-info font-weight-bold" style="border-radius: 8px; padding: 8px 16px;">
                        <i class="fas fa-plus-circle mr-1"></i> <?= $isTr ? 'API Anahtarı Üret' : 'Generate API Key' ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Summernote CSS & JS for Modern Email Signature -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
<script>
    $(document).ready(function() {
        $('#signatureEditor').summernote({
            height: 250,
            placeholder: '<?= __("email_signature_placeholder") ?>',
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'clear']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['table', ['table']],
                ['insert', ['link', 'picture', 'hr']],
                ['view', ['fullscreen', 'codeview']]
            ]
        });
        
        // Dark mode adjustments
        if ($('body').hasClass('dark-mode')) {
            $('.note-editor').css('border-color', '#6c757d');
            $('.note-editable').css({'background-color': '#212529', 'color': '#fff'});
            $('.note-toolbar').css({'background-color': '#343a40', 'border-bottom-color': '#6c757d'});
            $('.note-toolbar .btn').css({'color': '#fff', 'background-color': 'transparent', 'border-color': '#6c757d'});
        }

        // Keep status toggle icon styled green if active
        $('#statusSelect').on('change', function() {
            if ($(this).val() == '1') {
                $('#statusIcon').removeClass('fa-toggle-off text-muted').addClass('fa-toggle-on text-success').get(0).style.setProperty('color', '#10b981', 'important');
            } else {
                $('#statusIcon').removeClass('fa-toggle-on text-success').addClass('fa-toggle-off text-muted').css('color', '');
            }
        });

        renderAvatarGrid();
    });

    const distBaseUrl = '<?= $base_url ?>dist';
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
            const isMatch = (currentSelected === url || (currentSelected && url.endsWith(currentSelected)));
            item.style.border = isMatch ? '3px solid #3b82f6' : '1px solid #cbd5e1';
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

    function selectAvatar(url) {
        const headerPreview = document.getElementById('avatarHeaderPreview');
        const formPreview = document.getElementById('avatarFormPreview');
        if (headerPreview) headerPreview.src = url;
        if (formPreview) formPreview.src = url;
        
        document.getElementById('avatarUrlInput').value = url;
        const customFileInput = document.getElementById('customFile');
        if (customFileInput) customFileInput.value = '';
        const fileLabel = document.getElementById('fileLabel');
        if (fileLabel) fileLabel.innerText = '<?= $isTr ? "Fotoğraf Yükle (Dosya Seç)" : "Upload Photo (Choose File)" ?>';
        renderAvatarGrid();
    }

    function previewUpload(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function (e) {
                const headerPreview = document.getElementById('avatarHeaderPreview');
                const formPreview = document.getElementById('avatarFormPreview');
                if (headerPreview) headerPreview.src = e.target.result;
                if (formPreview) formPreview.src = e.target.result;
            }
            reader.readAsDataURL(input.files[0]);

            const fileLabel = document.getElementById('fileLabel');
            if (fileLabel) fileLabel.innerText = input.files[0].name;
            document.getElementById('avatarUrlInput').value = '';
        }
    }
</script>

<?php if ((int)$my_role === 1): ?>
<script>
    function generateUserKey() {
        Swal.fire({
            title: '<?= $isTr ? "Emin misiniz?" : "Are you sure?" ?>',
            text: '<?= $isTr ? "Bu kullanıcı için yeni bir API anahtarı üretilecek. Varsa eski anahtarlar iptal edilir." : "A new API key will be generated for this user. Existing keys, if any, will be revoked." ?>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<?= $isTr ? "Evet, Üret" : "Yes, Generate" ?>',
            cancelButtonText: '<?= $isTr ? "İptal" : "Cancel" ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: '<?= $isTr ? "Üretiliyor..." : "Generating..." ?>', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                $.post(window.location.href, { action: 'generate_user_api_key_admin', csrf_token: '<?= csrf_token() ?>' }, (res) => {
                    if (res.status === 'success') {
                        Swal.fire('<?= $isTr ? "Başarılı!" : "Success!" ?>', res.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('<?= $isTr ? "Hata!" : "Error!" ?>', res.message, 'error');
                    }
                }, 'json');
            }
        });
    }

    function revokeUserKey() {
        Swal.fire({
            title: '<?= $isTr ? "Emin misiniz?" : "Are you sure?" ?>',
            text: '<?= $isTr ? "Bu kullanıcının API anahtarı iptal edilecek ve bu anahtarı kullanan ajanlar çalışmayacaktır." : "This user\'s API key will be revoked and agents using it will stop working." ?>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<?= $isTr ? "Evet, İptal Et" : "Yes, Revoke" ?>',
            cancelButtonText: '<?= $isTr ? "Vazgeç" : "Cancel" ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: '<?= $isTr ? "İptal Ediliyor..." : "Revoking..." ?>', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                $.post(window.location.href, { action: 'revoke_user_api_key_admin', csrf_token: '<?= csrf_token() ?>' }, (res) => {
                    if (res.status === 'success') {
                        Swal.fire('<?= $isTr ? "İptal Edildi!" : "Revoked!" ?>', res.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('<?= $isTr ? "Hata!" : "Error!" ?>', res.message, 'error');
                    }
                }, 'json');
            }
        });
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                Swal.fire('<?= $isTr ? 'Kopyalandı!' : 'Copied!' ?>', '', 'success');
            }).catch(() => {
                fallbackCopyToClipboard(text);
            });
        } else {
            fallbackCopyToClipboard(text);
        }
    }

    function fallbackCopyToClipboard(text) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            Swal.fire('<?= $isTr ? 'Kopyalandı!' : 'Copied!' ?>', '', 'success');
        } catch (err) {
            Swal.fire('<?= $isTr ? 'Kopyalanamadı' : 'Failed to copy' ?>', '<?= $isTr ? 'Lütfen el ile kopyalayın' : 'Please copy manually' ?>', 'error');
        }
        document.body.removeChild(textArea);
    }
</script>
<?php endif; ?>