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
    $taskText = trim($_POST['task_text'] ?? '');
    if ($taskText) {
        $stmt = $pdo->prepare("INSERT INTO ticket_subtasks (ticket_id, task_text) VALUES (?, ?)");
        $stmt->execute([$ticketId, $taskText]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Task text empty']);
    }
} elseif ($action === 'toggle') {
    $taskId = (int)($_POST['task_id'] ?? 0);
    $isChecked = (int)($_POST['is_checked'] ?? 0);
    $completedBy = $isChecked ? $_SESSION['user_id'] : null;
    
    if ($taskId) {
        $stmt = $pdo->prepare("UPDATE ticket_subtasks SET is_completed = ?, completed_by = ? WHERE id = ? AND ticket_id = ?");
        $stmt->execute([$isChecked, $completedBy, $taskId, $ticketId]);
        echo json_encode(['success' => true]);
    }
} elseif ($action === 'delete') {
    $taskId = (int)($_POST['task_id'] ?? 0);
    if ($taskId) {
        $stmt = $pdo->prepare("DELETE FROM ticket_subtasks WHERE id = ? AND ticket_id = ?");
        $stmt->execute([$taskId, $ticketId]);
        echo json_encode(['success' => true]);
    }
}
