<?php
// pages/varlik_detay.php
require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/asset_helpers.php";

$pdo = db();
$item_id = $route_params[1] ?? ($_GET['id'] ?? null);

// ===== KRÄ°TÄ°K: Bu sÃ¼tun olmadan sayfa Ã§alÄ±ÅŸmaz =====
try {
    $pdo->exec("ALTER TABLE asset_consumable_checkouts ADD COLUMN transaction_type VARCHAR(20) NOT NULL DEFAULT 'consume'");
} catch (Exception $e) { /* Zaten varsa hata ver, geÃ§ */
}
try {
    $pdo->exec("ALTER TABLE asset_timeline ADD COLUMN is_deleted TINYINT DEFAULT 0");
} catch (Exception $e) { /* Zaten varsa hata ver, geÃ§ */
}
// =====================================================


// SAFEGUARD: URL'deki /cihaz/izle/ kÄ±smÄ±ndan sonraki metni Ã§ek (SayÄ± deÄŸilse de Ã§alÄ±ÅŸÄ±r)
if (!$item_id && strpos($_SERVER['REQUEST_URI'], '/cihaz/izle/') !== false) {
    $uri_parts = explode('/cihaz/izle/', $_SERVER['REQUEST_URI']);
    if (isset($uri_parts[1])) {
        $item_id = urldecode(explode('?', $uri_parts[1])[0]);
        $item_id = rtrim($item_id, '/');
    }
}

$view = $_GET['view'] ?? 'assets';
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

// Resolve token to integer ID if necessary
$is_token_request = (strlen($item_id) === 16 && !is_numeric($item_id));
$db_id = $item_id;

if ($is_token_request && !empty($item_id)) {
    $SALT = "inventory_secure_2024_super_salt";
    if ($view === 'assets') {
        $stmtResolve = $pdo->prepare("SELECT id FROM assets WHERE SUBSTR(MD5(CONCAT(COALESCE(NULLIF(asset_tag, ''), CAST(id AS CHAR)), ?)), 1, 16) = ?");
        $stmtResolve->execute([$SALT, $item_id]);
        $resolvedAsset = $stmtResolve->fetch(PDO::FETCH_ASSOC);
        if ($resolvedAsset) {
            $db_id = $resolvedAsset['id'];
        }
    } else {
        $tableMeta = inventoryTableMeta($view);
        $table = $tableMeta['table'];
        $tagCol = tableHasColumn($pdo, $table, 'asset_tag') ? "asset_tag" : "id";
        $stmtResolve = $pdo->prepare("SELECT id FROM $table WHERE SUBSTR(MD5(CONCAT($tagCol, ?)), 1, 16) = ?");
        $stmtResolve->execute([$SALT, $item_id]);
        $resolvedAsset = $stmtResolve->fetch(PDO::FETCH_ASSOC);
        if ($resolvedAsset) {
            $db_id = $resolvedAsset['id'];
        }
    }
}

function _vdHasThumb($img, $type = 'assets', $fallbackIcon = 'fa-tag', $catImg = null)
{
    if (!$img && $catImg) {
        $img = 'categories/' . $catImg;
    }
    if (!$img)
        return '<div class="vd-asset-icon mr-2" style="width:38px; height:38px; background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; border-radius:6px; display:flex; align-items:center; justify-content:center; flex-shrink:0;"><i class="fas ' . $fallbackIcon . '"></i></div>';

    $path = 'public/';
    if (strpos($img, 'http') === 0)
        return '<div class="vd-asset-icon mr-3" style="width:38px; height:38px; background:white; border:1px solid #eef2f6; overflow:hidden; border-radius:6px; flex-shrink:0; display:flex; align-items:center; justify-content:center;"><img src="' . $img . '" style="width:100%; height:100%; object-fit:contain;"></div>';
    if (strpos($img, 'public/') === 0)
        $path = '';

    $fullImg = $img;
    if (strpos($img, 'uploads/') !== 0 && $path !== '' && strpos($img, 'categories/') !== 0) {
        // Auto-detect folder by prefix
        if (strpos($img, 'models-') === 0)
            $fullImg = 'uploads/models/' . $img;
        elseif (strpos($img, 'assets-') === 0)
            $fullImg = 'uploads/assets/' . $img;
        elseif (strpos($img, 'consumables-') === 0)
            $fullImg = 'uploads/consumables/' . $img;
        elseif (strpos($img, 'accessories-') === 0)
            $fullImg = 'uploads/accessories/' . $img;
        elseif (strpos($img, 'components-') === 0)
            $fullImg = 'uploads/assets/' . $img;
        elseif (strpos($img, 'licenses-') === 0)
            $fullImg = 'uploads/licenses/' . $img;
        else
            $fullImg = 'uploads/' . $type . '/' . $img;
    } elseif (strpos($img, 'categories/') === 0) {
        $fullImg = 'uploads/' . $img;
    }

    return '<div class="vd-asset-icon" style="width:38px; height:38px; background:white; border:1px solid #eef2f6; overflow:hidden; border-radius:6px; flex-shrink:0; margin-right:12px; display:flex; align-items:center; justify-content:center;">
                <img src="' . $path . $fullImg . '" style="width:100%; height:100%; object-fit:contain;" onerror="this.outerHTML=\'<i class=\\\'fas ' . $fallbackIcon . '\\\'></i>\'">
            </div>';
}

// Handle Log Deletions

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();

    // GÜVENLİK KONTROLÜ: POST işlemleri sadece admin ve yetkili teknik destek tarafından yapılabilir
    $role = $_SESSION['role'] ?? 3;
    if ($role != 1 && !hasPermission('varliklar_edit')) {
        echo json_encode(['success' => false, 'message' => $isTr ? 'Bu işlem için yetkiniz bulunmamaktadır.' : 'Unauthorized action.']);
        exit;
    }
    if ($_POST['action'] === 'restore_item') {
        $id = intval($_POST['item_id']);
        if ($id > 0) {
            $table = ($view === 'assets') ? 'assets' : "asset_" . $view;
            if (tableHasColumn($pdo, $table, 'deleted_at')) {
                $pdo->prepare("UPDATE $table SET deleted_at = NULL WHERE id = ?")->execute([$id]);
                if ($view === 'assets') {
                    $readyStatusId = 3; // Fallback
                    $stmtReady = $pdo->query("SELECT id FROM asset_status_labels WHERE type = 'deployable' AND is_default = 1 AND deleted_at IS NULL LIMIT 1");
                    $readyDbVal = $stmtReady->fetchColumn();
                    if ($readyDbVal) {
                        $readyStatusId = (int)$readyDbVal;
                    } else {
                        $stmtReady2 = $pdo->prepare("SELECT id FROM asset_status_labels WHERE type = 'deployable' AND name LIKE ? AND deleted_at IS NULL LIMIT 1");
                        $stmtReady2->execute(['%Hazır%']);
                        $readyDbVal2 = $stmtReady2->fetchColumn();
                        if ($readyDbVal2) {
                            $readyStatusId = (int)$readyDbVal2;
                        }
                    }
                    $pdo->prepare("UPDATE assets SET status_id = ? WHERE id = ?")->execute([$readyStatusId, $id]);
                }
                addAssetLog($pdo, $id, $current_user_id, 'restored', $isTr ? "Envanter çöp kutusundan geri yüklendi." : "Inventory item restored from trash.", null, substr($view, 0, -1) ?: $view);
                $_SESSION['mesaj'] = $isTr ? "Envanter başarıyla geri yüklendi." : "Inventory item restored successfully.";
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }
    if ($_POST['action'] === 'delete_item_permanent') {
        $id = intval($_POST['item_id']);
        if ($id > 0) {
            $table = ($view === 'assets') ? 'assets' : "asset_" . $view;
            $singular = substr($view, 0, -1) ?: $view;
            if (substr($view, -3) === 'ies') {
                $singular = substr($view, 0, -3) . 'y';
            }
            $checkoutTable = "asset_" . $singular . "_checkouts";
            if ($view === 'assets') {
                $checkoutTable = "asset_checkouts";
            }
            
            // Cleanup assignments if any
            if ($table === 'asset_licenses' || $table === 'asset_accessories' || $table === 'asset_consumables') {
                $pdo->prepare("DELETE FROM $checkoutTable WHERE " . $singular . "_id = ?")->execute([$id]);
            }
            
            // Delete record
            $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
            $_SESSION['mesaj'] = $isTr ? "Kayıt kalıcı olarak silindi." : "Record permanently deleted.";
        }
        echo json_encode(['success' => true]);
        exit;
    }
    if ($_POST['action'] === 'delete_log') {
        $log_id = (int) $_POST['log_id'];
        $pdo->prepare("DELETE FROM asset_timeline WHERE id = ?")->execute([$log_id]);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($_POST['action'] === 'delete_multiple_logs') {
        $ids = array_map('intval', explode(',', $_POST['ids']));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM asset_timeline WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($_POST['action'] === 'clear_logs') {
        $pdo->prepare("DELETE FROM asset_timeline WHERE asset_id = ? AND item_type = ?")->execute([$db_id, substr($view, 0, -1) ?: $view]);
        echo json_encode(['success' => true]);
        exit;
    }
}

$current_user_id = $_SESSION['user_id'] ?? 0;

if (empty($item_id)) {
    // MODERN EMPTY STATE VIEW
    ?>
    <div class="container d-flex align-items-center justify-content-center" style="min-height: 80vh;">
        <div class="text-center p-5 shadow-lg bg-white"
            style="border-radius: 24px; max-width: 500px; border: 1px solid rgba(0,0,0,0.05);">
            <div class="mb-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle"
                    style="width: 100px; height: 100px;">
                    <i class="fas fa-qrcode text-muted fa-3x" style="opacity: 0.5;"></i>
                </div>
            </div>
            <h2 class="font-weight-bold mb-3"><?= $isTr ? 'GiriÅŸ AnahtarÄ± Gerekli' : 'Access Token Required' ?></h2>
            <p class="text-muted mb-4">
                <?= $isTr ? 'LÃ¼tfen detaylarÄ±nÄ± gÃ¶rÃ¼ntÃ¼lemek istediÄŸiniz cihazÄ±n QR kodunu okutun.' : 'Please scan the QR code of the device you wish to view details for.' ?>
            </p>
            <?php if ($current_user_id > 0): ?>
                <a href="varliklar" class="btn btn-primary btn-lg rounded-pill px-5 shadow-sm">
                    <i class="fas fa-arrow-left mr-2"></i> <?= $isTr ? 'Listeye DÃ¶n' : 'Back to List' ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return;
}

$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

// Map views to tables
$tables = [
    'assets' => 'assets',
    'consumables' => 'asset_consumables',
    'accessories' => 'asset_accessories',
    'components' => 'asset_components',
    'licenses' => 'asset_licenses'
];

// 1. NORMALIZE VIEW & MAPPING
if ($view === 'asset')
    $view = 'assets';
if ($view === 'accessory')
    $view = 'accessories';
if ($view === 'consumable')
    $view = 'consumables';
if ($view === 'license')
    $view = 'licenses';
if ($view === 'component')
    $view = 'components';

$view_singular = rtrim($view, 's');
if (substr($view, -3) === 'ies')
    $view_singular = substr($view, 0, -3) . 'y';

$table = "asset_" . $view;
$ckTable = "asset_" . $view_singular . "_checkouts";
$itmCol = $view_singular . "_id";

if ($view === 'assets') {
    $table = "assets";
    $ckTable = "asset_checkouts";
} else if ($view === 'licenses') {
    $table = "asset_licenses";
} else if ($view === 'accessories') {
    $table = "asset_accessories";
} else if ($view === 'consumables') {
    $table = "asset_consumables";
} else if ($view === 'components') {
    $table = "asset_components";
}

// 2. FETCH PRIMARY DATA
try {
    $current_user_id = $_SESSION['user_id'] ?? 0;
    $SALT = "inventory_secure_2024_super_salt";
    $params = [];
    $asset = false;

    // Ã–N-TANIMLAMALAR (Kritik: Hata almamak iÃ§in boÅŸ dizi olarak baÅŸlatÄ±yoruz)
    $child_assets = [];
    $assigned_licenses = [];
    $assigned_accessories = [];
    $assigned_components = [];
    $usage_summary = [];

    // Is $item_id a token? (16 chars, hex-like)
    $is_token_request = (strlen($item_id) === 16 && !is_numeric($item_id));

    if ($view === 'assets') {
        if ($current_user_id == 0 || $is_token_request) {
            $sql = "SELECT a.*, m.name as model_name, m.image as model_image, c.name as category_name, c.image as category_image, comp.name as company_name, NULL as location_name, supp.name as supplier_name, u.fullname as assigned_user_name, u.profil_fotosu as assigned_user_avatar, u.mail as assigned_user_email, u.sirket_ismi as assigned_user_company, u.bolum as assigned_user_dept, pa.name as assigned_asset_name, pa.id as assigned_asset_id, pa.image as assigned_asset_image, pam.image as assigned_asset_model_image, b.bolum_adi as dept_name, sl.name as status_label_name, sl.color as status_label_color, man.name as manufacturer_name, SUBSTR(MD5(CONCAT(COALESCE(NULLIF(a.asset_tag, ''), CAST(a.id AS CHAR)), :salt_col)), 1, 16) as public_token 
                    FROM assets a 
                    LEFT JOIN asset_models m ON a.model_id = m.id 
                    LEFT JOIN asset_categories c ON a.category_id = c.id 
                    LEFT JOIN asset_companies comp ON a.company_id = comp.id 
                    
                    LEFT JOIN asset_suppliers supp ON a.supplier_id = supp.id 
                    LEFT JOIN users u ON a.assigned_user_id = u.id 
                    LEFT JOIN assets pa ON a.asset_id = pa.id 
                    LEFT JOIN asset_models pam ON pa.model_id = pam.id 
                    LEFT JOIN bolumler b ON a.department_id = b.id 
                    LEFT JOIN asset_status_labels sl ON a.status_id = sl.id 
                    LEFT JOIN asset_manufacturers man ON m.manufacturer_id = man.id 
                    WHERE SUBSTR(MD5(CONCAT(COALESCE(NULLIF(a.asset_tag, ''), CAST(a.id AS CHAR)), :salt_where)), 1, 16) = :token";
            $params = ['salt_col' => $SALT, 'salt_where' => $SALT, 'token' => $item_id];
        } else {
            $search_col = (is_numeric($item_id) && strlen($item_id) < 8) ? "a.id" : "a.asset_tag";
            $sql = "SELECT a.*, m.name as model_name, m.image as model_image, c.name as category_name, c.image as category_image, comp.name as company_name, NULL as location_name, supp.name as supplier_name, u.fullname as assigned_user_name, u.profil_fotosu as assigned_user_avatar, u.mail as assigned_user_email, u.sirket_ismi as assigned_user_company, u.bolum as assigned_user_dept, pa.name as assigned_asset_name, pa.id as assigned_asset_id, pa.image as assigned_asset_image, pam.image as assigned_asset_model_image, b.bolum_adi as dept_name, sl.name as status_label_name, sl.color as status_label_color, man.name as manufacturer_name, SUBSTR(MD5(CONCAT(COALESCE(NULLIF(a.asset_tag, ''), CAST(a.id AS CHAR)), :salt_col)), 1, 16) as public_token 
                    FROM assets a 
                    LEFT JOIN asset_models m ON a.model_id = m.id 
                    LEFT JOIN asset_categories c ON a.category_id = c.id 
                    LEFT JOIN asset_companies comp ON a.company_id = comp.id 
                    
                    LEFT JOIN asset_suppliers supp ON a.supplier_id = supp.id 
                    LEFT JOIN users u ON a.assigned_user_id = u.id 
                    LEFT JOIN assets pa ON a.asset_id = pa.id 
                    LEFT JOIN asset_models pam ON pa.model_id = pam.id 
                    LEFT JOIN bolumler b ON a.department_id = b.id 
                    LEFT JOIN asset_status_labels sl ON a.status_id = sl.id 
                    LEFT JOIN asset_manufacturers man ON m.manufacturer_id = man.id 
                    WHERE $search_col = :id_or_tag";
            $params = ['salt_col' => $SALT, 'id_or_tag' => $item_id];
        }
    } else {
        $extraWhere = ($view === 'consumables') ? " AND transaction_type IN ('consume', 'checkin')" : " AND (transaction_type = 'assign' OR transaction_type IS NULL)";
        $tagCol = tableHasColumn($pdo, $table, 'asset_tag') ? "i.asset_tag" : "i.id";

        $whereClause = "WHERE i.id = :item_id";
        if ($current_user_id == 0 || $is_token_request) {
            $whereClause = "WHERE SUBSTR(MD5(CONCAT($tagCol, :salt_where)), 1, 16) = :token";
            $params = ['salt_col' => $SALT, 'salt_where' => $SALT, 'token' => $item_id];
        } else {
            $params = ['salt_col' => $SALT, 'item_id' => $item_id];
        }

        $sumExpr = "quantity";
        if ($view === 'consumables') {
            $sumExpr = "CASE WHEN transaction_type = 'checkin' THEN -quantity ELSE quantity END";
        }

        $sql = "SELECT i.*, c.name as category_name, c.image as category_image, comp.name as company_name, supp.name as supplier_name, 
                       u.fullname as assigned_user_name, u.mail as assigned_user_email, u.profil_fotosu as assigned_user_avatar, u.sirket_ismi as assigned_user_company, u.bolum as assigned_user_dept,
                       man.name as manufacturer_name,
                       (SELECT COALESCE(SUM($sumExpr), 0) FROM $ckTable WHERE $itmCol = i.id $extraWhere) as assigned_count,
                       (SELECT COALESCE(SUM($sumExpr), 0) FROM $ckTable WHERE $itmCol = i.id $extraWhere AND YEAR(created_at) = YEAR(CURDATE())) as used_this_year,
                       b.bolum_adi as dept_name,
                       NULL as status_label_name, NULL as status_label_color, NULL as model_image,
                       SUBSTR(MD5(CONCAT($tagCol, :salt_col)), 1, 16) as public_token
                FROM $table i
                LEFT JOIN asset_categories c ON i.category_id = c.id
                LEFT JOIN asset_companies comp ON (i.company_id = comp.id)
                LEFT JOIN asset_suppliers supp ON (i.supplier_id = supp.id)
                LEFT JOIN asset_manufacturers man ON (i.manufacturer_id = man.id)
                LEFT JOIN bolumler b ON (i.department_id = b.id)
                LEFT JOIN users u ON (1=0) 
                $whereClause";
    }

    if ($params) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$asset) {
        ?>
        <div class="container d-flex align-items-center justify-content-center" style="min-height: 80vh;">
            <div class="text-center p-5 shadow-lg bg-white"
                style="border-radius: 24px; max-width: 500px; border: 1px solid rgba(0,0,0,0.05);">
                <div class="mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle"
                        style="width: 100px; height: 100px;">
                        <i class="fas fa-search text-muted fa-3x" style="opacity: 0.5;"></i>
                    </div>
                </div>
                <h2 class="font-weight-bold mb-3"><?= $isTr ? 'VarlÄ±k BulunamadÄ±' : 'Asset Not Found' ?></h2>
                <p class="text-muted mb-4">
                    <?= $isTr ? 'AradÄ±ÄŸÄ±nÄ±z varlÄ±k sistemde kayÄ±tlÄ± deÄŸil veya eriÅŸim anahtarÄ±nÄ±z hatalÄ±.' : 'The asset you are looking for is not registered or your access token is invalid.' ?>
                </p>
                <?php if ($current_user_id > 0): ?>
                    <a href="varliklar" class="btn btn-primary btn-lg rounded-pill px-5 shadow-sm">
                        <i class="fas fa-arrow-left mr-2"></i> <?= $isTr ? 'Listeye DÃ¶n' : 'Back to List' ?>
                    </a>
                <?php else: ?>
                    <div class="text-muted small border-top pt-3 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        <?= $isTr ? 'LÃ¼tfen geÃ§erli bir QR kod okutun.' : 'Please scan a valid QR code.' ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return;
    }

    // GÜVENLİK KONTROLÜ: Giriş yapmış kullanıcının yetki veya sahiplik kontrolü
    if ($current_user_id > 0 && $asset) {
        $has_route_perm = false;
        try {
            $stmtPerm = $pdo->prepare("SELECT id FROM user_perm WHERE role_id = ? AND (route_name = '*' OR FIND_IN_SET('varlik_detay', route_name) OR FIND_IN_SET('varliklar', route_name))");
            $stmtPerm->execute([$_SESSION['role']]);
            if ($stmtPerm->fetch()) {
                $has_route_perm = true;
            }
        } catch (Exception $e) {}

        // Personel rolündekiler (role = 2) için kendi cihazı dışındakileri görme yetkisi kısıtlanır
        if (($_SESSION['role'] ?? 3) == 2) {
            $has_route_perm = false;
        }

        if (!$has_route_perm) {
            $is_assigned = false;
            if ($view === 'assets') {
                if (isset($asset['assigned_user_id']) && $asset['assigned_user_id'] == $current_user_id) {
                    $is_assigned = true;
                }
            } else {
                // Lisans, aksesuar, sarf malzeme, parÃ§a vb. iÃ§in sahiplik kontrolÃ¼
                try {
                    $stmtCheck = $pdo->prepare("SELECT id FROM $ckTable WHERE $itmCol = ? AND user_id = ?");
                    $stmtCheck->execute([$asset['id'], $current_user_id]);
                    if ($stmtCheck->fetch()) {
                        $is_assigned = true;
                    }
                } catch (Exception $e) {}
            }

            if (!$is_assigned) {
                $_GET['hedef'] = 'varlik_detay';
                include __DIR__ . "/403.php";
                return;
            }
        }
    }

        // Resolve assigned user department name, company name, and checkout date
        if (!empty($asset['assigned_user_id'])) {
            $uId = intval($asset['assigned_user_id']);
            $uRow = $pdo->query("SELECT u.sirket_ismi, u.bolum FROM users u WHERE u.id = $uId")->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                // Dept lookup
                $rawDept = trim((string)($uRow['bolum'] ?? ''));
                if (is_numeric($rawDept) && intval($rawDept) > 0) {
                    try {
                        $stD = $pdo->prepare("SELECT bolum_adi FROM bolumler WHERE id = ?");
                        $stD->execute([intval($rawDept)]);
                        $asset['resolved_user_dept'] = $stD->fetchColumn() ?: '';
                    } catch (Exception $ex) { $asset['resolved_user_dept'] = ''; }
                } else {
                    $asset['resolved_user_dept'] = $rawDept;
                }

                // Company lookup
                $rawComp = trim((string)($uRow['sirket_ismi'] ?? ''));
                if (is_numeric($rawComp) && intval($rawComp) > 0) {
                    try {
                        $stC = $pdo->prepare("SELECT name FROM asset_companies WHERE id = ?");
                        $stC->execute([intval($rawComp)]);
                        $asset['resolved_user_company'] = $stC->fetchColumn() ?: '';
                    } catch (Exception $ex) { $asset['resolved_user_company'] = ''; }
                } else {
                    $asset['resolved_user_company'] = $rawComp;
                }
            }

            // Get last checkout date for asset
            $ckDate = '';
            if (!empty($asset['id'])) {
                try {
                    $cStmt = $pdo->prepare("SELECT created_at FROM asset_checkouts WHERE asset_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
                    $cStmt->execute([$asset['id'], $uId]);
                    $ckDate = $cStmt->fetchColumn();
                } catch (Exception $ex) {}

                if (!$ckDate) {
                    try {
                        $tStmt = $pdo->prepare("SELECT created_at FROM asset_timeline WHERE asset_id = ? AND action IN ('checkout', 'assign') ORDER BY id DESC LIMIT 1");
                        $tStmt->execute([$asset['id']]);
                        $ckDate = $tStmt->fetchColumn();
                    } catch (Exception $ex) {}
                }
            }
            if ($ckDate) {
                $asset['checkout_formatted_date'] = date('d.m.Y', strtotime($ckDate));
            }
        }
} catch (Exception $e) {
    echo "<div class='container p-4'><div class='alert alert-danger font-monospace' style='border-radius:12px;'>Error: " . htmlspecialchars($e->getMessage()) . "</div></div>";
    return;
}

// 2. FETCH LINKED ITEMS

if ($view === 'assets') {
    try {
        $uId = intval($asset['assigned_user_id'] ?? 0);
        $stmtLic = $pdo->prepare("
            SELECT alc.id as checkout_id, al.id, al.software_name, al.license_key, al.image 
            FROM asset_license_checkouts alc 
            JOIN asset_licenses al ON alc.license_id = al.id 
            WHERE (alc.asset_id = ? OR (alc.user_id IS NOT NULL AND alc.user_id = ? AND alc.user_id > 0)) AND al.deleted_at IS NULL
            UNION
            SELECT 0 as checkout_id, al.id, al.software_name, al.license_key, al.image 
            FROM asset_licenses al
            WHERE (al.asset_id = ? OR (al.assigned_user_id IS NOT NULL AND al.assigned_user_id = ? AND al.assigned_user_id > 0)) AND al.deleted_at IS NULL
        ");
        $stmtLic->execute([$asset['id'], $uId, $asset['id'], $uId]);
        $assigned_licenses = $stmtLic->fetchAll(PDO::FETCH_ASSOC);

        // Accessories (Same logic)
        $stmtAcc = $pdo->prepare("
            SELECT aac.id as checkout_id, aa.id, aa.name, aa.model_no, aa.image 
            FROM asset_accessory_checkouts aac 
            JOIN asset_accessories aa ON aac.accessory_id = aa.id 
            WHERE aac.asset_id = ? AND aa.deleted_at IS NULL
        ");
        $stmtAcc->execute([$asset['id']]);
        $assigned_accessories = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);

        $hasAccAssetId = tableHasColumn($pdo, 'asset_accessories', 'asset_id');
        if ($hasAccAssetId) {
            $oldAcc = $pdo->query("SELECT id, name, model_no, image FROM asset_accessories WHERE asset_id = " . intval($asset['id']) . " AND deleted_at IS NULL")->fetchAll();
            foreach ($oldAcc as $oa) {
                $exists = false;
                foreach ($assigned_accessories as $aa) {
                    if ($aa['id'] == $oa['id'] && ($aa['checkout_id'] ?? 0) == 0)
                        $exists = true;
                }
                if (!$exists) {
                    $oa['checkout_id'] = 0;
                    $assigned_accessories[] = $oa;
                }
            }
        }

        // Fetch components directly linked via asset_id OR linked via component checkouts
        $stmtComp = $pdo->prepare(
            "SELECT DISTINCT c.id, c.name, c.serial_no, c.image, c.asset_id FROM asset_components c
             LEFT JOIN asset_component_checkouts cc ON cc.component_id = c.id
             WHERE (c.asset_id = :asset_id OR cc.asset_id = :asset_id) AND c.deleted_at IS NULL"
        );
        $stmtComp->execute(['asset_id' => $asset['id']]);
        $assigned_components = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

        // Prep collections for split tabs
        $deviceItems = [];
        foreach ($child_assets as $ca)
            $deviceItems[] = ['id' => $ca['id'], 'v' => 'assets', 'n' => $ca['name'], 'img' => $ca['image'] ?? $ca['model_image'] ?? '', 'icon' => 'fa-laptop', 'lbl' => $isTr ? 'Bağlı Cihaz' : 'Child Device'];

        $accessoryItems = [];
        foreach ($assigned_accessories as $ac)
            $accessoryItems[] = ['id' => $ac['id'], 'v' => 'accessories', 'n' => $ac['name'], 'img' => $ac['image'] ?? '', 'icon' => 'fa-plug', 'lbl' => $isTr ? 'Aksesuar' : 'Accessory'];

        $licenseItems = [];
        foreach ($assigned_licenses as $al)
            $licenseItems[] = ['id' => $al['id'], 'v' => 'licenses', 'n' => $al['software_name'], 'img' => $al['image'] ?? '', 'icon' => 'fa-key', 'lbl' => $isTr ? 'Lisans' : 'License'];
    } catch (Exception $e) {
    }
} else {
    // Non-asset assignments (Show WHO it is assigned TO)
    $sItem = rtrim($view, 's');
    if (substr($view, -3) === 'ies')
        $sItem = substr($view, 0, -3) . 'y';
    $ckTbl = "asset_" . $sItem . "_checkouts";
    $itmC = $sItem . "_id";
    $extraW = ($view === 'consumables') ? " AND t.transaction_type IN ('consume', 'checkin')" : " AND (t.transaction_type = 'assign' OR t.transaction_type IS NULL)";

    $stmtAssignments = $pdo->prepare("
        SELECT t.id as checkout_id, t.quantity, t.notes, t.user_id, t.asset_id, t.created_at,
               u.fullname as user_name, a.name as asset_name, a.image as asset_image, am.image as asset_model_image, b.bolum_adi as dept_name
        FROM $ckTbl t
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN assets a ON t.asset_id = a.id
        LEFT JOIN asset_models am ON a.model_id = am.id
        LEFT JOIN bolumler b ON (COALESCE(u.bolum, a.department_id) = b.id)
        WHERE t.$itmC = ? $extraW
        ORDER BY t.created_at DESC
    ");
    $stmtAssignments->execute([$asset['id']]);
    $item_assignments = $stmtAssignments->fetchAll(PDO::FETCH_ASSOC);

    // USAGE SUMMARY FOR CONSUMABLES
    $usage_summary = [];
    if ($view === 'consumables') {
        $usage_summary = $pdo->query("
            SELECT 
                COALESCE(u.fullname, a.name) as target_name,
                MAX(CASE WHEN t.user_id > 0 THEN 'user' ELSE 'asset' END) as target_type,
                SUM(CASE WHEN t.transaction_type = 'checkin' THEN -t.quantity ELSE t.quantity END) as total_qty,
                DATE_FORMAT(t.created_at, '%Y-%m') as mo,
                b.bolum_adi as dept_name
            FROM asset_consumable_checkouts t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN assets a ON t.asset_id = a.id
            LEFT JOIN bolumler b ON COALESCE(u.bolum, a.department_id) = b.id
            WHERE t.consumable_id = {$asset['id']} AND t.transaction_type IN ('consume', 'checkin')
            GROUP BY target_name, mo, b.bolum_adi
            HAVING total_qty > 0
            ORDER BY mo DESC, total_qty DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 3. FETCH ACTIVITY LOGS
// 3. GENERATE LINKED SUMMARY FOR DELETE DIALOG

$linked_list = [];
$has_linked_items = false;
if (!empty($asset['assigned_user_name'])) {
    $uAvRaw = $asset['assigned_user_avatar'] ?? '';
    if (!empty($uAvRaw) && strpos($uAvRaw, 'http') === 0) {
        $avatar = $uAvRaw;
    } else if (!empty($uAvRaw)) {
        $uAvClean = ltrim($uAvRaw, '/');
        if (strpos($uAvClean, 'public/') === 0) {
            $uAvClean = substr($uAvClean, 7);
        }
        if (strpos($uAvClean, 'uploads/') !== 0 && strpos($uAvClean, 'dist/') !== 0) {
            $uAvClean = 'uploads/profil/' . $uAvClean;
        }
        $avatar = $base_url . $uAvClean;
    } else {
        $avatar = "https://ui-avatars.com/api/?name=" . urlencode($asset['assigned_user_name'] ?? 'U') . "&background=3b82f6&color=fff&size=128";
    }
    $imgHtml = "<img src='$avatar' style='width:22px; height:22px; object-fit:cover; border-radius:50%; margin-right:8px; vertical-align:middle; border:1px solid #e2e8f0;' onerror=\"this.src='https://ui-avatars.com/api/?name=" . urlencode($asset['assigned_user_name'] ?? 'U') . "&background=3b82f6&color=fff&size=128'\">";
    $linked_list[] = "<div class='text-danger mb-2 d-flex align-items-center'>$imgHtml <span class='mr-1'>" . ($isTr ? "Zimmetli Personel:" : "Assigned User:") . "</span> <strong class='ml-1'>" . htmlspecialchars($asset['assigned_user_name']) . "</strong></div>";
    $has_linked_items = true;
}
if (!empty($child_assets)) {
    foreach ($child_assets as $ca) {
        $imgHtml = _vdHasThumb($ca['image'] ?? $ca['model_image'] ?? '', 'assets', 'fa-desktop');
        $linked_list[] = "<div class='mb-2 d-flex align-items-center'>$imgHtml <a href='varlik-detay/" . $ca['id'] . "?view=assets' target='_blank' class='font-weight-bold text-info' style='text-decoration:underline;'>" . htmlspecialchars($ca['name']) . "</a> <span class='text-muted small ml-1'>(" . ($isTr ? 'Bağlı Cihaz' : 'Linked Device') . ")</span></div>";
    }
    $has_linked_items = true;
}
if (!empty($assigned_licenses)) {
    foreach ($assigned_licenses as $al) {
        $imgHtml = _vdHasThumb($al['image'] ?? '', 'licenses', 'fa-key');
        $linked_list[] = "<div class='mb-2 d-flex align-items-center'>$imgHtml <a href='varlik-detay/" . $al['id'] . "?view=licenses' target='_blank' class='font-weight-bold text-info' style='text-decoration:underline;'>" . htmlspecialchars($al['software_name']) . "</a> <span class='text-muted small ml-1'>(" . ($isTr ? 'Lisans' : 'License') . ")</span></div>";
    }
    $has_linked_items = true;
}
if (!empty($assigned_accessories)) {
    foreach ($assigned_accessories as $ac) {
        $imgHtml = _vdHasThumb($ac['image'] ?? '', 'accessories', 'fa-plug');
        $linked_list[] = "<div class='mb-2 d-flex align-items-center'>$imgHtml <a href='varlik-detay/" . $ac['id'] . "?view=accessories' target='_blank' class='font-weight-bold text-info' style='text-decoration:underline;'>" . htmlspecialchars($ac['name']) . "</a> <span class='text-muted small ml-1'>(" . ($isTr ? 'Aksesuar' : 'Accessory') . ")</span></div>";
    }
    $has_linked_items = true;
}
if (!empty($assigned_components)) {
    foreach ($assigned_components as $co) {
        $imgHtml = _vdHasThumb($co['image'] ?? '', 'components', 'fa-microchip');
        $linked_list[] = "<div class='mb-2 d-flex align-items-center'>$imgHtml <a href='varlik-detay/" . $co['id'] . "?view=components' target='_blank' class='font-weight-bold text-info' style='text-decoration:underline;'>" . htmlspecialchars($co['name']) . "</a> <span class='text-muted small ml-1'>(" . ($isTr ? 'BileÅŸen' : 'Component') . ")</span></div>";
    }
    $has_linked_items = true;
}

// Support for non-asset assignments (Accessories/Consumables/etc assigned to users/assets)
if ($view !== 'assets' && !empty($item_assignments)) {
    foreach ($item_assignments as $ia) {
        $name = $ia['user_name'] ?: $ia['asset_name'] ?: '?';
        $type = $ia['user_id'] > 0 ? ($isTr ? 'Personel' : 'User') : ($isTr ? 'Cihaz' : 'Device');
        $linked_list[] = "<div class='mb-2 d-flex align-items-center small'>- " . htmlspecialchars($name) . " <span class='text-muted small ml-1'>($type)</span></div>";
    }
    $has_linked_items = true;
}

$linkedSummary = !empty($linked_list) ? implode("", $linked_list) : "";

// 4. TIMELINE WITH PAGINATION
$typeMap = [
    'assets' => 'asset',
    'consumables' => 'consumable',
    'accessories' => 'accessory',
    'components' => 'component',
    'licenses' => 'license'
];
$item_type_log = $typeMap[$view] ?? 'asset';

$logPage = (int) ($_GET['log_page'] ?? 1);
if ($logPage < 1)
    $logPage = 1;
$logLimit = 10;
$logOffset = ($logPage - 1) * $logLimit;

$totalLogs = getAssetTimelineCount($pdo, $asset['id'], $item_type_log);
$totalPagesLogs = ceil($totalLogs / $logLimit);
$timeline = getAssetTimeline($pdo, $asset['id'], $item_type_log, $logLimit, $logOffset);

// 5. SPECS & CUSTOM FIELDS
$specsJSON = json_decode($asset['specs'] ?? '{}', true);
if (empty($specsJSON) || $specsJSON === ['-']) {
    $specsJSON = json_decode($asset['model_specs'] ?? '{}', true);
}
$customFields = [];
try {
    $stmtCF = $pdo->prepare("SELECT icf.field_label, icf.field_name, ifv.value FROM inventory_asset_field_values ifv JOIN inventory_custom_fields icf ON ifv.field_id = icf.id WHERE ifv.asset_id = ?");
    $stmtCF->execute([$asset['id']]);
    $customFields = $stmtCF->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Fetch Attachments
$attachments = [];
try {
    $stmtAtch = $pdo->prepare("SELECT * FROM attachments WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC");
    $stmtAtch->execute([$view_singular, $asset['id']]);
    $raw_attachments = $stmtAtch->fetchAll(PDO::FETCH_ASSOC);
    
    $attachments = [];
    foreach ($raw_attachments as $atch) {
        $fullPath = __DIR__ . '/../../' . $atch['file_path'];
        if (is_file($fullPath)) {
            $attachments[] = $atch;
        } else {
            // Automatically clean up missing file from DB
            $pdo->prepare("DELETE FROM attachments WHERE id = ?")->execute([$atch['id']]);
        }
    }

    // Personnel attachment filtering: Non-admin users ONLY see handover/return docs belonging to themselves,
    // UNLESS they have the explicit 'varlik_detay_view_all_attachments' permission in RBAC!
    $can_view_all_attachments = ((int)($current_user_role ?? 0) === 1 || hasPermission('varlik_detay_view_all_attachments'));

    if (!$can_view_all_attachments) {
        $is_own_asset = (isset($asset['assigned_user_id']) && (int)$asset['assigned_user_id'] === (int)($current_user_id ?? 0));
        if (!$is_own_asset) {
            $attachments = [];
        } else {
            $cUserRow = $pdo->query("SELECT username, fullname FROM users WHERE id = " . (int)$current_user_id)->fetch(PDO::FETCH_ASSOC);
            $cUsername = strtolower($cUserRow['username'] ?? '');
            $cFullname = strtolower(function_exists('convertTurkishToAscii') ? convertTurkishToAscii($cUserRow['fullname'] ?? '') : ($cUserRow['fullname'] ?? ''));
            $cCleanName = preg_replace('/[^a-z0-9]/', '', $cFullname);

            $attachments = array_values(array_filter($attachments, function($atch) use ($current_user_id, $cUsername, $cCleanName) {
                $docType = $atch['document_type'] ?? '';
                $isUploadedBySelf = (isset($atch['uploaded_by']) && (int)$atch['uploaded_by'] === (int)$current_user_id);
                if ($isUploadedBySelf) {
                    return true;
                }

                if (in_array($docType, ['handover', 'return'], true)) {
                    $fName = strtolower(function_exists('convertTurkishToAscii') ? convertTurkishToAscii($atch['file_name'] ?? '') : ($atch['file_name'] ?? ''));
                    $fClean = preg_replace('/[^a-z0-9]/', '', $fName);

                    if (!empty($cUsername) && strpos($fName, $cUsername) !== false) {
                        return true;
                    }
                    if (!empty($cCleanName) && strlen($cCleanName) > 2 && strpos($fClean, $cCleanName) !== false) {
                        return true;
                    }
                    return false; // Hide previous users' handover/return PDFs!
                }

                return false;
            }));
        }
    }
} catch (Exception $e) {
}

// IMAGE RESOLUTION FIX: Account for 'uploads/' according to item type
$is_category_default = empty($asset['image']) && !empty($asset['category_image']);
$raw_img = !empty($asset['image']) ? $asset['image'] : (!empty($asset['category_image']) ? 'categories/' . $asset['category_image'] : (!empty($asset['model_image']) ? $asset['model_image'] : ''));
if (!empty($raw_img)) {
    // If it starts with public/, remove it so we can re-add it consistently
    if (strpos($raw_img, 'public/') === 0)
        $raw_img = substr($raw_img, 7);

    // If it does not start with uploads/, detect prefix or use view
    if (strpos($raw_img, 'uploads/') !== 0) {
        if (strpos($raw_img, 'models-') === 0)
            $raw_img = 'uploads/models/' . $raw_img;
        elseif (strpos($raw_img, 'assets-') === 0)
            $raw_img = 'uploads/assets/' . $raw_img;
        elseif (strpos($raw_img, 'consumables-') === 0)
            $raw_img = 'uploads/consumables/' . $raw_img;
        elseif (strpos($raw_img, 'accessories-') === 0)
            $raw_img = 'uploads/accessories/' . $raw_img;
        elseif (strpos($raw_img, 'licenses-') === 0)
            $raw_img = 'uploads/licenses/' . $raw_img;
        elseif (strpos($raw_img, 'components-') === 0)
            $raw_img = 'uploads/components/' . $raw_img;
        elseif (strpos($raw_img, 'categories/') === 0)
            $raw_img = 'uploads/' . $raw_img;
        else
            $raw_img = 'uploads/' . $view . '/' . $raw_img;
    }
    $display_img = "public/" . $raw_img;
} else {
    $display_img = "https://ui-avatars.com/api/?name=" . urlencode($asset['name'] ?? $asset['software_name'] ?? 'Item') . "&background=f1f5f9&color=64748b&size=512";
}

$statusColor = $asset['status_label_color'] ?? '#64748b';

// --- SEÃ‡ENEK 1: GÄ°RÄ°Å?SÄ°Z HIZLI Ä°ZLEME (PUBLIC CARD) ---
if ($current_user_id == 0):
    // Avatar fix with absolute path and DISK check
    $avatar_val = !empty($asset['assigned_user_avatar']) ? $asset['assigned_user_avatar'] : '';
    if (!empty($avatar_val) && strpos($avatar_val, 'http') === 0) {
        $finalAvatar = $avatar_val;
    } else {
        if (strpos($avatar_val, 'public/') === 0)
            $avatar_val = substr($avatar_val, 7);
        if (!empty($avatar_val) && strpos($avatar_val, 'uploads/') !== 0 && strpos($avatar_val, 'dist/') !== 0)
            $avatar_val = 'uploads/profil/' . $avatar_val;

        $diskPath = __DIR__ . '/../../public/' . $avatar_val;
        $avatarExists = (!empty($avatar_val) && is_file($diskPath));
        $finalAvatar = $avatarExists ? $base_url . $avatar_val : "https://ui-avatars.com/api/?name=" . urlencode($asset['assigned_user_name'] ?? 'U') . "&background=3b82f6&color=fff&size=128";
    }
    $finalAssetImg = (strpos($display_img, 'http') === 0) ? $display_img : $base_url . $display_img;
    ?>
    <div class="container-fluid py-4 px-3" style="max-width: 500px; font-family: 'Inter', sans-serif;">
        <div class="card shadow-2xl border-0" style="border-radius: 24px; overflow: hidden; background: #fff;">
            <!-- Asset Header -->
            <div class="text-center p-5"
                style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); position: relative;">
                <div class="badge"
                    style="position: absolute; top: 20px; right: 20px; background: <?= $statusColor ?>; color: #fff; padding: 6px 14px; border-radius: 50px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 4px 6px -1px <?= $statusColor ?>44;">
                    <?php
                    $s_raw = $asset['status_label_name'] ?? ($isTr ? 'Aktif' : 'Active');
                    $trans_map = [
                        'Hazır' => ['tr' => 'Hazır', 'en' => 'Ready'],
                        'Ready' => ['tr' => 'Hazır', 'en' => 'Ready'],
                        'Arızalı' => ['tr' => 'Arızalı', 'en' => 'Faulty'],
                        'Faulty' => ['tr' => 'Arızalı', 'en' => 'Faulty'],
                        'Hurda' => ['tr' => 'Hurda', 'en' => 'Scrap'],
                        'Scrap' => ['tr' => 'Hurda', 'en' => 'Scrap'],
                        'Hurdaya Ayrılmış' => ['tr' => 'Hurda', 'en' => 'Scrap'],
                        'Atanmış' => ['tr' => 'Atanmış', 'en' => 'Assigned'],
                        'Assigned' => ['tr' => 'Atanmış', 'en' => 'Assigned'],
                        'İmza Bekliyor' => ['tr' => 'İmza Bekliyor', 'en' => 'Pending Signature'],
                        'Pending Signature' => ['tr' => 'İmza Bekliyor', 'en' => 'Pending Signature'],
                        'Beklemede' => ['tr' => 'Beklemede', 'en' => 'Pending'],
                        'Pending' => ['tr' => 'Beklemede', 'en' => 'Pending']
                    ];
                    echo htmlspecialchars($isTr ? ($trans_map[$s_raw]['tr'] ?? $s_raw) : ($trans_map[$s_raw]['en'] ?? $s_raw));
                    ?>
                </div>
                <img src="<?= $finalAssetImg ?>"
                    style="max-height: 180px; width: auto; object-fit: contain; filter: drop-shadow(0 10px 15px rgba(0,0,0,0.05));"
                    alt="Asset">
            </div>

            <div class="card-body p-4">
                <!-- Main Info -->
                <div class="text-center mb-4">
                    <h1 class="h3 font-weight-bold mb-1" style="color: var(--pulse-text-main);">
                        <?= htmlspecialchars($asset['name'] ?? $asset['software_name'] ?? 'Item') ?></h1>
                    <div class="d-flex align-items-center justify-content-center gap-2">
                        <span class="text-muted small font-weight-bold"
                            style="background: var(--pulse-bg); padding: 2px 8px; border-radius: 4px;"><?= htmlspecialchars($asset['asset_tag'] ?? 'No Tag') ?></span>
                    </div>
                </div>

                <!-- Assignment Slot -->
                <?php
                $hasUser = !empty($asset['assigned_user_name']);
                $hasAsset = !empty($asset['assigned_asset_name']);

                $label = $isTr ? 'ZÄ°MMET SAHÄ°BÄ°' : 'ASSIGNED TO';
                if (!$hasUser && $hasAsset)
                    $label = $isTr ? 'BAÄ?LI CÄ°HAZ' : 'LINKED DEVICE';

                // MASK NAME FUNCTION (E***h K***a)
                $maskName = function ($name) {
                    if (empty($name))
                        return '';
                    $parts = explode(' ', $name);
                    $masked = [];
                    foreach ($parts as $p) {
                        if (mb_strlen($p) <= 2) {
                            $masked[] = mb_substr($p, 0, 1) . '*';
                        } else {
                            $masked[] = mb_substr($p, 0, 1) . str_repeat('*', mb_strlen($p) - 2) . mb_substr($p, -1);
                        }
                    }
                    return implode(' ', $masked);
                };

                $displayName = $hasUser ? $maskName($asset['assigned_user_name']) : ($asset['assigned_asset_name'] ?? ($isTr ? 'Depoda / BoÅŸta' : 'In Storage'));

                // Avatar/Icon decision
                if ($hasUser) {
                    $dispAvatar = $finalAvatar;
                } elseif ($hasAsset) {
                    $paImg = $asset['assigned_asset_image'] ?: $asset['assigned_asset_model_image'] ?: '';
                    if (!empty($paImg)) {
                        // Path normalization for linked asset image
                        if (strpos($paImg, 'public/') === 0)
                            $paImg = substr($paImg, 7);
                        if (strpos($paImg, 'uploads/') !== 0) {
                            if (strpos($paImg, 'models-') === 0)
                                $paImg = 'uploads/models/' . $paImg;
                            elseif (strpos($paImg, 'assets-') === 0)
                                $paImg = 'uploads/assets/' . $paImg;
                            else
                                $paImg = 'uploads/assets/' . $paImg;
                        }

                        $fullDiskPath = __DIR__ . '/../../public/' . $paImg;
                        $dispAvatar = is_file($fullDiskPath) ? $base_url . 'public/' . $paImg : "https://ui-avatars.com/api/?name=C&background=eff6ff&color=3b82f6&size=128";
                    } else {
                        $dispAvatar = "https://ui-avatars.com/api/?name=C&background=eff6ff&color=3b82f6&size=128";
                    }
                } else {
                    $dispAvatar = "https://ui-avatars.com/api/?name=B&background=f1f5f9&color=64748b&size=128";
                }
                ?>
                <div class="p-3 mb-4 d-flex align-items-center assignment-owner-banner"
                    style="background: #eff6ff; border-radius: 16px; border: 1px solid #dbeafe;">
                    <img src="<?= $dispAvatar ?>" class="rounded-circle mr-3 shadow-sm"
                        style="width: 48px; height: 48px; object-fit: cover; border: 2px solid white;"
                        onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($asset['assigned_user_name'] ?? 'U') ?>&background=3b82f6&color=fff&size=128'">
                    <div>
                        <label class="text-primary small mb-0 font-weight-bold"
                            style="letter-spacing: 0.5px; text-transform: uppercase; font-size: 9px;"><?= $label ?></label>
                        <div class="font-weight-bold" style="color: #1e3a8a; font-size: 1.1rem; line-height: 1.2;">
                            <?= htmlspecialchars($displayName) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($asset['dept_name'] ?? '') ?></div>
                    </div>
                </div>

                <!-- Basic Specs Grid -->
                <div class="row no-gutters mb-4" style="background: var(--pulse-bg); border-radius: 16px; padding: 15px;">
                    <div class="col-6 pr-2">
                        <label class="text-muted small mb-1 d-block font-weight-bold"
                            style="font-size: 9px; text-transform: uppercase;"><?= $isTr ? 'SERÄ° NUMARASI' : 'SERIAL NUMBER' ?></label>
                        <div style="font-size: 13px; font-weight: 600; color: var(--pulse-text-main);">
                            <?= htmlspecialchars($asset['serial_no'] ?? '&mdash;') ?></div>
                    </div>
                    <div class="col-6 pl-2 border-left">
                        <label class="text-muted small mb-1 d-block font-weight-bold"
                            style="font-size: 9px; text-transform: uppercase;"><?= $isTr ? 'KATEGORÄ°' : 'CATEGORY' ?></label>
                        <div style="font-size: 13px; font-weight: 600; color: var(--pulse-text-main);">
                            <?= htmlspecialchars($asset['category_name'] ?? '&mdash;') ?></div>
                    </div>
                </div>

                <!-- Technical Specs List -->
                <?php 
                $hasTechSpecs = !empty($asset['os']) || !empty($asset['cpu']) || !empty($asset['ram']) || !empty($asset['gpu']) || !empty($asset['disk']) || !empty($asset['monitor']) || !empty($asset['mainboard']) || !empty($asset['ip_address']) || !empty($asset['mac_address']) || !empty($specsJSON) || !empty($customFields);
                if ($hasTechSpecs): ?>
                    <div class="mb-4">
                        <label class="font-weight-bold text-muted mb-2 px-1"
                            style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?= $isTr ? 'TEKNİK DETAYLAR' : 'TECHNICAL DETAILS' ?></label>
                        <div class="rounded-lg bg-white border">
                            <?php
                            $allSpecs = [];
                            if (!empty($asset['os'])) $allSpecs[$isTr ? 'İşletim Sistemi' : 'OS'] = $asset['os'];
                            if (!empty($asset['cpu'])) $allSpecs[$isTr ? 'İşlemci' : 'CPU'] = $asset['cpu'];
                            if (!empty($asset['mainboard'])) $allSpecs[$isTr ? 'Anakart' : 'Motherboard'] = $asset['mainboard'];
                            if (!empty($asset['ram'])) $allSpecs[$isTr ? 'Bellek (RAM)' : 'RAM'] = $asset['ram'];
                            if (!empty($asset['gpu'])) $allSpecs[$isTr ? 'Ekran Kartı' : 'GPU'] = $asset['gpu'];
                            if (!empty($asset['disk'])) $allSpecs[$isTr ? 'Disk' : 'Disk'] = $asset['disk'];
                            if (!empty($asset['monitor'])) $allSpecs[$isTr ? 'Monitör' : 'Monitor'] = $asset['monitor'];
                            if (!empty($asset['ip_address'])) $allSpecs[$isTr ? 'IP Adresi' : 'IP Address'] = $asset['ip_address'];
                            if (!empty($asset['mac_address'])) $allSpecs[$isTr ? 'MAC Adresi' : 'MAC Address'] = $asset['mac_address'];
                            if (!empty($asset['ip_secondary'])) $allSpecs[$isTr ? 'İkincil IP' : 'Secondary IP'] = $asset['ip_secondary'];

                            if (!empty($specsJSON)) {
                                foreach ($specsJSON as $k => $v) {
                                    if ($k === 'installed_software') continue;
                                    $excludedKeys = ['os', 'cpu', 'ram', 'ram_gb', 'gpu', 'monitor', 'disk', 'disk_c_total_gb', 'disk_c_free_gb', 'ip_address', 'mac_address', 'ip_secondary', 'ethernet_ip', 'ethernet_mac', 'wifi_ip', 'wifi_mac', 'secondary_ip', 'secondary_mac'];
                                    if (in_array(strtolower($k), $excludedKeys)) continue;
                                    if (!empty($v)) {
                                        if (is_array($v)) {
                                            foreach ($v as $subK => $subV) {
                                                if (!empty($subV) && !is_array($subV)) {
                                                    $displayKey = ucfirst(str_replace('_', ' ', $subK));
                                                    $allSpecs[$displayKey] = (string)$subV;
                                                }
                                            }
                                        } else {
                                            $displayKey = ucfirst(str_replace('_', ' ', $k));
                                            $allSpecs[$displayKey] = (string)$v;
                                        }
                                    }
                                }
                            }
                            foreach ($customFields as $cf) {
                                if (!empty($cf['value'])) {
                                    $allSpecs[$cf['field_label']] = $cf['value'];
                                }
                            }

                            $count = 0;
                            foreach ($allSpecs as $key => $val):
                                $count++; ?>
                                <div
                                    class="d-flex justify-content-between p-3 <?= $count < count($allSpecs) ? 'border-bottom' : '' ?>" style="gap: 15px;">
                                    <span class="text-muted" style="font-size: 13px; flex-shrink: 0;"><?= htmlspecialchars(ucfirst($key)) ?></span>
                                    <span class="font-weight-bold"
                                        style="font-size: 13px; color: var(--pulse-text-main); text-align: right; word-break: break-word;"><?= htmlspecialchars($val) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="mt-4">
                    
                    <p class="text-center text-muted m-0" style="font-size: 11px; font-weight: 500;">Â© <?= date('Y') ?> -
                        <?= htmlspecialchars(s('company_name')) ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php return; endif; ?>

<style>
    :root {
        --pulse-primary: #1e3a8a;
        --pulse-success: #10b981;
        --pulse-warning: #f59e0b;
        --pulse-danger: #ef4444;
        --pulse-bg: #f8fafc;
        --pulse-card-bg: #ffffff;
        --pulse-border: #e2e8f0;
        --pulse-text-main: #1e293b;
        --pulse-text-muted: #64748b;
        --pulse-header-bg: #1e3a8a;
    }

    body.dark-mode {
        --pulse-bg: #0f172a;
        --pulse-card-bg: #1e293b;
        --pulse-border: #334155;
        --pulse-text-main: #f8fafc;
        --pulse-text-muted: #94a3b8;
        --pulse-header-bg: #111827;
    }

    .assignment-user-card {
        background: rgba(99, 102, 241, 0.04) !important;
        border: 1px solid rgba(99, 102, 241, 0.2) !important;
        border-radius: 16px !important;
    }
    .assignment-user-card .user-name {
        color: var(--pulse-text-main) !important;
    }
    .assignment-user-card .user-detail-item {
        color: var(--pulse-text-main) !important;
    }
    body.dark-mode .assignment-user-card {
        background: rgba(30, 41, 59, 0.6) !important;
        border-color: rgba(99, 102, 241, 0.3) !important;
    }
    body.dark-mode .assignment-user-card .user-name {
        color: #f8fafc !important;
    }
    body.dark-mode .assignment-user-card .user-detail-item {
        color: #cbd5e1 !important;
    }

    body.dark-mode .assignment-owner-banner {
        background: rgba(30, 58, 138, 0.2) !important;
        border-color: rgba(59, 130, 246, 0.3) !important;
    }
    body.dark-mode .assignment-owner-banner .font-weight-bold {
        color: var(--pulse-text-main) !important;
    }
    body.dark-mode .bg-white,
    body.dark-mode .bg-light-soft {
        background-color: var(--pulse-card-bg) !important;
    }
    body.dark-mode .card,
    body.dark-mode .card-header {
        background-color: var(--pulse-card-bg) !important;
        border-color: var(--pulse-border) !important;
    }
    body.dark-mode #headingHandover button span {
        color: #93c5fd !important;
    }
    body.dark-mode #headingReturn button span {
        color: #fca5a5 !important;
    }
    body.dark-mode #headingInvoice button span {
        color: #86efac !important;
    }
    body.dark-mode .border,
    body.dark-mode .border-top,
    body.dark-mode .border-bottom,
    body.dark-mode .border-left,
    body.dark-mode .border-right {
        border-color: var(--pulse-border) !important;
    }

    body {
        background: var(--pulse-bg);
        color: var(--pulse-text-main);
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }

    .vd-container {
        padding: 20px;
        max-width: 1600px;
        margin: 0 auto;
    }

    /* MODERN HEADER */
    .vd-header-card {
        background: var(--pulse-header-bg);
        border-radius: 12px;
        padding: 12px 24px;
        color: white;
        margin-bottom: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .vd-header-left {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .vd-asset-icon {
        background: rgba(255, 255, 255, 0.1);
        width: 42px;
        height: 42px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }

    .vd-header-info h1 {
        font-size: 1.4rem;
        font-weight: 700;
        margin: 0;
        line-height: 1.2;
    }

    .vd-header-badges {
        display: flex;
        gap: 8px;
        margin-top: 4px;
    }

    .vd-header-badge {
        font-size: 10px;
        font-weight: 700;
        padding: 2px 10px;
        border-radius: 4px;
        text-transform: uppercase;
    }

    .vd-header-actions {
        display: flex;
        gap: 10px;
    }

    .btn-vd-action {
        padding: 6px 16px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        border: none;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
        text-decoration: none !important;
    }

    .btn-vd-edit {
        background: #2563eb;
        color: white !important;
    }

    .btn-vd-edit:hover {
        background: #1d4ed8;
        color: white !important;
    }

    .btn-vd-clone {
        background: #3b82f6;
        color: white !important;
    }

    .btn-vd-clone:hover {
        background: #2563eb;
        color: white !important;
    }

    .btn-vd-delete {
        background: #ef4444;
        color: white !important;
    }

    .btn-vd-delete:hover {
        background: #dc2626;
        color: white !important;
    }

    /* LAYOUT CARDS */
    .vd-card {
        background: var(--pulse-card-bg);
        border: 1px solid var(--pulse-border);
        border-radius: 12px;
        margin-bottom: 20px;
        overflow: hidden;
    }

    .vd-card-header {
        padding: 12px 16px;
        border-bottom: 1px solid var(--pulse-border);
        font-weight: 700;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--pulse-text-main);
    }

    .vd-card-body {
        padding: 16px;
    }

    /* MINI SPECS TABLE style like image */
    .specs-list {
        width: 100%;
        border-collapse: collapse;
    }

    .specs-list tr {
        border-bottom: 1px solid var(--pulse-border);
    }

    .specs-list tr:last-child {
        border-bottom: none;
    }

    .specs-list td {
        padding: 10px 0;
        font-size: 13px;
    }

    .specs-list td.label {
        font-weight: 600;
        color: var(--pulse-text-main);
        width: 160px;
    }

    .specs-list td.value {
        color: var(--pulse-text-muted);
        text-align: right;
    }

    /* TECH DRAWING BOX */
    .tech-drawing-box {
        background: #fff;
        height: 180px;
        border-radius: 10px;
        border: 1px solid var(--pulse-border);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 15px;
        position: relative;
    }

    body.dark-mode .tech-drawing-box {
        background: #f8fafc;
    }

    .tech-drawing-box i {
        font-size: 80px;
        color: #1e293b;
    }

    /* IMAGE CARD */
    .img-display-box {
        width: 100%;
        height: 300px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .img-display-box img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }

    /* TABS style */
    .nav-vd-tabs {
        border: none;
        padding: 0 16px;
        gap: 20px;
        margin-top: 10px;
    }

    .nav-vd-tabs .nav-link {
        border: none;
        color: var(--pulse-text-muted);
        font-weight: 600;
        font-size: 14px;
        padding: 10px 0;
        position: relative;
        background: none !important;
    }

    .nav-vd-tabs .nav-link.active {
        color: var(--pulse-primary) !important;
    }

    .nav-vd-tabs .nav-link.active::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--pulse-primary);
        border-radius: 3px;
    }

    body.dark-mode .nav-vd-tabs .nav-link {
        color: #94a3b8 !important;
    }

    body.dark-mode .nav-vd-tabs .nav-link:hover {
        color: #e2e8f0 !important;
    }

    body.dark-mode .nav-vd-tabs .nav-link.active {
        color: #60a5fa !important;
        font-weight: 700 !important;
    }

    body.dark-mode .nav-vd-tabs .nav-link.active::after {
        background: #60a5fa !important;
        box-shadow: 0 0 8px rgba(96, 165, 250, 0.5);
    }

    /* INFO LIST (MODERN) */
    .vd-info-table {
        width: 100%;
        font-size: 13px;
        border-collapse: collapse;
    }

    .vd-info-table tr {
        border-bottom: 1px solid var(--pulse-border);
    }

    .vd-info-table tr:last-child {
        border-bottom: none;
    }

    .vd-info-table td {
        padding: 12px 0;
    }

    .vd-info-table .lbl {
        color: var(--pulse-text-muted);
        font-weight: 500;
        text-align: left;
    }

    .vd-info-table .val {
        color: var(--pulse-text-main);
        font-weight: 700;
        text-align: right;
    }

    /* PREMIUM SHADOWS & RADIUS */
    .vd-card {
        background: var(--pulse-card-bg);
        border: 1px solid var(--pulse-border);
        border-radius: 12px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05), 0 1px 2px rgba(0, 0, 0, 0.06);
        overflow: hidden;
    }

    .mini-info-item .lbl {
        font-size: 9px;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--pulse-text-muted);
        display: block;
    }

    .mini-info-item .val {
        font-size: 11px;
        font-weight: 600;
        color: var(--pulse-text-main);
    }

    /* HISTORY TABLE */
    .history-table {
        width: 100%;
        font-size: 13px;
    }

    .history-table th {
        background: var(--pulse-bg);
        padding: 10px 15px;
        font-weight: 600;
        color: var(--pulse-text-muted);
        text-align: left;
        border-bottom: 2px solid var(--pulse-border);
    }

    .history-table td {
        padding: 12px 15px;
        border-bottom: 1px solid var(--pulse-border);
    }

    .history-table tr:hover {
        background: rgba(0, 0, 0, 0.02);
    }

    body.dark-mode .history-table tr:hover {
        background: rgba(255, 255, 255, 0.02);
    }

    .btn-return-sm {
        color: #ef4444;
        background: none;
        border: none;
        padding: 0;
        cursor: pointer;
    }

    .assignment-user-box {
        background: var(--pulse-bg);
        border-radius: 8px;
        padding: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid var(--pulse-border);
    }

    body.dark-mode .vd-card-header .text-primary {
        color: #60a5fa !important;
    }

    body.dark-mode .vd-card-header .text-success {
        color: #4ade80 !important;
    }

    body.dark-mode .vd-card-header .text-warning {
        color: #fbbf24 !important;
    }

    body.dark-mode .vd-card-header .text-info {
        color: #22d3ee !important;
    }
    
    /* Deleted Banner Styling */
    .vd-deleted-banner {
        border-radius: 16px; 
        border-left: 5px solid #ffc107 !important; 
        background-color: #fffbeb !important;
    }
    .vd-deleted-banner h5 {
        color: #92400e !important;
    }
    .vd-deleted-banner p {
        color: #b45309 !important;
    }
    body.dark-mode .vd-deleted-banner {
        background-color: rgba(245, 158, 11, 0.1) !important;
        border: 1px solid rgba(245, 158, 11, 0.2) !important;
        border-left: 5px solid #ffc107 !important;
    }
    body.dark-mode .vd-deleted-banner h5 {
        color: #fbbf24 !important;
    }
    body.dark-mode .vd-deleted-banner p {
        color: rgba(255, 255, 255, 0.7) !important;
    }
</style>

<div class="vd-container">

<?php if ($asset && !empty($asset['deleted_at'])): ?>
    <div class="alert alert-warning d-flex align-items-center justify-content-between p-4 mb-4 shadow-sm vd-deleted-banner">
        <div class="d-flex align-items-center">
            <i class="fas fa-trash-alt fa-2x mr-3 text-warning"></i>
            <div>
                <h5 class="font-weight-bold mb-1"><?= $isTr ? 'Bu envanter çöp kutusunda' : 'This inventory item is in the trash can' ?></h5>
                <p class="mb-0 text-muted" style="font-size:14px;"><?= $isTr ? 'Bu varlık silinmiştir. Aşağıdaki butonları kullanarak geri yükleyebilir veya kalıcı olarak silebilirsiniz.' : 'This asset is deleted. You can restore it or permanently delete it using the buttons below.' ?></p>
            </div>
        </div>
        <?php if ($current_user_role == 1 || hasPermission('varliklar_edit')): ?>
            <div class="d-flex" style="gap: 10px;">
                <button class="btn btn-success font-weight-bold px-4" style="border-radius:10px;" onclick="confirmRestoreDetail(<?= $asset['id'] ?>, '<?= $view ?>')">
                    <i class="fas fa-trash-restore mr-2"></i><?= $isTr ? 'Geri Yükle' : 'Restore' ?>
                </button>
                <button class="btn btn-danger font-weight-bold px-4" style="border-radius:10px;" onclick="confirmDeleteDetail(<?= $asset['id'] ?>, '<?= $view ?>')">
                    <i class="fas fa-trash mr-2"></i><?= $isTr ? 'Kalıcı Olarak Sil' : 'Permanently Delete' ?>
                </button>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

    <!-- MODERN HEADER (BLUE BAR) -->
    <div class="vd-header-card">
        <div class="vd-header-left">
            <div class="vd-asset-icon overflow-hidden bg-white" style="border: 2px solid rgba(255,255,255,0.2);">
                <?php if (!empty($display_img) && strpos($display_img, 'ui-avatars') === false): ?>
                    <img src="<?= $display_img ?>" style="width:100%; height:100%; object-fit:contain;">
                <?php else: ?>
                    <i
                        class="fas <?= match ($view) { 'accessories' => 'fa-keyboard', 'consumables' => 'fa-tint', 'components' => 'fa-microchip', 'licenses' => 'fa-key', default => 'fa-desktop'} ?>"></i>
                <?php endif; ?>
            </div>
            <div class="vd-header-info">
                <h1><?= htmlspecialchars($asset['name'] ?? $asset['software_name'] ?? 'VarlÄ±k') ?></h1>
                <?php if ($is_category_default): ?>
                    <div class="small text-white-50 mt-1 d-flex align-items-center">
                        <i class="fas fa-info-circle mr-1"></i>
                        <?= $isTr ? 'Kategori varsayÄ±lan resmidir. Ã–zel resim eklenebilir.' : 'Category default image. Custom image can be added.' ?>
                    </div>
                <?php endif; ?>
                <div class="vd-header-badges">
                    <span class="vd-header-badge"
                        style="background: rgba(255,255,255,0.2);"><?= strtoupper($isTr ? ($view === 'assets' ? 'Cihaz / DemirbaÅŸ' : $view) : ($view === 'assets' ? 'Device / Asset' : $view)) ?></span>
                    <?php if ($view === 'assets' && $asset['status_label_name']): ?>
                        <span class="vd-header-badge"
                            style="background: <?= $statusColor ?>;">
                            <?php
                            $s_raw = $asset['status_label_name'];
                            $trans_map = [
                                'Hazır' => ['tr' => 'Hazır', 'en' => 'Ready'],
                                'Ready' => ['tr' => 'Hazır', 'en' => 'Ready'],
                                'Arızalı' => ['tr' => 'Arızalı', 'en' => 'Faulty'],
                                'Faulty' => ['tr' => 'Arızalı', 'en' => 'Faulty'],
                                'Hurda' => ['tr' => 'Hurda', 'en' => 'Scrap'],
                                'Scrap' => ['tr' => 'Hurda', 'en' => 'Scrap'],
                                'Hurdaya Ayrılmış' => ['tr' => 'Hurda', 'en' => 'Scrap'],
                                'Atanmış' => ['tr' => 'Atanmış', 'en' => 'Assigned'],
                                'Assigned' => ['tr' => 'Atanmış', 'en' => 'Assigned'],
                                'İmza Bekliyor' => ['tr' => 'İmza Bekliyor', 'en' => 'Pending Signature'],
                                'Pending Signature' => ['tr' => 'İmza Bekliyor', 'en' => 'Pending Signature'],
                                'Beklemede' => ['tr' => 'Beklemede', 'en' => 'Pending'],
                                'Pending' => ['tr' => 'Beklemede', 'en' => 'Pending']
                            ];
                            echo htmlspecialchars($isTr ? ($trans_map[$s_raw]['tr'] ?? $s_raw) : ($trans_map[$s_raw]['en'] ?? $s_raw));
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (empty($asset['deleted_at']) && ($current_user_role == 1 || hasPermission('varliklar_edit'))): ?>
        <div class="vd-header-actions">
            <a href="varliklar?view=<?= $view ?>&action=edit&id=<?= $asset['id'] ?>&return_to=detail"
                class="btn-vd-action btn-vd-edit">
                <i class="fas fa-edit"></i> <?= $isTr ? 'Düzenle' : 'Edit' ?>
            </a>
            <button onclick="copyAsset()" class="btn-vd-action btn-vd-clone">
                <i class="fas fa-copy"></i> <?= $isTr ? 'Kopyala' : 'Clone' ?>
            </button>
            <button
                onclick="confirmDelete(<?= (int) $asset['id'] ?>, <?= htmlspecialchars(json_encode($asset['assigned_user_name'] ?: ($asset['assigned_asset_name'] ?? ''))) ?>, <?= htmlspecialchars(json_encode($linkedSummary)) ?>)"
                class="btn-vd-action btn-vd-delete">
                <i class="fas fa-trash"></i> <?= $isTr ? 'Sil' : 'Delete' ?>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- MAIN CONTENT GRID -->
    <div class="row">
        <!-- LEFT & MIDDLE: Specs and Tabs (EXPANDED) -->
        <div class="col-lg-8">
            <div class="vd-card" style="min-height: 600px;">
                <?php
                $can_view_tab_purchase    = hasPermission('varlik_detay_tab_purchase');
                $can_view_tab_licenses    = hasPermission('varlik_detay_tab_licenses');
                $can_view_tab_devices     = hasPermission('varlik_detay_tab_devices');
                $can_view_tab_accessories = hasPermission('varlik_detay_tab_accessories');
                $can_view_tab_components  = hasPermission('varlik_detay_tab_components');
                $can_view_tab_attachments = hasPermission('varlik_detay_tab_attachments');
                ?>
                <ul class="nav nav-vd-tabs" role="tablist">
                    <?php if ($view === 'assets'): ?>
                        <li class="nav-item"><a class="nav-link active" data-toggle="tab"
                                href="#tab-specs"><?= $isTr ? 'Teknik Özellikler' : 'Specifications' ?></a></li>
                        <?php if (floatval($asset['purchase_cost'] ?? 0) > 0): ?>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab"
                                    href="#tab-amortisman"><?= $isTr ? 'Amortisman' : 'Depreciation' ?></a></li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($view !== 'assets' && ($view === 'consumables' || $view === 'licenses' || $view === 'accessories')): ?>
                        <li class="nav-item"><a
                                class="nav-link <?= ($view === 'consumables' || $view === 'licenses' || $view === 'accessories') ? 'active' : '' ?>"
                                data-toggle="tab" href="#tab-stock"><?= $isTr ? 'Stok Bilgisi' : 'Inventory' ?></a></li>
                    <?php endif; ?>
                    <?php if ($can_view_tab_purchase): ?>
                    <li class="nav-item"><a
                            class="nav-link <?= ($view !== 'assets' && $view !== 'consumables' && $view !== 'licenses' && $view !== 'accessories') ? 'active' : '' ?>"
                            data-toggle="tab" href="#tab-purchase"><?= $isTr ? 'Satın Alma' : 'Purchase' ?></a></li>
                    <?php endif; ?>

                    <?php if ($view === 'licenses' || $view === 'accessories'): ?>
                        <li class="nav-item"><a class="nav-link" data-toggle="tab"
                                href="#tab-related-mixed"><?= $isTr ? 'İlişkili Cihazlar & Personeller' : 'Linked Devices & Staff' ?></a>
                        </li>
                    <?php endif; ?>

                    <?php if ($view !== 'consumables' && $view !== 'licenses' && $view !== 'accessories'): ?>
                        <li class="nav-item"><a class="nav-link <?= (!$can_view_tab_purchase && $view !== 'assets') ? 'active' : '' ?>" data-toggle="tab"
                                href="#tab-zimmet"><?= $isTr ? 'Zimmet' : 'Ownership' ?></a></li>
                    <?php endif; ?>

                    <?php if ($view === 'consumables'): ?>
                        <li class="nav-item"><a class="nav-link" data-toggle="tab"
                                href="#tab-usage"><?= $isTr ? 'Kullanım Özeti' : 'Usage' ?></a></li>
                    <?php endif; ?>

                    <?php if ($view !== 'consumables' && $view !== 'licenses' && $view !== 'accessories'): ?>
                        <?php if ($can_view_tab_licenses): ?>
                        <li class="nav-item"><a class="nav-link" data-toggle="tab"
                                href="#tab-lisanslar"><?= $isTr ? 'Lisanslar' : 'Licenses' ?></a></li>
                        <?php endif; ?>
                        <?php if ($can_view_tab_devices): ?>
                        <li class="nav-item"><a class="nav-link" data-toggle="tab"
                                href="#tab-related-devices"><?= $isTr ? 'İlişkili Cihazlar' : 'Related Devices' ?></a></li>
                        <?php endif; ?>
                        <?php if ($can_view_tab_accessories): ?>
                        <li class="nav-item"><a class="nav-link" data-toggle="tab"
                                href="#tab-related-accessories"><?= $isTr ? 'İlişkili Aksesuarlar' : 'Accessories' ?></a>
                        </li>
                        <?php endif; ?>
                        <?php if ($can_view_tab_components): ?>
                        <li class="nav-item"><a class="nav-link" data-toggle="tab"
                                href="#tab-related-components"><?= $isTr ? 'Bileşenler' : 'Components' ?></a>
                        </li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($can_view_tab_attachments): ?>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab"
                            href="#tab-attachments"><?= $isTr ? 'Ekler / Belgeler' : 'Attachments' ?>
                            <?php if (count($attachments) > 0): ?>
                                <span class="badge badge-pill badge-warning ml-1" style="font-size:10px;"><?= count($attachments) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                <div class="vd-card-body">
                    <div class="tab-content">
                        <!-- TAB: AMORTISMAN -->
                        <?php if ($view === 'assets' && floatval($asset['purchase_cost'] ?? 0) > 0): 
                            $cost = floatval($asset['purchase_cost']);
                            $salvage = floatval($asset['salvage_value'] ?? 0);
                            $life = intval($asset['useful_life_months'] ?? 60);
                            if ($life <= 0) $life = 60;
                            
                            $p_date = new DateTime($asset['purchase_date'] ?: 'now');
                            $now = new DateTime();
                            $diff = $p_date->diff($now);
                            $months_passed = max(0, ($diff->y * 12) + $diff->m);
                            
                            $depreciable = $cost - $salvage;
                            $monthly_dep = $depreciable > 0 ? ($depreciable / $life) : 0;
                            $accumulated = min($depreciable, $monthly_dep * $months_passed);
                            $net_value = max($salvage, $cost - $accumulated);
                            $rem_life = max(0, $life - $months_passed);
                        ?>
                            <div class="tab-pane fade" id="tab-amortisman">
                                <h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-calculator mr-2"></i><?= $isTr ? 'Finansal Amortisman DetaylarÄ±' : 'Financial Depreciation Details' ?></h6>
                                <table class="specs-list">
                                    <tr>
                                        <td class="label"><?= $isTr ? 'Ä°lk SatÄ±n Alma Maliyeti' : 'Initial Purchase Cost' ?></td>
                                        <td class="value font-weight-bold text-dark"><?= number_format($cost, 2) ?> â‚º</td>
                                    </tr>
                                    <tr>
                                        <td class="label"><?= $isTr ? 'Hurda / KalÄ±ntÄ± DeÄŸeri' : 'Salvage / Residual Value' ?></td>
                                        <td class="value"><?= number_format($salvage, 2) ?> â‚º</td>
                                    </tr>
                                    <tr>
                                        <td class="label"><?= $isTr ? 'FaydalÄ± Ã–mÃ¼r (Toplam / Kalan)' : 'Useful Life (Total / Remaining)' ?></td>
                                        <td class="value"><?= $life ?> Ay / <?= $rem_life ?> Ay</td>
                                    </tr>
                                    <tr>
                                        <td class="label"><?= $isTr ? 'GeÃ§en SÃ¼re (Ay)' : 'Time Elapsed (Months)' ?></td>
                                        <td class="value"><?= $months_passed ?> Ay</td>
                                    </tr>
                                    <tr>
                                        <td class="label text-danger font-weight-bold"><?= $isTr ? 'BirikmiÅŸ DeÄŸer KaybÄ±' : 'Accumulated Depreciation' ?></td>
                                        <td class="value text-danger font-weight-bold"><?= number_format($accumulated, 2) ?> â‚º</td>
                                    </tr>
                                    <tr class="bg-success-soft">
                                        <td class="label text-success font-weight-bold" style="font-size:14px;"><?= $isTr ? 'GÃ¼ncel Net Defter DeÄŸeri' : 'Current Net Book Value' ?></td>
                                        <td class="value text-success font-weight-bold" style="font-size:16px;"><?= number_format($net_value, 2) ?> â‚º</td>
                                    </tr>
                                </table>
                                <div class="mt-3 text-right">
                                    <a href="amortisman" class="btn btn-sm btn-outline-primary" style="border-radius:10px;">
                                        <i class="fas fa-chart-area mr-1"></i> <?= $isTr ? 'DetaylÄ± Projeksiyon GrafiÄŸi' : 'Detailed Projection Graph' ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- TAB: SPECS -->
                        <div class="tab-pane fade <?= ($view === 'assets') ? 'show active' : '' ?>" id="tab-specs">
                            <table class="specs-list">
                                <?php
                                if (!empty($asset['ip_address'])) {
                                    echo "<tr><td class='label'>" . ($isTr ? 'IP Adresi' : 'IP Address') . "</td><td class='value font-weight-bold text-primary'>" . htmlspecialchars($asset['ip_address']) . "</td></tr>";
                                }
                                if (!empty($asset['mac_address'])) {
                                    echo "<tr><td class='label'>" . ($isTr ? 'MAC Adresi' : 'MAC Address') . "</td><td class='value text-muted'>" . htmlspecialchars($asset['mac_address']) . "</td></tr>";
                                }
                                if (!empty($asset['ip_secondary'])) {
                                    echo "<tr><td class='label'>" . ($isTr ? 'Yedek IP Adresi' : 'Secondary IP Address') . "</td><td class='value text-muted'>" . htmlspecialchars($asset['ip_secondary']) . "</td></tr>";
                                }
                                if (!empty($asset['os'])) {
                                    echo "<tr><td class='label'>" . ($isTr ? 'Ä°ÅŸletim Sistemi' : 'Operating System') . "</td><td class='value'>" . htmlspecialchars($asset['os']) . "</td></tr>";
                                }
                                if (!empty($asset['cpu'])) {
                                    echo "<tr><td class='label'>" . ($isTr ? 'Ä°ÅŸlemci (CPU)' : 'Processor (CPU)') . "</td><td class='value'>" . htmlspecialchars($asset['cpu']) . "</td></tr>";
                                }
                                if (!empty($asset['ram'])) {
                                    echo "<tr><td class='label'>" . ($isTr ? 'Bellek (RAM)' : 'Memory (RAM)') . "</td><td class='value'>" . htmlspecialchars($asset['ram']) . "</td></tr>";
                                }
                                if (!empty($asset['gpu'])) {
                                    echo "<tr><td class='label'>" . ($isTr ? 'Ekran KartÄ± (GPU)' : 'Graphics Card (GPU)') . "</td><td class='value'>" . htmlspecialchars($asset['gpu']) . "</td></tr>";
                                }
                                if (!empty($asset['disk'])) {
                                    echo "<tr><td class='label'>" . ($isTr ? 'Disk' : 'Disk') . "</td><td class='value'>" . htmlspecialchars($asset['disk']) . "</td></tr>";
                                }
                                 $antivirusVal = trim($asset['antivirus'] ?? ($specsJSON['antivirus'] ?? ($specsJSON['installed_antivirus'] ?? '')));
                                 if (!empty($antivirusVal) && $antivirusVal !== '-') {
                                     echo "<tr><td class='label'>" . ($isTr ? 'Antivirus' : 'Antivirus') . "</td><td class='value'>" . htmlspecialchars($antivirusVal) . "</td></tr>";
                                 }
                                 
                                 if (!empty($specsJSON)) {
                                     foreach ($specsJSON as $sk => $sv) {
                                         if ($sk === 'installed_software') continue;
                                         $excludedKeys = ['os', 'cpu', 'ram', 'ram_gb', 'gpu', 'monitor', 'disk', 'disk_c_total_gb', 'disk_c_free_gb', 'ip_address', 'mac_address', 'ip_secondary', 'ethernet_ip', 'ethernet_mac', 'wifi_ip', 'wifi_mac', 'secondary_ip', 'secondary_mac', 'antivirus', 'installed_antivirus'];
                                         if (in_array(strtolower($sk), $excludedKeys)) continue;
                                         if (!empty($sv) && $sv !== '-') {
                                             if (is_array($sv)) {
                                                 foreach ($sv as $subK => $subV) {
                                                     if (!empty($subV) && !is_array($subV)) {
                                                         echo "<tr><td class='label'>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $subK))) . "</td><td class='value'>" . htmlspecialchars($subV) . "</td></tr>";
                                                     }
                                                 }
                                             } else {
                                                 echo "<tr><td class='label'>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $sk))) . "</td><td class='value'>" . htmlspecialchars($sv) . "</td></tr>";
                                             }
                                         }
                                     }
                                 }

                                 foreach ($customFields as $cf) {
                                     if (!empty($cf['value']) && $cf['value'] !== '-') {
                                         echo "<tr><td class='label font-weight-bold text-primary'>" . htmlspecialchars($cf['field_label']) . "</td><td class='value font-weight-bold text-dark'>" . htmlspecialchars($cf['value']) . "</td></tr>";
                                     }
                                 }
                                 ?>
                            </table>
                        </div>
                        
                        <?php if(!empty($specsJSON['installed_software'])): ?>
                        <!-- TAB: SOFTWARE -->
                        <div class="tab-pane fade" id="tab-software">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover" style="font-size:13px;">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Program</th>
                                            <th>Sürüm / Version</th>
                                            <th>Yayımcı / Publisher</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($specsJSON['installed_software'] as $sw): ?>
                                        <tr>
                                            <td class="font-weight-bold text-dark"><?= htmlspecialchars($sw['Name'] ?? '') ?></td>
                                            <td class="text-muted"><?= htmlspecialchars($sw['Version'] ?? '') ?></td>
                                            <td class="text-muted"><?= htmlspecialchars($sw['Publisher'] ?? '') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($can_view_tab_purchase): ?>
                        <div class="tab-pane fade <?= ($view !== 'assets' && $view !== 'consumables' && $view !== 'licenses' && $view !== 'accessories') ? 'show active' : '' ?>"
                            id="tab-purchase">
                            <table class="specs-list">
                                <tr>
                                    <td class="label"><?= $isTr ? 'Maliyet' : 'Cost' ?></td>
                                    <td class="value font-weight-bold" style="color:var(--pulse-text-main)">
                                        <?= number_format((float) ($asset['purchase_cost'] ?? 0), 2) ?>
                                        <?= htmlspecialchars($asset['purchase_currency'] ?? 'TRY') ?></td>
                                </tr>
                                <tr>
                                    <td class="label"><?= $isTr ? 'Alım Tarihi' : 'Date' ?></td>
                                    <td class="value">
                                        <?= !empty($asset['purchase_date']) ? date('d.m.Y', strtotime($asset['purchase_date'])) : '-' ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="label"><?= $isTr ? 'Sipariş No' : 'Order' ?></td>
                                    <td class="value"><?= htmlspecialchars(($asset['order_number'] ?? '-') ?: '-') ?></td>
                                </tr>
                                 <tr>
                                    <td class="label"><?= $isTr ? 'Garanti' : 'Warranty' ?></td>
                                    <td class="value">
                                        <?= intval($asset['warranty_months'] ?? 0) > 0 ? (intval($asset['warranty_months']) . ' ' . ($isTr ? 'Ay' : 'Months')) : '-' ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="label"><?= $isTr ? 'EOL (Ömür Bitişi)' : 'EOL Date' ?></td>
                                    <td class="value">
                                        <?= !empty($asset['eol_date']) ? date('d.m.Y', strtotime($asset['eol_date'])) : '-' ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="label"><?= $isTr ? 'Tedarikçi' : 'Supplier' ?></td>
                                    <td class="value"><?= htmlspecialchars($asset['supplier_name'] ?? '-') ?></td>
                                </tr>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- TAB: ZİMMET -->
                        <?php if ($view !== 'consumables'): ?>
                            <div class="tab-pane fade <?= (!$can_view_tab_purchase && $view !== 'assets' && $view !== 'consumables' && $view !== 'licenses' && $view !== 'accessories') ? 'show active' : '' ?>" id="tab-zimmet">
                                <?php if ($asset['assigned_user_id']): ?>
                                     <div class="assignment-user-card p-3 mb-3 shadow-sm">
                                         <?php 
$uAvRaw = $asset['assigned_user_avatar'] ?? '';
$_uLogoRaw = s('logo_path') ?: 'logo.png';
$uAvFallback = $base_url . (str_starts_with($_uLogoRaw, 'public/') ? $_uLogoRaw : 'public/' . $_uLogoRaw);
if (!empty($uAvRaw) && strpos($uAvRaw, 'http') === 0) {
    $avatar = $uAvRaw;
} elseif (!empty($uAvRaw) && $uAvRaw !== 'default.png') {
    $uAvClean = ltrim($uAvRaw, '/');
    if (strpos($uAvClean, 'public/') === 0) {
        $uAvClean = substr($uAvClean, 7);
    }
    if (strpos($uAvClean, 'uploads/') !== 0 && strpos($uAvClean, 'dist/') !== 0) {
        $uAvClean = 'uploads/profil/' . $uAvClean;
    }
    $avatar = $base_url . $uAvClean;
} else {
    $avatar = $uAvFallback;
}
?>
                                         <div class="d-flex align-items-center mb-3 pb-3 border-bottom" style="border-bottom-color: rgba(99,102,241,0.15) !important;">
                                             <img src="<?= $avatar ?>"
                                                 style="width:52px; height:52px; border-radius:50%; object-fit:cover; border:2px solid #6366f1;" class="mr-3 shadow-sm rounded-circle"
                                                 onerror="this.onerror=null; this.src='<?= $uAvFallback ?>'">
                                             <div>
                                                 <div class="font-weight-bold mb-1 user-name" style="font-size:15px;">
                                                     <?= htmlspecialchars(preg_replace('/\s*\(.*\)/', '', $asset['assigned_user_name'] ?? '&mdash;')) ?></div>
                                                 <div class="badge badge-light text-indigo px-2 py-1" style="background:rgba(99,102,241,0.15); color:#6366f1; border-radius:6px; font-size:11px;">
                                                     <i class="fas fa-user-check mr-1"></i><?= $isTr ? 'Zimmetli Personel' : 'Assigned User' ?>
                                                 </div>
                                             </div>
                                             <?php if ($current_user_role == 1 || hasPermission('varliklar_checkin') || hasPermission('varliklar_edit')): ?>
                                             <button class="btn btn-sm btn-outline-danger rounded-pill ml-auto px-3 shadow-sm d-flex align-items-center"
                                                 onclick="checkInItem(<?= $asset['id'] ?>, '<?= $view ?>', 'user', '<?= addslashes($asset['assigned_user_name'] ?? 'User') ?>')"
                                                 title="<?= $isTr ? 'Geri Al' : 'Check In' ?>">
                                                 <i class="fas fa-undo-alt mr-1"></i> <?= $isTr ? 'Geri Al' : 'Check In' ?>
                                             </button>
                                             <?php endif; ?>
                                         </div>

                                         <div class="user-details-list small">
                                             <ul class="list-unstyled mb-0" style="gap:10px; display:flex; flex-direction:column;">
                                                 <?php if (!empty($asset['assigned_user_email'])): ?>
                                                 <li class="d-flex align-items-center">
                                                     <i class="fas fa-envelope mr-2 text-primary" style="width:18px;"></i>
                                                     <span class="font-weight-600 user-detail-item"><?= htmlspecialchars($asset['assigned_user_email']) ?></span>
                                                 </li>
                                                 <?php endif; ?>

                                                 <?php 
                                                 $userDept = !empty($asset['resolved_user_dept']) ? $asset['resolved_user_dept'] : (!empty($asset['assigned_user_dept']) && !is_numeric($asset['assigned_user_dept']) ? $asset['assigned_user_dept'] : (!empty($asset['dept_name']) && !is_numeric($asset['dept_name']) ? $asset['dept_name'] : ''));
                                                 if (!empty($userDept) && $userDept !== '1'): 
                                                 ?>
                                                 <li class="d-flex align-items-center">
                                                     <i class="fas fa-building mr-2 text-info" style="width:18px;"></i>
                                                     <span class="user-detail-item"><?= htmlspecialchars($userDept) ?></span>
                                                 </li>
                                                 <?php endif; ?>

                                                 <?php 
                                                 $userComp = !empty($asset['resolved_user_company']) ? $asset['resolved_user_company'] : (!empty($asset['assigned_user_company']) && !is_numeric($asset['assigned_user_company']) ? $asset['assigned_user_company'] : (!empty($asset['company_name']) && !is_numeric($asset['company_name']) ? $asset['company_name'] : ''));
                                                 if (!empty($userComp) && $userComp !== '1'): 
                                                 ?>
                                                 <li class="d-flex align-items-center">
                                                     <i class="fas fa-city mr-2 text-warning" style="width:18px;"></i>
                                                     <span class="user-detail-item"><?= htmlspecialchars($userComp) ?></span>
                                                 </li>
                                                 <?php endif; ?>

                                                 <?php if (!empty($asset['checkout_formatted_date'])): ?>
                                                 <li class="d-flex align-items-center">
                                                     <i class="fas fa-calendar-alt mr-2 text-secondary" style="width:18px;"></i>
                                                     <span class="user-detail-item"><?= $isTr ? 'Çıkış / Zimmet Tarihi' : 'Assigned Date' ?>: <strong><?= htmlspecialchars($asset['checkout_formatted_date']) ?></strong></span>
                                                 </li>
                                                 <?php endif; ?>
                                             </ul>
                                         </div>
                                     </div>
                                <?php elseif ($asset['asset_id']): ?>
                                    <div class="assignment-user-box mb-3">
                                        <?php
                                        $paImg = $asset['assigned_asset_image'] ?: $asset['assigned_asset_model_image'] ?: '';
                                        echo _vdHasThumb($paImg, 'assets', 'fa-desktop');
                                        ?>
                                        <div>
                                            <div class="font-weight-bold">
                                                <a href="varlik-detay/<?= $asset['asset_id'] ?>?view=assets"
                                                    class="text-primary no-underline transition-hover">
                                                    <?= htmlspecialchars($asset['assigned_asset_name']) ?>
                                                </a>
                                            </div>
                                            <div class="text-muted small"><?= $isTr ? 'Bağlı Cihaz' : 'Assigned to Device' ?></div>
                                        </div>
                                         <?php if ($current_user_role == 1 || hasPermission('varliklar_checkin') || hasPermission('varliklar_edit')): ?>
                                         <button class="btn-return-sm ml-auto"
                                             onclick="checkInItem(<?= $asset['id'] ?>, '<?= $view ?>', 'asset', '<?= addslashes($asset['assigned_asset_name'] ?? 'Device') ?>')"
                                             title="<?= $isTr ? 'Geri Al' : 'Check In' ?>"><i
                                                 class="fas fa-undo-alt fa-lg"></i></button>
                                         <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-user-slash fa-3x mb-3 opacity-25"></i>
                                        <p><?= $isTr ? 'Bu varlÄ±k ÅŸu an boÅŸtadÄ±r.' : 'Not assigned.' ?></p>
                                        <a href="varliklar?view=<?= $view ?>&action=assign&id=<?= $asset['id'] ?>"
                                            class="btn btn-sm btn-primary rounded-pill px-4"><?= $isTr ? 'Zimmetle' : 'Assign' ?></a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- TAB: STOCK INFO (CONSUMABLES & LICENSES ENHANCED) -->
                        <?php if ($view !== 'assets'):
                            $total_q = (int) ($asset['total_qty'] ?? $asset['seats'] ?? 1);
                            $assigned_q = (int) ($asset['assigned_count'] ?? 0);
                            if ($view === 'consumables') {
                                $thisYear = date('Y');
                                $currentMonthNum = (int) date('n'); // 1 to 12
                        
                                // PHYSICAL TOTALS
                                $physicalRemaining = max(0, $total_q - $assigned_q);

                                // CURRENT YEAR STOCK
                                $stmtYearAdd = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM asset_consumable_checkouts WHERE consumable_id = ? AND transaction_type = 'add' AND YEAR(created_at) = ?");
                                $stmtYearAdd->execute([$asset['id'], $thisYear]);
                                $currentYearStock = (int) $stmtYearAdd->fetchColumn();

                                // CURRENT YEAR USED
                                $stmtYearUsed = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'checkin' THEN -quantity ELSE quantity END), 0) FROM asset_consumable_checkouts WHERE consumable_id = ? AND transaction_type IN ('consume', 'checkin') AND YEAR(created_at) = ?");
                                $stmtYearUsed->execute([$asset['id'], $thisYear]);
                                $currentYearUsed = (int) $stmtYearUsed->fetchColumn();

                                // CURRENT YEAR NET
                                $currentYearRemaining = $currentYearStock - $currentYearUsed; // Can be negative if consuming last year's stock
                        
                                // MONTHLY AVERAGE (Current Year Pace)
                                $avgMonthly = ceil($currentYearUsed / $currentMonthNum);

                                // ESTIMATED DEPLETION (Requires physical true stock, not year's budget deficit limit)
                                $estMonths = -1;
                                if ($avgMonthly > 0) {
                                    $estMonths = round($physicalRemaining / $avgMonthly, 1);
                                }
                            } else {
                                $rem_q = (int) ($asset['remaining_qty'] ?? ($total_q - $assigned_q));
                            }
                            ?>
                            <div class="tab-pane fade <?= ($view === 'consumables' || $view === 'licenses' || $view === 'accessories') ? 'show active' : '' ?>"
                                id="tab-stock">
                                <div class="vd-card-body p-0">
                                    <table class="specs-list">
                                        <?php if ($view === 'consumables'): ?>
                                            <tr class="bg-success-soft">
                                                <td class="label pl-3 text-success font-weight-bold"
                                                    style="font-size:15px; border-bottom:2px solid #fff;">
                                                    <?= $isTr ? 'FÄ°ZÄ°KSEL KALAN STOK (DEPO)' : 'TRUE REMAINING STOCK' ?></td>
                                                <td class="value pr-3 text-success font-weight-bold"
                                                    style="font-size:18px; border-bottom:2px solid #fff;">
                                                    <?= $physicalRemaining ?></td>
                                            </tr>
                                            <tr class="bg-light-soft">
                                                <td class="label pl-3" style="font-style:italic; border-top:2px solid #fff;">
                                                    <?= $isTr ? 'Bu YÄ±l SatÄ±n AlÄ±nan (Eklenen)' : 'Current Year Purchases' ?></td>
                                                <td class="value pr-3 font-weight-bold"
                                                    style="font-style:italic; border-top:2px solid #fff;">
                                                    <?= $currentYearStock ?></td>
                                            </tr>
                                            <tr>
                                                <td class="label pl-3 text-info font-weight-bold" style="font-style:italic;">
                                                    <?= $isTr ? 'Bu YÄ±l KullanÄ±lan (TÃ¼ketilen)' : 'Current Year Consumed' ?></td>
                                                <td class="value pr-3 text-info font-weight-bold"
                                                    style="font-size:15px; font-style:italic;"><?= $currentYearUsed ?></td>
                                            </tr>
                                            <tr class="bg-light">
                                                <td class="label pl-3 text-muted" style="font-size:11px;">
                                                    <?= $isTr ? 'TÜM ZAMANLAR TOPLAM ALIM (Tarihçe)' : 'ALL TIME TOTAL STOCK (History)' ?>
                                                </td>
                                                <td class="value pr-3 text-muted font-weight-bold" style="font-size:13px;">
                                                    <?= $total_q ?></td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" class="p-0 border-0">
                                                    <div class="row m-0 border-top mt-2 mb-2">
                                                        <div class="col-6 p-3 border-right text-center"
                                                            style="background: rgba(0,0,0,0.02);">
                                                            <div class="text-xs text-muted mb-1 font-weight-bold">
                                                                <?= $isTr ? 'AYLIK ORTALAMA TÜKETİM' : 'AVERAGE MONTHLY USAGE' ?>
                                                            </div>
                                                            <div class="h5 mb-0 text-dark font-weight-bold">
                                                                <?= $avgMonthly > 0 ? $avgMonthly . ' <span class="text-xs text-muted">' . ($isTr ? 'Adet/Ay' : 'Pcs/Mo') . '</span>' : '-' ?>
                                                            </div>
                                                        </div>
                                                        <div class="col-6 p-3 text-center"
                                                            style="background: rgba(0,0,0,0.02);">
                                                            <div class="text-xs text-muted mb-1 font-weight-bold">
                                                                <?= $isTr ? 'TAHMİNİ BİTİŞ SÜRESİ' : 'ESTIMATED STOCK DEPLETION' ?>
                                                            </div>
                                                            <?php
                                                            if ($estMonths >= 0) {
                                                                $warnClass = 'text-success';
                                                                $warnText = '';
                                                                $warnIcon = 'fa-check-circle';
                                                                if ($estMonths < 1) {
                                                                    $warnClass = 'text-danger';
                                                                    $warnText = $isTr ? 'Acil stok yenileme gerekli' : 'Urgent restock required';
                                                                    $warnIcon = 'fa-exclamation-triangle';
                                                                } elseif ($estMonths < 3) {
                                                                    $warnClass = 'text-warning';
                                                                    $warnText = $isTr ? 'Stoklar yakında tükenecek' : 'Stock will run out soon';
                                                                    $warnIcon = 'fa-exclamation-circle';
                                                                }
                                                                echo '<div class="h5 mb-1 ' . $warnClass . ' font-weight-bold">~ ' . $estMonths . ' ' . ($isTr ? 'Ay' : 'months') . '</div>';
                                                                if ($warnText) {
                                                                    echo '<div class="text-xs ' . $warnClass . '"><i class="fas ' . $warnIcon . ' mr-1"></i>' . $warnText . '</div>';
                                                                }
                                                            } else {
                                                                echo '<div class="h5 mb-0 text-muted font-weight-bold">N/A</div>';
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <tr class="bg-light-soft">
                                                <td class="label pl-3">
                                                    <?= ($view === 'licenses' || $view === 'accessories') ? ($isTr ? 'TOPLAM STOK' : 'TOTAL QTY') : ($isTr ? 'TOPLAM STOK' : 'TOTAL STOCK') ?>
                                                </td>
                                                <td class="value pr-3 h5 mb-0 font-weight-bold"><?= $total_q ?></td>
                                            </tr>
                                            <tr>
                                                <td class="label pl-3">
                                                    <?= ($view === 'licenses' || $view === 'accessories') ? ($isTr ? 'ATANAN' : 'ASSIGNED') : ($isTr ? 'TOPLAM KULLANILAN' : 'LIFETIME CONSUMED') ?>
                                                </td>
                                                <td class="value pr-3 text-warning font-weight-bold"><?= $assigned_q ?></td>
                                            </tr>
                                            <tr class="bg-success-soft">
                                                <td class="label pl-3 text-success font-weight-bold">
                                                    <?= $isTr ? 'KALAN STOK (Fiziksel)' : 'AVAILABLE STOCK' ?></td>
                                                <td class="value pr-3 text-success h4 mb-0 font-weight-bold"><?= $rem_q ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="tab-pane fade" id="tab-usage">
                            <?php if (empty($usage_summary)): ?>
                                <p class="text-center py-4 text-muted">
                                    <?= $isTr ? 'Kullanım kaydı bulunamadı.' : 'No usage history.' ?></p>
                            <?php else:
                                $chartData = [];
                                $monthlyTrend = [];
                                foreach ($usage_summary as $us) {
                                    $tName = $us['target_name'];
                                    if (!isset($chartData[$tName]))
                                        $chartData[$tName] = 0;
                                    $chartData[$tName] += $us['total_qty'];

                                    $moKey = $us['mo'];
                                    if (!isset($monthlyTrend[$moKey]))
                                        $monthlyTrend[$moKey] = 0;
                                    $monthlyTrend[$moKey] += $us['total_qty'];
                                }
                                arsort($chartData);
                                ksort($monthlyTrend);
                                $topTargets = array_slice($chartData, 0, 7, true);
                                ?>
                                <!-- Analytics Charts Row -->
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <div class="card border-0 shadow-sm h-100" style="background: #f8fafc; border-radius: 12px;">
                                            <div class="card-body p-3">
                                                <h6 class="font-weight-bold text-center text-muted mb-3" style="font-size:12px; letter-spacing:0.5px;">
                                                    <i class="fas fa-chart-pie mr-1 text-primary"></i>
                                                    <?= $isTr ? 'EN ÇOK TÜKETEN HEDEFLER (PERSONEL / BÖLÜM)' : 'TOP CONSUMERS (PERSONNEL / DEPT)' ?>
                                                </h6>
                                                <div style="height: 210px;">
                                                    <canvas id="topConsumersChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card border-0 shadow-sm h-100" style="background: #f8fafc; border-radius: 12px;">
                                            <div class="card-body p-3">
                                                <h6 class="font-weight-bold text-center text-muted mb-3" style="font-size:12px; letter-spacing:0.5px;">
                                                    <i class="fas fa-chart-bar mr-1 text-success"></i>
                                                    <?= $isTr ? 'AYLIK TÜKETİM TRENDİ (SON DÖNEMLER)' : 'MONTHLY CONSUMPTION TREND' ?>
                                                </h6>
                                                <div style="height: 210px;">
                                                    <canvas id="monthlyTrendChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <script>
                                    document.addEventListener('DOMContentLoaded', function () {
                                        var ctx1 = document.getElementById('topConsumersChart');
                                        if (ctx1 && typeof Chart !== 'undefined') {
                                            new Chart(ctx1, {
                                                type: 'doughnut',
                                                data: {
                                                    labels: <?= json_encode(array_keys($topTargets)) ?>,
                                                    datasets: [{
                                                        data: <?= json_encode(array_values($topTargets)) ?>,
                                                        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#64748b'],
                                                        borderWidth: 2,
                                                        hoverOffset: 4
                                                    }]
                                                },
                                                options: {
                                                    maintainAspectRatio: false,
                                                    cutout: '65%',
                                                    plugins: {
                                                        legend: { position: 'right', labels: { boxWidth: 12, usePointStyle: true, font: { size: 11 } } }
                                                    }
                                                }
                                            });
                                        }

                                        var ctx2 = document.getElementById('monthlyTrendChart');
                                        if (ctx2 && typeof Chart !== 'undefined') {
                                            new Chart(ctx2, {
                                                type: 'bar',
                                                data: {
                                                    labels: <?= json_encode(array_keys($monthlyTrend)) ?>,
                                                    datasets: [{
                                                        label: '<?= $isTr ? "Tüketim Miktarı" : "Consumed Quantity" ?>',
                                                        data: <?= json_encode(array_values($monthlyTrend)) ?>,
                                                        backgroundColor: '#10b981',
                                                        borderRadius: 6
                                                    }]
                                                },
                                                options: {
                                                    maintainAspectRatio: false,
                                                    plugins: { legend: { display: false } },
                                                    scales: {
                                                        y: { beginAtZero: true, grid: { color: '#e2e8f0' } },
                                                        x: { grid: { display: false } }
                                                    }
                                                }
                                            });
                                        }
                                    });
                                </script>

                                <div id="usageAccordion">
                                    <?php
                                    $usageByYear = [];
                                    foreach ($usage_summary as $us) {
                                        $yy = substr($us['mo'], 0, 4);
                                        $usageByYear[$yy][$us['mo']][] = $us;
                                    }

                                    krsort($usageByYear); // Latest year first
                                    $yIdx = 0;
                                    foreach ($usageByYear as $yr => $months):
                                        $yIdx++; ?>
                                        <div class="border rounded mb-3 bg-white shadow-sm overflow-hidden">
                                            <div class="bg-dark text-white py-2 px-3 d-flex justify-content-between align-items-center"
                                                data-toggle="collapse" href="#yrGroup<?= $yr ?>" style="cursor:pointer;">
                                                <div class="font-weight-bold"><?= $yr ?>
                                                    <?= $isTr ? 'Yılı Tüketimi' : 'Annual Consumption' ?></div>
                                                <i class="fas fa-calendar-check"></i>
                                            </div>
                                            <div id="yrGroup<?= $yr ?>" class="collapse <?= $yIdx == 1 ? 'show' : '' ?>">
                                                <div class="p-2">
                                                    <?php
                                                    krsort($months); // Latest month first
                                                    $mIdx = 0;
                                                    $trMonths = ['01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart', '04' => 'Nisan', '05' => 'Mayıs', '06' => 'Haziran', '07' => 'Temmuz', '08' => 'Ağustos', '09' => 'Eylül', '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık'];
                                                    foreach ($months as $mo => $logs):
                                                        $mIdx++; ?>
                                                        <div class="card mb-1 border-0">
                                                            <?php
                                                            $parts = explode('-', $mo);
                                                            $displayMo = count($parts) == 2 ? $parts[0] . ' ' . ($isTr ? ($trMonths[$parts[1]] ?? $parts[1]) : date('F', mktime(0, 0, 0, $parts[1], 10))) : $mo;
                                                            $sumQty = array_sum(array_column($logs, 'total_qty'));
                                                            ?>
                                                            <div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"
                                                                data-toggle="collapse" href="#moGroup<?= str_replace('-', '', $mo) ?>"
                                                                style="cursor:pointer; border-radius:6px;">
                                                                <div class="small font-weight-bold text-dark"
                                                                    style="font-size:13px;"><?= $displayMo ?> &mdash; <span
                                                                        class="badge badge-info ml-1"><?= $sumQty ?>
                                                                        <?= $isTr ? 'Adet' : 'Pcs' ?></span></div>
                                                            </div>
                                                            <div id="moGroup<?= str_replace('-', '', $mo) ?>"
                                                                class="collapse <?= ($yIdx == 1 && $mIdx == 1) ? 'show' : '' ?>">
                                                                <table class="table table-sm mb-0" style="font-size:12px;">
                                                                    <?php foreach ($logs as $l): ?>
                                                                        <tr>
                                                                            <td class="pl-3 border-0 text-muted">
                                                                                <?= htmlspecialchars($l['target_name']) ?></td>
                                                                            <td class="pr-3 text-right border-0 font-weight-bold">&rarr;
                                                                                <?= $l['total_qty'] ?></td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- TAB: LÄ°SANSLAR -->
                        <div class="tab-pane fade" id="tab-lisanslar">
                            <?php if (empty($assigned_licenses)): ?>
                                <p class="text-center py-4 text-muted">
                                    <?= $isTr ? 'Bağlı lisans bulunamadı.' : 'No licenses.' ?></p>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($assigned_licenses as $al): ?>
                                        <a href="varlik-detay/<?= $al['id'] ?>?view=licenses"
                                            class="list-group-item d-flex align-items-center px-0 no-underline text-dark transition-hover">
                                            <?= _vdHasThumb($al['image'] ?? '', 'licenses', 'fa-key') ?>
                                            <div class="flex-grow-1">
                                                <div class="font-weight-bold" style="font-size:13px;">
                                                    <?= htmlspecialchars($al['software_name']) ?></div>
                                                <code
                                                    class="small text-muted"><?= htmlspecialchars($al['license_key']) ?></code>
                                            </div>
                                            <i class="fas fa-chevron-right text-muted small"></i>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>


                        <!-- TAB: RELATED DEVICES -->
                        <div class="tab-pane fade" id="tab-related-devices">
                            <?php if (empty($deviceItems)): ?>
                                <p class="text-center py-4 text-muted">
                                    <?= $isTr ? 'İlişkili cihaz bulunamadı.' : 'No related devices.' ?></p>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($deviceItems as $ri): ?>
                                        <div class="list-group-item d-flex align-items-center px-0">
                                            <?= _vdHasThumb($ri['img'], 'assets', $ri['icon']) ?>
                                            <div class="flex-grow-1">
                                                <div class="font-weight-bold" style="font-size:13px;">
                                                    <?= htmlspecialchars($ri['n']) ?></div>
                                                <div class="small text-muted"><?= $ri['lbl'] ?></div>
                                            </div>
                                            <a href="varlik-detay/<?= $ri['id'] ?>?view=assets" class="btn btn-xs btn-light"><i
                                                    class="fas fa-external-link-alt"></i></a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- TAB: RELATED ACCESSORIES -->
                        <div class="tab-pane fade" id="tab-related-accessories">
                            <?php if (empty($accessoryItems)): ?>
                                <p class="text-center py-4 text-muted">
                                    <?= $isTr ? 'İlişkili aksesuar bulunamadı.' : 'No accessories.' ?></p>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($accessoryItems as $ri): ?>
                                        <div class="list-group-item d-flex align-items-center px-0">
                                            <?= _vdHasThumb($ri['img'], 'accessories', 'fa-plug') ?>
                                            <div class="flex-grow-1">
                                                <div class="font-weight-bold" style="font-size:13px;">
                                                    <?= htmlspecialchars($ri['n']) ?></div>
                                                <div class="small text-muted"><?= $isTr ? 'Aksesuar' : 'Accessory' ?></div>
                                            </div>
                                            <a href="varlik-detay/<?= $ri['id'] ?>?view=accessories"
                                                class="btn btn-xs btn-light"><i class="fas fa-external-link-alt"></i></a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- TAB: RELATED COMPONENTS -->
                        <div class="tab-pane fade" id="tab-related-components">
                            <?php if (empty($assigned_components)): ?>
                                <p class="text-center py-4 text-muted">
                                    <?= $isTr ? 'İlişkili bileşen bulunamadı.' : 'No components.' ?></p>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($assigned_components as $co): ?>
                                        <div class="list-group-item d-flex align-items-center px-0">
                                            <?= _vdHasThumb($co['image'] ?? '', 'components', 'fa-microchip') ?>
                                            <div class="flex-grow-1">
                                                <div class="font-weight-bold" style="font-size:13px;">
                                                    <?= htmlspecialchars($co['name']) ?></div>
                                                <div class="small text-muted"><?= htmlspecialchars($co['serial_no'] ?? '') ?> (<?= $isTr ? 'Bileşen' : 'Component' ?>)</div>
                                            </div>
                                            <a href="varlik-detay/<?= $co['id'] ?>?view=components"
                                                class="btn btn-xs btn-light"><i class="fas fa-external-link-alt"></i></a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>



                        <!-- TAB: MIXED ASSIGNMENTS (DEVICES & STAFF) -->
                        <?php if ($view === 'licenses' || $view === 'accessories'): ?>
                            <div class="tab-pane fade" id="tab-related-mixed">
                                <?php if (empty($item_assignments)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-link-slash fa-3x mb-3 opacity-25"></i>
                                        <p><?= $isTr ? 'Henüz hiçbir personele veya cihaza atanmamış.' : 'No assignments found.' ?>
                                        </p>
                                        <a href="varliklar?view=<?= $view ?>&action=assign&id=<?= $asset['id'] ?>"
                                            class="btn btn-sm btn-primary rounded-pill px-4"><?= $isTr ? 'Şimdi Ata' : 'Assign Now' ?></a>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush mt-2">
                                        <?php foreach ($item_assignments as $ia): ?>
                                            <div class="list-group-item d-flex align-items-center px-0 bg-transparent"
                                                style="border-bottom: 1px solid var(--pulse-border);">
                                                <?php if (!empty($ia['user_id'])): ?>
                                                    <div class="vd-asset-icon mr-3"
                                                        style="background:var(--pulse-primary-soft); color:var(--pulse-primary);"><i
                                                            class="fas fa-user-check"></i></div>
                                                    <div class="flex-grow-1">
                                                        <div class="font-weight-bold" style="font-size:13px;">
                                                            <?= htmlspecialchars($ia['user_name']) ?></div>
                                                        <div class="small text-muted"><?= htmlspecialchars($ia['dept_name'] ?? '') ?>
                                                            (Personel)</div>
                                                    </div>
                                                <?php else: ?>
                                                    <?php
                                                    $riImg = $ia['asset_image'] ?: $ia['asset_model_image'] ?: '';
                                                    echo _vdHasThumb($riImg, 'assets', 'fa-desktop');
                                                    ?>
                                                    <div class="flex-grow-1">
                                                        <div class="font-weight-bold" style="font-size:13px;">
                                                            <a href="varlik-detay/<?= $ia['asset_id'] ?>?view=assets"
                                                                class="text-info no-underline transition-hover">
                                                                <?= htmlspecialchars($ia['asset_name']) ?>
                                                            </a>
                                                        </div>
                                                        <div class="small text-muted"><?= $isTr ? 'Cihaz Ataması' : 'Device Assignment' ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($current_user_role == 1 || hasPermission('varliklar_checkin') || hasPermission('varliklar_edit')): ?>
                                                <button class="btn btn-xs btn-outline-danger border-0 rounded-pill ml-auto"
                                                    onclick="checkInLinkedItem(<?= $asset['id'] ?>, '<?= $view ?>', '<?= htmlspecialchars($ia['user_name'] ?? $ia['asset_name'] ?? 'Item') ?>', <?= intval($ia['checkout_id'] ?? 0) ?>, '<?= !empty($ia['user_id']) ? 'user' : 'asset' ?>')"><i
                                                        class="fas fa-undo-alt"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- TAB: ATTACHMENTS -->
                        <div class="tab-pane fade" id="tab-attachments">
                            <div class="attachments-wrapper">
                                <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-light rounded-xl border shadow-sm">
                                    <div>
                                        <h6 class="mb-1 font-weight-bold text-dark"><?= $isTr ? 'Belge ve Tutanak Arşivi' : 'Document Archive' ?></h6>
                                        <p class="mb-0 text-muted small"><?= $isTr ? 'Bu cihaz için üretilen veya yüklenen tüm belgeler' : 'All documents generated or uploaded for this asset' ?></p>
                                    </div>
                                    <?php if ($current_user_role == 1 || hasPermission('varliklar_upload_attachment') || hasPermission('varliklar_edit')): ?>
                                    <button type="button" onclick="$('#modal-upload-attachment').modal('show')" class="btn btn-primary px-4 shadow-sm d-flex align-items-center gap-2" style="border-radius:12px; font-weight:600;">
                                        <i class="fas fa-plus-circle"></i> <?= $isTr ? 'Yeni Dosya' : 'New File' ?>
                                    </button>
                                    <?php endif; ?>
                                </div>

                                <?php if (empty($attachments)): ?>
                                    <div class="text-center py-5 rounded-xl border-dashed">
                                        <div class="mb-3 opacity-25"><i class="fas fa-folder-open fa-4x text-muted"></i></div>
                                        <p class="text-muted"><?= $isTr ? 'Henüz hiçbir belge bulunmuyor.' : 'No documents yet.' ?></p>
                                    </div>
                                <?php else: 
                                    $handoverDocs = array_filter($attachments, function($a) { return $a['document_type'] === 'handover'; });
                                    $returnDocs = array_filter($attachments, function($a) { return $a['document_type'] === 'return'; });
                                    $invoiceDocs = array_filter($attachments, function($a) { return $a['document_type'] === 'invoice'; });
                                ?>
                                    <!-- ACCORDION SECTION -->
                                    <div class="accordion" id="attachmentsAccordion">
                                        
                                        <!-- TESLİM BÖLÜMÜ -->
                                        <div class="card border-0 mb-3" style="border-radius:14px; overflow:hidden;">
                                            <div class="card-header border p-0" id="headingHandover">
                                                <button class="btn btn-link btn-block text-left p-3 d-flex align-items-center justify-content-between no-underline collapsed" 
                                                        type="button" data-toggle="collapse" data-target="#collapseHandover" aria-expanded="false">
                                                    <span class="font-weight-bold" style="color:#1e3a8a;"><i class="fas fa-file-signature mr-2"></i> <?= $isTr ? 'TESLİM TUTANAKLARI' : 'DELIVERY PROTOCOLS' ?></span>
                                                    <span class="badge badge-primary badge-pill"><?= count($handoverDocs) ?></span>
                                                </button>
                                            </div>
                                            <div id="collapseHandover" class="collapse" data-parent="#attachmentsAccordion">
                                                <div class="card-body p-3 bg-light-soft border border-top-0" style="border-bottom-left-radius:14px; border-bottom-right-radius:14px;">
                                                    <div class="row">
                                                        <?php if (empty($handoverDocs)): ?>
                                                            <div class="col-12 text-muted small italic px-3"><?= $isTr ? 'Henüz teslim belgesi yok.' : 'No delivery protocols.' ?></div>
                                                        <?php else: foreach ($handoverDocs as $atch): renderPremiumCard($atch, $base_url, 'primary', $isTr); endforeach; endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- İADE BÖLÜMÜ -->
                                        <div class="card border-0 mb-3" style="border-radius:14px; overflow:hidden;">
                                            <div class="card-header border p-0" id="headingReturn">
                                                <button class="btn btn-link btn-block text-left p-3 d-flex align-items-center justify-content-between no-underline collapsed" 
                                                        type="button" data-toggle="collapse" data-target="#collapseReturn" aria-expanded="false">
                                                    <span class="font-weight-bold" style="color:#991b1b;"><i class="fas fa-undo-alt mr-2"></i> <?= $isTr ? 'İADE TUTANAKLARI' : 'RETURN PROTOCOLS' ?></span>
                                                    <span class="badge badge-danger badge-pill"><?= count($returnDocs) ?></span>
                                                </button>
                                            </div>
                                            <div id="collapseReturn" class="collapse" data-parent="#attachmentsAccordion">
                                                <div class="card-body p-3 bg-light-soft border border-top-0" style="border-bottom-left-radius:14px; border-bottom-right-radius:14px;">
                                                    <div class="row">
                                                        <?php if (empty($returnDocs)): ?>
                                                            <div class="col-12 text-muted small italic px-3"><?= $isTr ? 'Henüz iade belgesi yok.' : 'No return protocols.' ?></div>
                                                        <?php else: foreach ($returnDocs as $atch): renderPremiumCard($atch, $base_url, 'danger', $isTr); endforeach; endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- FATURA BÖLÜMÜ -->
                                        <div class="card border-0 mb-3" style="border-radius:14px; overflow:hidden;">
                                            <div class="card-header border p-0" id="headingInvoice">
                                                <button class="btn btn-link btn-block text-left p-3 d-flex align-items-center justify-content-between no-underline collapsed" 
                                                        type="button" data-toggle="collapse" data-target="#collapseInvoice" aria-expanded="false">
                                                    <span class="font-weight-bold" style="color:#047857;"><i class="fas fa-file-invoice-dollar mr-2"></i> <?= $isTr ? 'FATURALAR' : 'INVOICES' ?></span>
                                                    <span class="badge badge-success badge-pill"><?= count($invoiceDocs) ?></span>
                                                </button>
                                            </div>
                                            <div id="collapseInvoice" class="collapse" data-parent="#attachmentsAccordion">
                                                <div class="card-body p-3 bg-light-soft border border-top-0" style="border-bottom-left-radius:14px; border-bottom-right-radius:14px;">
                                                    <div class="row">
                                                        <?php if (empty($invoiceDocs)): ?>
                                                            <div class="col-12 text-muted small italic px-3"><?= $isTr ? 'Henüz fatura belgesi yok.' : 'No invoice documents.' ?></div>
                                                        <?php else: foreach ($invoiceDocs as $atch): renderPremiumCard($atch, $base_url, 'success', $isTr); endforeach; endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div> <!-- End of tab-content -->
                </div> <!-- End of vd-card-body -->
            </div> <!-- End of vd-card -->
        </div> <!-- End of col-lg-8 -->

        <?php
        function renderPremiumCard($atch, $base_url, $colorType, $isTr) {
            global $current_user_role;
            $isPdf = strpos($atch['file_type'] ?? '', 'pdf') !== false;
            $icon = $isPdf ? 'fa-file-pdf' : 'fa-file-image';
            $filePath = $base_url . 'dashboard?route=view_attachment&id=' . $atch['id'];
            $iconColor = $colorType === 'primary' ? 'text-primary' : ($colorType === 'success' ? 'text-success' : 'text-danger');
            ?>
            <div class="col-xl-6 col-lg-12 col-md-6 mb-3 attachment-card-wrapper" id="atch-<?= $atch['id'] ?>">
                <div class="premium-doc-card shadow-sm border p-3 d-flex align-items-center bg-white transition-hover" 
                     style="border-radius:12px; position:relative; height:80px;">
                    
                    <div class="doc-icon-box mr-3 d-flex align-items-center justify-content-center <?= $iconColor ?>" 
                         onclick="window.open('<?= $filePath ?>', '_blank')"
                         style="min-width:45px; height:45px; background:rgba(0,0,0,0.03); border-radius:10px; cursor:pointer;">
                        <i class="fas <?= $icon ?> fa-xl"></i>
                    </div>
                    
                    <div class="flex-grow-1 overflow-hidden" onclick="window.open('<?= $filePath ?>', '_blank')" style="cursor:pointer;">
                        <div class="font-weight-bold text-dark text-truncate mb-1" style="font-size:12px;"><?= htmlspecialchars($atch['file_name']) ?></div>
                        <div class="d-flex align-items-center text-muted" style="font-size:10px;">
                            <span class="mr-2"><i class="far fa-calendar-alt mr-1"></i> <?= date('d.m.Y', strtotime($atch['created_at'])) ?></span>
                            <span class="border-left pl-2"><i class="fas fa-database mr-1"></i> <?= round(($atch['file_size'] ?? 0) / 1024, 1) ?> KB</span>
                        </div>
                    </div>
                    
                    <?php if ($current_user_role == 1 || hasPermission('varliklar_delete_attachment') || hasPermission('varliklar_edit')): ?>
                    <div class="doc-actions ml-auto">
                        <button type="button" onclick="deleteAttachment(<?= (int)$atch['id'] ?>)" class="btn btn-sm btn-outline-danger border-0 p-2" style="border-radius:8px;">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
        ?>

        <!-- RIGHT COLUMN: Image & Consolidated Info -->
        <div class="col-lg-4">
            <div class="vd-card mb-3">
                <div class="img-display-box" style="height: auto; min-height: 250px; padding: 25px;">
                    <img src="<?= htmlspecialchars($display_img) ?>" alt="Asset"
                        style="width: 100%; height: auto; max-height: 250px; object-fit: contain; filter: drop-shadow(0 10px 15px rgba(0,0,0,0.1));"
                        onerror="this.src='https://ui-avatars.com/api/?name=Asset&background=eee&color=888'">
                </div>

                <div class="vd-card-body border-top">
                    <table class="vd-info-table">
                        <tr>
                            <td class="lbl" style="width: 100px;"><?= $isTr ? 'Kategori' : 'Category' ?></td>
                            <td class="val"><?= htmlspecialchars($asset['category_name'] ?? '-') ?></td>
                        </tr>
                        <?php if (!empty($asset['manufacturer_name']) && $asset['manufacturer_name'] !== '-'): ?>
                            <tr>
                                <td class="lbl"><?= $isTr ? 'Marka' : 'Brand' ?></td>
                                <td class="val"><?= htmlspecialchars($asset['manufacturer_name']) ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="lbl"><?= $isTr ? 'Model' : 'Model' ?></td>
                            <td class="val"><?= htmlspecialchars($asset['model_name'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="lbl"><?= $isTr ? 'Seri No' : 'Serial No' ?></td>
                            <td class="val"><?= htmlspecialchars($asset['serial_no'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="lbl"><?= $isTr ? 'Şirket' : 'Company' ?></td>
                            <td class="val"><?= htmlspecialchars($asset['company_name'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="lbl"><?= $isTr ? 'Bölüm' : 'Department' ?></td>
                            <td class="val"><?= htmlspecialchars($asset['dept_name'] ?? '-') ?></td>
                        </tr>
                        <?php if (!empty($asset['notes'])): ?>
                            <tr>
                                <td class="lbl"><?= $isTr ? 'Notlar' : 'Notes' ?></td>
                                <td class="val small font-italic"><?= htmlspecialchars($asset['notes']) ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <?php if (s('inventory_enable_qr_labels') === '1'): ?>
                <!-- QR CODE / BARCODE CARD -->
                <div class="vd-card">
                    <div class="vd-card-header">
                        <i class="fas fa-qrcode text-primary"></i> <?= $isTr ? 'VarlÄ±k Etiketi / QR' : 'Asset Label / QR' ?>
                    </div>
                    <div class="vd-card-body p-0">
                        <?php
                        
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = rtrim(str_replace('/public', '', dirname($scriptName)), '/\\');
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . $basePath;

                        $qr_url = $baseUrl . "/cihaz/izle/" . urlencode($asset['public_token']) . "?lang=" . ($_SESSION['lang'] ?? 'tr');
                        $qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qr_url);

                        $displayName = htmlspecialchars($asset['name'] ?? ($asset['software_name'] ?? 'Item'));
                        $displayTag = htmlspecialchars($asset['asset_tag'] ?? '');
                        $fullLabelHeader = ($displayTag && $displayTag !== $displayName) ? "$displayTag: $displayName" : $displayName;

                        $barcodeData = !empty($asset['asset_tag']) ? $asset['asset_tag'] : (!empty($asset['serial_no']) ? $asset['serial_no'] : $item_id);
                        $realBarcodeUrl = "https://bwipjs-api.metafloor.com/?bcid=code128&text=" . urlencode($barcodeData) . "&scale=2&rotate=N&includetext=true";
                        ?>
                        <!-- PROFESYONEL ETÄ°KET TASARIMI -->
                        <div id="printableLabel" class="asset-label-container shadow-sm mx-auto my-3">
                            <div class="label-top-row">
                                <div class="label-qr-box">
                                    <img src="<?= $qr_api_url ?>" alt="QR" crossorigin="anonymous">
                                </div>
                                <div class="label-info-box">
                                    <div class="label-asset-tag"><?= $fullLabelHeader ?></div>
                                    <div class="label-logo-box">
                                        <?php
                                        $logo = s('logo_path');
                                        if ($logo)
                                            echo '<img src="' . $baseUrl . '/public/' . $logo . '" alt="Logo" crossorigin="anonymous">';
                                        else
                                            echo '<span class="font-weight-bold text-primary">' . s('company_name') . '</span>';
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div class="label-barcode-row">
                                <img src="<?= $realBarcodeUrl ?>" alt="Barcode" style="max-width:100%; height:42px;"
                                    crossorigin="anonymous">
                            </div>
                        </div>

                        <div class="text-center pb-4 d-flex justify-content-center gap-2 flex-wrap">
                            <button onclick="downloadLabelAsPDF()"
                                class="btn btn-sm btn-danger rounded-pill px-3 shadow-sm">
                                <i class="fas fa-file-pdf mr-1"></i> PDF Ä°ndir
                            </button>
                            <button onclick="downloadLabelAsJPG()" class="btn btn-sm btn-info rounded-pill px-3 shadow-sm">
                                <i class="fas fa-image mr-1"></i> JPG Ä°ndir
                            </button>
                            <button onclick="generateAndPrintLabel()"
                                class="btn btn-sm btn-dark rounded-pill px-3 shadow-sm">
                                <i class="fas fa-print mr-1"></i> YazdÄ±r
                            </button>
                        </div>
                    </div>
                </div>

                <style>
                    .asset-label-container {
                        width: 60mm;
                        height: 30mm;
                        background: white !important;
                        border: 1.2px solid #000 !important;
                        padding: 1.5mm !important;
                        display: flex !important;
                        flex-direction: column !important;
                        color: black !important;
                        font-size: 8pt;
                        font-family: 'Arial', sans-serif !important;
                        -webkit-print-color-adjust: exact;
                        box-sizing: border-box;
                    }

                    .label-top-row {
                        display: flex !important;
                        height: 17mm !important;
                        border-bottom: 1.2px solid #000 !important;
                        box-sizing: border-box;
                    }

                    .label-qr-box {
                        width: 17mm !important;
                        display: flex !important;
                        align-items: center !important;
                        justify-content: center !important;
                        border-right: 1.2px solid #000 !important;
                        padding-right: 1mm !important;
                        box-sizing: border-box;
                    }

                    .label-qr-box img {
                        width: 15mm !important;
                        height: 15mm !important;
                    }

                    .label-info-box {
                        flex: 1 !important;
                        padding-left: 1.5mm !important;
                        display: flex !important;
                        flex-direction: column !important;
                        justify-content: space-between !important;
                        overflow: hidden !important;
                        box-sizing: border-box;
                    }

                    .label-asset-tag {
                        font-size: 8pt !important;
                        font-weight: bold !important;
                        text-transform: uppercase !important;
                        line-height: 1.1 !important;
                        word-break: break-all !important;
                        color: #000 !important;
                    }

                    .label-logo-box {
                        height: 7mm !important;
                        display: flex !important;
                        align-items: flex-end !important;
                        justify-content: flex-end !important;
                    }

                    .label-logo-box img {
                        max-height: 6.5mm !important;
                        max-width: 25mm !important;
                        object-fit: contain !important;
                    }

                    .label-barcode-row {
                        flex: 1 !important;
                        display: flex !important;
                        align-items: center !important;
                        justify-content: center !important;
                        padding-top: 1mm !important;
                        box-sizing: border-box;
                    }
                </style>

                <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
                <script>
                    async function downloadLabelAsJPG() {
                        const label = document.getElementById('printableLabel');
                        try {
                            const canvas = await html2canvas(label, { useCORS: true, scale: 4, backgroundColor: '#ffffff' });
                            const link = document.createElement('a');
                            link.download = '<?= $displayName ?>-etiket.jpg';
                            link.href = canvas.toDataURL('image/jpeg', 0.95);
                            link.click();
                        } catch (err) { alert('Hata: ' + err); }
                    }

                    async function downloadLabelAsPDF() {
                        const { jsPDF } = window.jspdf;
                        const label = document.getElementById('printableLabel');
                        try {
                            const canvas = await html2canvas(label, { useCORS: true, scale: 4, backgroundColor: '#ffffff' });
                            const imgData = canvas.toDataURL('image/png');
                            const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: [60, 30] });
                            pdf.addImage(imgData, 'PNG', 0, 0, 60, 30);
                            pdf.save('<?= $displayName ?>-etiket.pdf');
                        } catch (err) { alert('Hata: ' + err); }
                    }

                    function generateAndPrintLabel() {
                        const label = document.getElementById('printableLabel');
                        const styleContent = `
                        @page { size: 60mm 30mm; margin: 0mm; }
                        body { 
                            margin: 0; 
                            padding: 0; 
                            display: flex; 
                            justify-content: center; 
                            align-items: center; 
                            background: #fff;
                        }
                        .asset-label-container {
                            width: 60mm !important;
                            height: 30mm !important;
                            background: white !important;
                            border: 1.2px solid #000 !important;
                            padding: 1.5mm !important;
                            display: flex !important;
                            flex-direction: column !important;
                            color: black !important;
                            font-size: 8pt !important;
                            font-family: Arial, sans-serif !important;
                            -webkit-print-color-adjust: exact !important;
                            box-sizing: border-box !important;
                            page-break-inside: avoid;
                        }
                        .label-top-row {
                            display: flex !important;
                            height: 17mm !important;
                            border-bottom: 1.2px solid #000 !important;
                            box-sizing: border-box !important;
                        }
                        .label-qr-box {
                            width: 17mm !important;
                            display: flex !important;
                            align-items: center !important;
                            justify-content: center !important;
                            border-right: 1.2px solid #000 !important;
                            padding-right: 1mm !important;
                            box-sizing: border-box !important;
                        }
                        .label-qr-box img { width: 15mm !important; height: 15mm !important; display: block !important; }
                        .label-info-box {
                            flex: 1 !important;
                            padding-left: 1.5mm !important;
                            display: flex !important;
                            flex-direction: column !important;
                            justify-content: space-between !important;
                            overflow: hidden !important;
                        }
                        .label-asset-tag {
                            font-size: 8pt !important;
                            font-weight: bold !important;
                            text-transform: uppercase !important;
                            margin-top: 0.5mm !important;
                            line-height: 1.1 !important;
                        }
                        .label-logo-box {
                            height: 7mm !important;
                            display: flex !important;
                            align-items: flex-end !important;
                            justify-content: flex-end !important;
                        }
                        .label-logo-box img { max-height: 6.5mm !important; max-width: 25mm !important; object-fit: contain !important; }
                        .label-barcode-row {
                            flex: 1 !important;
                            display: flex !important;
                            align-items: center !important;
                            justify-content: center !important;
                            padding-top: 5px !important;
                        }
                        .label-barcode-row img { max-width: 100% !important; height: 42px !important; display: block !important; }
                    `;

                        let labelHTML = label.outerHTML;
                        const bUrl = '<?= $baseUrl ?>/';
                        labelHTML = labelHTML.replace(/src="public\//g, 'src="' + bUrl + 'public/');

                        const printWindow = window.open('', '_blank', 'width=600,height=400');
                        printWindow.document.write('<html><head><title>BaskÄ± Ã–nizleme</title>');
                        printWindow.document.write('<style>' + styleContent + '</style>');
                        printWindow.document.write('</head><body>');
                        printWindow.document.write(labelHTML);
                        printWindow.document.write('<script>window.onload = function() { setTimeout(function(){ window.print(); window.close(); }, 700); };<\/script>');
                        printWindow.document.write('</body></html>');
                        printWindow.document.close();
                    }
                </script>
            <?php endif; ?>
        </div>
    </div>

    <!-- BOTTOM FULL WIDTH PAGE: HISTORY -->
    <div id="full-history" class="vd-card mt-2">
        <div class="vd-card-header">
            <i class="fas fa-history text-primary"></i> <?= $isTr ? 'Hareket KayÄ±tlarÄ±' : 'Activity History' ?>
            <div class="ml-auto d-flex align-items-center gap-2">
                <?php if ($totalPagesLogs > 1): ?>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($i = 1; $i <= $totalPagesLogs; $i++): ?>
                                <li class="page-item <?= ($i == $logPage) ? 'active' : '' ?>"><a class="page-link"
                                        href="<?= (strpos($_SERVER['REQUEST_URI'], '/cihaz/izle/') !== false) ? 'cihaz/izle/' . $item_id : 'varlik-detay/' . $item_id ?>?view=<?= $view ?>&log_page=<?= $i ?>#full-history"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
                <?php if ($current_user_role == 1 || hasPermission('varliklar_clear_logs') || hasPermission('varliklar_edit')): ?>
                <button class="btn btn-xs btn-outline-danger border-0 rounded-pill px-3" onclick="clearAllLogs()"><i
                        class="fas fa-trash-alt mr-1"></i> <?= $isTr ? 'Temizle' : 'Clear' ?></button>
                <?php endif; ?>
            </div>
        </div>
        <div class="vd-card-body p-0">
            <div class="table-responsive">
                <table class="history-table">
                    <thead>
                        <tr>
                            <?php if ($current_user_role == 1 || hasPermission('varliklar_clear_logs') || hasPermission('varliklar_edit')): ?>
                            <th style="width:40px;"><input type="checkbox" id="selectAllLogs"></th>
                            <?php endif; ?>
                            <th><?= $isTr ? 'İsim' : 'Name' ?></th>
                            <th><?= $isTr ? 'Tarih' : 'Date' ?></th>
                            <th><?= $isTr ? 'Kişi' : 'User' ?></th>
                            <th><?= $isTr ? 'İşlem' : 'Action' ?></th>
                            <?php if ($current_user_role == 1 || hasPermission('varliklar_clear_logs') || hasPermission('varliklar_edit')): ?>
                            <th class="text-right"><?= $isTr ? 'İşlem' : 'Action' ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($timeline)): ?>
                            <tr>
                                <td colspan="<?= ($current_user_role == 1 || hasPermission('varliklar_clear_logs') || hasPermission('varliklar_edit')) ? 6 : 4 ?>" class="text-center py-4 text-muted">
                                    <?= $isTr ? 'Henüz bir hareket kaydı yok.' : 'No history yet.' ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($timeline as $t):
                                $evTypeRaw = strtoupper($t['event_type']);
                                $evTypeTrans = $evTypeRaw;
                                $evTypeTranslations = [
                                    'UPDATED' => ['tr' => 'güncellendi', 'en' => 'updated'],
                                    'CHECKOUT' => ['tr' => 'zimmetlendi', 'en' => 'checked out'],
                                    'CHECKIN' => ['tr' => 'iade alındı', 'en' => 'checked in'],
                                    'CREATE' => ['tr' => 'oluşturuldu', 'en' => 'created'],
                                    'CREATED' => ['tr' => 'oluşturuldu', 'en' => 'created'],
                                    'DELETE' => ['tr' => 'silindi', 'en' => 'deleted'],
                                    'TRANSFER' => ['tr' => 'taşındı', 'en' => 'transferred'],
                                    'APPROVED' => ['tr' => 'onaylandı', 'en' => 'approved'],
                                    'REJECTED' => ['tr' => 'reddedildi', 'en' => 'rejected'],
                                    'PENDING' => ['tr' => 'beklemede', 'en' => 'pending'],
                                    'TIMELINE_CREATED' => ['tr' => 'oluşturuldu', 'en' => 'created'],
                                    'TIMELINE_UPDATED' => ['tr' => 'güncellendi', 'en' => 'updated'],
                                    'TIMELINE_CHECKIN' => ['tr' => 'iade alındı', 'en' => 'checked in'],
                                    'TIMELINE_CHECKOUT' => ['tr' => 'zimmetlendi', 'en' => 'checked out']
                                ];
                                if (isset($evTypeTranslations[$evTypeRaw])) {
                                    $evTypeTrans = $isTr ? $evTypeTranslations[$evTypeRaw]['tr'] : $evTypeTranslations[$evTypeRaw]['en'];
                                }
                                ?>
                                <tr>
                                    <?php if ($current_user_role == 1 || hasPermission('varliklar_clear_logs') || hasPermission('varliklar_edit')): ?>
                                    <td><input type="checkbox" class="selectLog" value="<?= $t['id'] ?>"></td>
                                    <?php endif; ?>
                                    <td class="font-weight-bold">
                                        <?= htmlspecialchars($asset['name'] ?? $asset['software_name'] ?? 'Varlık') ?></td>
                                    <td><?= date('d.m.Y H:i', strtotime($t['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($t['fullname'] ?: 'System') ?></td>
                                    <td class="text-info">
                                        <div class="d-flex flex-column">
                                            <span class="font-weight-bold"><?= $evTypeTrans ?></span>
                                            <div class="text-muted small mt-1" style="font-size: 11px; line-height: 1.3; font-weight: normal; color: #94a3b8 !important;"><?= htmlspecialchars(translateLogDescription($t['event_description'], $isTr)) ?></div>
                                        </div>
                                    </td>
                                    <?php if ($current_user_role == 1 || hasPermission('varliklar_clear_logs') || hasPermission('varliklar_edit')): ?>
                                    <td class="text-right">
                                        <button class="btn-return-sm" onclick="deleteLog(<?= $t['id'] ?>)"><i
                                                class="fas fa-times"></i></button>
                                    </td>
                                    <?php endif; ?>
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


<style>
    .btn-return-sm {
        background: none;
        border: none;
        color: #6c757d;
        font-size: 1rem;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        transition: all 0.2s ease-in-out;
    }

    .btn-return-sm:hover {
        color: #dc3545;
        background-color: rgba(220, 53, 69, 0.1);
        cursor: pointer;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function confirmRestoreDetail(id, view) {
        Swal.fire({
            title: '<?= $isTr ? "Geri Yükle" : "Restore" ?>',
            text: '<?= $isTr ? "Bu envanteri geri yüklemek istediğinize emin misiniz?" : "Are you sure you want to restore this inventory item?" ?>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            confirmButtonText: '<?= $isTr ? "Evet, Geri Yükle" : "Yes, Restore" ?>',
            cancelButtonText: '<?= __("cancel") ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('<?= $base_url ?>varlik-detay/' + id + '?view=' + view, {
                    action: 'restore_item',
                    item_id: id,
                    csrf_token: '<?= csrf_token() ?>'
                }, function(res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        Swal.fire('Error', res.message || 'Operation failed', 'error');
                    }
                }, 'json');
            }
        });
    }

    function confirmDeleteDetail(id, view) {
        Swal.fire({
            title: '<?= $isTr ? "Kalıcı Olarak Sil" : "Delete Permanently" ?>',
            text: '<?= $isTr ? "Bu işlem geri alınamaz! Kalıcı olarak silmek istediğinize emin misiniz?" : "This action cannot be undone! Are you sure you want to delete permanently?" ?>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: '<?= $isTr ? "Evet, Kalıcı Sil" : "Yes, Delete Permanently" ?>',
            cancelButtonText: '<?= __("cancel") ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('<?= $base_url ?>varlik-detay/' + id + '?view=' + view, {
                    action: 'delete_item_permanent',
                    item_id: id,
                    csrf_token: '<?= csrf_token() ?>'
                }, function(res) {
                    if (res.success) {
                        window.location.href = '<?= $base_url ?>varliklar/' + view + '/deleted';
                    } else {
                        Swal.fire('Error', res.message || 'Operation failed', 'error');
                    }
                }, 'json');
            }
        });
    }

    function deleteLog(logId) {
        Swal.fire({
            title: '<?= $isTr ? "Emin misiniz?" : "Are you sure?" ?>',
            text: '<?= $isTr ? "Bu kaydı kalıcı olarak silmek istediğinizden emin misiniz?" : "Are you sure you want to permanently delete this log?" ?>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: '<?= $isTr ? "Evet, Sil" : "Yes, Delete" ?>',
            cancelButtonText: '<?= $isTr ? "Vazgeç" : "Cancel" ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post(window.location.href, { action: 'delete_log', log_id: logId, csrf_token: '<?= csrf_token() ?>' }, function (r) {
                    window.location.reload();
                });
            }
        });
    }

    function deleteSelectedLogs() {
        const ids = Array.from(document.querySelectorAll(".selectLog:checked")).map(cb => cb.value);
        if (ids.length === 0) return;

        Swal.fire({
            title: '<?= $isTr ? "Emin misiniz?" : "Are you sure?" ?>',
            text: '<?= $isTr ? "Seçilen kayıtları kalıcı olarak silmek istediğinizden emin misiniz?" : "Are you sure you want to permanently delete selected logs?" ?>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: '<?= $isTr ? "Evet, Sil" : "Yes, Delete" ?>',
            cancelButtonText: '<?= $isTr ? "Vazgeç" : "Cancel" ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post(window.location.href, {
                    action: 'delete_multiple_logs',
                    ids: ids.join(','),
                    csrf_token: '<?= csrf_token() ?>'
                }, function (r) {
                    window.location.reload();
                });
            }
        });
    }

    function clearAllLogs() {
        Swal.fire({
            title: '<?= $isTr ? "Tümünü Sil?" : "Delete All?" ?>',
            text: '<?= $isTr ? "TÜM geçmiş hareketlerini kalıcı olarak silmek istediğinizden emin misiniz?" : "Are you sure you want to permanently delete ALL history logs?" ?>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: '<?= $isTr ? "Evet, Tümünü Sil" : "Yes, Delete All" ?>',
            cancelButtonText: '<?= $isTr ? "Vazgeç" : "Cancel" ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post(window.location.href, { action: 'clear_logs', csrf_token: '<?= csrf_token() ?>' }, function (r) {
                    window.location.reload();
                });
            }
        });
    }

    <?php if ($current_user_role == 1 || hasPermission('varliklar_clear_logs') || hasPermission('varliklar_edit')): ?>
    $(document).on("change", "#selectAllLogs", function () {
        $(".selectLog").prop("checked", $(this).prop("checked"));
        updateBulkBtn();
    });
    $(document).on("change", ".selectLog", function () {
        updateBulkBtn();
    });
    function updateBulkBtn() {
        const count = $(".selectLog:checked").length;
        if (count > 0) {
            if (!$("#btnBulkLogDel").length) {
                $("<button id='btnBulkLogDel' class='btn btn-xs btn-danger rounded-pill px-3 mr-2' onclick='deleteSelectedLogs()'><i class='fas fa-trash-alt mr-1'></i> <?= $isTr ? 'Seçilenleri Sil' : 'Delete Selected' ?></button>").insertBefore("button[onclick='clearAllLogs()']");
            }
        } else {
            $("#btnBulkLogDel").remove();
        }
    }
    <?php endif; ?>

    function confirmDelete(id, assignedTo = '', linkedSummary = '', itemName = '', curView = '<?= $view ?>') {
        const isAssigned = (assignedTo && assignedTo !== '' && assignedTo !== '&mdash;' && assignedTo !== 'null' && assignedTo !== 'undefined');
        const hasLinks = (linkedSummary && linkedSummary.trim() !== '' && linkedSummary !== 'null' && linkedSummary !== 'undefined');

        if (isAssigned || hasLinks) {
            let msg = `<strong>${itemName || '<?= addslashes($asset['name'] ?? $asset['software_name'] ?? 'VarlÄ±k') ?>'}</strong> <?= $isTr ? 'ÅŸu anda silinemez.' : 'cannot be deleted at this time.' ?><br><br>`;
            
            if (isAssigned) {
                msg += `<?= $isTr ? 'Bu varlÄ±k ÅŸu anda' : 'This item is currently assigned to' ?> <strong>${assignedTo}</strong> <?= $isTr ? 'Ã¼zerinde zimmetlidir.' : '.' ?><br>`;
            }
            
            if (hasLinks) {
                msg += `<div class="alert alert-warning py-2 px-3 small text-left mt-2" style="border-radius:10px; border:none; background:rgba(245,158,11,0.1); color:#92400e;">
                            <i class="fas fa-link mr-2"></i><strong><?= $isTr ? 'Bağlı Nesneler:' : 'Linked Items:' ?></strong><br>${linkedSummary}
                         </div>`;
            }
            
            msg += `<br><?= $isTr ? 'Silme işlemini gerçekleştirmek için önce zimmetleri iade almanız (Check-in) gerekmektedir.' : 'To proceed with deletion, you must first check-in (return) all assignments.' ?>`;

            Swal.fire({
                title: '<?= $isTr ? 'Silme İşlemi Engellendi' : 'Deletion Blocked' ?>',
                html: msg,
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#0891b2',
                cancelButtonColor: '#64748b',
                confirmButtonText: '<?= $isTr ? 'İade Al sekmesine git' : 'Go to Return tab' ?>',
                cancelButtonText: '<?= __("close") ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    let hash = '';
                    if (curView === 'assets') hash = '#tab-zimmet';
                    else if (curView === 'accessories' || curView === 'licenses') hash = '#tab-related-mixed';
                    else if (curView === 'consumables') hash = '#tab-usage';
                    
                    if (hash) {
                         $('.nav-link[href="' + hash + '"]').tab('show');
                    }
                }
            });
            return;
        }

        let title = '<?= $isTr ? "Emin misiniz?" : "Are you sure?" ?>';
        let text = '<?= $isTr ? "Bu işlem kalıcıdır ve geri alınamaz!" : "This action is permanent and cannot be undone!" ?>';
        text += `<br><br><strong><?= $isTr ? 'Yine de silmek istiyor musunuz?' : 'Do you still want to delete?' ?></strong>`;

        Swal.fire({
            title: title,
            html: text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: '<?= $isTr ? "Evet, Sil" : "Yes, Delete" ?>',
            cancelButtonText: '<?= $isTr ? "Vazgeç" : "Cancel" ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                try {
                    const f = document.createElement('form'); f.method = 'POST'; f.action = 'varliklar';
                    f.innerHTML = `<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="view" value="<?= $view ?>"><input type="hidden" name="asset_id" value="${id}">`;
                    document.body.appendChild(f); f.submit();
                } catch (e) { }
            }
        });
    }

    function checkInItem(id, type, targetType = 'user', name = '') {
        if (targetType === 'asset') {
            window.directCheckin(null, id, type, name);
        } else {
            openReturnModal(id, type, targetType, name);
        }
    }
    function checkInLinkedItem(itemId, view, itemName, checkoutId = 0, targetType = 'user') {
        if (targetType === 'asset') {
            window.directCheckin(checkoutId, itemId, view, itemName);
        } else {
            openReturnModal(itemId, view, targetType, itemName, checkoutId);
            setTimeout(() => {
                $('#return_asset_id').data('checkout_id', checkoutId);
                $('#return_asset_id').data('from_asset_id', <?= intval($asset['id'] ?? 0) ?>);
            }, 100);
        }
    }

    function copyAsset() {
        const id = <?= json_encode($item_id) ?>;
        const view = <?= json_encode($view) ?>;
        if (typeof isTr === 'undefined') var isTr = <?= json_encode($isTr) ?>;
        const name = <?= json_encode($asset['name'] ?? $asset['software_name'] ?? 'Item') ?>;

        Swal.fire({
            title: isTr ? 'Kopyala' : 'Copy',
            text: isTr ? `"${name}" öğesini kopyalamak istediğinizden emin misiniz?` : `Are you sure you want to copy "${name}"?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: isTr ? 'Evet, Kopyala' : 'Yes, Copy',
            cancelButtonText: isTr ? 'Vazgeç' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'varliklar';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="view" value="${view}">
                    <input type="hidden" name="asset_id" value="">
                    <input type="hidden" name="name" value="${(name + ' (Copy)').replace(/"/g, '&quot;')}">
                    <input type="hidden" name="serial_no" value="">
                    <input type="hidden" name="asset_tag" value="">
                    <input type="hidden" name="model_id" value="<?= $asset['model_id'] ?? '' ?>">
                    <input type="hidden" name="category_id" value="<?= $asset['category_id'] ?? '' ?>">
                    <input type="hidden" name="quantity" value="<?= $asset['total_qty'] ?? 1 ?>">
                    <input type="hidden" name="purchase_cost" value="<?= $asset['purchase_cost'] ?? 0 ?>">
                    <input type="hidden" name="purchase_currency" value="<?= $asset['purchase_currency'] ?? 'TRY' ?>">
                    <input type="hidden" name="manufacturer_id" value="<?= $asset['manufacturer_id'] ?? '' ?>">
                    <input type="hidden" name="supplier_id" value="<?= $asset['supplier_id'] ?? '' ?>">
                    <input type="hidden" name="item_no" value="">
                    <input type="hidden" name="model_no" value="<?= $asset['model_no'] ?? '' ?>">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    $(function() {
        const hash = window.location.hash;
        if(hash) {
            $('.nav-link[href="' + hash + '"]').tab('show');
        }
    });

    function filterAttachments() {
        let input = document.getElementById('attachment-search');
        if (!input) return;
        let filter = input.value.toUpperCase();
        let items = document.querySelectorAll('.attachment-item');
        items.forEach(item => {
            let name = item.getAttribute('data-name');
            if (name.indexOf(filter) > -1) {
                item.style.display = "";
            } else {
                item.style.display = "none";
            }
        });
    }

    function exportAttachmentsToExcel() {
        let csv = "Dosya Adi;Yukleme Tarihi\n";
        document.querySelectorAll('.attachment-item').forEach(item => {
            if (item.style.display !== 'none') {
                let name = item.getAttribute('data-name');
                let date = item.getAttribute('data-date');
                csv += `${name};${date}\n`;
            }
        });
        
        const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement("a");
        const url = URL.createObjectURL(blob);
        link.setAttribute("href", url);
        link.setAttribute("download", "cihaz_belge_gecmisi.csv");
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function deleteAttachment(id) {
        Swal.fire({
            title: <?= json_encode($isTr ? 'Emin misiniz?' : 'Are you sure?') ?>,
            text: <?= json_encode($isTr ? 'Bu belge kalıcı olarak silinecektir!' : 'This document will be permanently deleted!') ?>,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: <?= json_encode($isTr ? 'Evet, Sil' : 'Yes, Delete') ?>,
            cancelButtonText: <?= json_encode($isTr ? 'Vazgeç' : 'Cancel') ?>,
            reverseButtons: true,
            customClass: {
                confirmButton: 'btn btn-danger px-4 mx-2',
                cancelButton: 'btn btn-light px-4 mx-2'
            },
            buttonsStyling: false
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '<?= $baseUrl ?>/varliklar',
                    type: 'POST',
                    data: { action: 'delete_attachment', id: id },
                    success: function(response) {
                        try {
                            const res = typeof response === 'string' ? JSON.parse(response) : response;
                            if (res.success) {
                                $('#atch-' + id).fadeOut(400, function() { $(this).remove(); location.reload(); });
                                Swal.fire({
                                    icon: 'success',
                                    title: <?= json_encode($isTr ? 'Silindi!' : 'Deleted!') ?>,
                                    text: <?= json_encode($isTr ? 'Belge başarıyla kaldırıldı.' : 'Document has been removed.') ?>,
                                    timer: 1500,
                                    showConfirmButton: false
                                });
                            } else {
                                Swal.fire('Hata!', res.message || 'Silinemedi.', 'error');
                            }
                        } catch(e) { 
                            console.error(response);
                            Swal.fire('Hata!', 'Sunucudan geçersiz yanıt alındı.', 'error'); 
                        }
                    }
                });
            }
        });
    }

    $(document).ready(function() {
        // --- TAB PERSISTENCE ON F5 / REFRESH (Clean & Isolated) ---
        const assetIdKey = 'vd_active_tab_<?= (int)($asset['id'] ?? 0) ?>_<?= htmlspecialchars($view) ?>';
        
        // Clean any leaked #tab- hash from URL bar immediately
        if (window.location.hash && window.location.hash.indexOf('#tab-') === 0) {
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, null, window.location.pathname + window.location.search);
            }
        }

        // Listen for tab click / switch
        $(document).on('shown.bs.tab', 'a[data-toggle="tab"]', function (e) {
            const tabHash = $(e.target).attr('href');
            if (tabHash && tabHash.indexOf('#tab-') === 0) {
                try {
                    sessionStorage.setItem(assetIdKey, tabHash);
                } catch(err) {}
            }
        });

        // Restore tab on F5 refresh for this specific asset only
        try {
            const activeTab = sessionStorage.getItem(assetIdKey);
            if (activeTab && activeTab.indexOf('#tab-') === 0 && $(activeTab).length) {
                const tabLink = $('a[data-toggle="tab"][href="' + activeTab + '"]');
                if (tabLink.length) {
                    $('.nav-vd-tabs .nav-link').removeClass('active');
                    $('.vd-card-body .tab-content .tab-pane').removeClass('active show');
                    tabLink.addClass('active');
                    $(activeTab).addClass('active show');
                }
            }
        } catch(err) {}

        $('#form-upload-attachment').on('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const btn = $(this).find('button[type="submit"]');
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
            $.ajax({
                url: '<?= $baseUrl ?>/varliklar',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    try {
                        const res = typeof response === 'string' ? JSON.parse(response) : response;
                        if (res.success) { location.reload(); } else { alert(res.message); }
                    } catch(e) { alert('Hata oluştu.'); }
                },
                complete: function() { btn.prop('disabled', false).html('Yükle'); }
            });
        });
    });
</script>

<!-- MODAL: UPLOAD ATTACHMENT -->
<div class="modal fade" id="modal-upload-attachment" tabindex="-1" role="dialog" aria-hidden="true" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-file-upload text-primary mr-2"></i><?= $isTr ? 'Yeni Belge Yükle' : 'Upload Document' ?></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="form-upload-attachment" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_attachment">
                <input type="hidden" name="entity_id" value="<?= $asset['id'] ?>">
                <input type="hidden" name="entity_type" value="<?= htmlspecialchars($view_singular) ?>">
                <div class="modal-body py-4">
                    <div class="form-group mb-4">
                        <label class="font-weight-bold small text-muted"><?= $isTr ? 'BELGE TÜRÜ' : 'TYPE' ?></label>
                        <select name="document_type" class="form-control bg-light border-0" style="border-radius:10px;">
                            <option value="handover"><?= $isTr ? 'Teslim Tutanağı' : 'Handover' ?></option>
                            <option value="return"><?= $isTr ? 'İade Tutanağı' : 'Return' ?></option>
                            <option value="invoice"><?= $isTr ? 'Fatura' : 'Invoice' ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold small text-muted"><?= $isTr ? 'DOSYA SEÇ' : 'FILE' ?></label>
                        <input type="file" name="attachment_file" class="form-control-file" required>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-dismiss="modal"><?= $isTr ? 'İptal' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary px-4"><?= $isTr ? 'Yükle' : 'Upload' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/return_modal.php'; ?>

