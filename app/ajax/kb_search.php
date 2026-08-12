<?php
require_once __DIR__ . '/../includes/session.php';
requireLogin();

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

if (function_exists('checkRateLimit') && !checkRateLimit('kb_search', 30, 60)) {
    echo json_encode([]);
    exit;
}

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 3) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, title, content FROM knowledge_base WHERE tenant_id = ? AND is_published = 1 AND (title LIKE ? OR content LIKE ?) LIMIT 3");
$searchTerm = "%$query%";
$stmt->execute([CURRENT_TENANT_ID, $searchTerm, $searchTerm]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formatted = array_map(function($r) {
    return [
        'id' => $r['id'],
        'title' => $r['title'],
        'snippet' => mb_substr(strip_tags($r['content']), 0, 80) . '...'
    ];
}, $results);

header('Content-Type: application/json');
echo json_encode($formatted);
