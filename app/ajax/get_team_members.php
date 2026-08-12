<?php
// ajax/get_team_members.php
require_once __DIR__ . '/../includes/session.php';
requireLogin();
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

if ($_SESSION['role'] != 1 && $_SESSION['role'] != 3) {
    http_response_code(403);
    exit('Yetkisiz Erişim');
}

$team_id = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
if ($team_id <= 0)
    exit;

// Mevcut üyeler ve liderlik durumları
$existingStmt = $pdo->prepare("SELECT user_id, is_leader FROM teams_users WHERE team_id = ?");
$existingStmt->execute([$team_id]);
$existingData = $existingStmt->fetchAll(PDO::FETCH_KEY_PAIR); // [user_id => is_leader]

// Tüm kullanıcılar
$stmt = $pdo->prepare("
    SELECT id, fullname, role
    FROM users
    WHERE role IS NOT NULL
    ORDER BY fullname ASC
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$roleLabels = [
    1 => ['text' => 'Master Admin', 'class' => 'badge-danger'],
    3 => ['text' => 'Supervisor', 'class' => 'badge-warning'],
    2 => ['text' => 'Personel', 'class' => 'badge-secondary'],
];

echo '<form id="teamMembersForm">';
echo '<input type="hidden" name="team_id" value="' . $team_id . '">';

if (empty($users)) {
    echo '<div class="text-center text-muted py-4"><i class="fas fa-users fa-2x mb-2 d-block"></i>Henüz kullanıcı yok.</div>';
} else {
    echo '<table class="table table-hover mb-0">';
    echo '<thead style="position:sticky;top:0;background:#fff;z-index:1;">
            <tr>
                <th>Personel</th>
                <th>Sistem Rolü</th>
                <th class="text-center">Takım Üyesi</th>
                <th class="text-center">Takım Lideri</th>
            </tr>
          </thead><tbody>';

    foreach ($users as $u) {
        $isMember = array_key_exists($u['id'], $existingData);
        $isLeader = $isMember && $existingData[$u['id']] == 1;
        $checked = $isMember ? 'checked' : '';
        $leaderChecked = $isLeader ? 'checked' : '';
        $rl = $roleLabels[(int) $u['role']] ?? ['text' => 'Bilinmiyor', 'class' => 'badge-light'];

        echo '<tr>';
        echo '<td class="align-middle"><strong>' . htmlspecialchars($u['fullname']) . '</strong></td>';
        echo '<td class="align-middle"><span class="badge ' . $rl['class'] . ' px-2 py-1">' . $rl['text'] . '</span></td>';
        echo '<td class="text-center align-middle">
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input" 
                           id="member_' . $u['id'] . '" 
                           name="users[' . $u['id'] . ']" 
                           value="1" ' . $checked . '>
                    <label class="custom-control-label" for="member_' . $u['id'] . '"></label>
                </div>
              </td>';
        echo '<td class="text-center align-middle">
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input" 
                           id="leader_' . $u['id'] . '" 
                           name="leaders[' . $u['id'] . ']" 
                           value="1" ' . $leaderChecked . '>
                    <label class="custom-control-label" for="leader_' . $u['id'] . '"></label>
                </div>
              </td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

echo '</form>';