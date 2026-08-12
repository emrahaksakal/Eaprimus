<?php
/**
 * Eaprimus REST API v1 - Ticket Endpoint
 * -----------------------------------------------------------------------
 * Handles external ticket creation requests (Mobile, Webhooks, API).
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../app/config/db.php';
$pdo = db();

// 1. API KEY AUTHENTICATION
$headers = getallheaders();
$apiKey = $headers['X-API-KEY'] ?? $headers['x-api-key'] ?? '';

$dbApiKey = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'api_key'")->fetchColumn() ?: 'desk_stack_secret_key';

if ($apiKey !== $dbApiKey) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid API Key']));
}

// 2. REQUEST METHOD CHECK
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['status' => 'error', 'message' => 'Method Not Allowed: Use POST']));
}

// 3. PARSE JSON BODY
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['title']) || empty($input['description'])) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Bad Request: Missing required fields (title, description)']));
}

$title = trim($input['title']);
$description = trim($input['description']);
$email = trim($input['email'] ?? 'api@system.local');
$name = trim($input['name'] ?? 'API User');
$source = trim($input['source'] ?? 'api');

try {
    // Check if user exists
    $stmtU = $pdo->prepare("SELECT id FROM users WHERE mail = ?");
    $stmtU->execute([$email]);
    $userId = $stmtU->fetchColumn();

    $customerId = null;
    if (!$userId) {
        $stmtC = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
        $stmtC->execute([$email]);
        $customerId = $stmtC->fetchColumn();

        if (!$customerId) {
            $pdo->prepare("INSERT INTO customers (name, email, source, created_at) VALUES (?,?,?,NOW())")->execute([$name, $email, $source]);
            $customerId = $pdo->lastInsertId();
        }
        $userId = 17; // General System Creator
    }

    $prefix = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ticket_prefix'")->fetchColumn() ?: 'TCK';
    $ticketNo = $prefix . '-' . date('YmdHis') . rand(10,99);

    $sql = "INSERT INTO tickets (ticket_no, title, description, status, queue_id, creator_id, customer_id, sla_due_date, create_date) 
            VALUES (?, ?, ?, 'open', 1, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticketNo, $title, $description, $userId, $customerId]);
    $ticketId = $pdo->lastInsertId();

    echo json_encode([
        'status' => 'success',
        'message' => 'Ticket created successfully',
        'data' => [
            'id' => $ticketId,
            'ticket_no' => $ticketNo
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal Server Error: ' . $e->getMessage()]);
}
?>
