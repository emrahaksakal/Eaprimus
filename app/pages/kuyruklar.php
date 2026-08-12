<?php
// pages/kuyruklar.php

require_once __DIR__ . '/../includes/session.php';
requireLogin();
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

// Yalnızca Admin (Role 1) görebilir.
if ($_SESSION['role'] != 1) {
    include __DIR__ . "/403.php";
    return;
}

$mesaj = '';
$hata = '';

// KUYRUK EKLEME / DÜZENLEME
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    if ($_POST['action'] == 'add_queue') {
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        $team_id = (int) $_POST['team_id'];
        $email = trim($_POST['email_address']);
        $sla_res = (int) $_POST['sla_resolution'];
        $sla_resp = (int) $_POST['sla_response'];
        $auto_assign = $_POST['auto_assign_mode'] ?? 'manual';
        $def_priority = $_POST['default_priority'] ?? 'normal';
        $keywords = trim($_POST['critical_keywords']);

        try {
            $stmt = $pdo->prepare("INSERT INTO queues (name, description, team_id, email_address, sla_resolution_hours, sla_response_hours, auto_assign_mode, default_priority, critical_keywords) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $desc, $team_id, $email, $sla_res, $sla_resp, $auto_assign, $def_priority, $keywords]);
            $mesaj = __("queue_created_success");
        } catch (PDOException $e) {
            $hata = __("db_error") . ": " . $e->getMessage();
        }
    } elseif ($_POST['action'] == 'delete_queue') {
        $id = (int) $_POST['queue_id'];
        try {
            $pdo->prepare("DELETE FROM queues WHERE id = ?")->execute([$id]);
            $mesaj = __("queue_deleted_success");
        } catch (PDOException $e) {
            $hata = __("queue_delete_error_tickets");
        }
    } elseif ($_POST['action'] == 'update_queue') {
        $id = (int) $_POST['queue_id'];
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        $team_id = (int) $_POST['team_id'];
        $email = trim($_POST['email_address']);
        $sla_res = (int) $_POST['sla_resolution'];
        $sla_resp = (int) $_POST['sla_response'];
        $auto_assign = $_POST['auto_assign_mode'] ?? 'manual';
        $def_priority = $_POST['default_priority'] ?? 'normal';
        $keywords = trim($_POST['critical_keywords']);

        try {
            $stmt = $pdo->prepare("UPDATE queues SET name=?, description=?, team_id=?, email_address=?, sla_resolution_hours=?, sla_response_hours=?, auto_assign_mode=?, default_priority=?, critical_keywords=? WHERE id=?");
            $stmt->execute([$name, $desc, $team_id, $email, $sla_res, $sla_resp, $auto_assign, $def_priority, $keywords, $id]);
            $mesaj = __("updated_successfully");
        } catch (PDOException $e) {
            $hata = __("db_error") . ": " . $e->getMessage();
        }
    }
}

// KUYRUKLARI ÇEK
$queues = [];
try {
    $stmtQ = $pdo->query("SELECT q.*, t.name as team_name FROM queues q LEFT JOIN teams t ON q.team_id = t.id ORDER BY t.name ASC, q.name ASC");
    $queues = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hata = __("db_error") . ": " . $e->getMessage();
}

// DROPDOWN İÇİN TAKIMLARI ÇEK
$teams = $pdo->query("SELECT id, name FROM teams WHERE status = 1 ORDER BY name ASC")->fetchAll();

?>

<style>
    /* Modern Kart & Tablo */
    .modern-card {
        border: none !important;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, .05) !important;
        overflow: hidden;
        background: #fff;
        margin-bottom: 20px;
    }

    .modern-card .card-header {
        background: #fff;
        border-bottom: 1px solid #f0f2f5;
        padding: 20px 24px;
    }

    .modern-card .card-title {
        font-size: 16px;
        font-weight: 700;
        color: #2c3e50;
        margin: 0;
    }

    .modern-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-bottom: 0;
    }

    .modern-table thead th {
        background: #f8fafc;
        color: #64748b;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        padding: 12px 20px;
        border-bottom: 1px solid #e2e8f0;
        border-top: none;
    }

    .modern-table tbody td {
        padding: 16px 20px;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        font-size: 14px;
    }

    .modern-table tbody tr:hover td {
        background-color: #f8fafc;
    }

    /* Dark Mode Uyum */
    body.dark-mode .text-dark {
        color: #eee !important;
    }

    body.dark-mode .modern-card {
        background: #343a40 !important;
        border: 1px solid #444 !important;
        box-shadow: none !important;
    }

    body.dark-mode .modern-card .card-header {
        background: #343a40 !important;
        border-bottom-color: #4b545c !important;
    }

    body.dark-mode .card-title {
        color: #eee !important;
    }

    body.dark-mode .modern-table thead th {
        background: #3f474e !important;
        color: #ced4da !important;
        border-bottom-color: #4b545c !important;
    }

    body.dark-mode .modern-table tbody td {
        border-bottom-color: #4b545c !important;
        color: #eee !important;
    }

    body.dark-mode .modern-table tbody tr:hover td {
        background-color: #454d55 !important;
    }

    body.dark-mode .form-control {
        background: #454d55 !important;
        border-color: #555 !important;
        color: #eee !important;
    }

    body.dark-mode .text-muted,
    body.dark-mode .text-info {
        color: #adb5bd !important;
    }
</style>

<div class="row">
    <!-- Kuyruklar Başlığı -->
    <div class="col-12 mb-3">
        <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-stream mr-2 text-warning"></i>
            <?= __("queue_management_title") ?></h1>
        <p class="text-muted small"><?= __("queues_description") ?></p>
    </div>

    <!-- Kuyruk Ekleme Formu (SOL TARAFTA) -->
    <div class="col-lg-4 col-md-12 mb-4">
        <div class="modern-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus-circle text-warning mr-2"></i>
                    <?= __("create_new_queue") ?>
                </h3>
            </div>
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_queue">
                <div class="card-body">
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __("group_team") ?> <span
                                class="text-danger">*</span></label>
                        <select class="form-control" name="team_id" required>
                            <option value="" disabled selected><?= __("select_team") ?></option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __("queue_name") ?> <span
                                class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required
                            placeholder="<?= __("queue_name_placeholder") ?>">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __("support_email_optional") ?></label>
                        <input type="email" name="email_address" class="form-control"
                            placeholder="<?= __("support_email_placeholder") ?>">
                        <small class="text-muted"><?= __("email_to_ticket_hint") ?></small>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= __("sla_first_response_hours") ?></label>
                                <input type="number" name="sla_response" class="form-control" value="4" min="1">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= __("sla_resolution_hours") ?></label>
                                <input type="number" name="sla_resolution" class="form-control" value="24" min="1">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= __("auto_atama") ?></label>
                                <select name="auto_assign_mode" class="form-control">
                                    <option value="manual"><?= __("manual_distribution") ?></option>
                                    <option value="round_robin"><?= __("round_robin_assignment") ?></option>
                                    <option value="least_active"><?= __("least_active_assignment") ?></option>
                                    <option value="supervisor"><?= __("supervisor_approved") ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= __("default_priority_label") ?></label>
                                <select name="default_priority" class="form-control">
                                    <option value="low"><?= __("low") ?></option>
                                    <option value="normal" selected><?= __("normal") ?></option>
                                    <option value="high"><?= __("high") ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __("critical_keyword_filter") ?></label>
                        <input type="text" name="critical_keywords" class="form-control"
                            placeholder="<?= __("critical_keywords_placeholder") ?>">
                        <small class="text-muted"><?= __("critical_keywords_hint") ?></small>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __("description") ?></label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0 text-right pb-4 pr-4">
                    <button type="submit" class="btn btn-warning font-weight-bold px-4 rounded-pill"><i
                            class="fas fa-save mr-1"></i>
                        <?= __("create") ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Kuyruklar Listesi (SAĞ TARAFTA) -->
    <div class="col-lg-8 col-md-12 mb-4">
        <div class="modern-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-list-ul text-primary mr-2"></i>
                    <?= __("system_queues_categories") ?></h3>
            </div>
            <div class="card-body table-responsive p-0">
                <table class="table modern-table">
                    <thead>
                        <tr>
                            <th><?= __("team") ?></th>
                            <th><?= __("queue_name") ?></th>
                            <th><?= __("automation") ?></th>
                            <th><?= __("defined_email") ?></th>
                            <th><?= __("sla_resp_res") ?></th>
                            <th style="width: 80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queues as $q): ?>
                            <tr>
                                <td><span class="badge badge-primary px-2 py-1 shadow-sm"
                                        style="border-radius:6px;"><?= htmlspecialchars($q['team_name'] ?? __("unknown")) ?></span>
                                </td>
                                <td><strong style="font-size: 15px;"><?= htmlspecialchars($q['name']) ?></strong>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <?php 
                                            $modes = [
                                                'manual' => 'Manuel Atama',
                                                'round_robin' => 'Sırayla (Round Robin)',
                                                'least_active' => 'En Az Yoğun Olan',
                                                'supervisor' => 'Yönetici Onaylı'
                                            ];
                                            $m = $q['auto_assign_mode'];
                                            $m_tr = $modes[$m] ?? $m;
                                        ?>
                                        <small class="text-info font-weight-bold"><i class="fas fa-robot mr-1"></i>
                                            <?= htmlspecialchars($m_tr) ?></small>
                                        <?php if (!empty($q['critical_keywords'])): ?>
                                            <small class="text-danger mt-1"
                                                title="<?= htmlspecialchars($q['critical_keywords']) ?>">
                                                <i class="fas fa-exclamation-circle mr-1"></i>
                                                <?= __("critical_filter") ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-muted text-sm"><i
                                            class="far fa-envelope mr-1"></i><?= htmlspecialchars($q['email_address']) ?: __("none") ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-info shadow-sm px-2"
                                        title="<?= __("first_response_time") ?>"><?= $q['sla_response_hours'] ?><?= __("h_short") ?></span>
                                    /
                                    <span class="badge badge-danger shadow-sm px-2"
                                        title="<?= __("max_resolution_time") ?>"><?= $q['sla_resolution_hours'] ?><?= __("h_short") ?></span>
                                </td>
                                <td class="text-right text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-info border-0 mr-1" title="<?= __("edit") ?>" 
                                        onclick="editQueue(<?= htmlspecialchars(json_encode($q)) ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" action="" style="display:inline;" class="delete-queue-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_queue">
                                        <input type="hidden" name="queue_id" value="<?= $q['id'] ?>">
                                        <button type="button" class="btn btn-sm btn-outline-danger border-0 delete-btn"
                                            title="<?= __("delete") ?>"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($queues)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5"><i
                                        class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i><?= __("no_queues_found") ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit Queue Modal -->
<div class="modal fade" id="editQueueModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 16px; border: none; overflow: hidden;">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_queue">
                <input type="hidden" name="queue_id" id="edit_queue_id">
                
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-edit mr-2 text-info"></i><?= __("edit") ?></h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __("queue_name") ?></label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __("group_team") ?></label>
                        <select class="form-control" name="team_id" id="edit_team_id" required>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= __("sla_first_response_hours") ?></label>
                                <input type="number" name="sla_response" id="edit_sla_response" class="form-control" min="1">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= __("sla_resolution_hours") ?></label>
                                <input type="number" name="sla_resolution" id="edit_sla_resolution" class="form-control" min="1">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= __("auto_atama") ?></label>
                                <select name="auto_assign_mode" id="edit_auto_assign" class="form-control">
                                    <option value="manual"><?= __("manual_distribution") ?></option>
                                    <option value="round_robin"><?= __("round_robin_assignment") ?></option>
                                    <option value="least_active"><?= __("least_active_assignment") ?></option>
                                    <option value="supervisor"><?= __("supervisor_approved") ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= __("default_priority_label") ?></label>
                                <select name="default_priority" id="edit_default_priority" class="form-control">
                                    <option value="low"><?= __("low") ?></option>
                                    <option value="normal"><?= __("normal") ?></option>
                                    <option value="high"><?= __("high") ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __("support_email_optional") ?></label>
                        <input type="email" name="email_address" id="edit_email" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __("critical_keyword_filter") ?></label>
                        <input type="text" name="critical_keywords" id="edit_keywords" class="form-control">
                    </div>
                    
                    <div class="form-group mb-0">
                        <label class="font-weight-bold"><?= __("description") ?></label>
                        <textarea name="description" id="edit_desc" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer bg-light border-0 pt-2 pb-3 px-4">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __("cancel") ?></button>
                    <button type="submit" class="btn btn-info font-weight-bold px-4"><?= __("save_changes") ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modern UI ve F5 Yenileme Koruması -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function editQueue(queue) {
        document.getElementById('edit_queue_id').value = queue.id;
        document.getElementById('edit_name').value = queue.name;
        document.getElementById('edit_team_id').value = queue.team_id;
        document.getElementById('edit_sla_response').value = queue.sla_response_hours;
        document.getElementById('edit_sla_resolution').value = queue.sla_resolution_hours;
        document.getElementById('edit_auto_assign').value = queue.auto_assign_mode;
        document.getElementById('edit_default_priority').value = queue.default_priority;
        document.getElementById('edit_email').value = queue.email_address;
        document.getElementById('edit_keywords').value = queue.critical_keywords;
        document.getElementById('edit_desc').value = queue.description;
        
        $('#editQueueModal').modal('show');
    }

    // F5 yapıldığında formun tekrar gönderilmesini engelle
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }

    // Modern Silme Onayı
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const form = this.closest('.delete-queue-form');
            Swal.fire({
                title: 'Emin misiniz?',
                text: "Bu kuyruğu silmek istediğinize emin misiniz? (Eğer kuyruğa bağlı biletler varsa silinemez)",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Evet, Sil!',
                cancelButtonText: 'İptal'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
</script>