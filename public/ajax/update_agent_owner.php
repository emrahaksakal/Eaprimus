<?php
// public/ajax/update_agent_owner.php
require_once __DIR__ . '/../../app/includes/session.php';
require_once __DIR__ . '/../../app/config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$agentId = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
$userApiKey = isset($_POST['user_api_key']) ? trim($_POST['user_api_key']) : '';

if ($agentId <= 0 || empty($userApiKey)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$pdo = db();

try {
    // Verify user key exists
    $stmtUser = $pdo->prepare("SELECT user_id FROM api_keys WHERE client_id = ? AND revoked_at IS NULL LIMIT 1");
    $stmtUser->execute([$userApiKey]);
    $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz veya iptal edilmiş kullanıcı API anahtarı.']);
        exit;
    }

    // Update agent key registration
    $stmtUpd = $pdo->prepare("UPDATE agent_keys SET registered_by_client_id = ? WHERE id = ?");
    $stmtUpd->execute([$userApiKey, $agentId]);

    echo json_encode(['success' => true, 'message' => 'Cihaz başarıyla yeni kullanıcıyla eşleştirildi.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
