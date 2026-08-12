<?php
// includes/logger.php

date_default_timezone_set('Europe/Istanbul');

if (!defined('LOG_PATH')) {
    define('LOG_PATH', __DIR__ . '/../logs/');
}

// ---------------------------------------------------------------------------
// IP ADRESİ BULMA (GÜNCELLENDİ: CRON UYUMLU)
// ---------------------------------------------------------------------------
function getRealIpAddr()
{
    if (php_sapi_name() === 'cli') {
        return 'SYSTEM (CRON)';
    }
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    return ($ip == '::1') ? '127.0.0.1 (Localhost)' : $ip;
}

function logToFile($log_icerigi)
{
    try {
        $dosya_adi = "log_" . date('Y_m') . ".txt";
        $yol = LOG_PATH . $dosya_adi;
        if (!is_dir(dirname($yol)))
            mkdir(dirname($yol), 0777, true);
        file_put_contents($yol, $log_icerigi, FILE_APPEND);
    } catch (Exception $e) {
    }
}

// ---------------------------------------------------------------------------
// GİRİŞ - ÇIKIŞ LOGLARI
// ---------------------------------------------------------------------------
function girisCikisLogAl($pdo, $user_id, $islem, $ozel_tarih = null)
{
    try {
        if (!defined('EAPRIMUS_KEY'))
            return;
        $stmt = $pdo->prepare("SELECT fullname, mail, CAST(AES_DECRYPT(UNHEX(tc_no), '" . EAPRIMUS_KEY . "') AS CHAR) as tc FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($u) {
            $islem_zamani = ($ozel_tarih) ? $ozel_tarih : date('d.m.Y H:i:s');

            $log = "------------------------------------------------------" . PHP_EOL;
            $log .= "  ISLEM / ACTION   : " . strtoupper($islem) . PHP_EOL;
            $log .= "  TARIH / DATE     : " . $islem_zamani . PHP_EOL;
            $log .= "  KISI / PERSON    : " . $u['fullname'] . " (***" . substr($u['tc'], -4) . ")" . PHP_EOL;
            $log .= "  MAIL             : " . $u['mail'] . PHP_EOL;
            $log .= "  IP               : " . getRealIpAddr() . PHP_EOL;
            $log .= "------------------------------------------------------" . PHP_EOL . PHP_EOL;
            logToFile($log);
        }
    } catch (Exception $e) {
    }
}

// ---------------------------------------------------------------------------
// BİLET LOGLARI
// ---------------------------------------------------------------------------
// $islem : 'YANITLANDI', 'OLUSTURULDU', 'KAPATILDI', 'COZUMLENDI', 'ATANDI'
// $ticket_no : Bilet numarası (örn: GRS20260305123456)
// $detay     : Ek açıklama
function ticketLogAl($pdo, $user_id, $islem, $ticket_no = '', $detay = '')
{
    try {
        $aktor = 'SISTEM';
        if ($user_id) {
            $stmt = $pdo->prepare("SELECT fullname FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u)
                $aktor = $u['fullname'];
        }

        $log = "------------------------------------------------------" . PHP_EOL;
        $log .= "  ISLEM / ACTION   : BILET / TICKET " . mb_strtoupper($islem, 'UTF-8') . PHP_EOL;
        $log .= "  TARIH / DATE     : " . date('d.m.Y H:i:s') . PHP_EOL;
        if ($ticket_no)
            $log .= "  BILET / TICKET   : " . $ticket_no . PHP_EOL;
        $log .= "  YAPAN / ACTOR    : " . $aktor . PHP_EOL;
        if ($detay)
            $log .= "  DETAY / DETAIL   : " . mb_substr(strip_tags($detay), 0, 120, 'UTF-8') . PHP_EOL;
        $log .= "  IP               : " . getRealIpAddr() . PHP_EOL;
        $log .= "------------------------------------------------------" . PHP_EOL . PHP_EOL;

        logToFile($log);
    } catch (Exception $e) {
    }
}

// ---------------------------------------------------------------------------
// GENEL SISTEM LOGLARI
// ---------------------------------------------------------------------------
function systemLog($islem, $detay = '')
{
    try {
        $log = "------------------------------------------------------" . PHP_EOL;
        $log .= "  TARIH / DATE     : " . date('d.m.Y H:i:s') . PHP_EOL;
        $log .= "  ISLEM / ACTION   : " . mb_strtoupper($islem, 'UTF-8') . PHP_EOL;
        if ($detay)
            $log .= "  DETAY / DETAIL   : " . $detay . PHP_EOL;
        $log .= "  IP               : " . getRealIpAddr() . PHP_EOL;
        $log .= "------------------------------------------------------" . PHP_EOL . PHP_EOL;

        $yol = LOG_PATH . "system.log";
        if (!is_dir(dirname($yol)))
            mkdir(dirname($yol), 0777, true);
        file_put_contents($yol, $log, FILE_APPEND);
    } catch (Exception $e) {
    }
}

/**
 * Log action into system_logs DB table with IP Address, User Agent, and TR/EN localization
 */
function systemLogDb($pdo, string $action, string $detailsTr, string $detailsEn = '', $userId = null)
{
    if (!$pdo) return;
    static $columnsChecked = false;
    
    // Auto-migrate ip_address and user_agent columns if missing
    if (!$columnsChecked) {
        try {
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'system_logs' AND COLUMN_NAME = 'ip_address'");
            $stmt->execute([$dbName]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE system_logs ADD COLUMN ip_address VARCHAR(45) NULL, ADD COLUMN user_agent VARCHAR(255) NULL");
            }
        } catch (Throwable $e) {}
        $columnsChecked = true;
    }
    
    try {
        $ip = getRealIpAddr();
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);
        $uid = $userId ?: ($_SESSION['user_id'] ?? null);
        
        $lang = $_SESSION['lang'] ?? 'tr';
        $finalDetails = ($lang === 'en' && !empty($detailsEn)) ? $detailsEn : $detailsTr;
        if (!empty($detailsEn) && $lang !== 'en') {
            $finalDetails .= " [EN: {$detailsEn}]";
        }
        
        $stmt = $pdo->prepare("INSERT INTO system_logs (action, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$action, $finalDetails, $ip, $ua]);
        
        // File log backup
        systemLog($action, "[$ip] $finalDetails");
    } catch (Throwable $e) {}
}
?>
