<?php
// pages/takimlar.php
require_once __DIR__ . '/../includes/session.php';
requireLogin();
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

if ((int) $_SESSION['role'] !== 1) {
    include __DIR__ . "/403.php";
    return;
}

$mesaj = '';
$hata = '';

// POST İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    if ($_POST['action'] === 'add_team') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (empty($name)) {
            $hata = __("team_name_required");
        } else {
            try {
                $pdo->prepare("INSERT INTO teams (name, description, status) VALUES (?, ?, 1)")->execute([$name, $desc]);
                $_SESSION['mesaj'] = "✅ " . sprintf(__("team_created_success"), $name);
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            } catch (PDOException $e) {
                $hata = __("error") . ": " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete_team') {
        $id = (int) ($_POST['team_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM teams_users WHERE team_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM teams WHERE id = ?")->execute([$id]);
            $_SESSION['mesaj'] = "✅ " . __("team_deleted_success");
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        } catch (PDOException $e) {
            $hata = __("team_delete_error");
        }
    } elseif ($_POST['action'] === 'toggle_status') {
        $id = (int) ($_POST['team_id'] ?? 0);
        $pdo->prepare("UPDATE teams SET status = IF(status=1, 0, 1) WHERE id = ?")->execute([$id]);
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } elseif ($_POST['action'] === 'update_team') {
        $id = (int) ($_POST['team_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!empty($name)) {
            $pdo->prepare("UPDATE teams SET name = ?, description = ? WHERE id = ?")->execute([$name, $desc, $id]);
            $_SESSION['mesaj'] = __("updated_successfully");
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Takımları Çek - üye sayısı ve bağlı kuyruklar dahil
$teams = $pdo->query("
    SELECT t.*,
        COUNT(DISTINCT tu.user_id) AS member_count,
        GROUP_CONCAT(DISTINCT q.name ORDER BY q.name SEPARATOR ', ') AS queue_names
    FROM teams t
    LEFT JOIN teams_users tu ON t.id = tu.team_id
    LEFT JOIN queues q ON q.team_id = t.id
    GROUP BY t.id
    ORDER BY t.name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .team-card {
        border: none;
        border-radius: 16px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
        border: 1px solid #edf2f7;
    }

    .team-card:hover {
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
        transform: translateY(-4px);
        border-color: #e2e8f0;
    }

    .team-card .card-header {
        background: #fff;
        border-bottom: 1px solid #f1f5f9;
        padding: 24px;
        color: #1e293b;
    }

    .team-icon-box {
        width: 48px;
        height: 48px;
        background: #eff6ff;
        color: #2563eb;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        margin-right: 16px;
    }

    .team-header-info h5 {
        font-weight: 700;
        letter-spacing: -0.025em;
        margin-bottom: 2px;
    }

    .team-count-badge {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        padding: 4px 14px;
        font-size: 12px;
        font-weight: 600;
        color: #475569;
    }

    .queue-pill {
        background: #f1f5f9;
        color: #475569;
        border-radius: 8px;
        padding: 4px 12px;
        font-size: 12px;
        font-weight: 600;
        display: inline-block;
        margin: 2px;
        transition: all 0.2s;
    }

    .queue-pill:hover {
        background: #e2e8f0;
        color: #1e293b;
    }

    .action-btn-group {
        display: flex;
        gap: 8px;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #f1f5f9;
    }

    /* Dark Mode Support */
    body.dark-mode .team-card {
        background: #1e293b;
        border-color: #334155;
    }
    body.dark-mode .team-card .card-header {
        background: #1e293b;
        color: #f1f5f9;
        border-color: #334155;
    }
    body.dark-mode .team-icon-box {
        background: #1e293b;
        color: #60a5fa;
        border: 1px solid #334155;
    }
    body.dark-mode .queue-pill {
        background: #334155;
        color: #cbd5e1;
    }
    body.dark-mode .team-count-badge {
        background: #334155;
        color: #cbd5e1;
        border-color: #475569;
    }
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row align-items-center mb-4">
            <div class="col">
                <h1 class="m-0 font-weight-bold" style="letter-spacing: -1px;"><i class="fas fa-layer-group mr-3 text-primary"></i><?= __("team_management") ?></h1>
                <p class="text-muted mb-0"><?= __("teams_description") ?></p>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (isset($_SESSION['mesaj'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0" style="border-radius:12px;">
                <i class="fas fa-check-circle mr-2"></i><?= $_SESSION['mesaj']; unset($_SESSION['mesaj']); ?>
                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
            </div>
        <?php endif; ?>
        <?php if ($hata): ?>
            <div class="alert alert-danger shadow-sm border-0" style="border-radius:12px;">
                <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($hata) ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- SOL: Takım Ekleme Paneli -->
            <div class="col-xl-3 col-lg-4 mb-4">
                <div class="card shadow-sm border-0" style="border-radius:16px; position:sticky; top:20px;">
                    <div class="card-body p-4">
                        <h5 class="font-weight-bold mb-4 d-flex align-items-center">
                            <i class="fas fa-plus-circle text-primary mr-2"></i> <?= __("new_team") ?>
                        </h5>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_team">
                            <div class="form-group mb-3">
                                <label class="small font-weight-bold text-muted text-uppercase"><?= __("team_name") ?></label>
                                <input type="text" name="name" class="form-control form-control-lg border-light bg-light" 
                                    style="font-size:15px; border-radius:10px;"
                                    placeholder="<?= __("team_name_placeholder") ?>" required>
                            </div>
                            <div class="form-group mb-4">
                                <label class="small font-weight-bold text-muted text-uppercase"><?= __("description") ?></label>
                                <textarea name="description" class="form-control border-light bg-light" rows="4" 
                                    style="font-size:14px; border-radius:10px;"
                                    placeholder="<?= __("team_description_placeholder") ?>"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg btn-block shadow-sm" style="border-radius:10px; font-weight:700; font-size:15px;">
                                <i class="fas fa-plus mr-2"></i> <?= __("create_team") ?>
                            </button>
                        </form>
                        
                        <div class="mt-4 p-3 bg-light rounded" style="border-radius:12px !important;">
                            <p class="small text-muted mb-0"><i class="fas fa-lightbulb text-warning mr-2"></i><?= __("team_hint_text") ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SAĞ: Liste -->
            <div class="col-xl-9 col-lg-8">
                <!-- Search bar removed as requested -->

                <?php if (empty($teams)): ?>
                    <div class="card shadow-sm border-0 text-center py-5" style="border-radius:20px;">
                        <i class="fas fa-layer-group fa-4x text-muted mb-3"></i>
                        <h4 class="text-muted font-weight-bold"><?= __("no_teams_found") ?></h4>
                        <p class="text-muted"><?= __("create_first_team") ?></p>
                    </div>
                <?php else: ?>
                    <div class="row" id="teamList">
                        <?php foreach ($teams as $t): ?>
                            <div class="col-md-6 mb-4 team-item">
                                <div class="card team-card h-100">
                                    <div class="card-header border-0 pb-0">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="team-icon-box">
                                                <i class="fas fa-users"></i>
                                            </div>
                                            <div class="team-header-info">
                                                <h5 class="mb-0 text-truncate" style="max-width:200px;"><?= htmlspecialchars($t['name']) ?></h5>
                                                <span class="team-count-badge">
                                                    <?= $t['member_count'] ?> <?= __("member") ?>
                                                </span>
                                            </div>
                                            <div class="ml-auto">
                                                <?php if ($t['status'] == 1): ?>
                                                    <span class="badge badge-soft-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i><?= __("active") ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-soft-secondary px-2 py-1"><i class="fas fa-pause-circle mr-1"></i><?= __("passive") ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($t['description']): ?>
                                            <p class="text-muted small line-clamp-2" style="min-height:40px;"><?= htmlspecialchars($t['description']) ?></p>
                                        <?php else: ?>
                                            <p class="text-muted small font-italic" style="min-height:40px;"><?= __("no_description") ?></p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="card-body pt-2">
                                        <div class="mb-2">
                                            <label class="small font-weight-bold text-muted text-uppercase mb-2 d-block"><?= __("connected_queues") ?></label>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php if (!empty($t['queue_names'])): ?>
                                                    <?php foreach (explode(', ', $t['queue_names']) as $qn): ?>
                                                        <span class="queue-pill"><?= htmlspecialchars(trim($qn)) ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="text-muted small"><i class="fas fa-info-circle mr-1"></i>Henüz kuyruk atanmamış.</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="action-btn-group">
                                            <!-- Personel Yönetimi -->
                                            <button type="button" class="btn btn-primary flex-fill" 
                                                onclick="manageMembers(<?= $t['id'] ?>, '<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')"
                                                style="border-radius:10px; font-weight:600;">
                                                <i class="fas fa-user-plus mr-1"></i> <?= __("staff") ?>
                                            </button>

                                            <!-- Düzenle -->
                                            <button type="button" class="btn btn-light" 
                                                onclick="editTeam(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)"
                                                style="border-radius:10px; width:42px;">
                                                <i class="fas fa-pen text-info"></i>
                                            </button>

                                            <!-- Durum Değiştir -->
                                            <form method="POST" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="team_id" value="<?= $t['id'] ?>">
                                                <button type="submit" class="btn btn-light" style="border-radius:10px; width:42px;"
                                                    title="<?= $t['status'] ? __("deactivate") : __("activate") ?>">
                                                    <i class="fas fa-<?= $t['status'] ? 'pause text-warning' : 'play text-success' ?>"></i>
                                                </button>
                                            </form>

                                            <!-- Sil -->
                                            <form method="POST" class="d-inline" onsubmit="return confirm('<?= __("are_you_sure") ?>');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_team">
                                                <input type="hidden" name="team_id" value="<?= $t['id'] ?>">
                                                <button type="submit" class="btn btn-light" style="border-radius:10px; width:42px;">
                                                    <i class="fas fa-trash-alt text-danger"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Düzenleme Modalı -->
<div class="modal fade" id="editTeamModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_team">
            <input type="hidden" name="team_id" id="edit_team_id">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-edit mr-2 text-info"></i><?= ($_SESSION['lang'] ?? 'tr') == 'tr' ? 'Takımı Düzenle' : 'Edit Team' ?></h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-4">
                <div class="form-group">
                    <label class="small font-weight-bold text-muted text-uppercase"><?= __("team_name") ?></label>
                    <input type="text" name="name" id="edit_team_name" class="form-control form-control-lg bg-light border-0" style="border-radius:12px; font-size:15px;" required>
                </div>
                <div class="form-group mb-0">
                    <label class="small font-weight-bold text-muted text-uppercase"><?= __("description") ?></label>
                    <textarea name="description" id="edit_team_desc" class="form-control bg-light border-0" rows="4" style="border-radius:12px; font-size:14px;"></textarea>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light px-4" data-dismiss="modal" style="border-radius:10px;"><?= __("cancel") ?></button>
                <button type="submit" class="btn btn-info px-5 shadow-sm" style="border-radius:10px; font-weight:700;"><?= __("save_changes") ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Personel Yönetim Modalı -->
<div class="modal fade" id="teamMembersModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title font-weight-bold text-primary" id="teamModalTitle"><i class="fas fa-users-cog mr-2"></i><?= __("team_members") ?></h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-0" id="teamMembersBody" style="max-height:550px; overflow-y:auto; border-radius: 0 0 20px 20px;">
                <div class="text-center py-5">
                    <i class="fas fa-circle-notch fa-spin fa-3x text-primary mb-3"></i>
                    <p class="text-muted font-weight-bold"><?= __("loading") ?>...</p>
                </div>
            </div>
            <div class="modal-footer border-0 p-3">
                <button type="button" class="btn btn-light px-4" data-dismiss="modal" style="border-radius:10px;"><?= __("close") ?></button>
                <button type="button" class="btn btn-success px-5 shadow-sm" onclick="saveTeamMembers()" style="border-radius:10px; font-weight:700;">
                    <i class="fas fa-save mr-2"></i><?= __("save") ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>

    function editTeam(data) {
        $('#edit_team_id').val(data.id);
        $('#edit_team_name').val(data.name);
        $('#edit_team_desc').val(data.description);
        $('#editTeamModal').modal('show');
    }

    function manageMembers(teamId, teamName) {
        $('#teamModalTitle').html('<i class="fas fa-users-cog mr-2 text-primary"></i>' + teamName + ' — ' + '<?= __("staff_management") ?>');
        $('#teamMembersBody').html('<div class="text-center py-5"><i class="fas fa-circle-notch fa-spin fa-3x text-primary mb-3"></i><p class="text-muted font-weight-bold"><?= __("loading") ?>...</p></div>');
        $('#teamMembersModal').modal('show');

        $.ajax({
            url: '<?= $base_url ?>ajax/get_team_members.php',
            type: 'GET',
            headers: { 'X-CSRF-TOKEN': '<?= $_SESSION['csrf_token'] ?>' },
            data: { team_id: teamId },
            success: function (response) {
                $('#teamMembersBody').html(response);
            }
        });
    }

    function saveTeamMembers() {
        var formData = $('#teamMembersForm').serialize();
        var btn = $('#teamMembersModal .btn-success');
        var originalBtnHtml = btn.html();
        btn.addClass('disabled').html('<i class="fas fa-circle-notch fa-spin mr-2"></i><?= __("saving") ?>...');

        $.ajax({
            url: '<?= $base_url ?>ajax/save_team_members.php',
            type: 'POST',
            headers: { 'X-CSRF-TOKEN': '<?= $_SESSION['csrf_token'] ?>' },
            data: formData,
            success: function (response) {
                if (response.trim() === 'success') {
                    $('#teamMembersModal').modal('hide');
                    location.reload();
                } else {
                    btn.removeClass('disabled').html(originalBtnHtml);
                    Swal.fire('Hata', response, 'error');
                }
            }
        });
    }
</script>