<?php
/**
 * Eaprimus SLA Engine (Module for Worker)
 * -----------------------------------------------------------------------
 * Monitors deadlines, flags breaches, and handles auto-closing of tickets.
 */

if (php_sapi_name() !== 'cli' && !defined('FROM_WORKER')) {
    die("Error: This module must be called via the Master Worker.");
}

require_once __DIR__ . '/../config/db.php';
$pdo = db();
$now = date('Y-m-d H:i:s');

echo "[SLA Engine] Kontroller başlatıldı ($now)...\n";

// 1. FIRST RESPONSE SLA BREACH
$checkFirst = $pdo->prepare("
    SELECT t.id, t.ticket_no, t.title 
    FROM tickets t 
    WHERE t.status = 'open' 
      AND t.first_response_date IS NULL 
      AND t.first_response_deadline < ?
      AND NOT EXISTS (SELECT id FROM system_logs WHERE action = 'SLA_FIRST_RESPONSE_BREACH' AND details LIKE CONCAT('%', t.id, '%'))
");
$checkFirst->execute([$now]);
while ($ticket = $checkFirst->fetch(PDO::FETCH_ASSOC)) {
    $pdo->prepare("INSERT INTO system_logs (action, details, created_at) VALUES (?, ?, NOW())")->execute(['SLA_FIRST_RESPONSE_BREACH', 'Ticket ID: ' . $ticket['id']]);
    echo "[!] SLA İHLALİ (İLK CEVAP): " . $ticket['ticket_no'] . "\n";
}

// 2. RESOLUTION SLA BREACH
$checkRes = $pdo->prepare("
    SELECT t.id, t.ticket_no, t.title 
    FROM tickets t 
    WHERE t.status NOT IN ('resolved', 'closed')
      AND t.sla_due_date < ?
      AND NOT EXISTS (SELECT id FROM system_logs WHERE action = 'SLA_RESOLUTION_BREACH' AND details LIKE CONCAT('%', t.id, '%'))
");
$checkRes->execute([$now]);
while ($ticket = $checkRes->fetch(PDO::FETCH_ASSOC)) {
    $pdo->prepare("INSERT INTO system_logs (action, details, created_at) VALUES (?, ?, NOW())")->execute(['SLA_RESOLUTION_BREACH', 'Ticket ID: ' . $ticket['id']]);
    $pdo->prepare("UPDATE tickets SET priority = 'critical', update_date = NOW() WHERE id = ?")->execute([$ticket['id']]);
    echo "[!] SLA İHLALİ (ÇÖZÜM): " . $ticket['ticket_no'] . " (Kritik'e yükseltildi)\n";
}

// 3. AUTO-CLOSE RESOLVED TICKETS (3 Days limit)
$autoCloseDays = 3;
$closeDate = date('Y-m-d H:i:s', strtotime("-$autoCloseDays days"));
$stmtClose = $pdo->prepare("UPDATE tickets SET status = 'closed', update_date = NOW() WHERE status = 'resolved' AND update_date < ?");
$stmtClose->execute([$closeDate]);
$closedCount = $stmtClose->rowCount();
if ($closedCount > 0) {
    echo "[+] OTOMATİK KAPATILDI: $closedCount adet çözülmüş bilet 'Kapalı' durumuna getirildi.\n";
}
?>
