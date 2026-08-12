<?php
/**
 * Master Background Worker - Enterprise Orchestrator
 * Trigger: * * * * * php /var/www/app/cron/worker.php
 */

if (php_sapi_name() !== 'cli' && !defined('FROM_AUTO_CRON')) {
    die("Error: This script must be run via the CLI or via Auth AutoCron.");
}

define('FROM_WORKER', true);

// Determine running user to avoid permission conflicts on the lock file
$currentUser = 'default';
if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $uinfo = posix_getpwuid(posix_geteuid());
    if ($uinfo) {
        $currentUser = $uinfo['name'];
    }
} else {
    $currentUser = getenv('USERNAME') ?: (getenv('USER') ?: 'default');
}

$lockFile = __DIR__ . '/worker_' . $currentUser . '.lock';
$fp = @fopen($lockFile, 'c+');

if (!$fp) {
    // Fallback to system temp directory if local folder is not writeable
    $lockFile = sys_get_temp_dir() . '/eaprimus_worker_' . $currentUser . '.lock';
    $fp = @fopen($lockFile, 'c+');
}

if (!$fp) {
    // If still failing, log warning and bypass locking to prevent blocking the worker
    echo "[" . date('H:i:s') . "] UYARI: Kilit dosyası oluşturulamadı, kilitlemesiz devam ediliyor.\n";
} else {
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        echo "[" . date('H:i:s') . "] LOKAVT: Başka bir işçi ($currentUser) halihazırda çalışıyor.\n";
        exit;
    }
}

require_once __DIR__ . '/../config/db.php';
$pdo = db();

echo "--- GÖREV BAŞLATILDI: " . date('Y-m-d H:i:s') . " ---\n";

// 1. Mail Gateway (IMAP -> Ticket)
echo "1. Mail Gateway (E-posta -> Bilet Dönüştürücü) Çalıştırılıyor...\n";
if (file_exists(__DIR__ . '/mail_gateway.php')) {
    include __DIR__ . '/mail_gateway.php';
}

// 2. SLA Engine (Gecikme Kontrolü & Otomatik Kapatma)
echo "2. SLA Motoru (İhlalleri Kontrol Et) Çalıştırılıyor...\n";
if (file_exists(__DIR__ . '/sla_engine.php')) {
    include __DIR__ . '/sla_engine.php';
}

// 3. Notification Worker (Mail/Telegram Kuyruğu Gönderme)
echo "3. Bildirim İşleyici (Queue Dispatcher) Çalıştırılıyor...\n";
if (file_exists(__DIR__ . '/notification_worker.php')) {
    include __DIR__ . '/notification_worker.php';
}

echo "--- TÜM GÖREVLER TAMAMLANDI ---\n";

flock($fp, LOCK_UN);
fclose($fp);
?>
