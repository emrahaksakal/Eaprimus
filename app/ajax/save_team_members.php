<?php
// ajax/save_team_members.php
require_once __DIR__ . '/../includes/session.php';
requireLogin();
require_csrf_token();
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

if ($_SESSION['role'] != 1 && $_SESSION['role'] != 3) {
    http_response_code(403);
    echo "Yetkisiz Erişim";
    exit;
}

$team_id = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
if ($team_id <= 0) {
    echo "Geçersiz Takım";
    exit;
}

$selected_users = isset($_POST['users']) && is_array($_POST['users'])
    ? array_keys($_POST['users'])
    : [];

try {
    $pdo->beginTransaction();

    // Mevcut üyeleri sil
    $pdo->prepare("DELETE FROM teams_users WHERE team_id = ?")->execute([$team_id]);

    // Seçilenleri ekle
    if (!empty($selected_users)) {
        $stmt = $pdo->prepare("INSERT INTO teams_users (team_id, user_id, is_leader) VALUES (?, ?, ?)");
        $leaders = isset($_POST['leaders']) && is_array($_POST['leaders']) ? $_POST['leaders'] : [];

        foreach ($selected_users as $user_id) {
            $uid = (int) $user_id;
            if ($uid > 0) {
                $is_leader = isset($leaders[$uid]) ? 1 : 0;
                $stmt->execute([$team_id, $uid, $is_leader]);
            }
        }
    }

    $pdo->commit();
    echo "success";
} catch (PDOException $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    echo "Hata: " . $e->getMessage();
}
?>