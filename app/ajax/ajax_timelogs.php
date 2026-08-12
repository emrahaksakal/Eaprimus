<?php
require_once __DIR__ . '/../includes/session.php';
requireLogin();
require_once __DIR__ . '/../config/db.php';
$pdo = db();

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';
$ticketId = (int)($_POST['ticket_id'] ?? 0);

if (!$ticketId) {
    echo json_encode(['error' => 'Invalid ticket ID']);
    exit;
}

// Check if ticket is closed/resolved
$stmt = $pdo->prepare("SELECT status FROM tickets WHERE id = ?");
$stmt->execute([$ticketId]);
$ticketStatus = $stmt->fetchColumn();
if (in_array($ticketStatus, ['closed', 'resolved'])) {
    $isTr = (($_SESSION['lang'] ?? 'tr') === 'tr');
    echo json_encode(['error' => $isTr ? 'Bilet kapatıldığı için bu işlem gerçekleştirilemez.' : 'This action cannot be performed because the ticket is closed.']);
    exit;
}

if ($action === 'add') {
    $hours = (int)($_POST['hours'] ?? 0);
    $minutes = (int)($_POST['minutes'] ?? 0);
    $totalMinutes = ($hours * 60) + $minutes;
    $note = trim($_POST['note'] ?? '');
    
    if ($totalMinutes > 0) {
        $stmt = $pdo->prepare("INSERT INTO ticket_time_logs (ticket_id, user_id, time_spent_minutes, note) VALUES (?, ?, ?, ?)");
        $stmt->execute([$ticketId, $_SESSION['user_id'], $totalMinutes, $note]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Invalid time']);
    }
} elseif ($action === 'delete') {
    $logId = (int)($_POST['log_id'] ?? 0);
    if ($logId) {
        // Users can only delete their own logs, unless they are admin (role=1)
        if ($_SESSION['role'] == 1) {
            $stmt = $pdo->prepare("DELETE FROM ticket_time_logs WHERE id = ? AND ticket_id = ?");
            $stmt->execute([$logId, $ticketId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM ticket_time_logs WHERE id = ? AND ticket_id = ? AND user_id = ?");
            $stmt->execute([$logId, $ticketId, $_SESSION['user_id']]);
        }
        echo json_encode(['success' => true]);
    }
}
