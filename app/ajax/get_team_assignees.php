<?php
// app/ajax/get_team_assignees.php
require_once __DIR__ . '/../includes/session.php';
requireLogin();
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

if ($_SESSION['role'] != 1 && $_SESSION['role'] != 3) {
    http_response_code(403);
    exit(json_encode(['error' => 'Yetkisiz Erişim']));
}

header('Content-Type: application/json');

$team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
$ticket_id = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;

if ($ticket_id > 0) {
    // Resolve team_id from ticket's queue
    $stmt = $pdo->prepare("
        SELECT q.team_id 
        FROM tickets t 
        LEFT JOIN queues q ON t.queue_id = q.id 
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $team_id = (int)$stmt->fetchColumn();
}

if ($team_id <= 0) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.fullname 
    FROM users u 
    JOIN teams_users tu ON u.id = tu.user_id 
    WHERE tu.team_id = ? AND u.status = 1 
    ORDER BY u.fullname ASC
");
$stmt->execute([$team_id]);
$assignees = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($assignees);
