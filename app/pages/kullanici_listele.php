<?php
// pages/kullanici_listele.php

// Oturum kontrolü
require_once __DIR__ . '/../includes/session.php';
requireLogin();

// Veritabanı bağlantısı
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

$mesaj = '';
$hata = '';
$current_user_role = $_SESSION['role'];
$current_user_id = $_SESSION['user_id'];
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

$view_deleted = isset($_GET['view_deleted']) && $_GET['view_deleted'] == '1';

// -------------------------
// 🗑️ KULLANICI SİLME / GERİ YÜKLEME
// -------------------------
if (isset($_GET['delete'])) {
    if ($current_user_role == 1 || $current_user_role == 3) {
        $user_id_to_delete = (int) $_GET['delete'];
        if ($user_id_to_delete == $current_user_id) {
            $_SESSION['mesaj'] = __("error") . ": " . __("cannot_delete_self");
            header("Location: " . $base_url . "kullanici-listele" . ($view_deleted ? "/deleted" : ""));
            exit;
        }

        try {
            if (isset($_GET['permanent'])) {
                $stmtDel = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $event = 'deleted_permanent';
                $msg = $isTr ? "Kullanıcı kalıcı olarak silindi." : "User permanently deleted.";
            } else {
                // Soft Delete: Veritabanından silmek yerine deleted_at alanını dolduruyoruz
                $stmtDel = $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ?");
                $event = 'deleted';
                $msg = $isTr ? "Kullanıcı çöp kutusuna taşındı." : "User moved to trash.";
            }
            
            $stmtDel->execute([$user_id_to_delete]);
            
            if (file_exists(__DIR__ . '/../includes/asset_helpers.php')) {
                require_once __DIR__ . '/../includes/asset_helpers.php';
                addAssetLog($pdo, $user_id_to_delete, $current_user_id, $event, "Kullanıcı kaydı " . ($event == 'deleted' ? 'çöp kutusuna taşındı.' : 'kalıcı olarak silindi.'), null, 'user');
            }

            $_SESSION['mesaj'] = $msg;
            header("Location: " . $base_url . "kullanici-listele" . (isset($_GET['permanent']) ? "/deleted" : ""));
            exit;
        } catch (PDOException $e) {
            $hata = __("delete_error") . ": " . $e->getMessage();
        }
    }
}

if (isset($_GET['restore'])) {
    if ($current_user_role == 1 || $current_user_role == 3) {
        $user_id_to_restore = (int) $_GET['restore'];
        try {
            $pdo->prepare("UPDATE users SET deleted_at = NULL WHERE id = ?")->execute([$user_id_to_restore]);
            if (file_exists(__DIR__ . '/../includes/asset_helpers.php')) {
                require_once __DIR__ . '/../includes/asset_helpers.php';
                addAssetLog($pdo, $user_id_to_restore, $current_user_id, 'restored', "Kullanıcı çöp kutusundan geri yüklendi.", null, 'user');
            }
            
            $_SESSION['mesaj'] = $isTr ? "Kullanıcı başarıyla geri yüklendi." : "User restored successfully.";
            header("Location: kullanici-listele");
            exit;
        } catch (PDOException $e) {
            $hata = "Geri yükleme hatası: " . $e->getMessage();
        }
    }
}

// -------------------------
// 📋 LİSTELEME
// -------------------------
if (isset($_POST['action']) && $_POST['action'] == 'toggle_status' && !$view_deleted) {
    $uid = intval($_POST['user_id']);
    $new_status = intval($_POST['status']);
    if ($uid != $current_user_id && ($current_user_role == 1 || $current_user_role == 3)) {
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new_status, $uid]);
        $_SESSION['mesaj'] = $isTr ? "Kullanıcı durumu güncellendi." : "User status updated.";
        header("Location: kullanici-listele");
        exit;
    }
}

try {
    $deletedCondition = $view_deleted ? "u.deleted_at IS NOT NULL" : "u.deleted_at IS NULL";
    
    // Pagination Configuration
    $limit = intval($_GET['limit'] ?? 25);
    if ($limit < 5 || $limit > 100) $limit = 25;
    $current_page = intval($_GET['page'] ?? 1);
    if ($current_page < 1) $current_page = 1;

    $total_records = $pdo->query("SELECT COUNT(*) FROM users u WHERE $deletedCondition AND u.username != 'customer_gateway'")->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    if ($current_page > $total_pages && $total_pages > 0) {
        $current_page = $total_pages;
    }
    $offset = ($current_page - 1) * $limit;
    
    $sql = "SELECT
                u.id, u.fullname, u.username, u.role, u.status, u.profil_fotosu, u.password, u.deleted_at, u.custom_role_id, u.bolum as bolum_id,
                CAST(AES_DECRYPT(UNHEX(u.tc_no), '" . EAPRIMUS_KEY . "') AS CHAR) as tc_no,
                GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') as team_names,
                b.bolum_adi,
                cr.role_name as custom_role_name,
                (SELECT COUNT(*) FROM assets WHERE assigned_user_id = u.id AND deleted_at IS NULL) as asset_count,
                (SELECT COUNT(*) FROM asset_license_checkouts WHERE user_id = u.id) as license_count,
                (SELECT COUNT(*) FROM asset_accessories WHERE assigned_user_id = u.id AND deleted_at IS NULL) as accessory_count,
                (SELECT COUNT(*) FROM asset_consumable_checkouts WHERE user_id = u.id) as consumable_count
            FROM users u
            LEFT JOIN teams_users tu ON u.id = tu.user_id
            LEFT JOIN teams t ON tu.team_id = t.id
            LEFT JOIN bolumler b ON u.bolum = b.id
            LEFT JOIN custom_roles cr ON u.custom_role_id = cr.id
            WHERE $deletedCondition AND u.username != 'customer_gateway'
            GROUP BY u.id
            ORDER BY u.id DESC
            LIMIT $limit OFFSET $offset";

    $users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hata = __("database_error") . ": " . $e->getMessage();
}
?>

<style>
    .badge-soft-primary { background: #e0e7ff; color: #3730a3; }
    .badge-soft-success { background: #d1fae5; color: #065f46; }
    .badge-soft-danger { background: #fee2e2; color: #991b1b; }
    .badge-soft-info { background: #e0f2fe; color: #0369a1; }
    .nav-pills .nav-link.active { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    
    .row-highlight-pulse {
        animation: pulse-bg 2s infinite;
        background-color: #fff9db !important;
    }
    @keyframes pulse-bg {
        0% { background-color: #fff9db; }
        50% { background-color: #fff2b2; }
        100% { background-color: #fff9db; }
    }
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="m-0 font-weight-bold" style="letter-spacing:-1px;"><i class="fas fa-users-cog mr-3 text-primary"></i><?= __("user_list") ?></h1>
            <div class="d-flex align-items-center">
                <button type="button" class="btn btn-outline-danger btn-lg shadow-sm mr-2" data-toggle="modal" data-target="#userPdfModal" style="border-radius:12px; font-weight:700; font-size:15px;">
                    <i class="fas fa-file-pdf mr-2"></i><?= $isTr ? 'PDF / Yazdır' : 'PDF / Print' ?>
                </button>
                <a href="kullanici-ekle" class="btn btn-primary btn-lg shadow-sm" style="border-radius:12px; font-weight:700; font-size:15px;">
                    <i class="fas fa-plus-circle mr-2"></i><?= __("add_new_user") ?>
                </a>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">
        <?php if ($mesaj || isset($_SESSION['mesaj'])): ?>
            <div class="alert alert-success shadow-sm border-0 mb-4" style="border-radius:12px; border-left: 5px solid #28a745 !important;">
                <i class="fas fa-check-circle mr-2 text-success"></i><?= $mesaj ?: $_SESSION['mesaj']; unset($_SESSION['mesaj']); ?>
            </div>
        <?php endif; ?>
        <?php if ($hata): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-4" style="border-radius:12px; border-left: 5px solid #dc3545 !important;">
                <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($hata) ?>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0" style="border-radius:20px; overflow:hidden;">
            <div class="card-header bg-white border-bottom-0 pt-3 px-4">
                <ul class="nav nav-pills gap-2">
                    <li class="nav-item">
                        <a class="nav-link <?= !$view_deleted ? 'active' : 'bg-light' ?> font-weight-bold px-4 py-2" href="<?= $base_url ?>kullanici-listele" style="border-radius:12px;">
                            <i class="fas fa-user-check mr-2"></i><?= $isTr ? 'Aktif Üyeler' : 'Active Members' ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $view_deleted ? 'active bg-danger' : 'bg-light' ?> font-weight-bold px-4 py-2" href="<?= $base_url ?>kullanici-listele/deleted" style="border-radius:12px;">
                            <i class="fas fa-trash-alt mr-2 text-<?= $view_deleted ? 'white' : 'danger' ?>"></i><?= $isTr ? 'Çöp Kutusu (Silinenler)' : 'Trash Can' ?>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="card-body p-0 mt-2">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-uppercase text-muted" style="font-size:11px; letter-spacing:1px; border-top:1px solid #f1f5f9;">
                            <tr>
                                <th class="pl-4 py-3"><?= __("photo") ?></th>
                                <th class="py-3"><?= __("full_name") ?></th>
                                <th class="py-3"><?= $isTr ? 'Departman' : 'Dept' ?></th>
                                <th class="py-3 text-center"><?= $isTr ? 'Envanter' : 'Items' ?></th>
                                <th class="py-3"><?= __("teams") ?></th>
                                <th class="py-3"><?= __("role") ?></th>
                                <th class="py-3"><?= __("status") ?></th>
                                <th class="py-3 text-right pr-4"><?= __("action") ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted" style="background:#fcfdfe;">
                                        <div class="py-5">
                                            <i class="fas <?= $view_deleted ? 'fa-trash-restore' : 'fa-users' ?> fa-4x mb-3 opacity-20"></i><br>
                                            <h5 class="font-weight-bold"><?= $view_deleted ? ($isTr ? 'Çöp kutusunda hiç kayıt yok.' : 'Trash can is empty.') : __("no_users_found") ?></h5>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                    <tr id="user-<?= $u['id'] ?>" class="<?= (($_GET['highlight_id'] ?? 0) == $u['id']) ? 'row-highlight-pulse' : '' ?>">
                                        <td class="pl-4 py-3">
                                             <?php 
                                             $pImg = $u['profil_fotosu'] ?: 'default.png'; 
                                             $_pLogoRaw = s('logo_path') ?: 'logo.png';
                                             $_pLogoUrl = (str_starts_with($_pLogoRaw, 'public/') ? $_pLogoRaw : 'public/' . $_pLogoRaw);
                                             if ($pImg === 'default.png' || empty($pImg)) {
                                                 $imgSrc = $_pLogoUrl;
                                             } elseif (strpos($pImg, 'http') === 0) {
                                                 $imgSrc = $pImg;
                                             } elseif (strpos($pImg, 'dist/img/avatars/') !== false) {
                                                 $imgSrc = preg_replace('/^.*(dist\/img\/avatars\/[a-zA-Z0-9_\-\.]+)/', '$1', $pImg);
                                             } else {
                                                 $imgSrc = 'uploads/profil/' . $pImg;
                                             }
                                             ?>
                                             <img src="<?= $imgSrc ?>" class="rounded-circle shadow-sm" 
                                                 style="width:42px; height:42px; object-fit:cover; object-position:center; border:2px solid #fff; background:#f8fafc;"
                                                 onerror="this.onerror=null; this.src='<?= $_pLogoUrl ?>';">
                                        </td>
                                        <td class="py-3">
                                            <div class="font-weight-bold text-dark" style="font-size:14px;"><?= htmlspecialchars($u['fullname']) ?></div>
                                            <div class="text-xs text-muted">@<?= htmlspecialchars($u['username']) ?></div>
                                        </td>
                                         <td class="py-3 text-xs font-weight-bold">
                                             <?php if (!empty($u['bolum_id'])): ?>
                                                 <a href="varliklar?view=predefined&type=departments&highlight_id=<?= $u['bolum_id'] ?>" class="text-info font-weight-bold" title="<?= $isTr ? 'Bölüm Tanımına Git' : 'View Department' ?>">
                                                     <?= htmlspecialchars($u['bolum_adi']) ?>
                                                 </a>
                                             <?php else: ?>
                                                 <span class="text-muted">-</span>
                                             <?php endif; ?>
                                         </td>
                                        <td class="py-3 text-center">
                                            <div class="d-flex justify-content-center gap-1">
                                                <span class="badge badge-soft-primary px-2" title="Demirbaş"><i class="fas fa-laptop mr-1"></i> <?= $u['asset_count'] ?></span>
                                                <span class="badge badge-soft-success px-2" title="Lisans"><i class="fas fa-key mr-1"></i> <?= $u['license_count'] ?></span>
                                            </div>
                                        </td>
                                        <td class="py-3"><span class="badge bg-light text-dark font-weight-bold border" style="font-size:10px; border-radius:6px;"><?= htmlspecialchars($u['team_names'] ?: '—') ?></span></td>
                                        <td class="py-3">
                                            <?php if ($u['role'] == 1): ?><span class="badge badge-danger"><?= __("role_admin") ?></span>
                                            <?php elseif ($u['role'] == 3): ?><span class="badge badge-warning text-dark"><?= __("role_hr") ?></span>
                                            <?php else: ?><span class="badge badge-secondary"><?= __("role_staff") ?></span><?php endif; ?>
                                            <?php if (!empty($u['custom_role_name'])): ?>
                                                <br><span class="badge badge-info mt-1" style="font-size:10px;"><i class="fas fa-user-shield mr-1"></i><?= htmlspecialchars($u['custom_role_name']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3">
                                            <?php 
                                            if ($u['deleted_at']): ?>
                                                <span class="badge bg-dark px-2 py-1"><i class="fas fa-calendar-times mr-1"></i><?= date('d.m.Y', strtotime($u['deleted_at'])) ?></span>
                                            <?php else:
                                                $can_toggle = ($u['id'] != $current_user_id && ($current_user_role == 1 || $current_user_role == 3));
                                                if (isset($u['status']) && $u['status'] == 0): ?>
                                                    <span class="badge badge-soft-danger px-2 py-1 <?= $can_toggle ? 'cursor-pointer' : '' ?>" onclick="<?= $can_toggle ? "toggleUserStatus({$u['id']}, 1)" : "" ?>"><?= __("passive") ?></span>
                                                <?php else: ?>
                                                    <?php if (empty($u['password'])): ?>
                                                        <span class="badge badge-soft-info px-2 py-1 <?= $can_toggle ? 'cursor-pointer' : '' ?>" onclick="<?= $can_toggle ? "toggleUserStatus({$u['id']}, 0)" : "" ?>"><i class="fas fa-paper-plane mr-1"></i><?= __("pending") ?></span>
                                                    <?php else: ?>
                                                        <span class="badge badge-soft-success px-2 py-1 <?= $can_toggle ? 'cursor-pointer' : '' ?>" onclick="<?= $can_toggle ? "toggleUserStatus({$u['id']}, 0)" : "" ?>"><?= __("active") ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 text-right pr-4">
                                            <?php if ($view_deleted): ?>
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-success" style="border-radius:6px; margin-right:4px;"
                                                        onclick="confirmRestoreUser(<?= $u['id'] ?>, '<?= addslashes(htmlspecialchars($u['fullname'])) ?>')" title="<?= $isTr ? 'Geri Yükle' : 'Restore' ?>">
                                                        <i class="fas fa-trash-restore"></i>
                                                    </button>
                                                    <?php if ($u['id'] != $current_user_id): ?>
                                                    <button type="button" class="btn btn-sm btn-danger" style="border-radius:6px;"
                                                        onclick="confirmDeleteUser(<?= $u['id'] ?>, '<?= addslashes(htmlspecialchars($u['fullname'])) ?>', true, <?= (int)$u['asset_count'] ?>, <?= (int)$u['license_count'] ?>, <?= (int)$u['accessory_count'] ?>, <?= (int)$u['consumable_count'] ?>)" title="<?= $isTr ? 'Kalıcı Olarak Sil' : 'Permanently Delete' ?>">
                                                        <i class="fas fa-bomb"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="btn-group">
                                                    <a href="kullanici-ekle?copy_from=<?= $u['id'] ?>" class="btn btn-sm btn-info" style="border-radius:6px; margin-right:4px;" title="<?= $isTr ? 'Kullanıcıyı Kopyala' : 'Copy User' ?>">
                                                        <i class="fas fa-clone"></i>
                                                    </a>
                                                    <a href="kullanici-duzenle/<?= $u['id'] ?>" class="btn btn-sm btn-warning text-white" style="border-radius:6px; margin-right:4px;" title="<?= __("edit") ?>">
                                                        <i class="fas fa-pen"></i>
                                                    </a>
                                                    <?php if ($u['id'] != $current_user_id): ?>
                                                    <button type="button" class="btn btn-sm btn-danger" style="border-radius:6px;"
                                                        onclick="confirmDeleteUser(<?= $u['id'] ?>, '<?= addslashes(htmlspecialchars($u['fullname'])) ?>', false, <?= (int)$u['asset_count'] ?>, <?= (int)$u['license_count'] ?>, <?= (int)$u['accessory_count'] ?>, <?= (int)$u['consumable_count'] ?>)" title="<?= __("delete") ?>">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-3 px-4">
                <div class="text-muted small">
                    <?= sprintf($isTr ? 'Toplam %d kayıttan %d - %d arası gösteriliyor.' : 'Showing %d - %d of %d records.', 
                        $total_records, 
                        $total_records > 0 ? $offset + 1 : 0, 
                        min($offset + $limit, $total_records), 
                        $total_records) ?>
                </div>
                <?php if ($total_pages > 1): ?>
                    <nav>
                        <ul class="pagination pagination-sm mb-0" style="border-radius:8px; overflow:hidden;">
                             <?php $pagePrefix = $view_deleted ? $base_url . "kullanici-listele/deleted" : $base_url . "kullanici-listele"; ?>
                             <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                                 <a class="page-link" href="<?= $pagePrefix ?>?page=<?= $current_page - 1 ?>&limit=<?= $limit ?>">
                                     <i class="fas fa-chevron-left"></i>
                                 </a>
                             </li>
                             
                             <!-- Page Numbers -->
                             <?php 
                             $start_p = max(1, $current_page - 2);
                             $end_p = min($total_pages, $current_page + 2);
                             for ($p = $start_p; $p <= $end_p; $p++): ?>
                                 <li class="page-item <?= ($p == $current_page) ? 'active' : '' ?>">
                                     <a class="page-link" href="<?= $pagePrefix ?>?page=<?= $p ?>&limit=<?= $limit ?>"><?= $p ?></a>
                                 </li>
                             <?php endfor; ?>
                             
                             <!-- Next Page -->
                             <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                                 <a class="page-link" href="<?= $pagePrefix ?>?page=<?= $current_page + 1 ?>&limit=<?= $limit ?>">
                                     <i class="fas fa-chevron-right"></i>
                                 </a>
                             </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script>
    function confirmDeleteUser(userId, fullName, permanent = false, assetCount = 0, licenseCount = 0, accCount = 0, consCount = 0) {
        if (assetCount > 0 || licenseCount > 0 || accCount > 0 || consCount > 0) {
            let items = [];
            if (assetCount > 0) items.push(assetCount + ' <?= $isTr ? "Demirbaş" : "Assets" ?>');
            if (licenseCount > 0) items.push(licenseCount + ' <?= $isTr ? "Lisans" : "Licenses" ?>');
            if (accCount > 0) items.push(accCount + ' <?= $isTr ? "Aksesuar" : "Accessories" ?>');
            if (consCount > 0) items.push(consCount + ' <?= $isTr ? "Sarf Malzeme" : "Consumables" ?>');

            Swal.fire({
                title: '<?= $isTr ? "Silme Engellendi!" : "Deletion Blocked!" ?>',
                html: '<b>' + fullName + '</b> <?= $isTr ? "üzerinde aktif zimmetler bulunmaktadır:" : "has active assignments:" ?><br><br>' + 
                      '<div class="text-left bg-light p-3 rounded" style="font-size:14px;">' + items.join('<br>') + '</div><br>' +
                      '<?= $isTr ? "Kullanıcıyı silmeden önce tüm zimmetlerin iade alınması (check-in) gerekmektedir." : "All items must be checked in before deleting the user." ?>',
                icon: 'error',
                confirmButtonText: '<?= $isTr ? "Kullanıcı Detayına Git" : "Go to User Details" ?>',
                showCancelButton: true,
                cancelButtonText: '<?= __("close") ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'kullanici-detay/' + userId;
                }
            });
            return;
        }

        Swal.fire({
            title: '<?= __("are_you_sure") ?>',
            text: fullName + (permanent 
                ? " <?= $isTr ? 'isimli personeli KALICI olarak silmek üzeresiniz. Bu işlem geri alınamaz!' : 'will be permanently deleted. This cannot be undone!' ?>" 
                : " <?= $isTr ? 'isimli personeli çöp kutusuna taşımak istediğinize emin misiniz?' : 'will be moved to trash.' ?>"),
            icon: permanent ? 'error' : 'warning',
            showCancelButton: true,
            confirmButtonColor: permanent ? '#dc3545' : '#d33',
            confirmButtonText: permanent ? '<?= $isTr ? 'Evet, Kalıcı Sil' : 'Delete Permanently' ?>' : '<?= $isTr ? 'Evet, Çöpe At' : 'Move to Trash' ?>',
            cancelButtonText: '<?= __("cancel") ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'kullanici-listele?delete=' + userId + (permanent ? '&permanent=1' : '');
            }
        });
    }

    function confirmRestoreUser(userId, fullName) {
        Swal.fire({
            title: '<?= $isTr ? "Geri Yükle" : "Restore" ?>',
            text: fullName + " <?= $isTr ? 'isimli personeli geri yüklemek istiyor musunuz?' : 'Do you want to restore this user?' ?>",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            confirmButtonText: '<?= $isTr ? "Evet, Geri Yükle" : "Yes, Restore" ?>',
            cancelButtonText: '<?= __("cancel") ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'kullanici-listele?restore=' + userId;
            }
        });
    }

    function toggleUserStatus(uid, status) {
        let title = '';
        let text = '';
        const isTr = <?= json_encode($isTr) ?>;
        
        if (status === 1) {
            title = isTr ? 'Kullanıcıyı Aktifleştir' : 'Activate User';
            text = isTr ? 'Bu kullanıcının durumunu aktif olarak güncellemek istediğinize emin misiniz?' : 'Are you sure you want to set this user status to active?';
        } else {
            title = isTr ? 'Kullanıcıyı Pasifleştir' : 'Deactivate User';
            text = isTr ? 'Bu kullanıcının durumunu pasif olarak güncellemek istediğinize emin misiniz?' : 'Are you sure you want to set this user status to passive?';
        }

        Swal.fire({
            title: title,
            text: text,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3b82f6',
            confirmButtonText: isTr ? 'Evet, Güncelle' : 'Yes, Update',
            cancelButtonText: isTr ? 'İptal' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="${uid}"><input type="hidden" name="status" value="${status}"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">`;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    $(document).ready(function() {
        const urlParams = new URLSearchParams(window.location.search);
        const hid = urlParams.get('highlight_id');
        if (hid) {
            setTimeout(function() {
                const targetRow = $('#user-' + hid);
                if (targetRow.length) {
                    $('html, body').animate({
                        scrollTop: targetRow.offset().top - 200
                    }, 600);
                }
            }, 500);
        }
    });
</script>

<!-- User PDF Export Modal -->
<div class="modal fade" id="userPdfModal" tabindex="-1" role="dialog" aria-labelledby="userPdfModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0" style="border-radius:18px; overflow:hidden;">
            <div class="modal-header bg-danger text-white py-3 px-4">
                <h5 class="modal-title font-weight-bold" id="userPdfModalLabel">
                    <i class="fas fa-file-pdf mr-2"></i><?= $isTr ? 'Personel Listesi PDF / Yazdırma Ayarları' : 'Personnel List PDF / Print Settings' ?>
                </h5>
                <button type="button" class="close text-white opacity-80" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="export_users_pdf" method="GET" target="_blank" id="userPdfForm">
                <?php if ($view_deleted): ?>
                    <input type="hidden" name="view_deleted" value="1">
                <?php endif; ?>
                <input type="hidden" name="page" value="<?= $current_page ?>">
                <input type="hidden" name="limit" value="<?= $limit ?>">

                <div class="modal-body p-4 bg-light">
                    <!-- Orientation -->
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark mb-2" style="font-size:14px;">
                            <i class="fas fa-redo-alt mr-2 text-primary"></i><?= $isTr ? 'Sayfa Yönü (Orientation)' : 'Page Orientation' ?>
                        </label>
                        <div class="row">
                            <div class="col-6">
                                <div class="custom-control custom-radio p-3 bg-white rounded border shadow-sm h-100">
                                    <input type="radio" id="oriLandscape" name="orientation" value="landscape" class="custom-control-input" checked>
                                    <label class="custom-control-label font-weight-bold cursor-pointer text-dark w-100" for="oriLandscape" style="font-size:13px;">
                                        <i class="fas fa-arrows-alt-h text-danger mr-2"></i><?= $isTr ? 'Yatay (Landscape)' : 'Landscape' ?>
                                    </label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="custom-control custom-radio p-3 bg-white rounded border shadow-sm h-100">
                                    <input type="radio" id="oriPortrait" name="orientation" value="portrait" class="custom-control-input">
                                    <label class="custom-control-label font-weight-bold cursor-pointer text-dark w-100" for="oriPortrait" style="font-size:13px;">
                                        <i class="fas fa-arrows-alt-v text-info mr-2"></i><?= $isTr ? 'Dikey (Portrait)' : 'Portrait' ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paper Size -->
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark mb-2" style="font-size:14px;">
                            <i class="fas fa-ruler-combined mr-2 text-primary"></i><?= $isTr ? 'Kağıt Boyutu (Paper Size)' : 'Paper Size' ?>
                        </label>
                        <div class="row">
                            <div class="col-6">
                                <div class="custom-control custom-radio p-3 bg-white rounded border shadow-sm h-100">
                                    <input type="radio" id="paperA4" name="paper" value="a4" class="custom-control-input" checked>
                                    <label class="custom-control-label font-weight-bold cursor-pointer text-dark w-100" for="paperA4" style="font-size:13px;">
                                        <i class="fas fa-file text-success mr-2"></i>A4 (<?= $isTr ? 'Standart' : 'Standard' ?>)
                                    </label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="custom-control custom-radio p-3 bg-white rounded border shadow-sm h-100">
                                    <input type="radio" id="paperA3" name="paper" value="a3" class="custom-control-input">
                                    <label class="custom-control-label font-weight-bold cursor-pointer text-dark w-100" for="paperA3" style="font-size:13px;">
                                        <i class="fas fa-file-alt text-warning mr-2"></i>A3 (<?= $isTr ? 'Geniş' : 'Large' ?>)
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ek Seçenekler (TC & Scope) -->
                    <div class="bg-white p-3 rounded border shadow-sm">
                        <label class="font-weight-bold text-dark mb-3" style="font-size:13px;">
                            <i class="fas fa-sliders-h mr-2 text-primary"></i><?= $isTr ? 'Ek Özellikler ve Sütunlar' : 'Options & Columns' ?>
                        </label>
                        
                        <div class="custom-control custom-checkbox mb-3">
                            <input type="checkbox" class="custom-control-input" id="showTcCheck" name="show_tc" value="1" checked>
                            <label class="custom-control-label font-weight-bold cursor-pointer text-dark" for="showTcCheck" style="font-size:13px;">
                                <i class="fas fa-id-card text-primary mr-2"></i><?= $isTr ? 'TC Kimlik Numarası Gösterilsin' : 'Show TC Identity Numbers' ?>
                            </label>
                        </div>

                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="scopeAllCheck" name="scope" value="all" checked>
                            <label class="custom-control-label font-weight-bold cursor-pointer text-dark" for="scopeAllCheck" style="font-size:13px;">
                                <i class="fas fa-users text-success mr-2"></i><?= $isTr ? 'Tüm Kayıtları Dahil Et (Tek/Optimal Sayfaya Sığdır)' : 'Include All Records (Fit to Page)' ?>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top py-3 px-4 justify-content-between">
                    <button type="button" class="btn btn-light rounded-pill px-4 font-weight-bold text-secondary" data-dismiss="modal">
                        <?= $isTr ? 'İptal' : 'Cancel' ?>
                    </button>
                    <button type="submit" class="btn btn-danger rounded-pill px-5 font-weight-bold shadow-sm" onclick="$('#userPdfModal').modal('hide')">
                        <i class="fas fa-print mr-2"></i><?= $isTr ? 'PDF Oluştur ve Yazdır' : 'Generate & Print PDF' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>