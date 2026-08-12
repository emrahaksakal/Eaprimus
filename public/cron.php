<?php
if (isset($_GET['debug_db'])) {
    require_once __DIR__ . '/../app/config/db.php';
    $pdo = db();
    $ticketNo = 'EA-20260805160318';
    $stmtT = $pdo->prepare("SELECT id, ticket_no, title FROM tickets WHERE ticket_no = ?");
    $stmtT->execute([$ticketNo]);
    $row = $stmtT->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "FOUND: ID=" . $row['id'] . ", Title=" . $row['title'];
    } else {
        echo "NOT FOUND: '" . $ticketNo . "'";
        $all = $pdo->query("SELECT id, ticket_no FROM tickets ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        echo "\nLast 5 tickets:\n";
        foreach ($all as $t) {
            echo " - ID: " . $t['id'] . " | No: '" . $t['ticket_no'] . "'\n";
        }
    }
    exit;
}
// public/cron.php
// Bu dosya Dashboard açıkken JavaScript tarafından her dakikada bir çağrılır.
// Gerçek sunucularda cron olarak eklenen işlemleri lokalde web üzerinden de tetiklemek içindir.

require_once __DIR__ . '/../app/includes/session.php';
require_once __DIR__ . '/../app/config/db.php';

// Güvenlik: Sadece giriş yapmış kullanıcılar, komut satırı üzerinden (CLI) veya aynı sunucudan gelen (Referer) istekler çalıştırabilsin
$is_local_request = false;
if (isset($_SERVER['HTTP_REFERER'])) {
    $referer_host = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
    if ($referer_host === $_SERVER['HTTP_HOST']) {
        $is_local_request = true;
    }
}

$is_localhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '192.168.3.99', '192.168.3.92']);
if (php_sapi_name() !== 'cli' && empty($_SESSION['user_id']) && !$is_local_request && !$is_localhost) {
    http_response_code(403);
    exit('Unauthorized access to cron endpoint.');
}

// Güvenlik ve performans için çok sık çalışmayı önle
// Sadece 1 dakikadan daha eski tetiklemelerde çalıştır
$lockDir = __DIR__ . '/../app/storage';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockFile = $lockDir . '/eaprimus_cron.lock';
$lastRun = 0;
if (file_exists($lockFile) && !isset($_GET['force_test'])) {
    $lastRun = @filemtime($lockFile);
    $diff = time() - $lastRun;
    if ($lastRun && $diff >= 0 && $diff < 15) { // 15 saniye kilitli kalır
        exit('locked');
    }
}
@touch($lockFile);

// İçeriden çağrıldığı sinyalini ver
if (!defined('FROM_AUTO_CRON')) {
    define('FROM_AUTO_CRON', true);
}

// Yeni Master Worker sistemini tetikle
ob_start();
try {
    include_once __DIR__ . '/../app/cron/worker.php';
} catch (Throwable $e) {
    error_log("Cron Execution Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}
$output = ob_get_clean();

if (isset($_GET['force_test'])) {
    // Sadece Super Admin (rol 1) debug cikti gorebilir
    if ((int)($_SESSION['role'] ?? 0) !== 1) {
        http_response_code(403);
        exit('Unauthorized.');
    }
    echo "CRON RUN OUTPUT:\n" . $output;
    exit;
}

// Return response early for browser-triggered cron calls.
header('Content-Type: text/plain; charset=UTF-8');
echo "👌";
if (function_exists('session_write_close')) {
    session_write_close();
}
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ignore_user_abort(true);
    while (ob_get_level()) {
        ob_end_flush();
    }
    flush();
}

// Continue cron worker execution after response is flushed.
if (!defined('FROM_AUTO_CRON')) {
    define('FROM_AUTO_CRON', true);
}

try {
    include_once __DIR__ . '/../app/cron/worker.php';
} catch (Throwable $e) {
    error_log("Cron Execution Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}

return; 
?>