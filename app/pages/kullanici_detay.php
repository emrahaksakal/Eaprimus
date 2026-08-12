<?php
// app/pages/kullanici_detay.php
require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/asset_helpers.php";
requireLogin();

$pdo = db();
$userId = intval($route_params[1] ?? ($_GET['id'] ?? 0));
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

if (!$userId) {
    header("Location: anasayfa");
    exit;
}

// Fetch User Info
$stmt = $pdo->prepare("SELECT u.*, CAST(AES_DECRYPT(UNHEX(u.tc_no), '" . EAPRIMUS_KEY . "') AS CHAR) as aes_tc, b.bolum_adi as dept_name 
                      FROM users u 
                      LEFT JOIN bolumler b ON u.bolum = b.id 
                      WHERE u.id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    echo "Kullanıcı bulunamadı.";
    return;
}

if ($user && !empty($user['deleted_at'])) {
    header("Location: " . $base_url . "kullanici-listele/deleted?highlight_id=" . $user['id']);
    exit;
}

// Fetch Assigned Assets
$stmtAssets = $pdo->prepare("SELECT a.*, ac.name as category_name, m.image as model_image FROM assets a LEFT JOIN asset_categories ac ON a.category_id = ac.id LEFT JOIN asset_models m ON a.model_id = m.id WHERE a.assigned_user_id = ? AND a.deleted_at IS NULL");
$stmtAssets->execute([$userId]);
$assigned_assets = $stmtAssets->fetchAll();

// Fetch Assigned Accessories (with totals)
$stmtAcc = $pdo->prepare("SELECT a.id, a.name, a.image, SUM(ck.quantity) as total_qty FROM asset_accessory_checkouts ck JOIN asset_accessories a ON ck.accessory_id = a.id WHERE ck.user_id = ? GROUP BY a.id HAVING total_qty > 0");
$stmtAcc->execute([$userId]);
$assigned_accessories = $stmtAcc->fetchAll();

// Fetch Consumed Consumables (with balance)
$stmtCons = $pdo->prepare("SELECT c.id, c.name, c.image, SUM(CASE WHEN ck.transaction_type = 'checkin' THEN -ck.quantity ELSE ck.quantity END) as total_qty 
                         FROM asset_consumable_checkouts ck 
                         JOIN asset_consumables c ON ck.consumable_id = c.id 
                         WHERE ck.user_id = ? AND ck.transaction_type IN ('consume', 'checkin')
                         GROUP BY c.id 
                         HAVING total_qty > 0");
$stmtCons->execute([$userId]);
$consumed_consumables = $stmtCons->fetchAll();

// Fetch Assigned Components
$stmtComp = $pdo->prepare("SELECT c.id, c.name, c.image, SUM(ck.quantity) as total_qty FROM asset_component_checkouts ck JOIN asset_components c ON ck.component_id = c.id WHERE ck.user_id = ? GROUP BY c.id HAVING total_qty > 0");
$stmtComp->execute([$userId]);
$assigned_components = $stmtComp->fetchAll();

$foto_raw = $user['profil_fotosu'] ?? '';
// Varsayılan avatar: şirket logosu
$_logo_raw = !empty($allSettings['logo_path']) ? $allSettings['logo_path'] : 'logo.png';
$foto_fallback = $base_url . (str_starts_with($_logo_raw, 'public/') ? $_logo_raw : 'public/' . $_logo_raw);
if (empty($foto_raw) || $foto_raw === 'default.png') {
    $foto_src = $foto_fallback;
} elseif (filter_var($foto_raw, FILTER_VALIDATE_URL)) {
    $foto_src = $foto_raw;
} elseif (strpos($foto_raw, 'dist/img/avatars/') !== false) {
    $foto_src = preg_replace('/^.*(dist\/img\/avatars\/[a-zA-Z0-9_\-\.]+)/', '$1', $foto_raw);
} else {
    $foto_src = "uploads/profil/" . $foto_raw;
}

$role_labels = [
    1 => ['label' => t('role_admin'), 'color' => 'danger'],
    2 => ['label' => t('role_staff'), 'color' => 'secondary'],
    3 => ['label' => t('role_hr'), 'color' => 'warning'],
];
$r_info = $role_labels[$user['role']] ?? ['label' => 'User', 'color' => 'dark'];

// Mask TC to **** based on permission
$is_owner_or_admin = (isset($_SESSION['role']) && $_SESSION['role'] == 1) || (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user['id']);
$tc_masked = $is_owner_or_admin ? htmlspecialchars($user['aes_tc'] ?: $user['tc_no']) : "***********";

// Bulk Log Deletion for Admin
if (isset($_POST['action']) && $_POST['action'] == 'bulk_delete_logs' && $_SESSION['role'] == 1) {
    if (!empty($_POST['selected_logs'])) {
        $logIds = array_map('intval', $_POST['selected_logs']);
        $placeholders = implode(',', array_fill(0, count($logIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM asset_timeline WHERE id IN ($placeholders)");
        $stmt->execute($logIds);
        $_SESSION['mesaj'] = $isTr ? "Seçilen kayıtlar silindi." : "Selected logs deleted.";
        header("Location: " . $base_url . "kullanici-detay/" . $userId);
        exit;
    }
}
?>

<div class="mb-4 d-flex justify-content-between align-items-center">
    <div>
        <h4 class="font-weight-bold mb-0 text-dark"><i class="fas fa-id-card mr-2 text-primary"></i> <?= __("Personel Detayı") ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-transparent p-0 mb-0 small">
                <li class="breadcrumb-item"><a href="anasayfa"><?= __("anasayfa") ?></a></li>
                <li class="breadcrumb-item"><a href="kullanici-listele"><?= __("user-list") ?></a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($user['fullname']) ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="kullanici-duzenle/<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary shadow-sm mr-2" style="border-radius:10px;">
            <i class="fas fa-edit mr-1"></i> <?= __("edit") ?>
        </a>
        <div class="badge badge-<?= $user['status'] ? 'success' : 'danger' ?> px-3 py-2 shadow-sm" style="border-radius:10px; font-size:12px;">
            <i class="fas fa-circle mr-1" style="font-size:8px;"></i> <?= $user['status'] ? t('active') : t('passive') ?>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 mb-4 overflow-hidden" style="border-radius:20px; border-top: 4px solid var(--primary-color) !important;">
            <div class="card-body text-center p-4">
                <div class="position-relative d-inline-block mb-3">
                    <img src="<?= $foto_src ?>" class="rounded-circle border-0 shadow" style="width:140px; height:140px; object-fit:cover; object-position:center; border: 4px solid #fff !important;" alt="User"
                        onerror="this.onerror=null; this.src='<?= $foto_fallback ?>';">
                    <span class="position-absolute border border-white rounded-circle bg-<?= $user['status'] ? 'success' : 'danger' ?>" style="width:24px; height:24px; bottom:10px; right:10px; border-width:3px !important;"></span>
                </div>
                <h3 class="font-weight-bold mb-1 text-dark"><?= htmlspecialchars($user['fullname']) ?></h3>
                <p class="text-primary font-weight-600 mb-3 small" style="letter-spacing:1px;"><?= strtoupper($r_info['label']) ?></p>
                
                <hr class="my-4 opacity-50">
                
                <div class="text-left bg-light-soft p-3 rounded-lg" style="background-color: rgba(0,0,0,0.02); border-radius:15px;">
                    <div class="d-flex mb-3 align-items-center">
                        <div class="bg-white rounded p-2 mr-3 shadow-xs text-primary" style="width:36px; height:36px; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-building"></i>
                        </div>
                        <div>
                            <label class="text-muted mb-0 x-small-text font-weight-bold"><?= t('department') ?></label>
                            <div class="text-dark font-weight-bold" style="font-size:14px;"><?= htmlspecialchars($user['dept_name'] ?: '-') ?></div>
                        </div>
                    </div>
                    <div class="d-flex mb-3 align-items-center">
                        <div class="bg-white rounded p-2 mr-3 shadow-xs text-primary" style="width:36px; height:36px; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div>
                            <label class="text-muted mb-0 x-small-text font-weight-bold">E-Posta</label>
                            <div class="text-dark font-weight-bold" style="font-size:14px;"><?= htmlspecialchars($user['mail']) ?></div>
                        </div>
                    </div>
                    <div class="d-flex mb-3 align-items-center">
                        <div class="bg-white rounded p-2 mr-3 shadow-xs text-primary" style="width:36px; height:36px; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <label class="text-muted mb-0 x-small-text font-weight-bold"><?= t('username') ?></label>
                            <div class="text-dark font-weight-bold" style="font-size:14px;"><?= htmlspecialchars($user['username']) ?></div>
                        </div>
                    </div>
                    <div class="d-flex mb-0 align-items-center">
                        <div class="bg-white rounded p-2 mr-3 shadow-xs text-primary" style="width:36px; height:36px; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-fingerprint"></i>
                        </div>
                        <div>
                            <label class="text-muted mb-0 x-small-text font-weight-bold"><?= t('tc_no') ?></label>
                            <div class="text-dark font-weight-bold" style="font-size:14px;">
                                <span class="badge badge-light border text-monospace" style="letter-spacing:1px; background:#fff;"><?= $tc_masked ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card shadow-sm border-0 mb-4 overflow-hidden" style="border-radius:20px;">
            <div class="card-header bg-white border-bottom-0 py-3 d-flex justify-content-between align-items-center mt-2">
                <h5 class="mb-0 font-weight-bold text-dark ml-2">
                    <i class="fas fa-laptop mr-2 text-primary opacity-50"></i> <?= t('assigned_assets') ?? 'Zimmetli Varlıklar' ?>
                </h5>
                <span class="badge badge-pill badge-primary px-3 shadow-none mr-2"><?= count($assigned_assets) ?> Ürün</span>
            </div>
            <div class="table-responsive p-2" style="max-height: 350px; overflow-y: auto;">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="bg-light-soft" style="background-color: rgba(0,0,0,0.015);">
                        <tr>
                            <th class="border-0 font-weight-bold text-muted text-xs px-4" style="text-transform:uppercase;"><?= t('asset_name') ?></th>
                            <th class="border-0 font-weight-bold text-muted text-xs" style="text-transform:uppercase;"><?= t('category') ?></th>
                            <th class="border-0 font-weight-bold text-muted text-xs" style="text-transform:uppercase;"><?= t('serial_no') ?></th>
                            <th class="border-0 font-weight-bold text-muted text-xs text-right pr-4" style="text-transform:uppercase;"><?= t('action') ?></th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if(empty($assigned_assets)): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">
                                <i class="fas fa-box-open fa-3x mb-3 opacity-10"></i><br>
                                <span class="font-weight-500 text-muted"><?= t('no_assigned_assets') ?? 'Zimmetinde varlık bulunmuyor.' ?></span>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach($assigned_assets as $as): 
                                $thumbUrl = '';
                                if(!empty($as['image']) && strpos($as['image'], 'assets-') === 0) {
                                    $thumbUrl = 'public/uploads/assets/' . $as['image'];
                                } else if(!empty($as['image'])) {
                                    $thumbUrl = (strpos($as['image'], 'public/') === 0 ? '' : 'public/uploads/') . $as['image'];
                                } else if(!empty($as['model_image'])) {
                                    $thumbUrl = 'public/uploads/models/' . $as['model_image'];
                                }
                            ?>
                            <tr>
                                <td class="px-4">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-light rounded mr-3 text-primary shadow-xs" style="width:40px; height:40px; display:flex; align-items:center; justify-content:center; background:#f8fafc !important; overflow:hidden;">
                                            <?php if($thumbUrl): ?>
                                                <img src="<?= $base_url . $thumbUrl ?>" style="width:100%; height:100%; object-fit:contain;" onerror="this.outerHTML='<i class=\'fas fa-laptop\' style=\'opacity:0.7\'></i>'">
                                            <?php else: ?>
                                                <i class="fas fa-laptop" style="opacity:0.7"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="font-weight-bold">
                                            <a href="varlik-detay/<?= $as['id'] ?>" class="text-dark hover-primary text-decoration-none"><?= htmlspecialchars($as['name']) ?></a>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge badge-soft-info px-2 py-1 text-xs" style="font-weight:600;"><?= htmlspecialchars($as['category_name'] ?? '-') ?></span></td>
                                <td><code class="text-xs text-muted font-weight-bold" style="background:#f1f5f9; padding:2px 6px; border-radius:4px;"><?= htmlspecialchars($as['serial_no'] ?: '-') ?></code></td>
                                <td class="px-4 text-right">
                                    <a href="varlik-detay/<?= $as['id'] ?>" class="btn btn-xs btn-outline-info px-2 py-1" style="border-radius:6px; font-size:11px;">
                                        <i class="fas fa-eye mr-1"></i> <?= $isTr ? 'Görüntüle' : 'View' ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4 overflow-hidden" style="border-radius:20px;">
            <div class="card-header bg-white border-bottom-0 py-3 d-flex justify-content-between align-items-center mt-2">
                <h5 class="mb-0 font-weight-bold text-dark ml-2">
                    <i class="fas fa-plug mr-2 text-warning opacity-50"></i> <?= $isTr ? 'Aksesuarlar, Bileşenler & Sarf Malzemeler' : 'Accessories, Components & Consumables' ?>
                </h5>
                <span class="badge badge-pill badge-warning px-3 shadow-none mr-2"><?= count($assigned_accessories) + count($consumed_consumables) + count($assigned_components) ?> <?= $isTr?'Kalem':'Items' ?></span>
            </div>
            <div class="table-responsive p-2" style="max-height: 350px; overflow-y: auto;">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="bg-light-soft" style="background-color: rgba(0,0,0,0.015);">
                        <tr>
                            <th class="border-0 font-weight-bold text-muted text-xs px-4" style="text-transform:uppercase;"><?= t('item_name') ?? 'Öğe Adı' ?></th>
                            <th class="border-0 font-weight-bold text-muted text-xs" style="text-transform:uppercase;"><?= $isTr?'Tür':'Type' ?></th>
                            <th class="border-0 font-weight-bold text-muted text-xs text-center" style="text-transform:uppercase;"><?= $isTr?'Adet':'Qty' ?></th>
                            <th class="border-0 font-weight-bold text-muted text-xs text-right pr-4" style="text-transform:uppercase;"><?= t('action') ?></th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if(empty($assigned_accessories) && empty($consumed_consumables) && empty($assigned_components)): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">
                                <i class="fas fa-keyboard fa-3x mb-3 opacity-10"></i><br>
                                <span class="font-weight-500 text-muted"><?= $isTr ? 'Kullanılan malzeme veya donanım bulunmuyor.' : 'No accessories, components, or consumables assigned.' ?></span>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach($assigned_accessories as $ac): 
                                $thumbUrl = !empty($ac['image']) ? 'public/uploads/accessories/' . $ac['image'] : '';
                            ?>
                            <tr>
                                <td class="px-4">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-light rounded mr-3 text-warning shadow-xs" style="width:40px; height:40px; background:#fff !important; border:1px solid #f1f5f9; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                            <?php if($thumbUrl): ?>
                                                <img src="<?= $base_url . $thumbUrl ?>" style="width:100%; height:100%; object-fit:contain;" onerror="this.outerHTML='<i class=\'fas fa-keyboard\' style=\'opacity:0.7\'></i>'">
                                            <?php else: ?>
                                                <i class="fas fa-keyboard" style="opacity:0.7"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="font-weight-bold">
                                            <a href="varlik-detay/<?= $ac['id'] ?>?view=accessories" class="text-dark hover-primary text-decoration-none"><?= htmlspecialchars($ac['name']) ?></a>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge badge-soft-warning px-2 py-1 text-xs" style="font-weight:600;"><?= $isTr?'Aksesuar':'Accessory' ?></span></td>
                                <td class="text-center font-weight-bold text-dark"><?= $ac['total_qty'] ?></td>
                                <td class="px-4 text-right">
                                    <a href="varlik-detay/<?= $ac['id'] ?>?view=accessories" class="btn btn-xs btn-outline-warning px-2 py-1" style="border-radius:6px; font-size:11px;">
                                        <i class="fas fa-eye mr-1"></i> <?= $isTr ? 'Detay' : 'View' ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php foreach($assigned_components as $co): 
                                $thumbUrl = !empty($co['image']) ? 'public/uploads/components/' . $co['image'] : '';
                            ?>
                            <tr>
                                <td class="px-4">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-light rounded mr-3 text-secondary shadow-xs" style="width:40px; height:40px; background:#fff !important; border:1px solid #f1f5f9; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                            <?php if($thumbUrl): ?>
                                                <img src="<?= $base_url . $thumbUrl ?>" style="width:100%; height:100%; object-fit:contain;" onerror="this.outerHTML='<i class=\'fas fa-microchip\' style=\'opacity:0.7\'></i>'">
                                            <?php else: ?>
                                                <i class="fas fa-microchip" style="opacity:0.7"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="font-weight-bold">
                                            <a href="varlik-detay/<?= $co['id'] ?>?view=components" class="text-dark hover-primary text-decoration-none"><?= htmlspecialchars($co['name']) ?></a>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge badge-secondary px-2 py-1 text-xs" style="font-weight:600;"><?= $isTr?'Bileşen':'Component' ?></span></td>
                                <td class="text-center font-weight-bold text-dark"><?= $co['total_qty'] ?></td>
                                <td class="px-4 text-right">
                                    <a href="varlik-detay/<?= $co['id'] ?>?view=components" class="btn btn-xs btn-outline-secondary px-2 py-1" style="border-radius:6px; font-size:11px;">
                                        <i class="fas fa-eye mr-1"></i> <?= $isTr ? 'Detay' : 'View' ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php foreach($consumed_consumables as $cc): 
                                $thumbUrl = !empty($cc['image']) ? 'public/uploads/consumables/' . $cc['image'] : '';
                            ?>
                            <tr>
                                <td class="px-4">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-light rounded mr-3 text-info shadow-xs" style="width:40px; height:40px; background:#fff !important; border:1px solid #f1f5f9; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                            <?php if($thumbUrl): ?>
                                                <img src="<?= $base_url . $thumbUrl ?>" style="width:100%; height:100%; object-fit:contain;" onerror="this.outerHTML='<i class=\'fas fa-tint\' style=\'opacity:0.7\'></i>'">
                                            <?php else: ?>
                                                <i class="fas fa-tint" style="opacity:0.7"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="font-weight-bold">
                                            <a href="varlik-detay/<?= $cc['id'] ?>?view=consumables" class="text-dark hover-primary text-decoration-none"><?= htmlspecialchars($cc['name']) ?></a>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge badge-soft-info px-2 py-1 text-xs" style="font-weight:600;"><?= $isTr?'Sarf Malzeme':'Consumable' ?></span></td>
                                <td class="text-center font-weight-bold text-dark"><?= $cc['total_qty'] ?></td>
                                <td class="px-4 text-right">
                                    <a href="varlik-detay/<?= $cc['id'] ?>?view=consumables" class="btn btn-xs btn-outline-info px-2 py-1" style="border-radius:6px; font-size:11px;">
                                        <i class="fas fa-eye mr-1"></i> <?= $isTr ? 'Detay' : 'View' ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm border-0 overflow-hidden" style="border-radius:20px;">
            <div class="card-header bg-white border-bottom-0 py-3 mt-2 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 font-weight-bold text-dark ml-2">
                    <i class="fas fa-history mr-2 text-warning opacity-50"></i> <?= t('asset_history') ?? 'İşlem Geçmişi' ?>
                </h5>
            </div>
            <div class="card-body p-0">
                <form id="bulkDeleteForm" method="POST">
                <input type="hidden" name="action" value="bulk_delete_logs">
                <div class="table-responsive p-0" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="bg-light-soft" style="background-color: rgba(0,0,0,0.015);">
                            <tr>
                                <?php if($_SESSION['role'] == 1): ?>
                                <th class="border-0 px-4" style="width:40px;">
                                    <input type="checkbox" id="selectAllLogs" onclick="toggleAllLogs(this)">
                                </th>
                                <?php endif; ?>
                                <th class="border-0 font-weight-bold text-muted text-xs <?= $_SESSION['role'] != 1 ? 'px-4' : '' ?>" style="text-transform:uppercase;"><?= t('date') ?></th>
                                <th class="border-0 font-weight-bold text-muted text-xs" style="text-transform:uppercase;"><?= t('asset') ?></th>
                                <th class="border-0 font-weight-bold text-muted text-xs" style="text-transform:uppercase;"><?= t('action') ?></th>
                                <th class="border-0 font-weight-bold text-muted text-xs" style="text-transform:uppercase;"><?= t('performer') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $user_logs = getUserTimeline($pdo, $userId);
                            
                            if(empty($user_logs)): ?>
                                <tr><td colspan="<?= $_SESSION['role'] == 1 ? '5' : '4' ?>" class="text-center py-5 text-muted small">
                                    <i class="fas fa-stream fa-2x mb-2 opacity-10"></i><br>
                                    Henüz bir hareket kaydı bulunmuyor.
                                </td></tr>
                            <?php else: ?>
                                <?php foreach($user_logs as $log): ?>
                                    <?php
                                        // Ensure accessory item names are always shown.
                                        $itemName = $log['item_name'] ?? '';
                                        $itemLink = null;
                                        if (empty($itemName) && ($log['item_type'] ?? '') === 'accessory' && !empty($log['asset_id'])) {
                                            try {
                                                $stmtAcc = $pdo->prepare("SELECT name FROM asset_accessories WHERE id = ? LIMIT 1");
                                                $stmtAcc->execute([(int)$log['asset_id']]);
                                                $accRow = $stmtAcc->fetch();
                                                if ($accRow && !empty($accRow['name'])) {
                                                    $itemName = $accRow['name'];
                                                }
                                            } catch (Exception $e) {
                                                // ignore DB errors and fall back to existing value
                                            }
                                        }
                                        if (!empty($itemName)) {
                                            // Build link for accessory -> details with view=accessories
                                            if (($log['item_type'] ?? '') === 'accessory') {
                                                $itemLink = "varlik-detay/" . intval($log['asset_id']) . "?view=accessories";
                                            } else {
                                                $itemLink = "varlik-detay/" . intval($log['asset_id']);
                                            }
                                        }
                                    ?>
                                <tr class="timeline-row">
                                    <?php if($_SESSION['role'] == 1): ?>
                                    <td class="px-4">
                                        <input type="checkbox" name="selected_logs[]" value="<?= $log['id'] ?>" class="log-checkbox" onclick="updateSelectedCount()">
                                    </td>
                                    <?php endif; ?>
                                    <td class="<?= $_SESSION['role'] != 1 ? 'px-4' : '' ?> small font-weight-bold text-muted" style="white-space:nowrap;">
                                        <div class="d-flex align-items-center">
                                            <i class="far fa-clock mr-2 opacity-50"></i>
                                            <?= date('d.m.Y H:i', strtotime($log['created_at'])) ?>
                                        </div>
                                    </td>
                                    <td class="small font-weight-bold">
                                        <?php if($log['item_deleted']): ?>
                                            <div class="d-flex align-items-center">
                                                <span class="badge badge-soft-danger mr-2" style="font-size:10px;"><i class="fas fa-trash-alt"></i> Silinmiş</span>
                                                <span class="text-muted"><?= htmlspecialchars($log['item_name'] ?: 'N/A') ?></span>
                                            </div>
                                        <?php else: ?>
                                            <?php if ($itemLink): ?>
                                                <div class="text-dark font-weight-bold"><a href="<?= $itemLink ?>" class="text-dark hover-primary text-decoration-none"><?= htmlspecialchars($itemName) ?></a></div>
                                            <?php else: ?>
                                                <div class="text-dark hover-primary cursor-pointer" onclick="location.href='varlik-detay/<?= $log['asset_id'] ?>'">
                                                    <?= htmlspecialchars($log['item_name'] ?: 'N/A') ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="text-xs text-muted font-weight-normal"><?= htmlspecialchars($log['item_type']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <span class="badge badge-pill badge-light border x-small-text font-weight-bold px-2 py-1 mb-1" style="width:fit-content; background: #f8fafc; color:#334155;">
                                                <i class="fas fa-tag mr-1 opacity-50"></i> <?= __( (strpos($log['event_type'], 'timeline_') === 0) ? $log['event_type'] : "timeline_" . $log['event_type'] ) ?>
                                            </span>
                                            <div class="text-muted" style="font-size:11px; line-height:1.2;">
                                                <?php 
                                                    echo htmlspecialchars(translateLogDescription($log['event_description'], $isTr));
                                                ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="small text-muted font-weight-500">
                                        <div class="d-flex align-items-center">
                                            <div class="bg-gray-100 rounded-circle mr-2 d-flex align-items-center justify-content-center" style="width:24px; height:24px; background:#f1f5f9;">
                                                <i class="fas fa-user text-xs opacity-50"></i>
                                            </div>
                                            <?= htmlspecialchars($log['performer_name'] ?: 'System') ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if($_SESSION['role'] == 1 && !empty($user_logs)): ?>
                <div class="p-3 border-top bg-light-soft d-flex justify-content-between align-items-center">
                    <span class="text-xs text-muted font-weight-bold" id="selectedCount">0 <?= $isTr ? 'Log Seçildi' : 'Logs Selected' ?></span>
                    <button type="button" class="btn btn-sm btn-danger px-3 shadow-none" onclick="confirmBulkDelete()" style="border-radius:10px; font-weight:600;">
                        <i class="fas fa-trash-alt mr-1"></i> <?= $isTr ? 'Seçilenleri Sil' : 'Delete Selected' ?>
                    </button>
                </div>
                <?php endif; ?>
                </form>
        </div>
    </div>
</div>

<style>
.x-small-text { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
.shadow-xs { box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.bg-light-soft { background-color: rgba(0,0,0,0.02); }
.hover-primary:hover { color: var(--primary-color) !important; }
.btn-circle { border-radius: 50% !important; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
.badge-soft-info { background: #e0f2fe; color: #0369a1; }
.badge-soft-success { background: #dcfce7; color: #15803d; }
.badge-soft-danger { background: #fee2e2; color: #b91c1c; }
.timeline-row:hover { background-color: #f8fafc !important; }
.log-checkbox { width: 16px; height: 16px; cursor: pointer; }
</style>

<script>
function toggleAllLogs(source) {
    const checkboxes = document.querySelectorAll('.log-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.log-checkbox:checked').length;
    document.getElementById('selectedCount').innerText = `${count} <?= $isTr ? 'Log Seçildi' : 'Logs Selected' ?>`;
}

function confirmBulkDelete() {
    const count = document.querySelectorAll('.log-checkbox:checked').length;
    if (count === 0) {
        Swal.fire('Hata', '<?= $isTr ? 'Lütfen silinecek kayıtları seçin.' : 'Please select logs to delete.' ?>', 'warning');
        return;
    }
    
    Swal.fire({
        title: '<?= __("are_you_sure") ?>',
        text: `${count} <?= $isTr ? 'kayıt kalıcı olarak silinecek.' : 'logs will be permanently deleted.' ?>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonText: '<?= __("cancel") ?>',
        confirmButtonText: '<?= $isTr ? 'Evet, Sil' : 'Yes, Delete' ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('bulkDeleteForm').submit();
        }
    });
}
</script>
