<?php
require_once __DIR__ . '/../includes/session.php';
requireLogin();

if ($current_user_role != 1) {
    include __DIR__ . "/403.php";
    exit;
}

$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';
$logPath = __DIR__ . '/../logs/imap_listener.log';
$sysLogPath = __DIR__ . '/../logs/system.log';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_logs'])) {
    if (file_exists($logPath)) {
        file_put_contents($logPath, '');
    }
    if (file_exists($sysLogPath)) {
        file_put_contents($sysLogPath, '');
    }
    systemLog('LOG_TEMIZLEME', 'Sistem ve IMAP logları temizlendi.');
    header("Location: sistem-loglari");
    exit;
}

$logs = '';
if (file_exists($logPath)) {
    // Sadece son 500 satırı oku
    $lines = file($logPath);
    if ($lines) {
        $lines = array_slice($lines, -500);
        $logs = implode("", $lines);
    }
} else {
    $logs = $isTr ? "Henüz log kaydı bulunmuyor." : "No logs available yet.";
}
$sysLogPath = __DIR__ . '/../logs/system.log';
$sysLogs = '';
if (file_exists($sysLogPath)) {
    $sysLines = file($sysLogPath);
    if ($sysLines) {
        $sysLines = array_slice($sysLines, -500);
        $sysLogs = implode("", $sysLines);
    }
} else {
    $sysLogs = $isTr ? "Henüz genel sistem log kaydı bulunmuyor." : "No general system logs available yet.";
}
?>

<div class="content-header p-0">
    <div class="container-fluid">
        <div class="row align-items-center py-4 px-3">
            <div class="col-sm-6">
                <h1 class="m-0 ds-topbar-title font-weight-bold">
                    <i class="fas fa-terminal mr-3 text-primary"></i>
                    <?= $isTr ? 'Sistem & CRON Logları' : 'System & CRON Logs' ?>
                </h1>
            </div>
            <div class="col-sm-6 text-right">
                <form method="POST" style="display:inline-block;" action="">
                    <input type="hidden" name="clear_logs" value="1">
                    <button type="submit" class="btn btn-danger font-weight-bold rounded-pill mr-2" onclick="return confirm('<?= $isTr ? 'Logları temizlemek istediğinize emin misiniz?' : 'Are you sure you want to clear logs?' ?>');">
                        <i class="fas fa-trash-alt mr-2"></i> <?= $isTr ? 'Temizle' : 'Clear' ?>
                    </button>
                </form>
                <button class="btn btn-primary font-weight-bold rounded-pill" onclick="location.reload()">
                    <i class="fas fa-sync-alt mr-2"></i> <?= $isTr ? 'Yenile' : 'Refresh' ?>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="content ds-page">
    <div class="container-fluid">
        
        <div class="card ds-card mb-4" style="border-radius: 12px; border-top: 4px solid #f39c12;">
            <div class="card-header bg-white border-bottom-0 py-3">
                <h3 class="card-title font-weight-bold text-dark">
                    <i class="fas fa-exclamation-triangle mr-2 text-warning"></i> <?= $isTr ? 'Genel Sistem Logları' : 'General System Logs' ?>
                </h3>
            </div>
            <div class="card-body bg-dark" style="border-radius: 0 0 12px 12px;">
                <pre class="text-warning m-0" style="max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 14px; white-space: pre-wrap;" id="sysLogContainer"><?= htmlspecialchars($sysLogs) ?></pre>
            </div>
        </div>

        <div class="card ds-card" style="border-radius: 12px; border-top: 4px solid var(--primary-color);">
            <div class="card-header bg-white border-bottom-0 py-3">
                <h3 class="card-title font-weight-bold text-dark">
                    <i class="fas fa-microchip mr-2 text-info"></i> <?= $isTr ? 'IMAP & Arka Plan İşleyici Kayıtları' : 'IMAP & Background Worker Logs' ?>
                </h3>
            </div>
            <div class="card-body bg-dark" style="border-radius: 0 0 12px 12px;">
                <pre class="text-success m-0" style="max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 14px; white-space: pre-wrap;" id="logContainer"><?= htmlspecialchars($logs) ?></pre>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        var logContainer = document.getElementById("logContainer");
        if(logContainer) logContainer.scrollTop = logContainer.scrollHeight;
        var sysLogContainer = document.getElementById("sysLogContainer");
        if(sysLogContainer) sysLogContainer.scrollTop = sysLogContainer.scrollHeight;
    });
</script>
