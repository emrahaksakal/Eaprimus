<?php
// public/ajax/heartbeat.php
require_once __DIR__ . '/../../app/includes/session.php';

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

// Update last activity timestamp for session management
$_SESSION['last_activity'] = time();

if (isset($_POST['action']) && $_POST['action'] === 'complete_onboarding') {
    require_once __DIR__ . '/../../app/config/db.php';
    try {
        $pdo = db();
        if (isset($pdo) && $pdo !== null) {
            $stmt = $pdo->prepare("UPDATE users SET onboarding_done = 1 WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
        }
    } catch (Throwable $e) {}
}

echo json_encode(['ok' => true]);
