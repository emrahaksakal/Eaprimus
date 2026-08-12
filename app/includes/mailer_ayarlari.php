<?php
// app/includes/mailer_ayarlari.php
if (!isset($pdo) && function_exists('db')) {
    $pdo = db();
}

$mailSettings = [];
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'mail_%'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $mailSettings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
    }
}

// Güvenli fallback değerleri (Eğer veritabanında ayar yoksa)
function getMailSetting($key, $default = '')
{
    global $mailSettings;
    return isset($mailSettings[$key]) && $mailSettings[$key] !== '' ? $mailSettings[$key] : $default;
}
?>