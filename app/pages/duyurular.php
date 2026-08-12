<?php
// pages/duyurular.php

require_once __DIR__ . '/../includes/session.php';
requireLogin();
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

// Ensure announcements tables exist
try {
    $pdo->query("SELECT 1 FROM announcements LIMIT 1");
} catch (Exception $ex) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            target_role VARCHAR(50) NOT NULL DEFAULT 'all',
            target_team_id INT NULL DEFAULT NULL,
            is_banner TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            start_date DATETIME NULL DEFAULT NULL,
            end_date DATETIME NULL DEFAULT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            announcement_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ann_user (announcement_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    } catch (Exception $ex2) {}
}

// Her oturum sahibi duyuruyu kapatabilir (Okundu İşaretleme)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dismiss_announcement') {
    $ann_id = intval($_POST['announcement_id'] ?? 0);
    $user_id = $_SESSION['user_id'] ?? 0;
    if ($ann_id > 0 && $user_id > 0) {
        try {
            $stmtRead = $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)");
            $stmtRead->execute([$ann_id, $user_id]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    exit;
}

// Sadece Yöneticiler (Role 1) veya Teknik Destek (Role 3) duyuru yönetebilir
$current_role = $_SESSION['role'] ?? 2;
if (!in_array($current_role, [1, 3])) {
    include __DIR__ . "/403.php";
    return;
}

$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';
$mesaj = '';
$hata = '';

// DUYURU KAYDETME (ADD / UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    
    if ($_POST['action'] === 'save_announcement') {
        $id = intval($_POST['announcement_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $type = trim($_POST['type'] ?? 'info');
        $target_role = trim($_POST['target_role'] ?? 'all');
        $target_team_id = !empty($_POST['target_team_id']) ? intval($_POST['target_team_id']) : null;
        $is_banner = isset($_POST['is_banner']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $created_by = $_SESSION['user_id'] ?? 0;

        if (!empty($title) && !empty($content)) {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE announcements SET title = ?, content = ?, type = ?, target_role = ?, target_team_id = ?, is_banner = ?, is_active = ?, start_date = ?, end_date = ? WHERE id = ?");
                    $stmt->execute([$title, $content, $type, $target_role, $target_team_id, $is_banner, $is_active, $start_date, $end_date, $id]);
                    $mesaj = $isTr ? "Duyuru başarıyla güncellendi." : "Announcement updated successfully.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO announcements (title, content, type, target_role, target_team_id, is_banner, is_active, start_date, end_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$title, $content, $type, $target_role, $target_team_id, $is_banner, $is_active, $start_date, $end_date, $created_by]);
                    $mesaj = $isTr ? "Yeni duyuru başarıyla yayınlandı." : "New announcement published successfully.";
                }
            } catch (PDOException $e) {
                $hata = ($isTr ? "Veritabanı hatası" : "Database error") . ": " . $e->getMessage();
            }
        } else {
            $hata = $isTr ? "Lütfen tüm zorunlu alanları doldurun." : "Please fill in all required fields.";
        }
    } elseif ($_POST['action'] === 'delete_announcement') {
        $id = intval($_POST['announcement_id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM announcement_reads WHERE announcement_id = ?")->execute([$id]);
                $mesaj = $isTr ? "Duyuru başarıyla silindi." : "Announcement deleted successfully.";
            } catch (PDOException $e) {
                $hata = ($isTr ? "Duyuru silinirken hata oluştu" : "Error deleting announcement") . ": " . $e->getMessage();
            }
        }
    }
}

// Tüm Duyuruları Çek
$announcements = [];
try {
    if ($my_role == 1) {
        // Admin tüm duyuruları yönetmek için görür
        $stmtAnn = $pdo->query("
            SELECT a.*, u.fullname as creator_name, t.name as team_name,
                   (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.id) as read_count
            FROM announcements a
            LEFT JOIN users u ON a.created_by = u.id
            LEFT JOIN teams t ON a.target_team_id = t.id
            ORDER BY a.created_at DESC
        ");
        $announcements = $stmtAnn->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Personel/Teknik ekip sadece kendisine hitap eden aktif duyuruları görür
        $userTeams = [0];
        $stmtT = $pdo->prepare("SELECT team_id FROM teams_users WHERE user_id = ?");
        $stmtT->execute([$_SESSION['user_id']]);
        $userTeams = array_merge($userTeams, $stmtT->fetchAll(PDO::FETCH_COLUMN));
        $inTeams = implode(',', array_map('intval', $userTeams));

        $roleMap = ($my_role == 3) ? 'tech' : 'personnel';

        $stmtAnn = $pdo->prepare("
            SELECT a.*, u.fullname as creator_name, t.name as team_name,
                   (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.id) as read_count
            FROM announcements a
            LEFT JOIN users u ON a.created_by = u.id
            LEFT JOIN teams t ON a.target_team_id = t.id
            WHERE a.is_active = 1
              AND (a.start_date IS NULL OR a.start_date <= NOW())
              AND (a.end_date IS NULL OR a.end_date >= NOW())
              AND (
                   a.target_role = 'all'
                   OR a.target_role = ?
                   OR (a.target_role = 'team' AND a.target_team_id IN ($inTeams))
              )
            ORDER BY a.created_at DESC
        ");
        $stmtAnn->execute([$roleMap]);
        $announcements = $stmtAnn->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $hata = $e->getMessage();
}

// Takımları Çek (Hedef kitle dropdown için)
$teams = [];
try {
    $teams = $pdo->query("SELECT id, name FROM teams WHERE status = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<!-- Summernote CSS & JS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>

<div class="row">
    <div class="col-md-12">
        <?php if (!empty($mesaj)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0" style="border-radius:10px;">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($mesaj) ?>
                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($hata)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" style="border-radius:10px;">
                <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($hata) ?>
                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
            </div>
        <?php endif; ?>

        <div class="card modern-card">
            <div class="card-header d-flex justify-content-between align-items-center bg-white py-3">
                <h4 class="mb-0 font-weight-bold" style="color:#0f1b3d;">
                    <i class="fas fa-bullhorn text-primary mr-2"></i><?= $isTr ? 'Toplu Duyurular & Bildirimler' : 'System Broadcast Announcements' ?>
                </h4>
                <button type="button" class="btn btn-primary btn-sm px-4 shadow-sm font-weight-bold" onclick="openAnnModal()">
                    <i class="fas fa-plus mr-1"></i><?= $isTr ? 'Yeni Duyuru Oluştur' : 'Create New Announcement' ?>
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:13.5px;">
                        <thead class="bg-light text-muted">
                            <tr>
                                <th style="width: 50px;">ID</th>
                                <th><?= $isTr ? 'Duyuru Başlığı' : 'Announcement Title' ?></th>
                                <th><?= $isTr ? 'Tür' : 'Type' ?></th>
                                <th><?= $isTr ? 'Hedef Kitle' : 'Target Audience' ?></th>
                                <th><?= $isTr ? 'Yayın Aralığı' : 'Broadcast Period' ?></th>
                                <th><?= $isTr ? 'Okunma' : 'Reads' ?></th>
                                <th>Durum</th>
                                <th class="text-right" style="width: 180px;"><?= $isTr ? 'İşlemler' : 'Actions' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($announcements)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fas fa-bullhorn fa-3x mb-3 text-secondary" style="opacity: 0.5;"></i>
                                        <h5 class="font-weight-bold"><?= $isTr ? 'Yayınlanmış Duyuru Bulunmuyor' : 'No Published Announcements Found' ?></h5>
                                        <p class="small text-muted"><?= $isTr ? 'Yeni bir duyuru oluşturup yayınlamak için yukarıdaki butonu kullanın.' : 'Click the button above to create and publish a system announcement.' ?></p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($announcements as $ann): ?>
                                    <tr>
                                        <td class="text-muted">#<?= $ann['id'] ?></td>
                                        <td class="font-weight-bold text-dark">
                                            <?= htmlspecialchars($ann['title']) ?>
                                            <div class="small text-muted font-weight-normal"><?= $isTr ? 'Oluşturan' : 'Created by' ?>: <?= htmlspecialchars($ann['creator_name'] ?? 'System') ?></div>
                                        </td>
                                        <td>
                                            <?php
                                            $badgeClass = 'badge-info';
                                            $typeText = $isTr ? 'Bilgi' : 'Info';
                                            if ($ann['type'] === 'warning') {
                                                $badgeClass = 'badge-warning text-dark';
                                                $typeText = $isTr ? 'Bakım / Uyarı' : 'Maintenance / Warning';
                                            } elseif ($ann['type'] === 'danger') {
                                                $badgeClass = 'badge-danger';
                                                $typeText = $isTr ? 'Kesinti / Arıza ⚠️' : 'Outage / Incident ⚠️';
                                            } elseif ($ann['type'] === 'success') {
                                                $badgeClass = 'badge-success';
                                                $typeText = $isTr ? 'Güncelleme' : 'Update';
                                            }
                                            ?>
                                            <span class="badge <?= $badgeClass ?> px-3 py-1 font-weight-bold" style="border-radius: 6px;">
                                                <?= $typeText ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            if ($ann['target_role'] === 'all') {
                                                echo '<span class="text-primary font-weight-bold"><i class="fas fa-globe mr-1"></i>' . ($isTr ? 'Tüm Kullanıcılar' : 'All Users') . '</span>';
                                            } elseif ($ann['target_role'] === 'personnel') {
                                                echo '<span class="text-secondary font-weight-bold"><i class="fas fa-user-friends mr-1"></i>' . ($isTr ? 'Müşteriler / Personel (Rol 2)' : 'Personnel (Role 2)') . '</span>';
                                            } elseif ($ann['target_role'] === 'tech') {
                                                echo '<span class="text-info font-weight-bold"><i class="fas fa-headset mr-1"></i>' . ($isTr ? 'Teknik Destek (Rol 3)' : 'Tech Support (Role 3)') . '</span>';
                                            } elseif ($ann['target_role'] === 'admin') {
                                                echo '<span class="text-dark font-weight-bold"><i class="fas fa-user-shield mr-1"></i>' . ($isTr ? 'Yöneticiler (Rol 1)' : 'Admins (Role 1)') . '</span>';
                                            } elseif ($ann['target_role'] === 'team') {
                                                echo '<span class="text-warning font-weight-bold"><i class="fas fa-users mr-1"></i>' . ($isTr ? 'Takım' : 'Team') . ': ' . htmlspecialchars($ann['team_name'] ?? 'Unknown') . '</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($ann['start_date'] || $ann['end_date']): ?>
                                                <span class="small text-muted d-block">
                                                    <strong>Başlangıç:</strong> <?= $ann['start_date'] ? date('d.m.Y H:i', strtotime($ann['start_date'])) : '-' ?>
                                                </span>
                                                <span class="small text-muted d-block">
                                                    <strong>Bitiş:</strong> <?= $ann['end_date'] ? date('d.m.Y H:i', strtotime($ann['end_date'])) : '-' ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted font-italic"><?= $isTr ? 'Süresiz Yayın' : 'Indefinite' ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $readersTooltip = '';
                                            try {
                                                $stmtRNames = $pdo->prepare("
                                                    SELECT u.fullname, r.read_at 
                                                    FROM announcement_reads r 
                                                    JOIN users u ON r.user_id = u.id 
                                                    WHERE r.announcement_id = ?
                                                    ORDER BY r.read_at ASC
                                                ");
                                                $stmtRNames->execute([$ann['id']]);
                                                $readersData = $stmtRNames->fetchAll(PDO::FETCH_ASSOC);
                                                if (!empty($readersData)) {
                                                    $rNames = [];
                                                    foreach ($readersData as $rd) {
                                                        $rNames[] = $rd['fullname'] . ' (' . date('d.m.Y H:i', strtotime($rd['read_at'])) . ')';
                                                    }
                                                    $readersTooltip = ($isTr ? 'Okuyanlar: ' : 'Read by: ') . implode(', ', $rNames);
                                                } else {
                                                    $readersTooltip = $isTr ? 'Henüz kimse okumadı.' : 'No one has read this yet.';
                                                }
                                            } catch (Exception $e) {
                                                $readersTooltip = 'Error fetching readers';
                                            }
                                            ?>
                                            <span class="badge badge-light border text-muted px-2 py-1 font-weight-bold" style="cursor: help;" data-toggle="tooltip" data-placement="top" title="<?= htmlspecialchars($readersTooltip) ?>">
                                                <i class="fas fa-eye mr-1 text-primary"></i><?= $ann['read_count'] ?> <?= $isTr ? 'Okunma' : 'Reads' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($ann['is_active']): ?>
                                                <span class="badge badge-success px-2 py-1"><?= $isTr ? 'Aktif' : 'Active' ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary px-2 py-1"><?= $isTr ? 'Pasif' : 'Passive' ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <button type="button" class="btn btn-outline-primary btn-xs mr-1 font-weight-bold" onclick='editAnnouncement(<?= json_encode($ann, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                                <i class="fas fa-edit mr-1"></i><?= $isTr ? 'Düzenle' : 'Edit' ?>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-xs font-weight-bold" onclick="deleteAnnouncement(<?= $ann['id'] ?>, '<?= htmlspecialchars($ann['title'], ENT_QUOTES) ?>')">
                                                <i class="fas fa-trash-alt mr-1"></i><?= $isTr ? 'Sil' : 'Delete' ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add / Edit Announcement -->
<div class="modal fade" id="announcementModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content" style="border-radius:12px; border:none; overflow:hidden;">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title font-weight-bold" id="announcementModalTitle">
                    <i class="fas fa-bullhorn text-warning mr-2"></i><?= $isTr ? 'Sistem Duyurusu Oluştur' : 'Create System Announcement' ?>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="anasayfa?route=duyurular" onsubmit="return validateAnnForm(this);">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_announcement">
                <input type="hidden" name="announcement_id" id="modal_announcement_id" value="0">
                
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= $isTr ? 'Duyuru Başlığı' : 'Announcement Title' ?> <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="modal_ann_title" class="form-control" placeholder="<?= $isTr ? 'Örn: Eaprimus Sunucu Güncellemesi Hakkında' : 'e.g. Server Maintenance Notice' ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= $isTr ? 'Duyuru Türü' : 'Type' ?></label>
                                <select name="type" id="modal_ann_type" class="form-control">
                                    <option value="info">🔵 <?= $isTr ? 'Bilgilendirme (Info)' : 'Info' ?></option>
                                    <option value="warning">🟡 <?= $isTr ? 'Bakım / Uyarı (Warning)' : 'Maintenance' ?></option>
                                    <option value="danger">🔴 <?= $isTr ? 'Kesinti / Arıza (Incident)' : 'Incident/Outage' ?></option>
                                    <option value="success">🟢 <?= $isTr ? 'Güncelleme / Başarılı (Update)' : 'Update' ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= $isTr ? 'Hedef Kitle / Görünürlük' : 'Target Audience' ?></label>
                                <select name="target_role" id="modal_ann_target_role" class="form-control" onchange="toggleAnnTeamSelect(this.value)">
                                    <option value="all"><?= $isTr ? 'Tüm Kullanıcılar (Genel)' : 'All Users (Global)' ?></option>
                                    <option value="personnel"><?= $isTr ? 'Müşteriler / Personeller' : 'Personnel Only' ?></option>
                                    <option value="tech"><?= $isTr ? 'Teknik Destek Ekibi' : 'Tech Support Only' ?></option>
                                    <option value="admin"><?= $isTr ? 'Yöneticiler / Adminler' : 'Admins Only' ?></option>
                                    <option value="team"><?= $isTr ? 'Belirli Bir Takıma / Departmana Özel' : 'Specific Team Only' ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6" id="ann_team_select_wrapper" style="display:none;">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= $isTr ? 'Hedef Takım / Departman' : 'Target Team' ?></label>
                                <select name="target_team_id" id="modal_ann_team_id" class="form-control">
                                    <option value=""><?= $isTr ? '-- Takım Seçin --' : '-- Select Team --' ?></option>
                                    <?php foreach ($teams as $tm): ?>
                                        <option value="<?= $tm['id'] ?>"><?= htmlspecialchars($tm['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group mt-2">
                        <label class="font-weight-bold"><?= $isTr ? 'Duyuru İçeriği' : 'Announcement Content' ?> <span class="text-danger">*</span></label>
                        <textarea name="content" id="modal_ann_content" class="form-control summernote" rows="5"></textarea>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= $isTr ? 'Yayın Başlangıç Tarihi' : 'Start Date' ?></label>
                                <input type="datetime-local" name="start_date" id="modal_ann_start_date" class="form-control">
                                <small class="text-muted"><?= $isTr ? 'Boş bırakılırsa hemen yayınlanır.' : 'Leave blank for immediate release.' ?></small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= $isTr ? 'Yayın Bitiş Tarihi' : 'End Date' ?></label>
                                <input type="datetime-local" name="end_date" id="modal_ann_end_date" class="form-control">
                                <small class="text-muted"><?= $isTr ? 'Boş bırakılırsa süresiz yayınlanır.' : 'Leave blank for indefinite release.' ?></small>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="row mt-2">
                        <div class="col-md-6">
                            <div class="custom-control custom-switch mt-2">
                                <input type="checkbox" class="custom-control-input" name="is_banner" id="modal_ann_is_banner" value="1" checked>
                                <label class="custom-control-label font-weight-bold" for="modal_ann_is_banner">
                                    <?= $isTr ? 'Sayfa Üstünde Canlı Bant Olarak Göster' : 'Show as Top Banner Alert' ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="custom-control custom-switch mt-2">
                                <input type="checkbox" class="custom-control-input" name="is_active" id="modal_ann_is_active" value="1" checked>
                                <label class="custom-control-label font-weight-bold" for="modal_ann_is_active">
                                    <?= $isTr ? 'Duyuru Aktif (Yayınlansın)' : 'Announcement Active (Published)' ?>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-4" data-dismiss="modal"><?= $isTr ? 'İptal' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm font-weight-bold">
                        <i class="fas fa-paper-plane mr-1"></i><?= $isTr ? 'Yayınla' : 'Publish' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Delete Form -->
<form id="deleteAnnouncementForm" method="POST" action="anasayfa?route=duyurular" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_announcement">
    <input type="hidden" name="announcement_id" id="delete_ann_id" value="0">
</form>

<script>
    const isTr = <?= json_encode($isTr) ?>;

    function toggleAnnTeamSelect(val) {
        if (val === 'team') {
            $('#ann_team_select_wrapper').slideDown();
        } else {
            $('#ann_team_select_wrapper').slideUp();
        }
    }

    function validateAnnForm(form) {
        const title = $('#modal_ann_title').val().trim();
        const content = $('#modal_ann_content').val().trim();
        const target = $('#modal_ann_target_role').val();
        const team = $('#modal_ann_team_id').val();

        if (!title || !content) {
            Swal.fire({
                icon: 'warning',
                title: isTr ? 'Eksik Alan!' : 'Missing Field!',
                text: isTr ? 'Lütfen başlık ve duyuru içeriği alanlarını doldurun.' : 'Please fill in the title and content fields.'
            });
            return false;
        }

        if (target === 'team' && !team) {
            Swal.fire({
                icon: 'warning',
                title: isTr ? 'Takım Seçimi Zorunlu!' : 'Team Selection Required!',
                text: isTr ? 'Lütfen hedef takımı seçin.' : 'Please choose the target team.'
            });
            return false;
        }
        return true;
    }

    function openAnnModal() {
        $('#modal_announcement_id').val(0);
        $('#modal_ann_title').val('');
        $('#modal_ann_type').val('info');
        $('#modal_ann_target_role').val('all');
        $('#modal_ann_team_id').val('');
        $('#modal_ann_content').summernote('code', '');
        $('#modal_ann_start_date').val('');
        $('#modal_ann_end_date').val('');
        $('#modal_ann_is_banner').prop('checked', true);
        $('#modal_ann_is_active').prop('checked', true);
        toggleAnnTeamSelect('all');
        $('#announcementModalTitle').html('<i class="fas fa-bullhorn text-warning mr-2"></i>' + (isTr ? 'Sistem Duyurusu Oluştur' : 'Create System Announcement'));
        $('#announcementModal').modal('show');
    }

    function editAnnouncement(item) {
        $('#modal_announcement_id').val(item.id);
        $('#modal_ann_title').val(item.title);
        $('#modal_ann_type').val(item.type);
        $('#modal_ann_target_role').val(item.target_role);
        $('#modal_ann_team_id').val(item.target_team_id || '');
        $('#modal_ann_content').summernote('code', item.content || '');
        
        // Date formatting for datetime-local (Y-m-d\TH:i)
        if (item.start_date) {
            const sd = new Date(item.start_date);
            const formattedSd = sd.toISOString().slice(0, 16);
            $('#modal_ann_start_date').val(formattedSd);
        } else {
            $('#modal_ann_start_date').val('');
        }

        if (item.end_date) {
            const ed = new Date(item.end_date);
            const formattedEd = ed.toISOString().slice(0, 16);
            $('#modal_ann_end_date').val(formattedEd);
        } else {
            $('#modal_ann_end_date').val('');
        }

        $('#modal_ann_is_banner').prop('checked', item.is_banner == 1);
        $('#modal_ann_is_active').prop('checked', item.is_active == 1);
        
        toggleAnnTeamSelect(item.target_role);
        $('#announcementModalTitle').html('<i class="fas fa-edit text-primary mr-2"></i>' + (isTr ? 'Duyuruyu Düzenle' : 'Edit Announcement'));
        $('#announcementModal').modal('show');
    }

    function deleteAnnouncement(id, title) {
        Swal.fire({
            title: isTr ? 'Emin misiniz?' : 'Are you sure?',
            text: '"' + title + '" ' + (isTr ? 'başlıklı duyuru tamamen silinecektir.' : 'announcement will be permanently deleted.'),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: isTr ? 'Evet, Sil' : 'Yes, Delete',
            cancelButtonText: isTr ? 'İptal' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#delete_ann_id').val(id);
                $('#deleteAnnouncementForm').submit();
            }
        });
    }

    $(document).ready(function() {
        if ($('.summernote').length) {
            $('.summernote').summernote({
                height: 180,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', ['link']],
                    ['view', ['fullscreen', 'codeview']]
                ]
            });
        }
        // Initialize tooltips for readers count list hover
        $('[data-toggle="tooltip"]').tooltip();
    });
</script>
