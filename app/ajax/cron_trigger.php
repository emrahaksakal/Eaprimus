<?php
// app/ajax/cron_trigger.php
// Bu dosya Dashboard açıkken her dakikada bir arkaplanda çalışır.
// Gerçek sunucularda cron olarak eklenen işlemleri lokalde tetiklemek içindir.

require_once __DIR__ . '/../config/db.php';

// Güvenlik ve performans için çok sık çalışmayı önle
// Sadece 1 dakikadan daha eski tetiklemelerde çalıştır
$lockDir = __DIR__ . '/../storage';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockFile = $lockDir . '/eaprimus_cron.lock';
if (file_exists($lockFile)) {
    $lastRun = @filemtime($lockFile);
    if ($lastRun && (time() - $lastRun < 50)) { // 50 saniye kilitli kalır
        exit('locked');
    }
}
@touch($lockFile);

// Arka planda çalışacak dosyaları dahil et (hata olmasın diye include)
try {
    include_once __DIR__ . '/../cron/sla_checker.php';
} catch (Throwable $e) {
    error_log("SLA Checker Error: " . $e->getMessage());
}

try {
    include_once __DIR__ . '/../cron/imap_listener.php';
} catch (Throwable $e) {
    error_log("IMAP Listener Error: " . $e->getMessage());
}

echo "ok";
?>