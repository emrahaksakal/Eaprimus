<?php
/**
 * Eaprimus Notification Worker (Module for Worker)
 * -----------------------------------------------------------------------
 * Processes the notification_queue and sends pending Mail/Telegram messages.
 */

if (php_sapi_name() !== 'cli' && !defined('FROM_WORKER')) {
    die("Error: This module must be called via the Master Worker.");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';
$pdo = db();

echo "[Notification Worker] Kuyruk taranıyor...\n";

// Get pending notifications
$stmt = $pdo->prepare("SELECT * FROM notification_queue WHERE status = 'pending' AND attempts < 3 ORDER BY id ASC LIMIT 50");
$stmt->execute();
$queueItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($queueItems)) {
    echo "[i] Gönderilecek bekleyen bildirim yok.\n";
    return;
}

foreach ($queueItems as $item) {
    echo "Processing Notification ID: " . $item['id'] . " (Type: " . $item['type'] . ")\n";
    $success = false;

    if ($item['type'] === 'email') {
        try {
            $success = sendEaprimusMail($item['recipient'], '', $item['subject'], $item['body']);
        } catch (Exception $e) {
            $success = false;
        }
    } elseif ($item['type'] === 'telegram') {
        // Fetch TG settings and send
        $settings = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'telegram_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $token = $settings['telegram_bot_token'] ?? '';
        $chatId = $item['recipient'] ?: ($settings['telegram_admin_chat_id'] ?? '');
        
        if ($token && $chatId) {
            $url = "https://api.telegram.org/bot{$token}/sendMessage";
            $data = ['chat_id' => $chatId, 'text' => $item['body'], 'parse_mode' => 'HTML'];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
            $success = true;
        }
    }

    if ($success) {
        $pdo->prepare("UPDATE notification_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$item['id']]);
    } else {
        $pdo->prepare("UPDATE notification_queue SET attempts = attempts + 1 WHERE id = ?")->execute([$item['id']]);
    }
}
?>
